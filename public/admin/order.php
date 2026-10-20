<?php
declare(strict_types=1);

require __DIR__ . '/../../src/admin.php';
require __DIR__ . '/../../src/stock.php';

$admin = admin_require_login();

$code = (string) ($_GET['code'] ?? '');
$stmt = db()->prepare('SELECT * FROM orders WHERE order_code = ?');
$stmt->execute([$code]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    admin_header('Tidak ditemukan', $admin);
    echo '    <p>Pesanan tidak ditemukan. <a href="/admin/">Kembali</a></p>';
    admin_footer();
    exit;
}
$self = '/admin/order.php?code=' . urlencode($order['order_code']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $next = $_POST['status'] ?? '';
    $tracking = trim((string) ($_POST['tracking_number'] ?? ''));

    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$order['id']]);
    $current = $stmt->fetchColumn();

    if (!in_array($next, ORDER_TRANSITIONS[$current] ?? [], true)) {
        $pdo->rollBack();
        flash('Perubahan status tidak diizinkan dari status saat ini.');
        redirect($self);
    }
    if ($next === 'shipped' && !preg_match('/^[A-Za-z0-9-]{6,60}$/', $tracking)) {
        $pdo->rollBack();
        flash('Isi nomor resi yang valid (6-60 huruf/angka).');
        redirect($self);
    }

    // Pesanan yang sudah dibayar lalu dibatalkan: kembalikan stoknya.
    // Pesanan bertanda stok kurang tidak dikembalikan otomatis karena stoknya memang tidak terambil penuh.
    if ($next === 'cancelled' && in_array($current, ['paid', 'processing'], true) && !$order['stock_short']) {
        stock_return_for_order((int) $order['id']);
    }

    if ($next === 'shipped') {
        $pdo->prepare("UPDATE orders SET status = 'shipped', tracking_number = ?, shipped_at = NOW() WHERE id = ?")
            ->execute([$tracking, $order['id']]);
    } else {
        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$next, $order['id']]);
    }
    $pdo->commit();
    error_log(sprintf('[oleena-admin] %s mengubah %s: %s -> %s', $admin['username'], $order['order_code'], $current, $next));
    flash('Status pesanan diperbarui.');
    redirect($self);
}

$items = db()->prepare('SELECT product_name, product_series, unit_price, qty, line_total FROM order_items WHERE order_id = ? ORDER BY id');
$items->execute([$order['id']]);
$payment = db()->prepare('SELECT transaction_status, payment_type, transaction_id, created_at FROM payments WHERE order_id = ?');
$payment->execute([$order['id']]);
$payment = $payment->fetch();
$transitions = ORDER_TRANSITIONS[$order['status']] ?? [];

admin_header($order['order_code'], $admin);
?>
    <p><a href="/admin/">&larr; Semua pesanan</a></p>
    <h1><?= h($order['order_code']) ?> <span class="badge badge-<?= h($order['status']) ?>"><?= h(ORDER_STATUS_LABELS[$order['status']]) ?></span></h1>
<?php if ($order['stock_short']): ?>
    <p class="error"><strong>Stok kurang:</strong> saat pesanan ini dibayar, stok salah satu produk sudah habis (dibeli pembeli lain bersamaan). Hubungi pembeli untuk menawarkan pengganti, atau batalkan lalu refund dari Dashboard Midtrans. Stok tidak dikembalikan otomatis untuk pesanan ini.</p>
<?php endif; ?>
    <div class="grid">
      <section class="card">
        <h2>Penerima</h2>
        <dl>
          <dt>Nama</dt><dd><?= h($order['customer_name']) ?></dd>
          <dt>WhatsApp</dt><dd><a href="https://wa.me/<?= h($order['customer_phone']) ?>" target="_blank" rel="noreferrer">+<?= h($order['customer_phone']) ?></a></dd>
          <dt>Alamat</dt><dd class="pre"><?= h($order['address']) ?></dd>
          <dt>Tujuan</dt><dd><?= h($order['destination_area_name']) ?></dd>
          <dt>Catatan</dt><dd><?= h($order['note'] ?? '-') ?></dd>
        </dl>
      </section>
      <section class="card">
        <h2>Pengiriman &amp; pembayaran</h2>
        <dl>
          <dt>Kurir</dt><dd><?= h($order['courier_label']) ?> (<?= h($order['courier_code']) ?>/<?= h($order['courier_service_code']) ?>)</dd>
          <dt>Berat</dt><dd><?= number_format((int) $order['weight_gram'], 0, ',', '.') ?> g</dd>
          <dt>Resi</dt><dd><?= h($order['tracking_number'] ?? '-') ?></dd>
          <dt>Pembayaran</dt><dd><?= $payment ? h(($payment['payment_type'] ?: '-') . ' / ' . ($payment['transaction_status'] ?: 'belum ada notifikasi')) : 'Belum dibuka' ?></dd>
          <dt>Dibayar</dt><dd><?= h($order['paid_at'] ?? '-') ?></dd>
          <dt>Batas bayar</dt><dd><?= h($order['expires_at']) ?></dd>
        </dl>
      </section>
    </div>
    <section class="card">
      <h2>Item</h2>
      <table>
        <thead><tr><th>Produk</th><th>Harga</th><th>Qty</th><th>Jumlah</th></tr></thead>
        <tbody>
<?php foreach ($items as $item): ?>
          <tr><td><?= h($item['product_name']) ?> <small><?= h($item['product_series']) ?></small></td><td><?= rupiah($item['unit_price']) ?></td><td><?= (int) $item['qty'] ?></td><td><?= rupiah($item['line_total']) ?></td></tr>
<?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="3">Subtotal</td><td><?= rupiah($order['subtotal']) ?></td></tr>
          <tr><td colspan="3">Ongkir</td><td><?= rupiah($order['shipping_cost']) ?></td></tr>
          <tr><th colspan="3">Total</th><th><?= rupiah($order['total']) ?></th></tr>
        </tfoot>
      </table>
    </section>
<?php if ($transitions): ?>
    <section class="card">
      <h2>Ubah status</h2>
<?php foreach ($transitions as $next): ?>
      <form method="post" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="status" value="<?= h($next) ?>">
<?php if ($next === 'shipped'): ?>
        <input name="tracking_number" placeholder="Nomor resi" required pattern="[A-Za-z0-9-]{6,60}">
<?php endif; ?>
        <button type="submit" class="<?= $next === 'cancelled' ? 'danger' : 'primary' ?>"><?= h(ORDER_STATUS_LABELS[$next]) ?></button>
      </form>
<?php endforeach; ?>
<?php if ($order['status'] === 'paid'): ?>
      <p class="hint">Membatalkan pesanan yang sudah dibayar tidak otomatis mengembalikan dana. Lakukan refund dari Dashboard Midtrans.</p>
<?php endif; ?>
    </section>
<?php endif; ?>
<?php
admin_footer();
