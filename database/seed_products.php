<?php
declare(strict_types=1);

// Impor data awal dari database/products.js ke tabel products.
// Jalankan dari terminal: php database/seed_products.php
// Aman dijalankan ulang: produk dicocokkan lewat slug lalu diperbarui.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

$source = file_get_contents(__DIR__ . '/products.js');
$json = preg_replace('/^\s*window\.OLEENA_PRODUCTS\s*=\s*|;\s*$/', '', $source);
$products = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

$stmt = db()->prepare(
    'INSERT INTO products (slug, series, name, price, status, image, catalog_page, sort_order)
     VALUES (:slug, :series, :name, :price, :status, :image, :page, :sort)
     ON DUPLICATE KEY UPDATE series = VALUES(series), name = VALUES(name), price = VALUES(price),
       status = VALUES(status), image = VALUES(image), catalog_page = VALUES(catalog_page),
       sort_order = VALUES(sort_order)'
);

db()->beginTransaction();
foreach ($products as $index => $product) {
    $stmt->execute([
        'slug' => $product['id'],
        'series' => $product['series'],
        'name' => $product['name'],
        'price' => (int) $product['price'],
        'status' => $product['status'] === 'sold-out' ? 'sold-out' : 'available',
        'image' => $product['image'],
        'page' => (int) $product['page'],
        'sort' => $index,
    ]);
}
db()->commit();

echo count($products) . " produk diimpor.\n";
