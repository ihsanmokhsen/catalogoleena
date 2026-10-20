<?php
declare(strict_types=1);

require __DIR__ . '/../../src/admin.php';

if (!empty($_SESSION['admin'])) {
    redirect('/admin/');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    rate_limit('admin-login', 5, 900);

    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    $stmt = db()->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Tetap jalankan password_verify walau user tidak ada, agar waktu respons tidak membocorkan username.
    $hash = $user['password_hash'] ?? password_hash('dummy-password', PASSWORD_DEFAULT);
    if (password_verify($password, $hash) && $user) {
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        db()->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        session_regenerate_id(true);
        $_SESSION = [
            'admin' => ['id' => (int) $user['id'], 'username' => $user['username']],
            'last_seen' => time(),
        ];
        redirect('/admin/');
    }
    $error = 'Username atau password salah.';
}

admin_header('Masuk');
?>
    <form class="card login" method="post">
      <h1>Masuk admin</h1>
<?php if ($error): ?>
      <p class="error"><?= h($error) ?></p>
<?php endif; ?>
      <?= csrf_field() ?>
      <label>Username <input name="username" autocomplete="username" required maxlength="60"></label>
      <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
      <button class="primary" type="submit">Masuk</button>
    </form>
<?php
admin_footer();
