<?php
declare(strict_types=1);

// URL ini didaftarkan di Dashboard Midtrans > Settings > Payment > Notification URL.

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/payments.php';

api_boot(['POST']);
rate_limit('midtrans-notification', 120, 60);

$body = read_json_body(65536);

try {
    $result = payment_handle_notification($body);
} catch (PaymentException $e) {
    // Gagal cek status ke Midtrans: balas 503 supaya Midtrans mengirim ulang notifikasi.
    error_log('[oleena-payment] ' . $e->getMessage());
    json_response(['status' => 'retry'], 503);
}

if ($result === 'invalid_signature') {
    json_response(['status' => 'invalid_signature'], 403);
}
json_response(['status' => 'ok', 'result' => $result]);
