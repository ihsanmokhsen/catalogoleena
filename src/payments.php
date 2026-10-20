<?php
declare(strict_types=1);

// Pembayaran via Midtrans Snap (https://docs.midtrans.com).
// Status "paid" hanya diberikan oleh webhook yang tanda tangannya valid, lalu dikonfirmasi ulang ke Get Status API.

require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/stock.php';

final class PaymentException extends RuntimeException
{
}

function midtrans_config(): array
{
    $config = app_config()['midtrans'] ?? [];
    if (empty($config['server_key'])) {
        throw new PaymentException('Konfigurasi midtrans.server_key belum diisi.');
    }
    $production = (bool) ($config['is_production'] ?? false);
    return $config + [
        'snap_url' => $production ? 'https://app.midtrans.com/snap/v1/transactions' : 'https://app.sandbox.midtrans.com/snap/v1/transactions',
        'api_url' => $production ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com',
    ];
}

function midtrans_request(string $method, string $url, ?array $body = null): array
{
    $config = midtrans_config();
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($config['server_key'] . ':'),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);

    if ($raw === false) {
        throw new PaymentException("Midtrans tidak dapat dihubungi: $error");
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || $status >= 400) {
        throw new PaymentException("Midtrans gagal (HTTP $status): " . substr($raw, 0, 500));
    }
    return $data;
}

function order_find_by_code(string $code, bool $forUpdate = false): ?array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE order_code = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

// Cek kode pesanan + token akses pembeli. Dipakai oleh endpoint status dan pembayaran.
function order_authorize(mixed $code, mixed $token): array
{
    if (!is_string($code) || !preg_match('/^OLN-\d{6}-[0-9A-F]{6}$/', $code)
        || !is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        throw new InvalidArgumentException('Pesanan tidak ditemukan.');
    }
    $order = order_find_by_code($code);
    if ($order === null || !hash_equals($order['access_token_hash'], hash('sha256', $token))) {
        throw new InvalidArgumentException('Pesanan tidak ditemukan.');
    }
    return $order;
}

function payment_find(int $orderId): ?array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE order_id = ?');
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: null;
}

// Buat (atau pakai ulang) transaksi Snap untuk pesanan, lalu kembalikan URL halaman bayar Midtrans.
function payment_start(array $order): string
{
    if ($order['payment_method'] !== 'midtrans') {
        throw new InvalidArgumentException('Pesanan ini dibayar lewat QR/transfer toko, lalu konfirmasi via WhatsApp.');
    }
    if ($order['status'] !== 'pending_payment' || strtotime($order['expires_at']) <= time()) {
        throw new InvalidArgumentException('Pesanan ini tidak bisa dibayar lagi.');
    }

    // Barang bisa habis dibeli orang lain sejak pesanan dibuat; jangan sampai pembeli membayar barang kosong.
    $shortages = stock_shortages_for_order((int) $order['id']);
    if ($shortages) {
        throw new InvalidArgumentException('Maaf, stok ' . implode(', ', $shortages) . ' sudah habis. Silakan buat pesanan baru atau hubungi kami.');
    }

    $existing = payment_find((int) $order['id']);
    if ($existing !== null) {
        return $existing['redirect_url'];
    }

    $stmt = db()->prepare('SELECT product_id, product_name, unit_price, qty FROM order_items WHERE order_id = ? ORDER BY id');
    $stmt->execute([$order['id']]);
    $items = array_map(fn (array $item) => [
        'id' => 'P' . $item['product_id'],
        'name' => mb_substr($item['product_name'], 0, 50),
        'price' => (int) $item['unit_price'],
        'quantity' => (int) $item['qty'],
    ], $stmt->fetchAll());
    $items[] = [
        'id' => 'ONGKIR',
        'name' => mb_substr('Ongkir ' . $order['courier_label'], 0, 50),
        'price' => (int) $order['shipping_cost'],
        'quantity' => 1,
    ];

    $minutesLeft = max(1, intdiv(strtotime($order['expires_at']) - time(), 60));
    $data = midtrans_request('POST', midtrans_config()['snap_url'], [
        'transaction_details' => [
            'order_id' => $order['order_code'],
            'gross_amount' => (int) $order['total'],
        ],
        'item_details' => $items,
        'customer_details' => [
            'first_name' => mb_substr($order['customer_name'], 0, 50),
            'phone' => $order['customer_phone'],
        ],
        'expiry' => ['unit' => 'minute', 'duration' => $minutesLeft],
        'callbacks' => [
            'finish' => rtrim(app_config()['app']['url'], '/') . '/?order=' . rawurlencode($order['order_code']),
        ],
    ]);
    if (empty($data['token']) || empty($data['redirect_url'])) {
        throw new PaymentException('Respons Snap tidak berisi token.');
    }

    db()->prepare(
        'INSERT INTO payments (order_id, snap_token, redirect_url, gross_amount) VALUES (?, ?, ?, ?)'
    )->execute([$order['id'], $data['token'], $data['redirect_url'], $order['total']]);

    return $data['redirect_url'];
}

