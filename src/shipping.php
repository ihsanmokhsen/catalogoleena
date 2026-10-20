<?php
declare(strict_types=1);

// Integrasi ongkir via Biteship (https://biteship.com/id/docs/api).
// Berat dan nilai paket selalu dihitung dari database, bukan dari data yang dikirim browser.

const AREA_CACHE_TTL = 604800; // 7 hari, data wilayah jarang berubah
const RATES_CACHE_TTL = 3600;  // 1 jam
const MAX_CART_LINES = 50;
const MAX_LINE_QTY = 20;

final class ShippingException extends RuntimeException
{
}

function shipping_config(): array
{
    $config = app_config()['shipping'] ?? [];
    if (empty($config['biteship_key'])) {
        throw new ShippingException('Konfigurasi shipping.biteship_key belum diisi.');
    }
    return $config;
}

function biteship_request(string $method, string $path, array $query = [], ?array $body = null): array
{
    $config = shipping_config();
    $url = rtrim($config['base_url'], '/') . $path . ($query ? '?' . http_build_query($query) : '');

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'authorization: ' . $config['biteship_key'],
            'content-type: application/json',
            'accept: application/json',
        ],
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);

    if ($raw === false) {
        throw new ShippingException("Biteship tidak dapat dihubungi: $error");
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || $status >= 400 || ($data['success'] ?? false) !== true) {
        throw new ShippingException("Biteship $path gagal (HTTP $status): " . substr($raw, 0, 500));
    }
    return $data;
}

function shipping_search_areas(string $query): array
{
    $cacheKey = 'areas:' . mb_strtolower($query);
    $cached = cache_get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $data = biteship_request('GET', '/v1/maps/areas', ['countries' => 'ID', 'input' => $query, 'type' => 'single']);
    $areas = array_map(fn (array $area) => [
        'id' => (string) $area['id'],
        'name' => (string) $area['name'],
        'postalCode' => isset($area['postal_code']) ? (string) $area['postal_code'] : '',
    ], $data['areas'] ?? []);

    cache_set($cacheKey, $areas, AREA_CACHE_TTL);
    return $areas;
}

// Ubah isi keranjang [{id, qty}] menjadi paket dengan berat dan nilai dari database.
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
    $stmt = db()->prepare(
        "SELECT id, slug, series, name, price, weight_gram FROM products
         WHERE slug IN ($placeholders) AND is_active = 1 AND status = 'available'"
    );
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
        $weight += (int) $row['weight_gram'] * $qty;
        $value += (int) $row['price'] * $qty;
        $lines[] = [
            'productId' => (int) $row['id'],
            'name' => $row['name'],
            'series' => $row['series'],
            'unitPrice' => (int) $row['price'],
            'qty' => $qty,
            'lineTotal' => (int) $row['price'] * $qty,
        ];
    }

    return ['weight' => $weight, 'value' => $value, 'lines' => $lines];
}

function shipping_rates(string $destinationAreaId, array $package): array
{
    $config = shipping_config();
    if (empty($config['origin_area_id'])) {
        throw new ShippingException('Konfigurasi shipping.origin_area_id belum diisi.');
    }
    $cacheKey = "rates:{$config['origin_area_id']}:$destinationAreaId:{$package['weight']}:{$config['couriers']}";
    $cached = cache_get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $data = biteship_request('POST', '/v1/rates/couriers', [], [
        'origin_area_id' => $config['origin_area_id'],
        'destination_area_id' => $destinationAreaId,
        'couriers' => $config['couriers'],
        'items' => [[
            'name' => 'Paket Oleena',
            'category' => 'fashion',
            'value' => $package['value'],
            'weight' => $package['weight'],
            'quantity' => 1,
        ]],
    ]);

    $rates = [];
    foreach ($data['pricing'] ?? [] as $price) {
        $amount = (int) ($price['price'] ?? 0);
        if ($amount <= 0 || empty($price['courier_code']) || empty($price['courier_service_code'])) {
            continue;
        }
        $rates[] = [
            'key' => $price['courier_code'] . ':' . $price['courier_service_code'],
            'courierName' => (string) ($price['courier_name'] ?? $price['courier_code']),
            'serviceName' => (string) ($price['courier_service_name'] ?? $price['courier_service_code']),
            'duration' => (string) ($price['duration'] ?? ''),
            'price' => $amount,
        ];
    }
    usort($rates, fn (array $a, array $b) => $a['price'] <=> $b['price']);

    cache_set($cacheKey, $rates, RATES_CACHE_TTL);
    return $rates;
}
