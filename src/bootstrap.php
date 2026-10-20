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
