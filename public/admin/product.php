<?php
declare(strict_types=1);

// Tambah produk baru (tanpa ?id) atau edit produk beserta galeri fotonya (?id=123).

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
$self = $isNew ? '/admin/product.php' : '/admin/product.php?id=' . $id;

$values = $product ?? [
    'name' => '', 'series' => '', 'price' => '', 'weight_gram' => 250, 'stock' => '', 'is_active' => 1,
    'catalog_page' => (int) db()->query('SELECT COALESCE(MAX(catalog_page), 1) FROM products')->fetchColumn(),
];
$error = null;

// Kategori (seri) yang sudah ada, urut seperti di katalog, beserta halaman katalognya.
$categories = array_column(db()->query(
    'SELECT series, MIN(catalog_page) page FROM products GROUP BY series ORDER BY MIN(catalog_page), MIN(sort_order)'
)->fetchAll(), 'page', 'series');
const NEW_CATEGORY = '__new__';

// Pilihan kategori dari form. Kategori baru yang namanya sama dengan kategori lama
// (beda huruf besar/kecil atau spasi) otomatis memakai kategori lama supaya tidak dobel.
function chosen_category(array $categories): string
{
    $choice = (string) ($_POST['series'] ?? '');
    if ($choice !== NEW_CATEGORY) {
        if (!array_key_exists($choice, $categories)) {
            throw new InvalidArgumentException('Pilih kategori dari daftar.');
        }
        return $choice;
    }
    $new = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['series_new'] ?? '')));
    if (mb_strlen($new) < 2 || mb_strlen($new) > 120 || preg_match('/\p{C}/u', $new)) {
        throw new InvalidArgumentException('Nama kategori baru harus 2-120 karakter.');
    }
    foreach (array_keys($categories) as $existing) {
        if (mb_strtolower($existing) === mb_strtolower($new)) {
            return $existing;
        }
    }
    return $new;
}

function warning_suffix(array $warnings): string
{
    return $warnings
        ? ' Perhatian: ' . implode(', ', $warnings) . '. Sebaiknya foto ulang dengan cahaya cukup dan kamera diam.'
        : '';
}

