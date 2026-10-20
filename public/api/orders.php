<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/orders.php';

api_boot(['POST']);
rate_limit('orders', 20, 600);

$body = read_json_body();

try {
    $result = order_create($body);
} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage()], 422, ['Cache-Control' => 'no-store']);
} catch (ShippingException $e) {
    error_log('[oleena-orders] ' . $e->getMessage());
    json_response(['error' => 'Cek ongkir sedang bermasalah, pesanan belum dibuat. Coba lagi.'], 502, ['Cache-Control' => 'no-store']);
}

json_response(
    ['order' => $result['order'], 'accessToken' => $result['accessToken']],
    $result['created'] ? 201 : 200,
    ['Cache-Control' => 'no-store']
);
