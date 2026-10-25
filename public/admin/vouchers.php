<?php
declare(strict_types=1);

// Voucher diskon: buat, ubah, aktif/nonaktifkan. Voucher tidak dihapus supaya riwayat pesanan tetap terbaca.

require __DIR__ . '/../../src/admin.php';
require __DIR__ . '/../../src/vouchers.php';

$admin = admin_require_login();
$error = null;
$editId = (int) ($_GET['id'] ?? 0);
$blank = [
    'id' => 0, 'code' => '', 'description' => '', 'type' => 'percent', 'value' => '', 'min_subtotal' => 0,
    'max_discount' => 0, 'usage_limit' => null, 'once_per_customer' => 0, 'starts_at' => null, 'ends_at' => null, 'is_active' => 1,
];
$values = $blank;
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM vouchers WHERE id = ?');
    $stmt->execute([$editId]);
    $values = $stmt->fetch() ?: $blank;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) ($_POST['id'] ?? 0);

    if (isset($_POST['toggle'])) {
        db()->prepare('UPDATE vouchers SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        error_log(sprintf('[oleena-admin] %s mengubah status voucher #%d', $admin['username'], $id));
        flash('Status voucher diubah.');
        redirect('/admin/vouchers.php');
    }

    $int = fn (string $field, int $min, int $max) => filter_var($_POST[$field] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    $date = function (string $field): ?string {
        $value = trim((string) ($_POST[$field] ?? ''));
        if ($value === '') {
            return null;
        }
        $parsed = DateTime::createFromFormat('!Y-m-d', $value);
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Format tanggal tidak valid.');
        }
        return $value;
    };

    $values = [
        'id' => $id,
        'code' => voucher_normalize_code($_POST['code'] ?? ''),
        'description' => trim((string) preg_replace('/[\p{C}]+/u', ' ', (string) ($_POST['description'] ?? ''))),
        'type' => ($_POST['type'] ?? '') === 'fixed' ? 'fixed' : 'percent',
        'value' => $_POST['value'] ?? '',
        'min_subtotal' => $_POST['min_subtotal'] ?? 0,
        'max_discount' => $_POST['max_discount'] ?? 0,
        'usage_limit' => ($_POST['usage_limit'] ?? '') === '' ? null : $_POST['usage_limit'],
        'once_per_customer' => isset($_POST['once_per_customer']) ? 1 : 0,
        'starts_at' => $_POST['starts_at'] ?? null,
        'ends_at' => $_POST['ends_at'] ?? null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    try {
        if (!preg_match(VOUCHER_CODE_PATTERN, $values['code'])) {
            throw new InvalidArgumentException('Kode voucher 3-30 karakter, hanya huruf, angka, dan tanda strip (-).');
        }
        if (mb_strlen($values['description']) > 120) {
            throw new InvalidArgumentException('Keterangan maksimal 120 karakter.');
        }
        $value = $values['type'] === 'percent' ? $int('value', 1, 100) : $int('value', 1000, 100000000);
        if ($value === false) {
            throw new InvalidArgumentException($values['type'] === 'percent' ? 'Diskon persen harus 1-100.' : 'Potongan rupiah minimal Rp1.000.');
        }
        $minSubtotal = $int('min_subtotal', 0, 100000000);
        $maxDiscount = $values['type'] === 'percent' ? $int('max_discount', 0, 100000000) : 0;
        $usageLimit = $values['usage_limit'] === null ? null : $int('usage_limit', 1, 1000000);
        if ($minSubtotal === false || $maxDiscount === false || $usageLimit === false) {
            throw new InvalidArgumentException('Minimal belanja, maksimal potongan, atau kuota tidak valid.');
        }
        $startsAt = $date('starts_at');
        $endsAt = $date('ends_at');
        if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
            throw new InvalidArgumentException('Tanggal berakhir tidak boleh sebelum tanggal mulai.');
        }

        $params = [
            $values['code'], $values['description'] === '' ? null : $values['description'], $values['type'], $value,
            $minSubtotal, $maxDiscount, $usageLimit, $values['once_per_customer'], $startsAt, $endsAt, $values['is_active'],
        ];
        try {
            if ($id > 0) {
                db()->prepare(
                    'UPDATE vouchers SET code = ?, description = ?, type = ?, value = ?, min_subtotal = ?, max_discount = ?,
                       usage_limit = ?, once_per_customer = ?, starts_at = ?, ends_at = ?, is_active = ? WHERE id = ?'
                )->execute([...$params, $id]);
            } else {
                db()->prepare(
                    'INSERT INTO vouchers (code, description, type, value, min_subtotal, max_discount, usage_limit, once_per_customer, starts_at, ends_at, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute($params);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException("Kode {$values['code']} sudah dipakai voucher lain.");
            }
            throw $e;
        }
        error_log(sprintf('[oleena-admin] %s menyimpan voucher %s', $admin['username'], $values['code']));
        flash("Voucher {$values['code']} disimpan.");
        redirect('/admin/vouchers.php');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

// Pemakaian: pesanan yang masih berlaku, dan yang sudah dibayar beserta total potongannya.
$vouchers = db()->query(
    "SELECT v.*,
       (SELECT COUNT(*) FROM orders o WHERE o.voucher_code = v.code AND o.status NOT IN ('cancelled', 'expired')) used,
       (SELECT COUNT(*) FROM orders o WHERE o.voucher_code = v.code AND o.status IN ('paid', 'processing', 'shipped', 'completed')) paid,
       (SELECT COALESCE(SUM(o.discount), 0) FROM orders o WHERE o.voucher_code = v.code AND o.status IN ('paid', 'processing', 'shipped', 'completed')) paid_discount
     FROM vouchers v ORDER BY v.is_active DESC, v.created_at DESC"
)->fetchAll();

$today = date('Y-m-d');
$state = function (array $voucher) use ($today): string {
    if (!$voucher['is_active']) {
        return '<span class="badge">Nonaktif</span>';
    }
    if ($voucher['starts_at'] !== null && $today < $voucher['starts_at']) {
        return '<span class="badge">Belum mulai</span>';
    }
    if ($voucher['ends_at'] !== null && $today > $voucher['ends_at']) {
        return '<span class="badge">Berakhir</span>';
    }
    if ($voucher['usage_limit'] !== null && (int) $voucher['used'] >= (int) $voucher['usage_limit']) {
        return '<span class="badge">Kuota habis</span>';
    }
    return '<span class="badge badge-paid">Aktif</span>';
};

admin_header('Voucher', $admin);
?>
    <h1>Voucher</h1>
    <p class="hint">Pembeli memasukkan kode di keranjang. Potongan hanya dari harga produk (ongkir tidak ikut dipotong). Pesanan yang dibatalkan atau kedaluwarsa tidak dihitung sebagai pemakaian.</p>

    <div class="table-wrap">
      <table>
        <thead><tr><th>Kode</th><th>Potongan</th><th>Syarat</th><th>Berlaku</th><th class="num">Dipakai</th><th>Status</th><th></th></tr></thead>
        <tbody>
<?php if (!$vouchers): ?>
          <tr><td colspan="7" class="hint">Belum ada voucher. Buat voucher pertama di formulir bawah.</td></tr>
<?php endif; ?>
<?php foreach ($vouchers as $voucher): ?>
          <tr>
            <td><strong><?= h($voucher['code']) ?></strong><?= $voucher['description'] ? '<br><small>' . h($voucher['description']) . '</small>' : '' ?></td>
            <td><?= h(voucher_label($voucher)) ?></td>
            <td>
              <?= (int) $voucher['min_subtotal'] > 0 ? 'Min. belanja ' . rupiah($voucher['min_subtotal']) : 'Tanpa minimal' ?>
              <?= $voucher['once_per_customer'] ? '<br><small>1x per nomor WhatsApp</small>' : '' ?>
            </td>
            <td><small><?= $voucher['starts_at'] ? h(voucher_date($voucher['starts_at'])) : 'Sekarang' ?> &ndash; <?= $voucher['ends_at'] ? h(voucher_date($voucher['ends_at'])) : 'seterusnya' ?></small></td>
            <td class="num">
              <?= (int) $voucher['used'] ?><?= $voucher['usage_limit'] !== null ? ' / ' . (int) $voucher['usage_limit'] : '' ?>
              <br><small><?= (int) $voucher['paid'] ?> dibayar<?= (int) $voucher['paid'] > 0 ? ' &middot; potongan ' . rupiah($voucher['paid_discount']) : '' ?></small>
            </td>
            <td><?= $state($voucher) ?></td>
            <td>
              <a href="/admin/vouchers.php?id=<?= (int) $voucher['id'] ?>#form">Edit</a>
              <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                <button type="submit" name="toggle" value="1"><?= $voucher['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
              </form>
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h2 class="spaced" id="form"><?= (int) $values['id'] > 0 ? 'Edit voucher ' . h($values['code']) : 'Buat voucher baru' ?></h2>
<?php if ($error): ?>
    <p class="error"><?= h($error) ?></p>
<?php endif; ?>
    <form class="card settings-form" method="post" action="/admin/vouchers.php<?= (int) $values['id'] > 0 ? '?id=' . (int) $values['id'] : '' ?>#form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $values['id'] ?>">
      <div class="grid">
        <label>Kode voucher <small>(huruf besar, angka, strip)</small>
          <input name="code" value="<?= h($values['code']) ?>" required maxlength="30" pattern="[A-Za-z0-9\-]{3,30}" placeholder="OLEENA10" autocapitalize="characters">
        </label>
        <label>Keterangan <small>(opsional, untuk catatan admin)</small>
          <input name="description" value="<?= h($values['description'] ?? '') ?>" maxlength="120" placeholder="Promo pembukaan toko">
        </label>
      </div>

      <fieldset>
        <legend>Jenis potongan</legend>
        <label class="check"><input type="radio" name="type" value="percent" <?= $values['type'] !== 'fixed' ? 'checked' : '' ?>> Persen dari harga produk (misalnya 10%)</label>
        <label class="check"><input type="radio" name="type" value="fixed" <?= $values['type'] === 'fixed' ? 'checked' : '' ?>> Potongan rupiah tetap (misalnya Rp15.000)</label>
      </fieldset>

      <div class="grid">
        <label>Besar potongan <small>(angka persen, atau rupiah)</small>
          <input name="value" type="number" min="1" value="<?= h($values['value']) ?>" required placeholder="10">
        </label>
        <label>Maksimal potongan (Rp) <small>(khusus persen, 0 = tanpa batas)</small>
          <input name="max_discount" type="number" min="0" step="500" value="<?= (int) $values['max_discount'] ?>">
        </label>
        <label>Minimal belanja produk (Rp) <small>(0 = tanpa minimal)</small>
          <input name="min_subtotal" type="number" min="0" step="1000" value="<?= (int) $values['min_subtotal'] ?>">
        </label>
        <label>Kuota pemakaian <small>(kosongkan = tanpa batas)</small>
          <input name="usage_limit" type="number" min="1" value="<?= $values['usage_limit'] === null ? '' : (int) $values['usage_limit'] ?>" placeholder="Tanpa batas">
        </label>
        <label>Mulai berlaku <small>(kosongkan = sekarang)</small>
          <input name="starts_at" type="date" value="<?= h($values['starts_at'] ?? '') ?>">
        </label>
        <label>Berakhir <small>(termasuk tanggal ini, kosongkan = seterusnya)</small>
          <input name="ends_at" type="date" value="<?= h($values['ends_at'] ?? '') ?>">
        </label>
      </div>

      <label class="check"><input type="checkbox" name="once_per_customer" <?= $values['once_per_customer'] ? 'checked' : '' ?>> Hanya sekali per nomor WhatsApp pembeli</label>
      <label class="check"><input type="checkbox" name="is_active" <?= $values['is_active'] ? 'checked' : '' ?>> Aktif (bisa dipakai pembeli)</label>
      <div class="inline">
        <button class="primary" type="submit">Simpan voucher</button>
<?php if ((int) $values['id'] > 0): ?>
        <a href="/admin/vouchers.php#form">Batal, buat voucher baru</a>
<?php endif; ?>
      </div>
    </form>
<?php
admin_footer();
