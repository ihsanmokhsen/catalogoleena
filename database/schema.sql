-- Skema database katalog Oleena (MySQL 8 / MariaDB 10.6+).
-- Tabel orders, order_items, dan pembayaran ditambahkan di fase berikutnya.

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(120) NOT NULL UNIQUE,
    series VARCHAR(120) NOT NULL,
    name VARCHAR(120) NOT NULL,
    price INT UNSIGNED NOT NULL,
    weight_gram INT UNSIGNED NOT NULL DEFAULT 250,
    status ENUM('available', 'sold-out') NOT NULL DEFAULT 'available',
    image VARCHAR(255) NOT NULL,
    catalog_page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_listing (is_active, catalog_page, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
