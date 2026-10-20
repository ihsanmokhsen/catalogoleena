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

CREATE TABLE IF NOT EXISTS api_rate_limits (
    bucket VARCHAR(120) NOT NULL PRIMARY KEY,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_cache (
    cache_key CHAR(64) NOT NULL PRIMARY KEY,
    payload MEDIUMTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_api_cache_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(32) NOT NULL UNIQUE,
    idempotency_key CHAR(36) NULL UNIQUE,
    -- Token akses pembeli untuk melihat status dan membayar (yang disimpan hanya hash-nya).
    access_token_hash CHAR(64) NOT NULL,
    status ENUM('pending_payment', 'paid', 'processing', 'shipped', 'completed', 'cancelled', 'expired')
        NOT NULL DEFAULT 'pending_payment',
    customer_name VARCHAR(120) NOT NULL,
    customer_phone VARCHAR(20) NOT NULL,
    address VARCHAR(500) NOT NULL,
    note VARCHAR(500) NULL,
    destination_area_id VARCHAR(64) NOT NULL,
    destination_area_name VARCHAR(255) NOT NULL,
    courier_code VARCHAR(40) NOT NULL,
    courier_service_code VARCHAR(60) NOT NULL,
    courier_label VARCHAR(160) NOT NULL,
    weight_gram INT UNSIGNED NOT NULL,
    subtotal INT UNSIGNED NOT NULL,
    shipping_cost INT UNSIGNED NOT NULL,
    total INT UNSIGNED NOT NULL,
    client_ip_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nama dan harga produk disalin saat order dibuat, supaya riwayat tidak berubah bila katalog diedit.
CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_name VARCHAR(120) NOT NULL,
    product_series VARCHAR(120) NOT NULL,
    unit_price INT UNSIGNED NOT NULL,
    qty SMALLINT UNSIGNED NOT NULL,
    line_total INT UNSIGNED NOT NULL,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL UNIQUE,
    provider VARCHAR(20) NOT NULL DEFAULT 'midtrans',
    snap_token VARCHAR(80) NOT NULL,
    redirect_url VARCHAR(255) NOT NULL,
    gross_amount INT UNSIGNED NOT NULL,
    transaction_status VARCHAR(30) NULL,
    payment_type VARCHAR(40) NULL,
    transaction_id VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Semua notifikasi webhook dicatat apa adanya untuk audit.
CREATE TABLE IF NOT EXISTS payment_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(64) NULL,
    transaction_status VARCHAR(30) NULL,
    signature_valid TINYINT(1) NOT NULL,
    result VARCHAR(60) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_notifications_order (order_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
