<?php
declare(strict_types=1);

require __DIR__ . '/../../src/admin.php';

$admin = admin_require_login();

$status = $_GET['status'] ?? '';
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = [];
$params = [];
if (is_string($status) && isset(ORDER_STATUS_LABELS[$status])) {
    $where[] = 'status = ?';
    $params[] = $status;
} else {
    $status = '';
}
if ($search !== '') {
    $where[] = '(order_code LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ?)';
    $like = '%' . addcslashes(mb_substr($search, 0, 60), '%_\\') . '%';
    array_push($params, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare(
    "SELECT order_code, status, customer_name, destination_area_name, courier_label, total, created_at
     FROM orders $whereSql ORDER BY created_at DESC, id DESC LIMIT " . ($perPage + 1) . ' OFFSET ' . (($page - 1) * $perPage)
);
$stmt->execute($params);
$orders = $stmt->fetchAll();
$hasNext = count($orders) > $perPage;
$orders = array_slice($orders, 0, $perPage);

$counts = db()->query('SELECT status, COUNT(*) total FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

admin_header('Pesanan', $admin);
?>
    <h1>Pesanan</h1>
    <nav class="tabs">
      <a href="/admin/" class="<?= $status === '' ? 'active' : '' ?>">Semua</a>
<?php foreach (ORDER_STATUS_LABELS as $key => $label): ?>
      <a href="/admin/?status=<?= h($key) ?>" class="<?= $status === $key ? 'active' : '' ?>"><?= h($label) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</a>
<?php endforeach; ?>
    </nav>
    <form class="search" method="get">
      <input type="hidden" name="status" value="<?= h($status) ?>">
      <input name="q" value="<?= h($search) ?>" placeholder="Cari kode, nama, atau nomor HP" maxlength="60">
      <button type="submit">Cari</button>
    </form>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Kode</th><th>Status</th><th>Pembeli</th><th>Tujuan</th><th>Kurir</th><th>Total</th><th>Dibuat</th></tr></thead>
        <tbody>
<?php foreach ($orders as $order): ?>
          <tr>
            <td><a href="/admin/order.php?code=<?= h(urlencode($order['order_code'])) ?>"><?= h($order['order_code']) ?></a></td>
            <td><span class="badge badge-<?= h($order['status']) ?>"><?= h(ORDER_STATUS_LABELS[$order['status']]) ?></span></td>
            <td><?= h($order['customer_name']) ?></td>
            <td><?= h($order['destination_area_name']) ?></td>
            <td><?= h($order['courier_label']) ?></td>
            <td><?= rupiah($order['total']) ?></td>
            <td><?= h($order['created_at']) ?></td>
          </tr>
<?php endforeach; ?>
<?php if (!$orders): ?>
          <tr><td colspan="7">Belum ada pesanan.</td></tr>
<?php endif; ?>
        </tbody>
      </table>
    </div>
    <nav class="pager">
<?php if ($page > 1): ?>
      <a href="?<?= h(http_build_query(['status' => $status, 'q' => $search, 'page' => $page - 1])) ?>">Sebelumnya</a>
<?php endif; ?>
<?php if ($hasNext): ?>
      <a href="?<?= h(http_build_query(['status' => $status, 'q' => $search, 'page' => $page + 1])) ?>">Berikutnya</a>
<?php endif; ?>
    </nav>
<?php
admin_footer();
