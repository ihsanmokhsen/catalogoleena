<?php
declare(strict_types=1);

// Server tiruan RajaOngkir (Komerce) + Midtrans untuk development lokal, sebelum key asli tersedia.
// Jalankan: php -S localhost:8001 dev/mock-server.php
// Lalu di config/config.php arahkan shipping.api_url ke http://localhost:8001/api/v1,
// serta midtrans.snap_url / api_url ke http://localhost:8001.

if (PHP_SAPI !== 'cli-server') {
    exit("Hanya untuk php -S (development lokal).\n");
}

const MOCK_RAJAONGKIR_KEY = 'rajaongkir.mock';
const MOCK_MIDTRANS_KEY = 'SB-Mid-server-mock';
const APP_URL = 'http://localhost:8000';

$stateFile = sys_get_temp_dir() . '/oleena-mock-midtrans.json';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function load_state(string $file): array
{
    return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
}

// ---------- RajaOngkir (Komerce) ----------

// [id, kecamatan, kota/kabupaten, provinsi, kode pos, zona tarif dari Kupang]
// Zona: 1 Kota Kupang, 2 daratan Timor, 3 pulau lain di NTT, 4 Bali/NTB/Jawa, 5 Sulawesi/Kalimantan, 6 Sumatera/Papua.
const AREAS = [
    [90001, 'Oebobo', 'Kupang', 'Nusa Tenggara Timur', '85111', 1],
    [90002, 'Kelapa Lima', 'Kupang', 'Nusa Tenggara Timur', '85228', 1],
    [90003, 'Maulafa', 'Kupang', 'Nusa Tenggara Timur', '85148', 1],
    [90004, 'Alak', 'Kupang', 'Nusa Tenggara Timur', '85231', 1],
    [90005, 'Kota Raja', 'Kupang', 'Nusa Tenggara Timur', '85119', 1],
    [90006, 'Kupang Tengah', 'Kabupaten Kupang', 'Nusa Tenggara Timur', '85361', 1],
    [90007, 'Kota Soe', 'Timor Tengah Selatan', 'Nusa Tenggara Timur', '85511', 2],
    [90008, 'Kota Kefamenanu', 'Timor Tengah Utara', 'Nusa Tenggara Timur', '85613', 2],
    [90009, 'Atambua Barat', 'Belu', 'Nusa Tenggara Timur', '85711', 2],
    [90010, 'Malaka Tengah', 'Malaka', 'Nusa Tenggara Timur', '85762', 2],
    [90011, 'Lobalain', 'Rote Ndao', 'Nusa Tenggara Timur', '85912', 3],
    [90012, 'Alok', 'Sikka', 'Nusa Tenggara Timur', '86111', 3],
    [90013, 'Ende Tengah', 'Ende', 'Nusa Tenggara Timur', '86319', 3],
    [90014, 'Bajawa', 'Ngada', 'Nusa Tenggara Timur', '86413', 3],
    [90015, 'Langke Rembong', 'Manggarai', 'Nusa Tenggara Timur', '86511', 3],
    [90016, 'Komodo', 'Manggarai Barat', 'Nusa Tenggara Timur', '86763', 3],
    [90017, 'Larantuka', 'Flores Timur', 'Nusa Tenggara Timur', '86213', 3],
    [90018, 'Teluk Mutiara', 'Alor', 'Nusa Tenggara Timur', '85811', 3],
    [90019, 'Kota Waingapu', 'Sumba Timur', 'Nusa Tenggara Timur', '87112', 3],
    [90020, 'Kota Waikabubak', 'Sumba Barat', 'Nusa Tenggara Timur', '87211', 3],
    [90021, 'Kuta', 'Badung', 'Bali', '80361', 4],
    [90022, 'Mataram', 'Mataram', 'Nusa Tenggara Barat', '83121', 4],
    [90023, 'Pesanggrahan', 'Jakarta Selatan', 'DKI Jakarta', '12250', 4],
    [90024, 'Coblong', 'Bandung', 'Jawa Barat', '40132', 4],
    [90025, 'Tegalsari', 'Surabaya', 'Jawa Timur', '60262', 4],
    [90026, 'Panakkukang', 'Makassar', 'Sulawesi Selatan', '90231', 5],
    [90027, 'Balikpapan Kota', 'Balikpapan', 'Kalimantan Timur', '76111', 5],
    [90028, 'Medan Baru', 'Medan', 'Sumatera Utara', '20153', 6],
    [90029, 'Jayapura Utara', 'Jayapura', 'Papua', '99111', 6],
];

