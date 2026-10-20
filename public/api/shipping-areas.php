<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/shipping.php';

api_boot(['GET']);
rate_limit('shipping-areas', 40, 60);

$query = trim((string) ($_GET['q'] ?? ''));
$length = mb_strlen($query);
if ($length < 3 || $length > 60 || !preg_match('/^[\p{L}\p{N} .,\'-]+$/u', $query)) {
    json_response(['error' => 'Ketik 3-60 huruf nama kecamatan atau kota.'], 422);
}

try {
    $areas = shipping_search_areas($query);
} catch (ShippingException $e) {
    error_log('[oleena-shipping] ' . $e->getMessage());
    json_response(['error' => 'Pencarian wilayah sedang bermasalah, coba lagi.'], 502, ['Cache-Control' => 'no-store']);
}

json_response(['areas' => $areas], 200, ['Cache-Control' => 'private, max-age=300']);
