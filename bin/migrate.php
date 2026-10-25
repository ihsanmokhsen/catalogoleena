<?php
declare(strict_types=1);

// Tambah kolom baru ke database yang sudah berjalan. Aman diulang.
// Pakai (setelah git pull, sesudah menjalankan database/schema.sql): php bin/migrate.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

$hasColumn = function (string $table, string $column): bool {
    $stmt = db()->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
};

// [tabel, kolom, perintah ALTER]
$migrations = [
    ['orders', 'voucher_code', 'ALTER TABLE orders ADD COLUMN voucher_code VARCHAR(30) NULL AFTER unique_code, ADD INDEX idx_orders_voucher (voucher_code)'],
    ['orders', 'discount', 'ALTER TABLE orders ADD COLUMN discount INT UNSIGNED NOT NULL DEFAULT 0 AFTER voucher_code'],
];

foreach ($migrations as [$table, $column, $sql]) {
    if ($hasColumn($table, $column)) {
        echo "Sudah ada: $table.$column\n";
        continue;
    }
    db()->exec($sql);
    echo "Ditambahkan: $table.$column\n";
}
echo "Migrasi selesai.\n";
