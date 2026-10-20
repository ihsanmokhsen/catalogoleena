<?php
declare(strict_types=1);

require __DIR__ . '/../../src/admin.php';

$admin = admin_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) ($_POST['id'] ?? 0);
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1000, 'max_range' => 100000000]]);
    $weight = filter_var($_POST['weight_gram'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50000]]);
    $status = ($_POST['status'] ?? '') === 'sold-out' ? 'sold-out' : 'available';
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($id < 1 || $price === false || $weight === false) {
        flash('Harga (min Rp1.000) atau berat (1-50.000 g) tidak valid.');
    } else {
        db()->prepare('UPDATE products SET price = ?, weight_gram = ?, status = ?, is_active = ? WHERE id = ?')
            ->execute([$price, $weight, $status, $active, $id]);
        error_log(sprintf('[oleena-admin] %s mengubah produk #%d', $admin['username'], $id));
        flash('Produk diperbarui.');
    }
    redirect('/admin/products.php#p' . $id);
}

$products = db()->query('SELECT id, series, name, price, weight_gram, status, image, is_active FROM products ORDER BY catalog_page, sort_order, id')->fetchAll();

admin_header('Produk', $admin);
?>
    <div class="toolbar">
      <h1>Produk</h1>
      <a class="button-link" href="/admin/product.php">+ Tambah produk</a>
    </div>
    <p class="hint">Berat dipakai untuk menghitung ongkir. Perubahan harga hanya berlaku untuk pesanan baru.</p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Produk</th><th>Harga (Rp)</th><th>Berat (g)</th><th>Status</th><th>Tampil</th><th></th></tr></thead>
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
                <select name="status" aria-label="Status">
                  <option value="available" <?= $product['status'] === 'available' ? 'selected' : '' ?>>Ready</option>
                  <option value="sold-out" <?= $product['status'] === 'sold-out' ? 'selected' : '' ?>>Sold out</option>
                </select>
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
