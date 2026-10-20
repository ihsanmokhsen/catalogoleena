<?php
declare(strict_types=1);

// Stok produk. Stok berkurang saat pesanan DIBAYAR (webhook Midtrans terverifikasi)
// dan dikembalikan bila admin membatalkan pesanan yang sudah dibayar.
// Kolom products.status selalu mengikuti stok: 'sold-out' bila stok 0.
// Semua fungsi di sini harus dipanggil di dalam transaksi database milik pemanggil.

const LOW_STOCK_THRESHOLD = 3;

/**
 * @return array<int, int> product_id => jumlah dipesan, urut product_id (urutan kunci tetap mencegah deadlock)
 */
function stock_order_quantities(int $orderId): array
{
    $stmt = db()->prepare('SELECT product_id, SUM(qty) qty FROM order_items WHERE order_id = ? GROUP BY product_id ORDER BY product_id');
    $stmt->execute([$orderId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * Kurangi stok untuk semua item pesanan. Baris produk dikunci (FOR UPDATE) supaya dua pembayaran
 * bersamaan tidak saling menimpa. Mengembalikan false bila ada produk yang stoknya tidak cukup;
 * stok produk itu menjadi 0 dan pesanan perlu ditangani admin (refund atau konfirmasi pembeli).
 */
function stock_take_for_order(int $orderId): bool
{
    $enough = true;
    $lock = db()->prepare('SELECT stock FROM products WHERE id = ? FOR UPDATE');
    $update = db()->prepare(
        "UPDATE products SET stock = IF(stock >= ?, stock - ?, 0), status = IF(stock > 0, 'available', 'sold-out') WHERE id = ?"
    );
    foreach (stock_order_quantities($orderId) as $productId => $qty) {
        $lock->execute([$productId]);
        if ((int) $lock->fetchColumn() < $qty) {
            $enough = false;
        }
        $update->execute([$qty, $qty, $productId]);
    }
    return $enough;
}

function stock_return_for_order(int $orderId): void
{
    $update = db()->prepare("UPDATE products SET stock = stock + ?, status = 'available' WHERE id = ?");
    foreach (stock_order_quantities($orderId) as $productId => $qty) {
        $update->execute([$qty, $productId]);
    }
}

/**
 * Cek (tanpa mengunci) apakah stok masih cukup untuk pesanan. Dipakai sebelum membuka pembayaran,
 * supaya pembeli tidak membayar barang yang sudah habis dibeli orang lain.
 *
 * @return string[] nama produk yang stoknya kurang
 */
function stock_shortages_for_order(int $orderId): array
{
    $stmt = db()->prepare(
        'SELECT p.name FROM products p
         JOIN (SELECT product_id, SUM(qty) qty FROM order_items WHERE order_id = ? GROUP BY product_id) x ON x.product_id = p.id
         WHERE p.stock < x.qty'
    );
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
