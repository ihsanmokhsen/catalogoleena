<?php
declare(strict_types=1);

// Buat atau reset akun admin.
// Pakai: php bin/create-admin.php namaadmin   (password diminta tanpa tampil di layar)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

$username = trim($argv[1] ?? '');
if (!preg_match('/^[a-z0-9_.-]{3,60}$/', $username)) {
    fwrite(STDERR, "Pakai: php bin/create-admin.php namaadmin (3-60 huruf kecil/angka)\n");
    exit(1);
}

function ask_hidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    // aaPanel sering menonaktifkan shell_exec. Jika begitu, password tetap bisa diketik tetapi akan terlihat di layar.
    $interactive = (function_exists('posix_isatty') ? posix_isatty(STDIN) : true) && function_exists('shell_exec');
    if ($interactive) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($interactive) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $value;
}

$password = ask_hidden('Password (min 12 karakter): ');
if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Password terlalu pendek.\n");
    exit(1);
}
if (ask_hidden('Ulangi password: ') !== $password) {
    fwrite(STDERR, "Password tidak sama.\n");
    exit(1);
}

db()->prepare(
    'INSERT INTO admin_users (username, password_hash) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
)->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

echo "Admin '$username' tersimpan. Login di /admin/\n";
