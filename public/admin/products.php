<?php
declare(strict_types=1);

require __DIR__ . '/../../src/admin.php';
require __DIR__ . '/../../src/stock.php';

$admin = admin_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) ($_POST['id'] ?? 0);
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1000, 'max_range' => 100000000]]);
    $weight = filter_var($_POST['weight_gram'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50000]]);
    $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($id < 1 || $price === false || $weight === false || $stock === false) {
        flash('Harga (min Rp1.000), berat (1-50.000 g), atau stok (0-100.000) tidak valid.');
    } else {
        // Status Ready/Sold out selalu mengikuti stok.
        db()->prepare("UPDATE products SET price = ?, weight_gram = ?, stock = ?, status = IF(? > 0, 'available', 'sold-out'), is_active = ? WHERE id = ?")
            ->execute([$price, $weight, $stock, $stock, $active, $id]);
        error_log(sprintf('[oleena-admin] %s mengubah produk #%d', $admin['username'], $id));
        flash('Produk diperbarui.');
    }
    redirect('/admin/products.php#p' . $id);
}

$products = db()->query('SELECT id, series, name, price, weight_gram, stock, status, image, is_active FROM products ORDER BY catalog_page, sort_order, id')->fetchAll();

admin_header('Produk', $admin);
?>
    <div class="toolbar">
      <h1>Produk</h1>
      <a class="button-link" href="/admin/product.php">+ Tambah produk</a>
    </div>
    <p class="hint">Berat dipakai untuk menghitung ongkir. Stok otomatis berkurang saat pesanan dibayar; stok 0 = Sold out. Perubahan harga hanya berlaku untuk pesanan baru.</p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Produk</th><th>Harga (Rp)</th><th>Berat (g)</th><th>Stok</th><th>Tampil</th><th></th></tr></thead>
        <tbody>
<?php foreach ($products as $product): ?>
          <tr id="p<?= (int) $product['id'] ?>">
            <td>
              <div class="name-cell">
                <img class="thumb" src="/<?= h($product['image']) ?>" alt="" loading="lazy">
                <div><?= h($product['name']) ?><br><small><?= h($product['series']) ?></small><br><a href="/admin/product.php?id=<?= (int) $product['id'] ?>">Edit &amp; foto</a></div>
              </div>
            </td>
            <td colspan="5">
              <form method="post" class="row-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                <input type="number" name="price" value="<?= (int) $product['price'] ?>" min="1000" step="500" required aria-label="Harga">
                <input type="number" name="weight_gram" value="<?= (int) $product['weight_gram'] ?>" min="1" max="50000" required aria-label="Berat">
                <input type="number" name="stock" value="<?= (int) $product['stock'] ?>" min="0" max="100000" required aria-label="Stok"
                  class="<?= (int) $product['stock'] === 0 ? 'stock-empty' : ((int) $product['stock'] <= LOW_STOCK_THRESHOLD ? 'stock-low' : '') ?>">
                <label class="check"><input type="checkbox" name="is_active" <?= $product['is_active'] ? 'checked' : '' ?>> Tampil</label>
                <button type="submit">Simpan</button>
              </form>
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php
admin_footer();