// Kelola galeri produk yang sudah ada: tambah foto, jadikan sampul, geser urutan, hapus.
function handle_gallery_action(array $product, array $admin, string $self): never
{
    $productId = (int) $product['id'];
    $action = (string) ($_POST['gallery_action'] ?? '');
    $pdo = db();

    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE')->execute([$productId]);
        product_gallery_materialize($productId);
        $ids = array_column(product_gallery($productId), 'id');
        $imageId = (int) ($_POST['image_id'] ?? 0);
        $position = array_search($imageId, $ids, true);
        $deletedPath = null;
        $message = 'Galeri diperbarui.';

        if ($action === 'add') {
            $files = uploaded_photos();
            if (!$files) {
                throw new InvalidArgumentException('Pilih foto yang mau ditambahkan.');
            }
            if (count($ids) + count($files) > PRODUCT_MAX_PHOTOS) {
                throw new InvalidArgumentException('Maksimal ' . PRODUCT_MAX_PHOTOS . ' foto per produk. Sekarang ada ' . count($ids) . '.');
            }
            $stored = product_images_store_many($files, $product['slug']);
            $insert = $pdo->prepare('INSERT INTO product_images (product_id, path, sort_order) VALUES (?, ?, ?)');
            foreach ($stored['paths'] as $path) {
                $insert->execute([$productId, $path, count($ids)]);
                $ids[] = (int) $pdo->lastInsertId();
            }
            $message = count($stored['paths']) . ' foto ditambahkan.' . warning_suffix($stored['warnings']);
        } elseif ($position === false) {
            throw new InvalidArgumentException('Foto tidak ditemukan.');
        } elseif ($action === 'cover') {
            array_splice($ids, $position, 1);
            array_unshift($ids, $imageId);
            $message = 'Sampul diganti.';
        } elseif ($action === 'left' || $action === 'right') {
            $target = $position + ($action === 'left' ? -1 : 1);
            if (isset($ids[$target])) {
                [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];
            }
        } elseif ($action === 'delete') {
            if (count($ids) <= 1) {
                throw new InvalidArgumentException('Produk harus punya minimal 1 foto.');
            }
            $stmt = $pdo->prepare('SELECT path FROM product_images WHERE id = ? AND product_id = ?');
            $stmt->execute([$imageId, $productId]);
            $deletedPath = $stmt->fetchColumn() ?: null;
            $pdo->prepare('DELETE FROM product_images WHERE id = ? AND product_id = ?')->execute([$imageId, $productId]);
            array_splice($ids, $position, 1);
            $message = 'Foto dihapus.';
        } else {
            throw new InvalidArgumentException('Aksi tidak dikenal.');
        }

        product_gallery_save_order($productId, $ids);
        $pdo->commit();
    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        flash($e->getMessage());
        redirect($self . '#galeri');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // File dihapus setelah commit, supaya tidak hilang bila transaksi batal.
    product_image_delete($deletedPath);
    error_log(sprintf('[oleena-admin] %s galeri produk #%d: %s', $admin['username'], $productId, $action));
    flash($message);
    redirect($self . '#galeri');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bila total upload melebihi post_max_size, PHP mengosongkan $_POST sehingga token CSRF ikut hilang.
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $error = 'Total ukuran foto terlalu besar untuk server. Upload lebih sedikit foto sekaligus.';
    } else {
        csrf_verify();
        if (!$isNew && isset($_POST['gallery_action'])) {
            handle_gallery_action($product, $admin, $self);
        }

        $values = array_merge($values, [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'series' => (string) ($_POST['series'] ?? ''),
            'series_new' => trim((string) ($_POST['series_new'] ?? '')),
            'price' => $_POST['price'] ?? '',
            'weight_gram' => $_POST['weight_gram'] ?? '',
            'stock' => $_POST['stock'] ?? '',
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'catalog_page' => $_POST['catalog_page'] ?? 1,
        ]);

        try {
            $name = $values['name'];
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || preg_match('/\p{C}/u', $name)) {
                throw new InvalidArgumentException('Nama produk harus 2-120 karakter.');
            }
            $series = chosen_category($categories);
            $price = filter_var($values['price'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1000, 'max_range' => 100000000]]);
            $weight = filter_var($values['weight_gram'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50000]]);
            $page = filter_var($values['catalog_page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
            $stock = filter_var($values['stock'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
            if ($price === false || $weight === false || $page === false || $stock === false) {
                throw new InvalidArgumentException('Harga (min Rp1.000), berat (1-50.000 g), stok (0-100.000), atau halaman katalog tidak valid.');
            }
            $status = $stock > 0 ? 'available' : 'sold-out';

            $pdo = db();
            if ($isNew) {
                $files = uploaded_photos();
                if (!$files) {
                    throw new InvalidArgumentException('Pilih minimal 1 foto produk.');
                }
                if (count($files) > PRODUCT_MAX_PHOTOS) {
                    throw new InvalidArgumentException('Maksimal ' . PRODUCT_MAX_PHOTOS . ' foto per produk.');
                }

                // Slug dibuat sekali dan tidak diubah lagi, karena dipakai sebagai ID produk di keranjang pembeli.
                $base = slugify("$series $name");
                $slug = $base;
                $exists = $pdo->prepare('SELECT 1 FROM products WHERE slug = ?');
                for ($n = 2; $exists->execute([$slug]) && $exists->fetchColumn(); $n++) {
                    $slug = "$base-$n";
                }

                $stored = product_images_store_many($files, $slug);
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare(
                        'INSERT INTO products (slug, series, name, price, weight_gram, stock, status, image, catalog_page, sort_order, is_active)
                         SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(MAX(sort_order), 0) + 1, ? FROM products'
                    )->execute([$slug, $series, $name, $price, $weight, $stock, $status, $stored['paths'][0], $page, $values['is_active']]);
                    $id = (int) $pdo->lastInsertId();
                    $insert = $pdo->prepare('INSERT INTO product_images (product_id, path, sort_order) VALUES (?, ?, ?)');
                    foreach ($stored['paths'] as $order => $path) {
                        $insert->execute([$id, $path, $order]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    array_map('product_image_delete', $stored['paths']);
                    throw $e;
                }
                error_log(sprintf('[oleena-admin] %s menambah produk #%d %s (%d foto)', $admin['username'], $id, $slug, count($stored['paths'])));
                flash('Produk ditambahkan dengan ' . count($stored['paths']) . ' foto.' . warning_suffix($stored['warnings']));
            } else {
                $pdo->prepare(
                    'UPDATE products SET series = ?, name = ?, price = ?, weight_gram = ?, stock = ?, status = ?, catalog_page = ?, is_active = ? WHERE id = ?'
                )->execute([$series, $name, $price, $weight, $stock, $status, $page, $values['is_active'], $id]);
                error_log(sprintf('[oleena-admin] %s mengedit produk #%d', $admin['username'], $id));
                flash('Produk diperbarui.');
            }
            redirect('/admin/products.php#p' . $id);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

$gallery = $isNew ? [] : product_gallery($id);
$photoHint = 'JPG, PNG, atau WebP, maksimal 8 MB per foto, sampai ' . PRODUCT_MAX_PHOTOS . ' foto. Pakai foto asli dari kamera (minimal 1080×1440 px), potret 3:4, cahaya terang, dan kamera diam supaya tidak blur.';

admin_header($isNew ? 'Tambah produk' : 'Edit produk', $admin);
?>
    <p><a href="/admin/products.php">&larr; Semua produk</a></p>
    <h1><?= $isNew ? 'Tambah produk' : 'Edit ' . h($product['name']) ?></h1>
<?php if ($error): ?>
    <p class="error"><?= h($error) ?></p>
<?php endif; ?>

<?php if (!$isNew): ?>
    <section class="card" id="galeri">
      <h2>Foto produk (<?= count($gallery) ?>/<?= PRODUCT_MAX_PHOTOS ?>)</h2>
      <p class="hint">Foto pertama menjadi sampul di katalog. Pembeli bisa menggeser (swipe) semua foto di halaman detail.</p>
      <ol class="gallery-admin">
<?php foreach ($gallery as $index => $photo): ?>
        <li>
          <img src="/<?= h(product_image_small($photo['path'])) ?>" alt="Foto <?= $index + 1 ?>" loading="lazy">
          <span class="gallery-badge"><?= $index === 0 ? 'Sampul' : $index + 1 ?></span>
<?php if ($photo['id'] !== null || count($gallery) > 1): ?>
          <form method="post" class="gallery-actions">
            <?= csrf_field() ?>
            <input type="hidden" name="image_id" value="<?= (int) $photo['id'] ?>">
<?php if ($index > 0): ?>
            <button type="submit" name="gallery_action" value="left" title="Geser ke kiri" aria-label="Geser foto <?= $index + 1 ?> ke kiri">&larr;</button>
<?php endif; ?>
<?php if ($index < count($gallery) - 1): ?>
            <button type="submit" name="gallery_action" value="right" title="Geser ke kanan" aria-label="Geser foto <?= $index + 1 ?> ke kanan">&rarr;</button>
<?php endif; ?>
<?php if ($index > 0): ?>
            <button type="submit" name="gallery_action" value="cover">Sampul</button>
<?php endif; ?>
<?php if (count($gallery) > 1): ?>
            <button type="submit" name="gallery_action" value="delete" class="danger" data-confirm="Hapus foto ini?">Hapus</button>
<?php endif; ?>
          </form>
<?php endif; ?>
        </li>
<?php endforeach; ?>
      </ol>
<?php if (count($gallery) < PRODUCT_MAX_PHOTOS): ?>
      <form method="post" enctype="multipart/form-data" class="gallery-upload">
        <?= csrf_field() ?>
        <input type="hidden" name="gallery_action" value="add">
        <label>Tambah foto
          <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required data-preview="addPreview" data-max="<?= PRODUCT_MAX_PHOTOS - count($gallery) ?>">
        </label>
        <div id="addPreview" class="preview-strip"></div>
        <button class="primary" type="submit">Upload foto</button>
        <p class="hint"><?= h($photoHint) ?></p>
      </form>
<?php endif; ?>
    </section>
<?php endif; ?>

    <form class="card product-form<?= $isNew ? '' : ' no-photo' ?>" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
<?php if ($isNew): ?>
      <div class="photo-field">
        <label>Foto produk <small>(bisa pilih beberapa sekaligus, foto pertama jadi sampul)</small>
          <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required data-preview="newPreview" data-max="<?= PRODUCT_MAX_PHOTOS ?>">
        </label>
        <div id="newPreview" class="preview-strip"></div>
        <p class="hint"><?= h($photoHint) ?></p>
      </div>
<?php endif; ?>
      <div class="fields">
        <label>Nama produk <input name="name" value="<?= h($values['name']) ?>" required maxlength="120" placeholder="Contoh: Layali Bloom"></label>
<?php $isNewCategory = $values['series'] === NEW_CATEGORY; ?>
        <label>Kategori
          <select name="series" id="seriesSelect" required>
<?php if ($isNew): ?>
            <option value="" <?= $values['series'] === '' ? 'selected' : '' ?> disabled>Pilih kategori</option>
<?php endif; ?>
<?php foreach ($categories as $category => $categoryPage): ?>
            <option value="<?= h($category) ?>" data-page="<?= (int) $categoryPage ?>" <?= $values['series'] === $category ? 'selected' : '' ?>><?= h($category) ?></option>
<?php endforeach; ?>
            <option value="<?= NEW_CATEGORY ?>" <?= $isNewCategory ? 'selected' : '' ?>>+ Kategori baru…</option>
          </select>
        </label>
        <label id="seriesNewField" <?= $isNewCategory ? '' : 'hidden' ?>>Nama kategori baru
          <input name="series_new" value="<?= h($values['series_new'] ?? '') ?>" maxlength="120" placeholder="Contoh: Bella Square Series" <?= $isNewCategory ? 'required' : '' ?>>
        </label>
        <label>Harga (Rp) <input type="number" name="price" value="<?= h($values['price']) ?>" min="1000" step="500" required></label>
        <label>Berat (gram) <input type="number" name="weight_gram" value="<?= h($values['weight_gram']) ?>" min="1" max="50000" required></label>
        <label>Stok <small>(0 = Sold out; otomatis berkurang saat pesanan dibayar)</small>
          <input type="number" name="stock" value="<?= h($values['stock']) ?>" min="0" max="100000" required>
        </label>
        <label>Halaman katalog <small>(otomatis mengikuti kategori; produk tampil berurutan per halaman)</small>
          <input type="number" name="catalog_page" id="catalogPage" value="<?= h($values['catalog_page']) ?>" min="1" max="999" required>
        </label>
        <label class="check"><input type="checkbox" name="is_active" <?= $values['is_active'] ? 'checked' : '' ?>> Tampilkan di katalog</label>
        <button class="primary" type="submit"><?= $isNew ? 'Simpan produk' : 'Simpan detail' ?></button>
      </div>
    </form>
    <script src="/admin/admin.js"></script>
<?php
admin_footer();
