<?php
declare(strict_types=1);

// Tambah produk baru (tanpa ?id) atau edit produk lengkap beserta fotonya (?id=123).

require __DIR__ . '/../../src/admin.php';
require __DIR__ . '/../../src/images.php';

$admin = admin_require_login();

$id = (int) ($_GET['id'] ?? 0);
$product = null;
if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $product = $stmt->fetch() ?: null;
    if (!$product) {
        http_response_code(404);
        admin_header('Tidak ditemukan', $admin);
        echo '    <p>Produk tidak ditemukan. <a href="/admin/products.php">Kembali</a></p>';
        admin_footer();
        exit;
    }
}
$isNew = $product === null;

$values = $product ?? [
    'name' => '', 'series' => '', 'price' => '', 'weight_gram' => 250, 'status' => 'available', 'is_active' => 1,
    'catalog_page' => (int) db()->query('SELECT COALESCE(MAX(catalog_page), 1) FROM products')->fetchColumn(),
];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bila file melebihi post_max_size, PHP mengosongkan $_POST sehingga token CSRF ikut hilang.
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $error = 'Foto terlalu besar untuk server (maksimal 8 MB).';
    } else {
        csrf_verify();
        $values = array_merge($values, [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'series' => trim((string) ($_POST['series'] ?? '')),
            'price' => $_POST['price'] ?? '',
            'weight_gram' => $_POST['weight_gram'] ?? '',
            'status' => ($_POST['status'] ?? '') === 'sold-out' ? 'sold-out' : 'available',
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'catalog_page' => $_POST['catalog_page'] ?? 1,
        ]);

        try {
            $name = $values['name'];
            $series = $values['series'];
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || preg_match('/\p{C}/u', $name)) {
                throw new InvalidArgumentException('Nama produk harus 2-120 karakter.');
            }
            if (mb_strlen($series) < 2 || mb_strlen($series) > 120 || preg_match('/\p{C}/u', $series)) {
                throw new InvalidArgumentException('Nama seri harus 2-120 karakter.');
            }
            $price = filter_var($values['price'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1000, 'max_range' => 100000000]]);
            $weight = filter_var($values['weight_gram'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50000]]);
            $page = filter_var($values['catalog_page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
            if ($price === false || $weight === false || $page === false) {
                throw new InvalidArgumentException('Harga (min Rp1.000), berat (1-50.000 g), atau halaman katalog tidak valid.');
            }

            $hasPhoto = ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($isNew && !$hasPhoto) {
                throw new InvalidArgumentException('Pilih foto produk.');
            }

            $pdo = db();
            if ($isNew) {
                // Slug dibuat sekali dan tidak diubah lagi, karena dipakai sebagai ID produk di keranjang pembeli.
                $base = slugify("$series $name");
                $slug = $base;
                $exists = $pdo->prepare('SELECT 1 FROM products WHERE slug = ?');
                for ($n = 2; $exists->execute([$slug]) && $exists->fetchColumn(); $n++) {
                    $slug = "$base-$n";
                }

                $image = product_image_store($_FILES['photo'], $slug);
                try {
                    $pdo->prepare(
                        'INSERT INTO products (slug, series, name, price, weight_gram, status, image, catalog_page, sort_order, is_active)
                         SELECT ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(MAX(sort_order), 0) + 1, ? FROM products'
                    )->execute([$slug, $series, $name, $price, $weight, $values['status'], $image, $page, $values['is_active']]);
                } catch (Throwable $e) {
                    product_image_delete($image);
                    throw $e;
                }
                $id = (int) $pdo->lastInsertId();
                error_log(sprintf('[oleena-admin] %s menambah produk #%d %s', $admin['username'], $id, $slug));
                flash('Produk ditambahkan.');
            } else {
                $newImage = $hasPhoto ? product_image_store($_FILES['photo'], $product['slug']) : null;
                try {
                    $pdo->prepare(
                        'UPDATE products SET series = ?, name = ?, price = ?, weight_gram = ?, status = ?, image = ?,
                           catalog_page = ?, is_active = ? WHERE id = ?'
                    )->execute([$series, $name, $price, $weight, $values['status'], $newImage ?? $product['image'], $page, $values['is_active'], $id]);
                } catch (Throwable $e) {
                    product_image_delete($newImage);
                    throw $e;
                }
                if ($newImage) {
                    product_image_delete($product['image']);
                }
                error_log(sprintf('[oleena-admin] %s mengedit produk #%d', $admin['username'], $id));
                flash('Produk diperbarui.');
            }
            redirect('/admin/products.php#p' . $id);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

$seriesList = db()->query('SELECT DISTINCT series FROM products ORDER BY series')->fetchAll(PDO::FETCH_COLUMN);

admin_header($isNew ? 'Tambah produk' : 'Edit produk', $admin);
?>
    <p><a href="/admin/products.php">&larr; Semua produk</a></p>
    <h1><?= $isNew ? 'Tambah produk' : 'Edit ' . h($product['name']) ?></h1>
<?php if ($error): ?>
    <p class="error"><?= h($error) ?></p>
<?php endif; ?>
    <form class="card product-form" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="photo-field">
        <img id="photoPreview" src="<?= $isNew ? '' : '/' . h($product['image']) ?>" alt="" <?= $isNew ? 'hidden' : '' ?>>
        <label>Foto produk <?= $isNew ? '' : '<small>(kosongkan bila tidak diganti)</small>' ?>
          <input id="photoInput" type="file" name="photo" accept="image/jpeg,image/png,image/webp" <?= $isNew ? 'required' : '' ?>>
        </label>
        <p class="hint">JPG, PNG, atau WebP, maksimal 8 MB. Foto otomatis diperkecil, dan rasio potret 3:4 paling pas di katalog.</p>
      </div>
      <div class="fields">
        <label>Nama produk <input name="name" value="<?= h($values['name']) ?>" required maxlength="120" placeholder="Contoh: Layali Bloom"></label>
        <label>Seri <input name="series" value="<?= h($values['series']) ?>" required maxlength="120" list="seriesList" placeholder="Pilih atau ketik seri baru"></label>
        <datalist id="seriesList">
<?php foreach ($seriesList as $series): ?>
          <option value="<?= h($series) ?>">
<?php endforeach; ?>
        </datalist>
        <label>Harga (Rp) <input type="number" name="price" value="<?= h($values['price']) ?>" min="1000" step="500" required></label>
        <label>Berat (gram) <input type="number" name="weight_gram" value="<?= h($values['weight_gram']) ?>" min="1" max="50000" required></label>
        <label>Status
          <select name="status">
            <option value="available" <?= $values['status'] === 'available' ? 'selected' : '' ?>>Ready</option>
            <option value="sold-out" <?= $values['status'] === 'sold-out' ? 'selected' : '' ?>>Sold out</option>
          </select>
        </label>
        <label>Halaman katalog <input type="number" name="catalog_page" value="<?= h($values['catalog_page']) ?>" min="1" max="999" required></label>
        <label class="check"><input type="checkbox" name="is_active" <?= $values['is_active'] ? 'checked' : '' ?>> Tampilkan di katalog</label>
        <button class="primary" type="submit"><?= $isNew ? 'Simpan produk' : 'Simpan perubahan' ?></button>
      </div>
    </form>
    <script src="/admin/admin.js"></script>
<?php
admin_footer();
