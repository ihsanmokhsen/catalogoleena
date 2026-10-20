# Deploy Oleena ke aaPanel

Domain: `oleena.hilmimokhsen.my.id`
Stack: Nginx + PHP-FPM 8.3 + MySQL/MariaDB + PDO (standar BPAD Simple PHP Stack).

## Struktur

```text
public/      webroot: katalog (index.html, script.js, styles.css, assets/), api/, admin/
src/         kode PHP bersama (tidak bisa diakses dari browser)
config/      config.php berisi kredensial (tidak ikut git)
database/    schema.sql, data awal produk, seeder
bin/         skrip terminal: cari Area ID, buat admin, cron kedaluwarsa
deploy/      konfigurasi server
```

## Checklist pengaturan (sekali saja)

Kerjakan berurutan. Tanda **[akun]** berarti perlu daftar atau login ke layanan luar.

### A. Akun yang perlu disiapkan

1. **[akun] Biteship** (biteship.com): daftar, aktifkan **Testing Mode**, salin API key `biteship_test.xxx`.
2. **[akun] Midtrans** (dashboard.midtrans.com): daftar, pilih environment **Sandbox**, buka Settings > Access Keys, lalu salin **Server Key** (`SB-Mid-server-xxx`).

### B. DNS dan situs

3. DNS: tambahkan record **A** `oleena` di zona `hilmimokhsen.my.id` yang mengarah ke IP server aaPanel.
4. Di server, clone repo:
   ```bash
   cd /www/wwwroot && git clone <url-repo> oleena.hilmimokhsen.my.id
   ```
5. aaPanel > Website > Add site `oleena.hilmimokhsen.my.id`, PHP 8.3. Lalu di pengaturan situs:
   - **Site directory > Running directory: `/public`**
   - SSL: Let's Encrypt, lalu aktifkan **Force HTTPS**
   - Config: tempel isi `deploy/nginx-oleena.conf` (sesuaikan `fastcgi_pass` dengan socket PHP 8.3 di server)
6. aaPanel > App Store > PHP 8.3 > Settings:
   - pastikan ekstensi `pdo_mysql`, `curl`, `mbstring`, `gd` (dengan dukungan JPEG/WebP), `fileinfo`, dan `exif` aktif
   - set `expose_php = Off`, `upload_max_filesize = 8M`, dan `post_max_size = 10M`
   - buat folder upload yang bisa ditulis PHP:
     ```bash
     mkdir -p /www/wwwroot/oleena.hilmimokhsen.my.id/public/uploads/products
     chown -R www:www /www/wwwroot/oleena.hilmimokhsen.my.id/public/uploads
     ```

### C. Database dan konfigurasi

7. aaPanel > Databases > Add database `oleena`, dengan user `oleena` dan password acak yang kuat (jangan pakai root).
8. Buat file konfigurasi:
   ```bash
   cd /www/wwwroot/oleena.hilmimokhsen.my.id
   cp config/config.example.php config/config.php
   chmod 640 config/config.php
   chown root:www config/config.php
   ```
   Lalu isi `config/config.php`:
   - `db.pass`: password database dari langkah 7
   - `shipping.biteship_key`: key dari langkah 1
   - `midtrans.server_key`: Server Key dari langkah 2 (`is_production` tetap `false`)
9. Buat tabel dan impor produk:
   ```bash
   mysql -u oleena -p oleena < database/schema.sql
   php database/seed_products.php
   ```
10. Cari Area ID lokasi toko, lalu isi ke `shipping.origin_area_id`:
    ```bash
    php bin/find-area.php "nama kecamatan toko"
    ```
11. Buat akun admin (password minimal 12 karakter):
    ```bash
    php bin/create-admin.php oleena
    ```

### D. Webhook dan cron

12. Midtrans Dashboard > Settings > Payment > **Notification URL**:
    `https://oleena.hilmimokhsen.my.id/api/midtrans-notification.php`
13. aaPanel > Cron > Add, tipe Shell Script, jalan **tiap 15 menit**:
    ```bash
    php /www/wwwroot/oleena.hilmimokhsen.my.id/bin/expire-orders.php
    ```

### E. Data produk

14. Login ke `https://oleena.hilmimokhsen.my.id/admin/` > **Produk**, lalu isi **berat asli** tiap produk (default 250 g). Berat ini menentukan ongkir.

