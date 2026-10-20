# Deploy Oleena ke aaPanel

Stack mengikuti standar BPAD Simple PHP Stack: Nginx + PHP-FPM 8.3 + MySQL/MariaDB + PDO.

## Struktur

```text
bin/         skrip CLI (cari Area ID Biteship)
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

## Ongkir (Biteship)

1. Daftar di biteship.com, aktifkan **Testing Mode**, lalu salin API key `biteship_test.xxx` ke `shipping.biteship_key` di `config/config.php`.
2. Cari Area ID lokasi toko dan isi ke `shipping.origin_area_id`:
   ```bash
   php bin/find-area.php "nama kecamatan toko"
   ```
3. Atur berat asli produk (gram) di database, contoh:
   ```sql
   UPDATE products SET weight_gram = 200 WHERE series = 'Layali Series 2.0';
   ```
4. Coba cek ongkir di keranjang. Jika sudah benar, ganti key menjadi `biteship_live.xxx`.

Catatan keamanan: API key hanya ada di server. Browser hanya mengirim Area ID tujuan dan daftar `{id, qty}`. Berat dan nilai paket dihitung ulang dari database. Endpoint ongkir dibatasi per IP (rate limit) dan hasilnya di-cache (wilayah 7 hari, tarif 1 jam) supaya hemat kuota API.

Saat skema berubah (misalnya setelah `git pull`), jalankan lagi `mysql -u oleena -p oleena < database/schema.sql`. Tabel yang sudah ada tidak akan ditimpa.

## Update

```bash
git pull
php database/seed_products.php   # hanya jika database/products.js berubah
```

## Development lokal

```bash
php -S localhost:8000 -t public
```
