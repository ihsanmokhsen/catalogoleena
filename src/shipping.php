<?php
declare(strict_types=1);

// Integrasi ongkir RajaOngkir Starter. API key hanya dipakai server-side.
// Berat dan nilai paket selalu dihitung dari database, bukan dari data browser.

const AREA_CACHE_TTL = 604800;
const RATES_CACHE_TTL = 3600;
const MAX_CART_LINES = 50;
const MAX_LINE_QTY = 20;

final class ShippingException extends RuntimeException
{
}

function shipping_config(): array
{
    $config = app_config()['shipping'] ?? [];
    if (empty($config['rajaongkir_key'])) {
        throw new ShippingException('Konfigurasi shipping.rajaongkir_key belum diisi.');
    }
    if (empty($config['origin_city_name'])) {
        throw new ShippingException('Konfigurasi shipping.origin_city_name belum diisi.');
    }
    return $config;
}

function rajaongkir_request(string $method, string $path, array $query = [], ?array $body = null): array
{
    $config = shipping_config();
    $url = rtrim((string) ($config['base_url'] ?? 'https://api.rajaongkir.com/starter'), '/') . $path;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['key: ' . $config['rajaongkir_key'], 'accept: application/json', 'content-type: application/x-www-form-urlencoded'],
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
    }
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) {
        throw new ShippingException("RajaOngkir tidak dapat dihubungi: $error");
    }
    $data = json_decode($raw, true);
    $apiStatus = $data['rajaongkir']['status'] ?? [];
    if (!is_array($data) || $status >= 400 || (int) ($apiStatus['code'] ?? 500) !== 200) {
        $message = is_array($apiStatus) ? (string) ($apiStatus['description'] ?? 'Respons tidak valid') : 'Respons tidak valid';
        throw new ShippingException("RajaOngkir $path gagal (HTTP $status): $message");
    }
    return $data['rajaongkir'];
}

function shipping_search_areas(string $query): array
{
    $cities = cache_get('rajaongkir:cities');
    if ($cities === null) {
        $data = rajaongkir_request('GET', '/city');
        $cities = [];
        foreach ($data['results'] ?? [] as $city) {
            if (!is_array($city) || empty($city['city_id'])) {
                continue;
            }
            $cities[] = [
                'id' => (string) $city['city_id'],
                'name' => trim((string) ($city['type'] ?? '') . ' ' . (string) ($city['city_name'] ?? '') . ', ' . (string) ($city['province'] ?? '')),
                'postalCode' => (string) ($city['postal_code'] ?? ''),
            ];
        }
        cache_set('rajaongkir:cities', $cities, AREA_CACHE_TTL);
    }
    $needle = mb_strtolower($query);
    return array_values(array_filter($cities, static fn (array $city): bool => str_contains(mb_strtolower($city['name']), $needle)));
}

function shipping_origin_city_id(): string
{
    $config = shipping_config();
    $configured = trim((string) ($config['origin_city_id'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }
    $matches = shipping_search_areas((string) $config['origin_city_name']);
    foreach ($matches as $city) {
        if (mb_strtolower($city['name']) === mb_strtolower($config['origin_city_name'])) {
            return $city['id'];
        }
    }
    throw new ShippingException('Kota asal tidak ditemukan di RajaOngkir. Isi shipping.origin_city_id.');
}

function shipping_package_from_cart(array $items): array
{
    if (!$items || count($items) > MAX_CART_LINES) {
        throw new InvalidArgumentException('Isi keranjang tidak valid.');
    }
    $quantities = [];
    foreach ($items as $item) {
        $slug = is_array($item) ? ($item['id'] ?? null) : null;
        $qty = is_array($item) ? ($item['qty'] ?? null) : null;
        if (!is_string($slug) || !preg_match('/^[a-z0-9-]{1,120}$/', $slug) || !is_int($qty) || $qty < 1 || $qty > MAX_LINE_QTY) {
            throw new InvalidArgumentException('Isi keranjang tidak valid.');
        }
        $quantities[$slug] = ($quantities[$slug] ?? 0) + $qty;
    }
    $placeholders = implode(',', array_fill(0, count($quantities), '?'));
    $stmt = db()->prepare("SELECT id, slug, series, name, price, weight_gram, stock FROM products WHERE slug IN ($placeholders) AND is_active = 1 AND status = 'available'");
    $stmt->execute(array_keys($quantities));
    $rows = $stmt->fetchAll();
    if (count($rows) !== count($quantities)) {
        throw new InvalidArgumentException('Ada produk yang tidak tersedia. Muat ulang katalog.');
    }
    $weight = (int) (app_config()['shipping']['packaging_weight_gram'] ?? 0);
    $value = 0;
    $lines = [];
    foreach ($rows as $row) {
        $qty = $quantities[$row['slug']];
        if ($qty > (int) $row['stock']) {
            throw new InvalidArgumentException("Stok {$row['name']} tinggal {$row['stock']}. Kurangi jumlahnya di keranjang.");
        }
        $weight += (int) $row['weight_gram'] * $qty;
        $value += (int) $row['price'] * $qty;
        $lines[] = ['productId' => (int) $row['id'], 'name' => $row['name'], 'series' => $row['series'], 'unitPrice' => (int) $row['price'], 'qty' => $qty, 'lineTotal' => (int) $row['price'] * $qty];
    }
    return ['weight' => $weight, 'value' => $value, 'lines' => $lines];
}

function shipping_rates(string $destinationCityId, array $package): array
{
    $config = shipping_config();
    if (!preg_match('/^\d+$/', $destinationCityId)) {
        throw new ShippingException('ID kota tujuan tidak valid.');
    }
    $couriers = array_values(array_filter(array_map('trim', explode(',', (string) $config['couriers']))));
    $origin = shipping_origin_city_id();
    $cacheKey = "rates:rajaongkir:$origin:$destinationCityId:{$package['weight']}:'" . implode(',', $couriers);
    $cached = cache_get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    $rates = [];
    foreach ($couriers as $courier) {
        $data = rajaongkir_request('POST', '/cost', [], ['origin' => $origin, 'destination' => $destinationCityId, 'weight' => max(1, $package['weight']), 'courier' => strtolower($courier)]);
        $courierResult = $data['results'][0] ?? [];
        foreach ($courierResult['costs'] ?? [] as $service) {
            $amount = (int) ($service['cost'][0]['value'] ?? 0);
            if ($amount <= 0 || empty($service['service'])) {
                continue;
            }
            $rates[] = [
                'key' => strtolower($courier) . ':' . strtolower((string) $service['service']),
                'courierName' => strtoupper($courierResult['code'] ?? $courier),
                'serviceName' => (string) $service['service'] . (!empty($service['description']) ? ' - ' . $service['description'] : ''),
                'duration' => (string) ($service['cost'][0]['etd'] ?? ''),
                'price' => $amount,
            ];
        }
    }
    usort($rates, fn (array $a, array $b): int => $a['price'] <=> $b['price']);
    cache_set($cacheKey, $rates, RATES_CACHE_TTL);
    return $rates;
}