### F. Uji coba (mode Sandbox)

15. Buka situs, masukkan produk ke keranjang, pilih tujuan dan kurir, lalu buat pesanan dan klik **Bayar sekarang**.
16. Di halaman Midtrans Sandbox, pilih metode (misalnya QRIS atau VA), lalu selesaikan pembayaran lewat simulator: https://simulator.sandbox.midtrans.com
17. Pastikan status pesanan menjadi **Sudah dibayar** di situs dan **Dibayar** di `/admin/`.

### G. Go-live

18. Midtrans: ajukan aktivasi Production. Setelah disetujui, ganti `midtrans.server_key` ke key Production (`Mid-server-xxx`), set `is_production => true`, lalu daftarkan ulang Notification URL di environment Production.
19. Biteship: matikan Testing Mode, lalu ganti `shipping.biteship_key` ke `biteship_live.xxx`.
20. Lakukan satu transaksi asli bernominal kecil, lalu refund dari Dashboard Midtrans.

## Update aplikasi

```bash
cd /www/wwwroot/oleena.hilmimokhsen.my.id
git pull
mysql -u oleena -p oleena < database/schema.sql   # aman diulang, tabel yang ada tidak ditimpa
```

## Backup

Foto produk hasil upload disimpan di `public/uploads/products/` dan **tidak ikut git**. Sertakan folder ini dan database `oleena` di backup aaPanel (Cron > Backup Site & Database).

## Catatan keamanan

- Kredensial (DB, Biteship, Midtrans) hanya ada di `config/config.php`, di luar webroot dan tidak ikut git.
- Harga, berat, ongkir, dan total selalu dihitung ulang di server. Angka dari browser tidak dipakai.
- Status **Dibayar** hanya diberikan oleh webhook Midtrans yang tanda tangannya valid, nominalnya cocok, dan sudah dikonfirmasi ulang ke Get Status API. Admin tidak bisa menandai pesanan dibayar secara manual.
- Semua notifikasi Midtrans dicatat di tabel `payment_notifications` untuk audit.
- Endpoint publik dibatasi per IP (rate limit). Login admin dibatasi 5 percobaan per 15 menit.
- Panel admin memakai CSRF token, cookie sesi `HttpOnly` + `SameSite=Strict` + `Secure`, dan otomatis logout setelah 2 jam tidak aktif.
- Foto upload dibuat ulang lewat GD (metadata dan isi tersembunyi terbuang), diberi nama acak oleh server, dan folder `uploads/` hanya melayani file `.jpg` statis.
- Detail kegagalan API hanya dicatat di log error PHP. Pengunjung hanya melihat pesan umum.

## Development lokal

Lokal memakai MySQL Homebrew dan server tiruan Biteship/Midtrans (`dev/mock-server.php`), jadi semua alur bisa dicoba tanpa akun asli.

1. Nyalakan MySQL: `brew services start mysql`
2. Buat database `oleena` dan user `oleena`, lalu buat `config/config.php` dari contoh dengan:
   - `app.url` = `http://localhost:8000` dan `app.env` = `'local'`
   - `shipping.biteship_key` = `biteship_test.mock`, `shipping.base_url` = `http://localhost:8001`
   - `midtrans.server_key` = `SB-Mid-server-mock`, ditambah `snap_url` = `http://localhost:8001/snap/v1/transactions` dan `api_url` = `http://localhost:8001`
3. Isi database:
   ```bash
   mysql -uroot oleena < database/schema.sql
   php database/seed_products.php
   php database/seed_demo.php
   ```
   Seeder demo membuat akun admin lokal (lihat komentar di `database/seed_demo.php`) dan 14 pesanan contoh di semua status.
4. Jalankan dua server (atau lewat `.claude/launch.json`):
   ```bash
   PHP_CLI_SERVER_WORKERS=4 php -S localhost:8001 dev/mock-server.php
   PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000 -t public
   ```
5. Buka http://localhost:8000. Area yang tersedia di server tiruan antara lain Kupang, Jakarta, Bandung, Surabaya, Kuta, Makassar, Medan, dan Jayapura. Tombol "Bayar sekarang" membuka halaman simulasi pembayaran.
