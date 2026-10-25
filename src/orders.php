<?php
declare(strict_types=1);

// Pembuatan pesanan. Semua angka (harga, berat, ongkir, total) dihitung ulang di server;
// dari browser hanya dipakai data pembeli, Area ID tujuan, kunci layanan kurir, dan {id, qty} produk.

require_once __DIR__ . '/shipping.php';
require_once __DIR__ . '/settings.php';

const ORDER_PAYMENT_WINDOW_HOURS = 24;

function clean_text(mixed $value, string $label, int $min, int $max, bool $multiline = false): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException("$label wajib diisi.");
    }
    $pattern = $multiline ? '/[^\P{C}\n]/u' : '/\p{C}/u';
    $text = trim((string) preg_replace($pattern, ' ', $value));
    $text = (string) preg_replace($multiline ? '/[ \t]+/' : '/\s+/', ' ', $text);
    $length = mb_strlen($text);
    if ($length < $min || $length > $max) {
        throw new InvalidArgumentException($min > 0 ? "$label harus $min-$max karakter." : "$label maksimal $max karakter.");
    }
    return $text;
}

function normalize_phone(mixed $value): string
{
    $phone = is_string($value) ? preg_replace('/[\s.-]/', '', $value) : '';
    if (!preg_match('/^(?:\+62|62|0)(8\d{7,12})$/', (string) $phone, $match)) {
        throw new InvalidArgumentException('Nomor WhatsApp tidak valid. Contoh: 081234567890.');
    }
    return '62' . $match[1];
}

// Kode unik 1-999 rupiah sehingga total tidak sama dengan pesanan lain yang sedang menunggu bayar.
// Dengan begitu setiap transfer di mutasi rekening bisa langsung dicocokkan ke satu pesanan.
function order_unique_code(int $baseTotal): int
{
    $taken = db()->prepare("SELECT 1 FROM orders WHERE status = 'pending_payment' AND total = ? LIMIT 1");
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $code = random_int(1, 999);
        $taken->execute([$baseTotal + $code]);
        if (!$taken->fetchColumn()) {
            return $code;
        }
    }
    return random_int(1, 999);
}

function order_find_id(string $column, string $value): ?int
{
    $stmt = db()->prepare("SELECT id FROM orders WHERE $column = ?");
    $stmt->execute([$value]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

// Data pesanan yang aman dikirim balik ke pembeli (tanpa alamat dan nomor HP).
function order_public(int $orderId): array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    $stmt = db()->prepare(
        'SELECT product_name, product_series, unit_price, qty, line_total FROM order_items WHERE order_id = ? ORDER BY id'
    );
    $stmt->execute([$orderId]);

    return [
        'code' => $order['order_code'],
        'status' => $order['status'],
        'customerName' => $order['customer_name'],
        // Data penerima hanya sampai ke pemegang token pesanan atau yang tahu kode + nomor WhatsApp-nya.
        'customerPhone' => $order['customer_phone'],
        'address' => $order['address'],
        'note' => $order['note'],
        'areaName' => $order['destination_area_name'],
        'courier' => $order['courier_label'],
        'weightGram' => (int) $order['weight_gram'],
        'subtotal' => (int) $order['subtotal'],
        'shippingCost' => (int) $order['shipping_cost'],
        'uniqueCode' => (int) $order['unique_code'],
        'total' => (int) $order['total'],
        'paymentMethod' => $order['payment_method'],
        // Instruksi bayar (QR, rekening, WhatsApp admin) hanya untuk pesanan manual yang masih menunggu bayar.
        'payment' => $order['payment_method'] === 'manual' && $order['status'] === 'pending_payment' ? manual_payment_info() : null,
        'expiresAt' => $order['expires_at'],
        'createdAt' => $order['created_at'],
        'paidAt' => $order['paid_at'],
        'shippedAt' => $order['shipped_at'],
        'trackingNumber' => $order['tracking_number'],
        'items' => array_map(fn (array $item) => [
            'name' => $item['product_name'],
            'series' => $item['product_series'],
            'unitPrice' => (int) $item['unit_price'],
            'qty' => (int) $item['qty'],
            'lineTotal' => (int) $item['line_total'],
        ], $stmt->fetchAll()),
    ];
}

// Idempotency key hanya diketahui klien yang membuat pesanan, jadi aman menerbitkan token baru saat permintaan diulang.
function order_rotate_token(int $orderId): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE orders SET access_token_hash = ? WHERE id = ?')->execute([hash('sha256', $token), $orderId]);
    return $token;
}

/**
 * Mengembalikan pesanan beserta token akses pembeli.
 *
 * @return array{order: array, created: bool, accessToken: string}
 */
