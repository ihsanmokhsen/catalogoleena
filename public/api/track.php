<?php
declare(strict_types=1);

// Lacak pesanan dari perangkat mana pun dengan kode pesanan + nomor WhatsApp pembeli.
// POST (bukan GET) supaya nomor HP tidak tercatat di URL atau log server.

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/payments.php';

api_boot(['POST']);
rate_limit('track', 15, 600);

$body = read_json_body();
$notFound = fn () => json_response(['error' => 'Pesanan tidak ditemukan. Periksa kode pesanan dan nomor WhatsApp.'], 404, ['Cache-Control' => 'no-store']);

$code = strtoupper(trim((string) ($body['code'] ?? '')));
if (!preg_match('/^OLN-\d{6}-[0-9A-F]{6}$/', $code)) {
    $notFound();
}
try {
    $phone = normalize_phone($body['phone'] ?? null);
} catch (InvalidArgumentException) {
    $notFound();
}

$order = order_find_by_code($code);
if ($order === null || !hash_equals($order['customer_phone'], $phone)) {
    $notFound();
}

json_response(['order' => order_public((int) $order['id'])], 200, ['Cache-Control' => 'no-store']);
