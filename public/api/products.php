<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

api_boot(['GET', 'HEAD']);

$rows = db()->query(
    'SELECT slug, series, name, price, weight_gram, status, image, catalog_page
     FROM products
     WHERE is_active = 1
     ORDER BY catalog_page, sort_order, id'
)->fetchAll();

$products = array_map(fn (array $row) => [
    'id' => $row['slug'],
    'series' => $row['series'],
    'name' => $row['name'],
    'price' => (int) $row['price'],
    'weightGram' => (int) $row['weight_gram'],
    'status' => $row['status'],
    'image' => $row['image'],
    'page' => (int) $row['catalog_page'],
], $rows);

json_response(['products' => $products], 200, ['Cache-Control' => 'public, max-age=60']);
