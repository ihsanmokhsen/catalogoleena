<?php
declare(strict_types=1);

// Pengaturan toko: nomor WhatsApp admin, QR pembayaran, rekening bank, dan mode pembayaran.

require __DIR__ . '/../../src/admin.php';
require __DIR__ . '/../../src/settings.php';
require __DIR__ . '/../../src/orders.php';

$admin = admin_require_login();
$error = null;
$values = settings_all();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $error = 'Gambar QR terlalu besar untuk server.';
    } else {
        csrf_verify();
        $text = fn (string $field) => trim((string) preg_replace('/[\p{C}]+/u', ' ', (string) ($_POST[$field] ?? '')));
        $values = array_merge($values, [
            'admin_whatsapp' => (string) ($_POST['admin_whatsapp'] ?? ''),
            'bank_name' => $text('bank_name'),
            'bank_account' => $text('bank_account'),
            'bank_holder' => $text('bank_holder'),
            'payment_note' => trim((string) ($_POST['payment_note'] ?? '')),
            'payment_mode' => ($_POST['payment_mode'] ?? '') === 'midtrans' ? 'midtrans' : 'manual',
        ]);

        $newQr = null;
        try {
            $values['admin_whatsapp'] = normalize_phone($values['admin_whatsapp']);
            foreach (['bank_name' => 60, 'bank_holder' => 80] as $field => $max) {
                if (mb_strlen($values[$field]) > $max) {
                    throw new InvalidArgumentException('Nama bank / atas nama terlalu panjang.');
                }
            }
            if ($values['bank_account'] !== '' && !preg_match('/^[0-9 .-]{4,40}$/', $values['bank_account'])) {
                throw new InvalidArgumentException('Nomor rekening hanya boleh angka (boleh dengan spasi atau tanda strip).');
            }
            if (mb_strlen($values['payment_note']) > 500 || preg_match('/[^\P{C}\n\r]/u', $values['payment_note'])) {
                throw new InvalidArgumentException('Catatan pembayaran maksimal 500 karakter.');
            }
            if ($values['payment_mode'] === 'midtrans' && empty(app_config()['midtrans']['server_key'])) {
                throw new InvalidArgumentException('Mode Midtrans butuh midtrans.server_key di config/config.php.');
            }

            $oldQr = $values['payment_qr'];
            if (($_FILES['payment_qr']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newQr = payment_qr_store($_FILES['payment_qr']);
                $values['payment_qr'] = $newQr;
            } elseif (isset($_POST['remove_qr'])) {
                $values['payment_qr'] = '';
            }
            if ($values['payment_mode'] === 'manual' && $values['payment_qr'] === '' && $values['bank_account'] === '') {
                throw new InvalidArgumentException('Mode QR/transfer butuh gambar QR atau nomor rekening.');
            }

            settings_save($values);
            if ($values['payment_qr'] !== $oldQr) {
                payment_qr_delete($oldQr);
            }
            error_log(sprintf('[oleena-admin] %s mengubah pengaturan pembayaran (mode %s)', $admin['username'], $values['payment_mode']));
            flash('Pengaturan disimpan.');
            redirect('/admin/settings.php');
        } catch (InvalidArgumentException $e) {
            payment_qr_delete($newQr);
            $values['payment_qr'] = settings_all()['payment_qr'];
            $error = $e->getMessage();
        }
    }
}

admin_header('Pengaturan', $admin);
?>
    <h1>Pengaturan pembayaran</h1>
<?php if ($error): ?>
    <p class="error"><?= h($error) ?></p>
<?php endif; ?>
    <form class="card settings-form" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <fieldset>
        <legend>Cara pembayaran</legend>
        <label class="check"><input type="radio" name="payment_mode" value="manual" <?= $values['payment_mode'] !== 'midtrans' ? 'checked' : '' ?>>
          QR / transfer ke rekening toko, lalu pembeli konfirmasi via WhatsApp (admin menandai dibayar)</label>
        <label class="check"><input type="radio" name="payment_mode" value="midtrans" <?= $values['payment_mode'] === 'midtrans' ? 'checked' : '' ?>>
          Midtrans (pembayaran otomatis, butuh akun Midtrans)</label>
      </fieldset>

      <label>Nomor WhatsApp admin <small>(tujuan konfirmasi pembayaran dan tombol WhatsApp di katalog)</small>
        <input name="admin_whatsapp" type="tel" value="<?= h($values['admin_whatsapp']) ?>" required placeholder="081234567890">
      </label>

      <div class="qr-field">
        <span>QR pembayaran (QRIS / QR bank)</span>
<?php if ($values['payment_qr']): ?>
        <img src="/<?= h($values['payment_qr']) ?>" alt="QR pembayaran saat ini">
        <label class="check"><input type="checkbox" name="remove_qr"> Hapus QR ini</label>
<?php endif; ?>
        <input type="file" name="payment_qr" accept="image/png,image/jpeg,image/webp">
        <p class="hint">Upload gambar QR asli dari aplikasi bank/QRIS (bukan foto layar yang miring). Gambar disimpan tanpa kompresi supaya tetap bisa di-scan.</p>
      </div>

      <div class="grid">
        <label>Nama bank <input name="bank_name" value="<?= h($values['bank_name']) ?>" maxlength="60" placeholder="BCA / BRI / Mandiri"></label>
        <label>Nomor rekening <input name="bank_account" value="<?= h($values['bank_account']) ?>" maxlength="40" inputmode="numeric"></label>
      </div>
      <label>Atas nama <input name="bank_holder" value="<?= h($values['bank_holder']) ?>" maxlength="80"></label>
      <label>Catatan cara bayar <small>(opsional, tampil di bawah QR)</small>
        <textarea name="payment_note" rows="3" maxlength="500"><?= h($values['payment_note']) ?></textarea>
      </label>
      <button class="primary" type="submit">Simpan pengaturan</button>
    </form>
<?php
admin_footer();
