<?php
declare(strict_types=1);

// Isi database LOKAL dengan data contoh: akun admin, pesanan di semua status, pembayaran, dan log webhook.
// Pakai: php database/seed_demo.php
// Hanya berjalan bila config app.env = 'local'. Aman diulang: data demo lama dihapus dulu.
//
// Akun admin lokal: username "admin", password "oleena-admin-lokal".

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

if ((app_config()['app']['env'] ?? '') !== 'local') {
    fwrite(STDERR, "Ditolak: seed_demo hanya untuk app.env = 'local'.\n");
    exit(1);
}

const DEMO_MARKER = 'demo-seed';

$pdo = db();
$products = $pdo->query("SELECT id, name, series, price, weight_gram FROM products WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
if (!$products) {
    fwrite(STDERR, "Tabel products kosong. Jalankan dulu: php database/seed_products.php\n");
    exit(1);
}

$pdo->prepare(
    'INSERT INTO admin_users (username, password_hash) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
)->execute(['admin', password_hash('oleena-admin-lokal', PASSWORD_DEFAULT)]);

$customers = [
    ['Maria Ndun', '6281234500011', "Jl. Frans Seda No. 12, RT 03/RW 01\nKel. Fatululi", 'IDNP19IDNC300IDND3800IDZ85111', 'Oebobo, Kupang, Nusa Tenggara Timur. 85111'],
    ['Yohana Tefa', '6281339200022', "Jl. Timor Raya Km 7, Oesapa", 'IDNP19IDNC300IDND3801IDZ85228', 'Kelapa Lima, Kupang, Nusa Tenggara Timur. 85228'],
    ['Fransiska Bria', '6282144300033', "Jl. Ahmad Yani No. 21, Kel. Fatubenao", 'IDNP19IDNC304IDND3840IDZ85711', 'Atambua Barat, Belu, Nusa Tenggara Timur. 85711'],
    ['Theresia Kewa', '6281237400044', "Jl. Moa Toda No. 5, Kel. Kota Uneng", 'IDNP19IDNC307IDND3870IDZ86111', 'Alok, Sikka, Nusa Tenggara Timur. 86111'],
    ['Agustina Raja', '6285239500055', "Jl. Soekarno, Kel. Hambala", 'IDNP19IDNC314IDND3940IDZ87111', 'Kota Waingapu, Sumba Timur, Nusa Tenggara Timur. 87112'],
    ['Elisabeth Nggaba', '6281246600066', "Jl. Soekarno Hatta, Kampung Ujung", 'IDNP19IDNC311IDND3910IDZ86754', 'Komodo, Labuan Bajo, Manggarai Barat, Nusa Tenggara Timur. 86763'],
    ['Ni Luh Ayu', '6281999100077', "Jl. Raya Kuta Gg. Melati 4", 'IDNP2IDNC17IDND200IDZ80361', 'Kuta, Badung, Bali. 80361'],
    ['Siti Rahmawati', '6281288800088', "Jl. Bintaro Utama 3 Blok C2 No. 9", 'IDNP6IDNC148IDND843IDZ12250', 'Pesanggrahan, Jakarta Selatan, DKI Jakarta. 12250'],
    ['Andi Fatimah', '6285255500099', "Jl. Boulevard Panakkukang Ruko B/10", 'IDNP28IDNC254IDND3335IDZ90231', 'Panakkukang, Makassar, Sulawesi Selatan. 90231'],
];
$couriers = [
    ['jne', 'reg', 'JNE Reguler'],
    ['jnt', 'ez', 'J&T EZ'],
    ['lion', 'regpack', 'Lion Parcel REGPACK'],
    ['jne', 'oke', 'JNE OKE'],
    ['lion', 'jagopack', 'Lion Parcel JAGOPACK'],
];
// [status, hari lalu, status transaksi Midtrans atau null bila belum dibuka]
$plan = [
    ['pending_payment', 0, null],
    ['pending_payment', 0, 'pending'],
    ['paid', 1, 'settlement'],
    ['paid', 1, 'settlement'],
    ['processing', 2, 'settlement'],
    ['processing', 3, 'settlement'],
    ['shipped', 4, 'settlement'],
    ['shipped', 5, 'settlement'],
    ['completed', 9, 'settlement'],
    ['completed', 12, 'settlement'],
    ['completed', 14, 'settlement'],
    ['cancelled', 6, 'cancel'],
    ['expired', 3, 'expire'],
    ['expired', 8, null],
];

mt_srand(2026);
$pdo->beginTransaction();

// Hapus data demo lama (order_items & payments ikut terhapus lewat ON DELETE CASCADE).
$old = $pdo->prepare('SELECT order_code FROM orders WHERE client_ip_hash = ?');
$old->execute([hash('sha256', DEMO_MARKER)]);
$oldCodes = $old->fetchAll(PDO::FETCH_COLUMN);
if ($oldCodes) {
    $in = implode(',', array_fill(0, count($oldCodes), '?'));
    $pdo->prepare("DELETE FROM payment_notifications WHERE order_code IN ($in)")->execute($oldCodes);
    $pdo->prepare("DELETE FROM orders WHERE order_code IN ($in)")->execute($oldCodes);
}

$insertOrder = $pdo->prepare(
    'INSERT INTO orders (order_code, access_token_hash, status, customer_name, customer_phone, address, note,
       destination_area_id, destination_area_name, courier_code, courier_service_code, courier_label,
       weight_gram, subtotal, shipping_cost, total, client_ip_hash, expires_at, paid_at, tracking_number,
       shipped_at, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertItem = $pdo->prepare(
    'INSERT INTO order_items (order_id, product_id, product_name, product_series, unit_price, qty, line_total)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$insertPayment = $pdo->prepare(
    'INSERT INTO payments (order_id, snap_token, redirect_url, gross_amount, transaction_status, payment_type, transaction_id, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertNotification = $pdo->prepare(
    'INSERT INTO payment_notifications (order_code, transaction_status, signature_valid, result, payload, created_at)
     VALUES (?, ?, 1, ?, ?, ?)'
);

$packaging = (int) (app_config()['shipping']['packaging_weight_gram'] ?? 100);
foreach ($plan as $index => [$status, $daysAgo, $txStatus]) {
    $customer = $customers[$index % count($customers)];
    $courier = $couriers[$index % count($couriers)];
    $createdTs = strtotime("-$daysAgo days") - mt_rand(0, 6 * 3600);
    $created = date('Y-m-d H:i:s', $createdTs);
    $code = 'OLN-' . date('ymd', $createdTs) . '-' . strtoupper(bin2hex(random_bytes(3)));

    $lines = [];
    $weight = $packaging;
    $subtotal = 0;
    $picked = (array) array_rand($products, mt_rand(1, 3));
    foreach ($picked as $key) {
        $product = $products[$key];
        $qty = mt_rand(1, 2);
        $lines[] = [$product, $qty];
        $weight += (int) $product['weight_gram'] * $qty;
        $subtotal += (int) $product['price'] * $qty;
    }
    $shipping = (int) (round((9000 + 4000 * ($index % 5)) * max(1, ceil($weight / 1000)) / 500) * 500);
    $total = $subtotal + $shipping;

    $paidAt = in_array($status, ['paid', 'processing', 'shipped', 'completed'], true) ? date('Y-m-d H:i:s', $createdTs + 1800) : null;
    $shippedAt = in_array($status, ['shipped', 'completed'], true) ? date('Y-m-d H:i:s', $createdTs + 86400) : null;
    $tracking = $shippedAt ? strtoupper($courier[0]) . mt_rand(100000000, 999999999) : null;

    $insertOrder->execute([
        $code, hash('sha256', bin2hex(random_bytes(32))), $status, $customer[0], $customer[1], $customer[2],
        $index % 3 === 0 ? 'Mohon dibungkus rapi, untuk hadiah.' : null,
        $customer[3], $customer[4], $courier[0], $courier[1], $courier[2],
        $weight, $subtotal, $shipping, $total, hash('sha256', DEMO_MARKER),
        date('Y-m-d H:i:s', $status === 'expired' ? $createdTs + 86400 : max($createdTs + 86400, time() + 3600)),
        $paidAt, $tracking, $shippedAt, $created,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    foreach ($lines as [$product, $qty]) {
        $insertItem->execute([$orderId, $product['id'], $product['name'], $product['series'], $product['price'], $qty, $product['price'] * $qty]);
    }

    if ($txStatus !== null) {
        $insertPayment->execute([
            $orderId, 'demo-' . bin2hex(random_bytes(8)), 'https://app.sandbox.midtrans.com/snap/v4/redirection/demo',
            $total, $txStatus, 'qris', 'demo-' . md5($code), date('Y-m-d H:i:s', $createdTs + 300),
        ]);
        $result = ['settlement' => 'paid', 'cancel' => 'cancelled', 'expire' => 'expired', 'pending' => 'no_change'][$txStatus];
        $payload = json_encode(['order_id' => $code, 'transaction_status' => $txStatus, 'gross_amount' => "$total.00", 'payment_type' => 'qris', 'demo' => true]);
        $insertNotification->execute([$code, $txStatus, $result, $payload, $paidAt ?? date('Y-m-d H:i:s', $createdTs + 900)]);
    }
}

$pdo->commit();

$counts = $pdo->query('SELECT status, COUNT(*) FROM orders GROUP BY status ORDER BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
echo count($plan) . " pesanan demo dibuat.\n";
foreach ($counts as $status => $count) {
    echo "  $status: $count\n";
}
echo "Admin lokal: username 'admin' (password ada di komentar atas file ini).\n";