function payment_log_notification(array $payload, bool $signatureValid, string $result): void
{
    db()->prepare(
        'INSERT INTO payment_notifications (order_code, transaction_status, signature_valid, result, payload)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        is_string($payload['order_id'] ?? null) ? mb_substr($payload['order_id'], 0, 64) : null,
        is_string($payload['transaction_status'] ?? null) ? mb_substr($payload['transaction_status'], 0, 30) : null,
        $signatureValid ? 1 : 0,
        $result,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function midtrans_signature_valid(array $payload): bool
{
    foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $field) {
        if (!is_string($payload[$field] ?? null)) {
            return false;
        }
    }
    $expected = hash('sha512', $payload['order_id'] . $payload['status_code'] . $payload['gross_amount'] . midtrans_config()['server_key']);
    return hash_equals($expected, strtolower($payload['signature_key']));
}

/**
 * Proses webhook Midtrans. Mengembalikan kode hasil singkat untuk log dan respons.
 */
function payment_handle_notification(array $payload): string
{
    if (!midtrans_signature_valid($payload)) {
        payment_log_notification($payload, false, 'invalid_signature');
        return 'invalid_signature';
    }

    // Jangan percaya isi notifikasi begitu saja: ambil status terbaru langsung dari Midtrans.
    $code = $payload['order_id'];
    $status = midtrans_request('GET', midtrans_config()['api_url'] . '/v2/' . rawurlencode($code) . '/status');
    $transactionStatus = (string) ($status['transaction_status'] ?? '');
    $fraudStatus = (string) ($status['fraud_status'] ?? 'accept');
    $grossAmount = (int) round((float) ($status['gross_amount'] ?? 0));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $order = order_find_by_code($code, true);
        if ($order === null) {
            $result = 'unknown_order';
        } elseif ($grossAmount !== (int) $order['total']) {
            $result = 'amount_mismatch';
        } else {
            $pdo->prepare(
                'UPDATE payments SET transaction_status = ?, payment_type = ?, transaction_id = ? WHERE order_id = ?'
            )->execute([
                mb_substr($transactionStatus, 0, 30),
                mb_substr((string) ($status['payment_type'] ?? ''), 0, 40),
                mb_substr((string) ($status['transaction_id'] ?? ''), 0, 80),
                $order['id'],
            ]);

            $isPaid = $transactionStatus === 'settlement' || ($transactionStatus === 'capture' && $fraudStatus === 'accept');
            $isFailed = in_array($transactionStatus, ['deny', 'cancel', 'expire', 'failure'], true);

            if ($isPaid && in_array($order['status'], ['pending_payment', 'expired', 'cancelled'], true)) {
                $enough = stock_take_for_order((int) $order['id']);
                $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = NOW(), stock_short = ? WHERE id = ?")
                    ->execute([$enough ? 0 : 1, $order['id']]);
                $result = $enough ? 'paid' : 'paid_stock_short';
            } elseif ($isFailed && $order['status'] === 'pending_payment') {
                $newStatus = $transactionStatus === 'expire' ? 'expired' : 'cancelled';
                $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$newStatus, $order['id']]);
                $result = $newStatus;
            } else {
                // pending, refund, atau notifikasi berulang: tidak mengubah status pesanan.
                $result = 'no_change';
            }
        }
        payment_log_notification($payload, true, $result);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($result === 'amount_mismatch') {
        error_log("[oleena-payment] Nominal tidak cocok untuk $code: $grossAmount");
    }
    return $result;
}
