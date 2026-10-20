<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/payments.php';

api_boot(['POST']);
rate_limit('payment', 10, 60);

$body = read_json_body();

try {
    $order = order_authorize($body['code'] ?? null, $body['token'] ?? null);
    $redirectUrl = payment_start($order);
} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage()], 422, ['Cache-Control' => 'no-store']);
} catch (PaymentException $e) {
    error_log('[oleena-payment] ' . $e->getMessage());
    json_response(['error' => 'Pembayaran sedang bermasalah, coba lagi sebentar.'], 502, ['Cache-Control' => 'no-store']);
}

json_response(['redirectUrl' => $redirectUrl], 200, ['Cache-Control' => 'no-store']);