function komerce_out(mixed $data, string $message = 'Success', int $code = 200): never
{
    json_out(['meta' => ['message' => $message, 'code' => $code, 'status' => $code === 200 ? 'success' : 'failed'], 'data' => $data], $code);
}

if (str_starts_with($path, '/api/v1/')) {
    if (($headers['key'] ?? '') !== MOCK_RAJAONGKIR_KEY) {
        komerce_out(null, 'Invalid Api key, key not found', 400);
    }
    if ($path === '/api/v1/destination/domestic-destination') {
        $search = mb_strtolower((string) ($_GET['search'] ?? ''));
        $found = array_values(array_filter(AREAS, fn ($a) => str_contains(mb_strtolower("$a[1] $a[2] $a[3]"), $search)));
        komerce_out(array_map(fn ($a) => [
            'id' => $a[0], 'label' => mb_strtoupper("$a[1], $a[1], $a[2], $a[3], $a[4]"),
            'province_name' => mb_strtoupper($a[3]), 'city_name' => mb_strtoupper($a[2]),
            'district_name' => mb_strtoupper($a[1]), 'subdistrict_name' => mb_strtoupper($a[1]), 'zip_code' => $a[4],
        ], $found));
    }
    if ($path === '/api/v1/calculate/domestic-cost') {
        $zone = 4;
        foreach (AREAS as $area) {
            if ((string) $area[0] === ($_POST['destination'] ?? '')) {
                $zone = $area[5];
            }
        }
        $kg = max(1, (int) ceil(((int) ($_POST['weight'] ?? 1000)) / 1000));
        $base = [1 => 9000, 2 => 21000, 3 => 29000, 4 => 46000, 5 => 55000, 6 => 72000][$zone];
        $wanted = explode(':', strtolower((string) ($_POST['courier'] ?? '')));
        // [kode kurir, nama, layanan, deskripsi, pengali tarif, estimasi, zona maksimum]
        $services = [
            ['jne', 'Jalur Nugraha Ekakurir (JNE)', 'REG', 'Layanan Reguler', 1.0, '2-3 day', 6],
            ['jne', 'Jalur Nugraha Ekakurir (JNE)', 'YES', 'Yakin Esok Sampai', 1.7, '1 day', 4],
            ['jnt', 'J&T Express', 'EZ', 'Reguler', 0.95, '', 6],
            ['pos', 'POS Indonesia (POS)', 'Pos Reguler', '240', 0.9, '4-6 day', 6],
            ['sicepat', 'SiCepat Express', 'REG', 'Reguler', 0.8, '2-3 day', 6],
        ];
        $available = array_filter($services, fn ($s) => $zone <= $s[6] && in_array($s[0], $wanted, true));
        komerce_out(array_values(array_map(fn ($s) => [
            'name' => $s[1], 'code' => $s[0], 'service' => $s[2], 'description' => $s[3],
            'cost' => (int) (round($base * $s[4] * $kg / 500) * 500), 'etd' => $s[5],
        ], $available)), 'Success Calculate Domestic Shipping cost');
    }
    komerce_out(null, 'Not found', 404);
}

// ---------- Midtrans ----------

if ($path === '/snap/v1/transactions' || preg_match('#^/v2/[^/]+/status$#', $path)) {
    if (($headers['authorization'] ?? '') !== 'Basic ' . base64_encode(MOCK_MIDTRANS_KEY . ':')) {
        json_out(['error_messages' => ['Unauthorized']], 401);
    }
}