function order_create(array $input): array
{
    $idempotencyKey = $input['idempotencyKey'] ?? null;
    if (!is_string($idempotencyKey) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $idempotencyKey)) {
        throw new InvalidArgumentException('Permintaan tidak valid, muat ulang halaman.');
    }

    // Klik ganda atau kirim ulang: kembalikan pesanan yang sama, jangan buat duplikat.
    $existingId = order_find_id('idempotency_key', $idempotencyKey);
    if ($existingId !== null) {
        return ['order' => order_public($existingId), 'created' => false, 'accessToken' => order_rotate_token($existingId)];
    }

    $customer = $input['customer'] ?? [];
    $name = clean_text($customer['name'] ?? null, 'Nama penerima', 2, 120);
    $phone = normalize_phone($customer['phone'] ?? null);
    $note = clean_text($customer['note'] ?? '', 'Catatan', 0, 500);

    $shipping = $input['shipping'] ?? [];
    $package = shipping_package_from_cart($input['items'] ?? []);
    $shippingMethod = $shipping['method'] ?? 'delivery';
    if ($shippingMethod === 'pickup') {
        $address = clean_text(!empty($customer['address']) ? $customer['address'] : 'Ambil di tempat', 'Alamat', 10, 500, true);
        $areaId = 'pickup';
        $areaName = 'Ambil di tempat';
        $courierCode = 'pickup';
        $serviceCode = 'pickup';
        $courierLabel = 'Ambil di tempat';
        $rate = ['price' => 0];
    } elseif ($shippingMethod === 'delivery') {
        $address = clean_text($customer['address'] ?? null, 'Alamat', 10, 500, true);
        $areaId = $shipping['areaId'] ?? null;
        if (!is_string($areaId) || !preg_match('/^\d{1,10}$/', $areaId)) {
            throw new InvalidArgumentException('Wilayah tujuan tidak valid.');
        }
        $areaName = clean_text($shipping['areaName'] ?? null, 'Nama wilayah', 3, 255);
        $rateKey = $shipping['rateKey'] ?? null;
        if (!is_string($rateKey) || !preg_match('/^[a-z0-9_-]{1,40}:[a-z0-9_-]{1,60}$/', $rateKey)) {
            throw new InvalidArgumentException('Pilih kurir terlebih dahulu.');
        }
        $rate = null;
        foreach (shipping_rates($areaId, $package) as $candidate) {
            if ($candidate['key'] === $rateKey) {
                $rate = $candidate;
                break;
            }
        }
        if ($rate === null) {
            throw new InvalidArgumentException('Tarif kurir sudah berubah, silakan pilih ulang kurir.');
        }
        [$courierCode, $serviceCode] = explode(':', $rateKey, 2);
        $courierLabel = $rate['courierName'] . ' ' . $rate['serviceName'];
    } else {
        throw new InvalidArgumentException('Metode pengiriman tidak valid.');
    }

    $subtotal = $package['value'];
    $method = payment_mode();
    $uniqueCode = $method === 'manual' ? order_unique_code($subtotal + $rate['price']) : 0;
    $total = $subtotal + $rate['price'] + $uniqueCode;

    $accessToken = bin2hex(random_bytes(32));

    $pdo = db();
    for ($attempt = 1; ; $attempt++) {
        $code = 'OLN-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO orders (order_code, idempotency_key, access_token_hash, customer_name, customer_phone, address, note,
                   destination_area_id, destination_area_name, courier_code, courier_service_code, courier_label,
                   weight_gram, subtotal, shipping_cost, unique_code, total, payment_method, client_ip_hash, expires_at)
                 VALUES (:code, :idem, :token_hash, :name, :phone, :address, :note, :area_id, :area_name, :courier, :service,
                   :courier_label, :weight, :subtotal, :shipping, :unique, :total, :method, :ip, NOW() + INTERVAL :hours HOUR)'
            )->execute([
                'code' => $code,
                'idem' => $idempotencyKey,
                'token_hash' => hash('sha256', $accessToken),
                'name' => $name,
                'phone' => $phone,
                'address' => $address,
                'note' => $note === '' ? null : $note,
                'area_id' => $areaId,
                'area_name' => $areaName,
                'courier' => $courierCode,
                'service' => $serviceCode,
                'courier_label' => $courierLabel,
                'weight' => $package['weight'],
                'subtotal' => $subtotal,
                'shipping' => $rate['price'],
                'unique' => $uniqueCode,
                'total' => $total,
                'method' => $method,
                'ip' => hash('sha256', client_ip()),
                'hours' => ORDER_PAYMENT_WINDOW_HOURS,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, product_series, unit_price, qty, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($package['lines'] as $line) {
                $insertItem->execute([
                    $orderId, $line['productId'], $line['name'], $line['series'],
                    $line['unitPrice'], $line['qty'], $line['lineTotal'],
                ]);
            }
            $pdo->commit();
            return ['order' => order_public($orderId), 'created' => true, 'accessToken' => $accessToken];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() !== '23000' || $attempt >= 3) {
                throw $e;
            }
            // Bentrok unik: permintaan paralel dengan idempotency key sama, atau kode order kebetulan sama.
            $existingId = order_find_id('idempotency_key', $idempotencyKey);
            if ($existingId !== null) {
                return ['order' => order_public($existingId), 'created' => false, 'accessToken' => order_rotate_token($existingId)];
            }
        }
    }
}
