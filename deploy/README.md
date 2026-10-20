# Deploy Oleena ke aaPanel

Stack mengikuti standar BPAD Simple PHP Stack: Nginx + PHP-FPM 8.3 + MySQL/MariaDB + PDO.

## Struktur

```text
public/      webroot (index.html, script.js, styles.css, assets/, api/)
src/         kode PHP bersama, tidak bisa diakses dari browser
config/      config.php berisi kredensial (tidak ikut git)
database/    schema.sql, data awal, dan seeder
deploy/      konfigurasi server
```

Domain: `oleena.hilmimokhsen.my.id`

## Langkah pertama kali

1. DNS: buat record **A** `oleena` di zona `hilmimokhsen.my.id` yang mengarah ke IP server aaPanel.
2. Clone repo ke `/www/wwwroot/oleena.hilmimokhsen.my.id`.
3. aaPanel > Website > Add site `oleena.hilmimokhsen.my.id`, pilih PHP 8.3, lalu set **Running directory / document root ke `public`**. Di pengaturan PHP 8.3, set `expose_php = Off`.
4. Tempel isi `deploy/nginx-oleena.conf` ke konfigurasi situs (sesuaikan socket PHP-FPM), lalu aktifkan SSL (Let's Encrypt) dan Force HTTPS.
5. aaPanel > Databases > Add database `oleena` dengan user khusus `oleena` (bukan root), password acak yang kuat.
6. Buat konfigurasi:
   ```bash
   cp config/config.example.php config/config.php
   chmod 640 config/config.php
   ```
   lalu isi kredensial database.
7. Buat tabel dan impor produk:
   ```bash
   mysql -u oleena -p oleena < database/schema.sql
   php database/seed_products.php
   ```
8. Buka `https://oleena.hilmimokhsen.my.id/api/products.php`, pastikan muncul JSON produk.

## Update

```bash
git pull
php database/seed_products.php   # hanya jika database/products.js berubah
```

## Development lokal

```bash
php -S localhost:8000 -t public
```
