<?php
declare(strict_types=1);

// Laporan penjualan bulanan. Penjualan = pesanan yang dibayar pada bulan itu (berdasarkan paid_at)
// dan tidak dibatalkan setelahnya. Pendapatan produk tidak termasuk ongkir.

require __DIR__ . '/../../src/admin.php';

$admin = admin_require_login();

const SOLD_STATUSES = "('paid', 'processing', 'shipped', 'completed')";

$month = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}
$start = $month . '-01 00:00:00';
$end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
$prevStart = date('Y-m-d H:i:s', strtotime($start . ' -1 month'));
$monthNames = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$monthLabel = fn (string $ym) => $monthNames[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);

function query_all(string $sql, array $params): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ---------- Ekspor CSV (per pesanan) ----------
if (($_GET['export'] ?? '') === 'csv') {
    $rows = query_all(
        'SELECT order_code, paid_at, status, customer_name, customer_phone, destination_area_name, courier_label,
                subtotal, voucher_code, discount, shipping_cost, total, tracking_number
         FROM orders WHERE status IN ' . SOLD_STATUSES . ' AND paid_at >= ? AND paid_at < ? ORDER BY paid_at',
        [$start, $end]
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="penjualan-oleena-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
    // Cegah "CSV injection": sel yang diawali =, +, -, @ diberi tanda kutip agar tidak dijalankan sebagai rumus.
    $safe = fn ($value) => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    fputcsv($out, ['Kode', 'Dibayar', 'Status', 'Nama', 'WhatsApp', 'Tujuan', 'Kurir', 'Subtotal', 'Voucher', 'Diskon', 'Ongkir', 'Total', 'Resi'], ';');
    foreach ($rows as $row) {
        fputcsv($out, array_map($safe, array_values($row)), ';');
    }
    exit;
}

// ---------- Ringkasan ----------
$summary = function (string $from, string $to): array {
    $row = query_all(
        'SELECT COUNT(*) orders, COALESCE(SUM(subtotal - discount), 0) revenue, COALESCE(SUM(shipping_cost), 0) shipping,
                COALESCE(SUM(total), 0) total,
                (SELECT COALESCE(SUM(oi.qty), 0) FROM order_items oi JOIN orders o2 ON o2.id = oi.order_id
                  WHERE o2.status IN ' . SOLD_STATUSES . ' AND o2.paid_at >= ? AND o2.paid_at < ?) items
         FROM orders WHERE status IN ' . SOLD_STATUSES . ' AND paid_at >= ? AND paid_at < ?',
        [$from, $to, $from, $to]
    )[0];
    return array_map('intval', $row);
};
$current = $summary($start, $end);
$previous = $summary($prevStart, $start);
$aov = $current['orders'] ? intdiv($current['total'], $current['orders']) : 0;

function change_badge(int $now, int $before): string
{
    if ($before === 0) {
        return $now > 0 ? '<span class="delta up">baru</span>' : '';
    }
    $pct = ($now - $before) / $before * 100;
    $class = $pct >= 0 ? 'up' : 'down';
    return sprintf('<span class="delta %s">%s%s%% vs bulan lalu</span>', $class, $pct >= 0 ? '▲ ' : '▼ ', number_format(abs($pct), 0, ',', '.'));
}

// Penjualan per hari (semua hari di bulan itu, termasuk yang 0).
$daily = array_fill(1, (int) date('t', strtotime($start)), ['revenue' => 0, 'orders' => 0]);
foreach (query_all(
    'SELECT DAY(paid_at) d, SUM(subtotal - discount) revenue, COUNT(*) orders FROM orders
     WHERE status IN ' . SOLD_STATUSES . ' AND paid_at >= ? AND paid_at < ? GROUP BY DAY(paid_at)',
    [$start, $end]
) as $row) {
    $daily[(int) $row['d']] = ['revenue' => (int) $row['revenue'], 'orders' => (int) $row['orders']];
}
$maxDaily = max(1, ...array_column($daily, 'revenue'));

$topProducts = query_all(
    'SELECT oi.product_name, oi.product_series, SUM(oi.qty) qty, SUM(oi.line_total) revenue
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.status IN ' . SOLD_STATUSES . ' AND o.paid_at >= ? AND o.paid_at < ?
     GROUP BY oi.product_id, oi.product_name, oi.product_series ORDER BY qty DESC, revenue DESC LIMIT 10',
    [$start, $end]
);
$couriers = query_all(
    'SELECT courier_label, COUNT(*) orders, SUM(shipping_cost) shipping FROM orders
     WHERE status IN ' . SOLD_STATUSES . ' AND paid_at >= ? AND paid_at < ? GROUP BY courier_label ORDER BY orders DESC',
    [$start, $end]
);
$destinations = query_all(
    'SELECT destination_area_name, COUNT(*) orders, SUM(total) total FROM orders
     WHERE status IN ' . SOLD_STATUSES . ' AND paid_at >= ? AND paid_at < ? GROUP BY destination_area_name ORDER BY orders DESC LIMIT 8',
    [$start, $end]
);
// Semua pesanan yang DIBUAT bulan ini, untuk melihat berapa yang tidak jadi dibayar.
$funnel = array_map('intval', array_column(
    query_all('SELECT status, COUNT(*) n FROM orders WHERE created_at >= ? AND created_at < ? GROUP BY status', [$start, $end]),
    'n',
    'status'
));
$created = array_sum($funnel);
$unpaid = ($funnel['expired'] ?? 0) + ($funnel['cancelled'] ?? 0) + ($funnel['pending_payment'] ?? 0);

$monthOptions = [];
for ($i = 0; $i < 12; $i++) {
    $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
    $monthOptions[$ym] = $monthLabel($ym);
}
if (!isset($monthOptions[$month])) {
    $monthOptions[$month] = $monthLabel($month);
}

admin_header('Laporan penjualan', $admin);
?>
    <div class="toolbar">
      <h1>Laporan penjualan</h1>
      <form method="get" class="report-filter">
        <select name="month" aria-label="Bulan">
<?php foreach ($monthOptions as $value => $label): ?>
          <option value="<?= h($value) ?>" <?= $value === $month ? 'selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
        </select>
        <button type="submit">Tampilkan</button>
        <a class="button-link" href="/admin/reports.php?month=<?= h($month) ?>&amp;export=csv">Unduh CSV</a>
      </form>
    </div>
    <p class="hint">Dihitung dari pesanan yang dibayar pada <?= h($monthLabel($month)) ?> dan tidak dibatalkan. Pendapatan produk belum termasuk ongkir dan sudah dikurangi diskon voucher.</p>

    <section class="stat-grid" aria-label="Ringkasan <?= h($monthLabel($month)) ?>">
      <div class="stat"><span>Pendapatan produk</span><strong><?= rupiah($current['revenue']) ?></strong><?= change_badge($current['revenue'], $previous['revenue']) ?></div>
      <div class="stat"><span>Pesanan dibayar</span><strong><?= number_format($current['orders'], 0, ',', '.') ?></strong><?= change_badge($current['orders'], $previous['orders']) ?></div>
      <div class="stat"><span>Item terjual</span><strong><?= number_format($current['items'], 0, ',', '.') ?></strong><?= change_badge($current['items'], $previous['items']) ?></div>
      <div class="stat"><span>Rata-rata per pesanan</span><strong><?= rupiah($aov) ?></strong><small>termasuk ongkir</small></div>
      <div class="stat"><span>Ongkir dibayar pembeli</span><strong><?= rupiah($current['shipping']) ?></strong><small>diteruskan ke kurir</small></div>
      <div class="stat"><span>Total diterima</span><strong><?= rupiah($current['total']) ?></strong><small>sebelum potongan Midtrans</small></div>
    </section>

    <section class="card">
      <h2>Pendapatan produk per hari</h2>
<?php if ($current['orders'] === 0): ?>
      <p class="hint">Belum ada penjualan di bulan ini.</p>
<?php else: ?>
      <div class="bar-chart" role="img" aria-label="Grafik pendapatan produk per hari, <?= h($monthLabel($month)) ?>. Tertinggi <?= h(rupiah($maxDaily)) ?>.">
        <div class="bar-axis"><span><?= h(rupiah($maxDaily)) ?></span><span>Rp0</span></div>
        <div class="bar-plot">
<?php foreach ($daily as $day => $data): ?>
          <div class="bar-col" data-tip="<?= h($day . ' ' . $monthLabel($month) . ': ' . rupiah($data['revenue']) . ' (' . $data['orders'] . ' pesanan)') ?>">
            <div class="bar" style="height: <?= $data['revenue'] ? max(2, round($data['revenue'] / $maxDaily * 100, 1)) : 0 ?>%"></div>
            <span class="bar-label"><?= $day === 1 || $day % 5 === 0 ? $day : '' ?></span>
          </div>
<?php endforeach; ?>
        </div>
      </div>
<?php endif; ?>
    </section>

    <div class="grid">
      <section class="card">
        <h2>Produk terlaris</h2>
        <table>
          <thead><tr><th>Produk</th><th class="num">Terjual</th><th class="num">Pendapatan</th></tr></thead>
          <tbody>
<?php foreach ($topProducts as $row): ?>
            <tr><td><?= h($row['product_name']) ?> <small><?= h($row['product_series']) ?></small></td><td class="num"><?= (int) $row['qty'] ?></td><td class="num"><?= rupiah($row['revenue']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$topProducts): ?>
            <tr><td colspan="3">Belum ada data.</td></tr>
<?php endif; ?>
          </tbody>
        </table>
      </section>
      <section class="card">
        <h2>Kurir</h2>
        <table>
          <thead><tr><th>Layanan</th><th class="num">Pesanan</th><th class="num">Ongkir</th></tr></thead>
          <tbody>
<?php foreach ($couriers as $row): ?>
            <tr><td><?= h($row['courier_label']) ?></td><td class="num"><?= (int) $row['orders'] ?></td><td class="num"><?= rupiah($row['shipping']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$couriers): ?>
            <tr><td colspan="3">Belum ada data.</td></tr>
<?php endif; ?>
          </tbody>
        </table>
        <h2 class="spaced">Tujuan teratas</h2>
        <table>
          <thead><tr><th>Wilayah</th><th class="num">Pesanan</th><th class="num">Total</th></tr></thead>
          <tbody>
<?php foreach ($destinations as $row): ?>
            <tr><td><?= h($row['destination_area_name']) ?></td><td class="num"><?= (int) $row['orders'] ?></td><td class="num"><?= rupiah($row['total']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$destinations): ?>
            <tr><td colspan="3">Belum ada data.</td></tr>
<?php endif; ?>
          </tbody>
        </table>
      </section>
    </div>

    <section class="card">
      <h2>Pesanan yang dibuat bulan ini</h2>
      <p class="hint"><?= number_format($created, 0, ',', '.') ?> pesanan dibuat, <?= number_format($unpaid, 0, ',', '.') ?> belum/tidak dibayar<?= $created ? ' (' . number_format($unpaid / $created * 100, 0, ',', '.') . '%)' : '' ?>.</p>
      <div class="funnel">
<?php foreach (ORDER_STATUS_LABELS as $status => $label): ?>
        <span class="badge badge-<?= h($status) ?>"><?= h($label) ?>: <?= (int) ($funnel[$status] ?? 0) ?></span>
<?php endforeach; ?>
      </div>
    </section>
<?php
admin_footer();
