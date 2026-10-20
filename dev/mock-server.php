<?php
declare(strict_types=1);

// Server tiruan Biteship + Midtrans untuk development lokal, sebelum key asli tersedia.
// Jalankan: php -S localhost:8001 dev/mock-server.php
// Lalu di config/config.php arahkan base_url / snap_url / api_url ke http://localhost:8001.

if (PHP_SAPI !== 'cli-server') {
    exit("Hanya untuk php -S (development lokal).\n");
}

const MOCK_BITESHIP_KEY = 'biteship_test.mock';
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

// ---------- Biteship ----------

const AREAS = [
    ['IDNP19IDNC300IDND3800IDZ85111', 'Oebobo, Kupang, Nusa Tenggara Timur. 85111', 85111, 1],
    ['IDNP19IDNC300IDND3801IDZ85228', 'Kelapa Lima, Kupang, Nusa Tenggara Timur. 85228', 85228, 1],
    ['IDNP19IDNC301IDND3810IDZ85361', 'Kota Soe, Timor Tengah Selatan, Nusa Tenggara Timur. 85511', 85511, 2],
    ['IDNP6IDNC148IDND843IDZ12250', 'Pesanggrahan, Jakarta Selatan, DKI Jakarta. 12250', 12250, 4],
    ['IDNP6IDNC147IDND836IDZ10110', 'Gambir, Jakarta Pusat, DKI Jakarta. 10110', 10110, 4],
    ['IDNP9IDNC23IDND263IDZ40115', 'Coblong, Bandung, Jawa Barat. 40132', 40132, 4],
    ['IDNP11IDNC444IDND5266IDZ60271', 'Tegalsari, Surabaya, Jawa Timur. 60262', 60262, 3],
    ['IDNP2IDNC17IDND200IDZ80361', 'Kuta, Badung, Bali. 80361', 80361, 2],
    ['IDNP28IDNC254IDND3335IDZ90231', 'Panakkukang, Makassar, Sulawesi Selatan. 90231', 90231, 2],
    ['IDNP34IDNC278IDND3523IDZ20112', 'Medan Baru, Medan, Sumatera Utara. 20153', 20153, 5],
    ['IDNP24IDNC371IDND4402IDZ99111', 'Jayapura Utara, Jayapura, Papua. 99111', 99111, 5],
];

if (str_starts_with($path, '/v1/')) {
    if (($headers['authorization'] ?? '') !== MOCK_BITESHIP_KEY) {
        json_out(['success' => false, 'error' => 'Unauthorized'], 401);
    }
    if ($path === '/v1/maps/areas') {
        $input = mb_strtolower((string) ($_GET['input'] ?? ''));
        $found = array_values(array_filter(AREAS, fn ($a) => str_contains(mb_strtolower($a[1]), $input)));
        json_out(['success' => true, 'areas' => array_map(fn ($a) => [
            'id' => $a[0], 'name' => $a[1], 'country_code' => 'ID', 'postal_code' => $a[2],
        ], $found)]);
    }
    if ($path === '/v1/rates/couriers') {
        $body = json_decode((string) file_get_contents('php://input'), true);
        $zone = 3;
        foreach (AREAS as $area) {
            if ($area[0] === ($body['destination_area_id'] ?? '')) {
                $zone = $area[3];
            }
        }
        $kg = max(1, (int) ceil(($body['items'][0]['weight'] ?? 1000) / 1000));
        $base = [1 => 9000, 2 => 22000, 3 => 30000, 4 => 34000, 5 => 52000][$zone];
        $services = [
            ['jne', 'JNE', 'reg', 'Reguler', 1.0, '2 - 4 days'],
            ['jne', 'JNE', 'yes', 'YES', 1.6, '1 - 1 days'],
            ['sicepat', 'SiCepat', 'reg', 'REG', 0.9, '2 - 3 days'],
            ['jnt', 'J&T', 'ez', 'EZ', 0.95, '2 - 4 days'],
            ['pos', 'POS Indonesia', 'kilat', 'Kilat Khusus', 0.85, '3 - 6 days'],
        ];
        json_out(['success' => true, 'pricing' => array_map(fn ($s) => [
            'courier_code' => $s[0], 'courier_name' => $s[1],
            'courier_service_code' => $s[2], 'courier_service_name' => $s[3],
            'duration' => $s[5], 'price' => (int) (round($base * $s[4] * $kg / 500) * 500),
        ], $services)]);
    }
    json_out(['success' => false, 'error' => 'Not found'], 404);
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
