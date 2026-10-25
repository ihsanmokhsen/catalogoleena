<?php
declare(strict_types=1);

// Cek kode voucher untuk isi keranjang saat ini. Potongan final tetap dihitung ulang saat pesanan dibuat.

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/orders.php';

api_boot(['POST']);
// Dibatasi ketat supaya kode voucher tidak bisa ditebak dengan mencoba banyak kode.
rate_limit('voucher', 20, 600);

$body = read_json_body();
try {
    $package = shipping_package_from_cart($body['items'] ?? []);
    $phone = null;
    try {
        $phone = normalize_phone($body['phone'] ?? null);
    } catch (InvalidArgumentException) {
        // Nomor belum diisi/benar: cek sekali-per-pembeli dilakukan lagi saat pesanan dibuat.
    }
    $voucher = voucher_apply($body['code'] ?? '', $package['value'], $phone);
} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage()], 422, ['Cache-Control' => 'no-store']);
}

json_response(['voucher' => $voucher], 200, ['Cache-Control' => 'no-store']);
