<?php
declare(strict_types=1);

// Impor data awal dari database/products.js ke tabel products.
// Jalankan dari terminal: php database/seed_products.php
// Aman dijalankan ulang: produk yang sudah ada (slug sama) tidak diubah, supaya harga, stok,
// dan foto yang diatur lewat admin tidak tertimpa. Hanya produk baru yang ditambahkan.

// Stok awal produk yang Ready. Sesuaikan jumlah aslinya di Admin > Produk.
const SEED_INITIAL_STOCK = 10;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

$source = file_get_contents(__DIR__ . '/products.js');
$json = preg_replace('/^\s*window\.OLEENA_PRODUCTS\s*=\s*|;\s*$/', '', $source);
$products = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

$stmt = db()->prepare(
    'INSERT IGNORE INTO products (slug, series, name, price, stock, status, image, catalog_page, sort_order)
     VALUES (:slug, :series, :name, :price, :stock, :status, :image, :page, :sort)'
);

$added = 0;
db()->beginTransaction();
foreach ($products as $index => $product) {
    $stmt->execute([
        'slug' => $product['id'],
        'series' => $product['series'],
        'name' => $product['name'],
        'price' => (int) $product['price'],
        'stock' => $product['status'] === 'sold-out' ? 0 : SEED_INITIAL_STOCK,
        'status' => $product['status'] === 'sold-out' ? 'sold-out' : 'available',
        'image' => $product['image'],
        'page' => (int) $product['page'],
        'sort' => $index,
    ]);
    $added += $stmt->rowCount();
}
db()->commit();

echo "$added produk baru ditambahkan (" . (count($products) - $added) . " sudah ada, tidak diubah).\n";