if ($path === '/snap/v1/transactions') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $orderId = $body['transaction_details']['order_id'];
    $state = load_state($stateFile);
    $state[$orderId] = [
        'gross_amount' => $body['transaction_details']['gross_amount'],
        'finish' => $body['callbacks']['finish'] ?? APP_URL,
        'transaction_status' => 'pending',
    ];
    file_put_contents($stateFile, json_encode($state));
    json_out([
        'token' => 'mock-' . bin2hex(random_bytes(8)),
        'redirect_url' => 'http://localhost:8001/pay?order=' . rawurlencode($orderId),
    ], 201);
}

if (preg_match('#^/v2/([^/]+)/status$#', $path, $match)) {
    $orderId = urldecode($match[1]);
    $tx = load_state($stateFile)[$orderId] ?? null;
    if (!$tx) {
        json_out(['status_code' => '404', 'status_message' => 'Transaction not found']);
    }
    json_out([
        'status_code' => $tx['transaction_status'] === 'settlement' ? '200' : '201',
        'order_id' => $orderId,
        'transaction_status' => $tx['transaction_status'],
        'fraud_status' => 'accept',
        'gross_amount' => number_format((float) $tx['gross_amount'], 2, '.', ''),
        'payment_type' => 'qris',
        'transaction_id' => 'mock-' . md5($orderId),
    ]);
}

// Halaman simulasi bayar: pilih hasil, kirim webhook bertanda tangan ke aplikasi, lalu kembali ke situs.
if ($path === '/pay') {
    $orderId = (string) ($_GET['order'] ?? $_POST['order'] ?? '');
    $state = load_state($stateFile);
    $tx = $state[$orderId] ?? null;
    if (!$tx) {
        http_response_code(404);
        exit('Transaksi tidak ditemukan.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = in_array($_POST['result'] ?? '', ['settlement', 'pending', 'expire', 'cancel'], true) ? $_POST['result'] : 'pending';
        $state[$orderId]['transaction_status'] = $result;
        file_put_contents($stateFile, json_encode($state));

        $statusCode = $result === 'settlement' ? '200' : ($result === 'pending' ? '201' : '202');
        $gross = number_format((float) $tx['gross_amount'], 2, '.', '');
        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $gross,
            'transaction_status' => $result,
            'payment_type' => 'qris',
            'signature_key' => hash('sha512', $orderId . $statusCode . $gross . MOCK_MIDTRANS_KEY),
        ];
        $curl = curl_init(APP_URL . '/api/midtrans-notification.php');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($curl);

        header('Location: ' . $tx['finish'] . '&transaction_status=' . $result, true, 303);
        exit;
    }

    $amount = 'Rp' . number_format((int) $tx['gross_amount'], 0, ',', '.');
    $safeOrder = htmlspecialchars($orderId, ENT_QUOTES);
    echo <<<HTML
<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Simulasi Midtrans</title>
<style>
body{font:15px system-ui,sans-serif;background:#f3f5f9;margin:0;display:grid;place-items:center;min-height:100vh}
main{background:#fff;padding:24px;border-radius:10px;box-shadow:0 6px 24px #0001;width:min(360px,calc(100% - 32px))}
h1{font-size:1.1rem;margin:0 0 4px}p{color:#555;margin:0 0 16px}strong{font-size:1.6rem;display:block;margin-bottom:16px}
button{display:block;width:100%;padding:12px;margin-top:8px;border-radius:6px;border:1px solid #ccc;background:#fff;font:inherit;cursor:pointer}
button.pay{background:#0a8a4a;border-color:#0a8a4a;color:#fff;font-weight:600}
</style></head>
<body><main>
<h1>Simulasi pembayaran (lokal)</h1><p>Pesanan $safeOrder</p><strong>$amount</strong>
<form method="post"><input type="hidden" name="order" value="$safeOrder">
<button class="pay" name="result" value="settlement">Bayar berhasil</button>
<button name="result" value="pending">Belum bayar (kembali)</button>
<button name="result" value="expire">Kedaluwarsa</button>
<button name="result" value="cancel">Batalkan</button>
</form></main></body></html>
HTML;
    exit;
}

http_response_code(404);
echo 'Mock server Oleena';
