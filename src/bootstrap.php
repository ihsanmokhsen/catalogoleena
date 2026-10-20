<?php
declare(strict_types=1);

// Bootstrap bersama untuk semua endpoint PHP. Folder ini berada di luar webroot (public/).

ini_set('display_errors', '0');
date_default_timezone_set('Asia/Makassar');

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/../config/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('config/config.php belum dibuat. Salin dari config/config.example.php.');
        }
        $config = require $file;
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = app_config()['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function json_response(array $data, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $name => $value) {
        header("$name: $value");
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Dipanggil di awal setiap endpoint API: batasi method dan jangan bocorkan detail error ke klien.
function api_boot(array $allowedMethods): void
{
    set_exception_handler(function (Throwable $e): void {
        error_log('[oleena-api] ' . $e);
        json_response(['error' => 'Terjadi kesalahan di server.'], 500, ['Cache-Control' => 'no-store']);
    });

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $allowedMethods, true)) {
        json_response(['error' => 'Method tidak diizinkan.'], 405, ['Allow' => implode(', ', $allowedMethods)]);
    }
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Batasi jumlah request per IP dalam satu jendela waktu (fixed window, disimpan di MySQL).
function rate_limit(string $name, int $maxHits, int $windowSeconds): void
{
    $bucket = $name . ':' . hash('sha256', client_ip());
    db()->prepare(
        'INSERT INTO api_rate_limits (bucket, hits, window_start) VALUES (:bucket, 1, NOW())
         ON DUPLICATE KEY UPDATE
           hits = IF(window_start < NOW() - INTERVAL :w1 SECOND, 1, hits + 1),
           window_start = IF(window_start < NOW() - INTERVAL :w2 SECOND, NOW(), window_start)'
    )->execute(['bucket' => $bucket, 'w1' => $windowSeconds, 'w2' => $windowSeconds]);

    $stmt = db()->prepare('SELECT hits FROM api_rate_limits WHERE bucket = ?');
    $stmt->execute([$bucket]);
    if ((int) $stmt->fetchColumn() > $maxHits) {
        json_response(['error' => 'Terlalu banyak permintaan, coba lagi sebentar.'], 429, ['Retry-After' => (string) $windowSeconds]);
    }

    if (random_int(1, 100) === 1) {
        db()->exec('DELETE FROM api_rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY');
    }
}

function cache_get(string $key): ?array
{
    $stmt = db()->prepare('SELECT payload FROM api_cache WHERE cache_key = ? AND expires_at > NOW()');
    $stmt->execute([hash('sha256', $key)]);
    $payload = $stmt->fetchColumn();
    return $payload === false ? null : json_decode($payload, true);
}

function cache_set(string $key, array $value, int $ttlSeconds): void
{
    db()->prepare(
        'REPLACE INTO api_cache (cache_key, payload, expires_at) VALUES (:key, :payload, NOW() + INTERVAL :ttl SECOND)'
    )->execute([
        'key' => hash('sha256', $key),
        'payload' => json_encode($value, JSON_UNESCAPED_UNICODE),
        'ttl' => $ttlSeconds,
    ]);

    if (random_int(1, 100) === 1) {
        db()->exec('DELETE FROM api_cache WHERE expires_at < NOW()');
    }
}

// Hanya terima body JSON kecil. Mewajibkan Content-Type JSON juga menahan form lintas situs (CSRF).
function read_json_body(int $maxBytes = 16384): array
{
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($type, 'application/json') !== 0) {
        json_response(['error' => 'Content-Type harus application/json.'], 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        json_response(['error' => 'Data terlalu besar.'], 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['error' => 'Format data tidak valid.'], 400);
    }
    return $data;
}
