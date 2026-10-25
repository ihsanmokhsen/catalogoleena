<?php
declare(strict_types=1);

// Integrasi ongkir RajaOngkir (Komerce, https://rajaongkir.komerce.id). API key hanya dipakai server-side.
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
    return $config;
}

function rajaongkir_request(string $method, string $path, array $query = [], ?array $body = null): array
{
    $config = shipping_config();
    // Sengaja memakai api_url, bukan base_url: base_url lama menunjuk API RajaOngkir Starter yang sudah mati.
    $url = rtrim((string) ($config['api_url'] ?? 'https://rajaongkir.komerce.id/api/v1'), '/') . $path;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        // DNS VPS kadang lambat: cukup cari alamat IPv4, dan beri waktu lebih untuk resolve + connect.
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['key: ' . $config['rajaongkir_key'], 'accept: application/json', 'content-type: application/x-www-form-urlencoded'],
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
    }
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    if ($raw === false) {
        throw new ShippingException("RajaOngkir tidak dapat dihubungi: $error");
    }
    $data = json_decode($raw, true);
    $meta = is_array($data) ? ($data['meta'] ?? []) : [];
    if (!is_array($data) || $status >= 400 || (int) ($meta['code'] ?? 500) !== 200) {
        $message = (string) ($meta['message'] ?? 'Respons tidak valid');
        throw new ShippingException("RajaOngkir $path gagal (HTTP $status): $message");
    }
    return is_array($data['data'] ?? null) ? $data['data'] : [];
}

// Huruf besar semua dari API diubah ke Title Case, singkatan dalam kurung (NTT) tetap.
function shipping_title_case(string $text): string
{
    $text = mb_convert_case(mb_strtolower(trim($text)), MB_CASE_TITLE);
    return (string) preg_replace_callback('/\(([^)]+)\)/u', static fn (array $m): string => '(' . mb_strtoupper($m[1]) . ')', $text);
}

function shipping_search_areas(string $query): array
{
    $needle = mb_strtolower(trim($query));
    $cacheKey = 'rajaongkir:areas:' . $needle;
    $cached = cache_get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    $areas = [];
    foreach (rajaongkir_request('GET', '/destination/domestic-destination', ['search' => $needle, 'limit' => 20, 'offset' => 0]) as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        $parts = [];
        foreach (['subdistrict_name', 'district_name', 'city_name', 'province_name'] as $field) {
            $part = shipping_title_case((string) ($row[$field] ?? ''));
            if ($part !== '' && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }
        $zip = (string) ($row['zip_code'] ?? '');
        $areas[] = [
            'id' => (string) $row['id'],
            'name' => implode(', ', $parts) . ($zip !== '' && $zip !== '0' ? " $zip" : ''),
            'postalCode' => $zip,
        ];
    }
    cache_set($cacheKey, $areas, AREA_CACHE_TTL);
    return $areas;
}

// ID tujuan RajaOngkir (kelurahan/kecamatan) lokasi toko. Cari dengan: php bin/find-area.php "nama kecamatan"
function shipping_origin_id(): string
{
    $config = shipping_config();
    $configured = trim((string) ($config['origin_id'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }
    $name = trim((string) ($config['origin_name'] ?? ''));
    $matches = $name !== '' ? shipping_search_areas($name) : [];
    if (!$matches) {
        throw new ShippingException('Lokasi toko tidak ditemukan di RajaOngkir. Isi shipping.origin_id.');
    }
    return $matches[0]['id'];
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

function shipping_rates(string $destinationId, array $package): array
{
    $config = shipping_config();
    if (!preg_match('/^\d+$/', $destinationId)) {
        throw new ShippingException('ID wilayah tujuan tidak valid.');
    }
    $couriers = array_values(array_filter(array_map(static fn (string $c): string => strtolower(trim($c)), explode(',', (string) ($config['couriers'] ?? '')))));
    if (!$couriers) {
        throw new ShippingException('Konfigurasi shipping.couriers belum diisi.');
    }
    $origin = shipping_origin_id();
    $cacheKey = "rates:komerce:$origin:$destinationId:{$package['weight']}:" . implode(',', $couriers);
    $cached = cache_get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    $data = rajaongkir_request('POST', '/calculate/domestic-cost', [], [
        'origin' => $origin,
        'destination' => $destinationId,
        'weight' => max(1, $package['weight']),
        'courier' => implode(':', $couriers),
        'price' => 'lowest',
    ]);
    $rates = [];
    foreach ($data as $service) {
        $code = strtolower((string) ($service['code'] ?? ''));
        $serviceName = trim((string) ($service['service'] ?? ''));
        $amount = (int) ($service['cost'] ?? 0);
        if ($amount <= 0 || $serviceName === '' || !in_array($code, $couriers, true)) {
            continue;
        }
        // Nama kurir dari API seperti "Jalur Nugraha Ekakurir (JNE)": pakai singkatan dalam kurung bila ada.
        $courierName = (string) ($service['name'] ?? $code);
        if (preg_match('/\(([^)]+)\)\s*$/', $courierName, $m)) {
            $courierName = $m[1];
        }
        $description = trim((string) ($service['description'] ?? ''));
        $serviceSlug = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtr(strtolower($serviceName), ['<' => '-lt', '>' => '-gt'])), '-');
        $rates[] = [
            'key' => $code . ':' . substr($serviceSlug, 0, 60),
            'courierName' => $courierName,
            // Deskripsi kadang berisi kode internal (mis. "240", "2q9"): tampilkan hanya bila berupa kata.
            'serviceName' => $serviceName . (preg_match('/[a-z]{4,}/i', $description) && strcasecmp($description, $serviceName) !== 0 ? " - $description" : ''),
            'duration' => (string) ($service['etd'] ?? ''),
            'price' => $amount,
        ];
    }
    usort($rates, fn (array $a, array $b): int => $a['price'] <=> $b['price']);
    cache_set($cacheKey, $rates, RATES_CACHE_TTL);
    return $rates;
}
