<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/shipping.php';

api_boot(['POST']);
rate_limit('shipping-rates', 20, 60);

$body = read_json_body();
$areaId = $body['areaId'] ?? null;
if (!is_string($areaId) || !preg_match('/^\d{1,10}$/', $areaId)) {
    json_response(['error' => 'Wilayah tujuan tidak valid.'], 422);
}

try {
    $package = shipping_package_from_cart($body['items'] ?? []);
    $rates = shipping_rates($areaId, $package);
} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage()], 422);
} catch (ShippingException $e) {
    error_log('[oleena-shipping] ' . $e->getMessage());
    json_response(['error' => 'Cek ongkir sedang bermasalah, coba lagi.'], 502, ['Cache-Control' => 'no-store']);
}

json_response(['weightGram' => $package['weight'], 'rates' => $rates], 200, ['Cache-Control' => 'no-store']);
