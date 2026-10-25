<?php
declare(strict_types=1);

// Bootstrap panel admin: sesi aman, CSRF, escape HTML, dan layout halaman.

require_once __DIR__ . '/bootstrap.php';

const ADMIN_IDLE_SECONDS = 7200;

const ORDER_STATUS_LABELS = [
    'pending_payment' => 'Menunggu bayar',
    'paid' => 'Dibayar',
    'processing' => 'Dikemas',
    'shipped' => 'Dikirim',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan',
    'expired' => 'Kedaluwarsa',
];

// Perpindahan status yang boleh dilakukan admin. "paid" diberikan oleh webhook Midtrans (mode Midtrans)
// atau lewat tombol khusus "Tandai sudah dibayar" untuk pembayaran QR/transfer manual.
const ORDER_TRANSITIONS = [
    'pending_payment' => ['cancelled'],
    'paid' => ['processing', 'cancelled'],
    'processing' => ['shipped', 'cancelled'],
    'shipped' => ['completed'],
];

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; style-src-attr 'unsafe-inline'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

set_exception_handler(function (Throwable $e): void {
    error_log('[oleena-admin] ' . $e);
    http_response_code(500);
    echo 'Terjadi kesalahan di server.';
});

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('oleena_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rupiah(int|string $value): string
{
    return 'Rp' . number_format((int) $value, 0, ',', '.');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $current = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $current;
}

function admin_require_login(): array
{
    $admin = $_SESSION['admin'] ?? null;
    if (!$admin || ($_SESSION['last_seen'] ?? 0) < time() - ADMIN_IDLE_SECONDS) {
        $_SESSION = [];
        redirect('/admin/login.php');
    }
    $_SESSION['last_seen'] = time();
    return $admin;
}

function admin_header(string $title, ?array $admin = null): void
{
    $flash = flash();
    ?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= h($title) ?> - Admin Oleena</title>
  <link rel="stylesheet" href="/admin/admin.css">
</head>
<body>
<?php if ($admin): ?>
  <header class="topbar">
    <strong>Admin Oleena</strong>
    <nav>
      <a href="/admin/">Pesanan</a>
      <a href="/admin/products.php">Produk</a>
      <a href="/admin/vouchers.php">Voucher</a>
      <a href="/admin/reports.php">Laporan</a>
      <a href="/admin/settings.php">Pengaturan</a>
      <form method="post" action="/admin/logout.php"><?= csrf_field() ?><button type="submit">Keluar (<?= h($admin['username']) ?>)</button></form>
    </nav>
  </header>
<?php endif; ?>
  <main>
<?php if ($flash): ?>
    <p class="flash"><?= h($flash) ?></p>
<?php endif; ?>
<?php
}

function admin_footer(): void
{
    echo "  </main>\n</body>\n</html>\n";
}
