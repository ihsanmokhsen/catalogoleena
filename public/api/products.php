<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/images.php';
require __DIR__ . '/../../src/settings.php';

api_boot(['GET', 'HEAD']);

$rows = db()->query(
    'SELECT id, slug, series, name, price, weight_gram, stock, status, image, catalog_page
     FROM products
     WHERE is_active = 1
     ORDER BY catalog_page, sort_order, id'
)->fetchAll();

// Semua foto galeri dalam satu query, dikelompokkan per produk.
$galleries = [];
foreach (db()->query('SELECT product_id, path FROM product_images ORDER BY product_id, sort_order, id') as $photo) {
    $galleries[(int) $photo['product_id']][] = $photo['path'];
}

$products = array_map(fn (array $row) => [
    'id' => $row['slug'],
    'series' => $row['series'],
    'name' => $row['name'],
    'price' => (int) $row['price'],
    'weightGram' => (int) $row['weight_gram'],
    'status' => $row['status'],
    'stock' => (int) $row['stock'],
    'image' => $row['image'],
    'imageSmall' => product_image_small($row['image']),
    'images' => array_map(fn (string $path) => [
        'src' => $path,
        'small' => product_image_small($path),
    ], $galleries[(int) $row['id']] ?? [$row['image']]),
    'page' => (int) $row['catalog_page'],
], $rows);

json_response([
    'products' => $products,
    'shop' => ['whatsapp' => setting('admin_whatsapp'), 'paymentMode' => payment_mode()],
], 200, ['Cache-Control' => 'public, max-age=60']);
