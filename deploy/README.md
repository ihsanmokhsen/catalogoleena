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

1. **[akun] RajaOngkir (Komerce)**: daftar di https://collaborator.komerce.id, salin API key **Shipping Cost**, dan simpan secara rahasia. API lama `api.rajaongkir.com/starter` sudah tidak aktif. Ongkir dihitung sampai tingkat kelurahan/kecamatan.
2. **[akun] Midtrans** (dashboard.midtrans.com): daftar, pilih environment **Sandbox**, buka Settings > Access Keys, lalu salin **Server Key** (`SB-Mid-server-xxx`).

### B. DNS dan situs

3. DNS: tambahkan record **A** `oleena` di zona `hilmimokhsen.my.id` yang mengarah ke IP server aaPanel.
4. Di server, clone repo:
   ```bash
   cd /www/wwwroot && git clone <url-repo> oleena.hilmimokhsen.my.id
   ```
5. aaPanel > Website > Add site `oleena.hilmimokhsen.my.id`, PHP 8.3. Lalu di pengaturan situs:
   - **Site directory > Running directory: `/public`**
   - **Matikan `open_basedir` / 防跨站攻击** untuk situs ini. Jika tetap ingin mengaktifkannya, izinkan seluruh root project, bukan hanya folder `public`:
     ```text
     /www/wwwroot/oleena.hilmimokhsen.my.id/:/tmp/
     ```
     Aplikasi memerlukan akses ke `src/` dan `config/` dari endpoint yang berada di dalam `public/`.
   - SSL: Let's Encrypt, lalu aktifkan **Force HTTPS**
   - Config: tempel isi `deploy/nginx-oleena.conf` (sesuaikan `fastcgi_pass` dengan socket PHP 8.3 di server)
6. aaPanel > App Store > PHP 8.3 > Settings:
   - pastikan ekstensi `pdo_mysql`, `curl`, `mbstring`, `gd` (dengan dukungan JPEG/WebP), `fileinfo`, dan `exif` aktif
   - set `expose_php = Off`, `upload_max_filesize = 8M`, `post_max_size = 64M`, `max_file_uploads = 20`, dan `memory_limit = 256M` (untuk memproses foto HP beresolusi tinggi)
   - buat folder upload yang bisa ditulis PHP:
     ```bash
     mkdir -p /www/wwwroot/oleena.hilmimokhsen.my.id/public/uploads/products
     chown -R www:www /www/wwwroot/oleena.hilmimokhsen.my.id/public/uploads
     ```

   Setelah mengubah `open_basedir`, restart/reload PHP-FPM dari aaPanel, lalu verifikasi:
   ```bash
   curl -i https://oleena.hilmimokhsen.my.id/api/products.php
   ```
   Respons harus berupa JSON. Jika muncul `open_basedir restriction`, jangan lanjut ke seed atau pengujian katalog sebelum pengaturan di atas diperbaiki.

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
   - `shipping.rajaongkir_key`: API key Komerce dari langkah 1
   - `shipping.api_url`: `https://rajaongkir.komerce.id/api/v1` (hapus `base_url` lama bila masih ada)
   - `shipping.origin_id`: ID lokasi toko dari `php bin/find-area.php "nama kecamatan"` (langkah 10)
   - `shipping.couriers`: `jne,jnt,pos`
   - `midtrans.server_key`: Server Key dari langkah 2 (`is_production` tetap `false`)
9. Buat tabel dan impor produk:
   ```bash
   mysql -u oleena -p oleena < database/schema.sql
   php database/seed_products.php
   ```
10. Cari ID lokasi toko lalu isi ke `shipping.origin_id`, misalnya `php bin/find-area.php "kelapa lima"` (hasil `34443  Kelapa Lima, Kupang, ...`).
11. Buat akun admin (password minimal 12 karakter):
    ```bash
    php bin/create-admin.php oleena
    ```

### D. Pembayaran, webhook, dan cron

12. Login ke `/admin/` > **Pengaturan**, lalu isi:
    - **Nomor WhatsApp admin**, sebagai tujuan konfirmasi pembayaran dan tombol WhatsApp di katalog
    - **Gambar QR pembayaran** (QRIS/QR bank asli dari aplikasi bank) dan/atau nama bank, nomor rekening, dan atas nama
    - Cara pembayaran: **QR / transfer** (default). Pilih Midtrans hanya bila akun Midtrans sudah aktif.

    Dengan mode QR / transfer, Midtrans tidak dipakai, sehingga langkah Midtrans di bagian A, F, dan G boleh dilewati.
    Bila memakai Midtrans: Dashboard Midtrans > Settings > Payment > **Notification URL**:
    `https://oleena.hilmimokhsen.my.id/api/midtrans-notification.php`
13. aaPanel > Cron > Add, tipe Shell Script, jalan **tiap 15 menit**:
    ```bash
    php /www/wwwroot/oleena.hilmimokhsen.my.id/bin/expire-orders.php
    ```

### E. Data produk

14. Login ke `https://oleena.hilmimokhsen.my.id/admin/` > **Produk**, lalu isi **berat asli** (default 250 g, menentukan ongkir) dan **stok asli** tiap produk (seeder mengisi 10 untuk produk Ready).

### F. Uji coba (mode Sandbox)

15. Buka situs, masukkan produk ke keranjang, pilih tujuan dan kurir, lalu buat pesanan dan klik **Bayar sekarang**.
16. Di halaman Midtrans Sandbox, pilih metode (misalnya QRIS atau VA), lalu selesaikan pembayaran lewat simulator: https://simulator.sandbox.midtrans.com
17. Pastikan status pesanan menjadi **Sudah dibayar** di situs dan **Dibayar** di `/admin/`.

### G. Go-live

18. Midtrans: ajukan aktivasi Production. Setelah disetujui, ganti `midtrans.server_key` ke key Production (`Mid-server-xxx`), set `is_production => true`, lalu daftarkan ulang Notification URL di environment Production.
19. RajaOngkir (Komerce): pastikan `shipping.rajaongkir_key` berisi API key yang valid dan sudah di-rotate jika pernah terekspos. Perhatikan kuota harian paket Komerce; hasil pencarian wilayah di-cache 7 hari dan ongkir 1 jam.
20. Lakukan satu transaksi asli bernominal kecil, lalu refund dari Dashboard Midtrans.

## Update aplikasi

```bash
cd /www/wwwroot/oleena.hilmimokhsen.my.id
git pull
mysql -u oleena -p oleena < database/schema.sql   # aman diulang, tabel yang ada tidak ditimpa
php bin/migrate.php                                # tambah kolom baru ke tabel lama, aman diulang
```

Bila `styles.css`, `script.js`, atau `lacak.js` berubah, naikkan angka `?v=` di `public/index.html` dan `public/lacak.html`
supaya browser pembeli langsung memakai versi baru (file statis di-cache 12 jam).

## Backup

Foto produk hasil upload disimpan di `public/uploads/products/` dan QR pembayaran di `public/uploads/settings/`. Keduanya **tidak ikut git**. Sertakan folder ini dan database `oleena` di backup aaPanel (Cron > Backup Site & Database).

## Catatan keamanan

- Kredensial (DB, RajaOngkir, Midtrans) hanya ada di `config/config.php`, di luar webroot dan tidak ikut git.
- Harga, berat, ongkir, dan total selalu dihitung ulang di server. Angka dari browser tidak dipakai.
- Mode QR / transfer: status **Dibayar** diberikan admin lewat tombol "Tandai sudah dibayar" setelah dana terlihat di mutasi rekening/QRIS. Nama admin dan waktunya tercatat. Setiap pesanan punya kode unik 1-999 rupiah supaya transfer mudah dicocokkan.
- Mode Midtrans: status **Dibayar** hanya diberikan oleh webhook Midtrans yang tanda tangannya valid, nominalnya cocok, dan sudah dikonfirmasi ulang ke Get Status API.
- Semua notifikasi Midtrans dicatat di tabel `payment_notifications` untuk audit.
- Endpoint publik dibatasi per IP (rate limit). Login admin dibatasi 5 percobaan per 15 menit.
- Panel admin memakai CSRF token, cookie sesi `HttpOnly` + `SameSite=Strict` + `Secure`, dan otomatis logout setelah 2 jam tidak aktif.
- Foto upload dibuat ulang lewat GD (metadata dan isi tersembunyi terbuang), diberi nama acak oleh server, dan folder `uploads/` hanya melayani file `.jpg` statis.
- Detail kegagalan API hanya dicatat di log error PHP. Pengunjung hanya melihat pesan umum.

## Development lokal

Lokal memakai MySQL Homebrew dan server tiruan RajaOngkir (Komerce) + Midtrans (`dev/mock-server.php`),
jadi alur ongkir bisa dicoba tanpa memakai kuota API asli. Mock ini hanya untuk lokal.

1. Nyalakan MySQL: `brew services start mysql`
2. Buat database `oleena` dan user `oleena`, lalu buat `config/config.php` dari contoh dengan:
   - `app.url` = `http://localhost:8000` dan `app.env` = `'local'`
   - `shipping.rajaongkir_key` = `rajaongkir.mock`, `shipping.api_url` = `http://localhost:8001/api/v1`, `shipping.origin_id` = `90002` (Kelapa Lima di mock)
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
   PHP_CLI_SERVER_WORKERS=4 php -d post_max_size=64M -d upload_max_filesize=8M -S localhost:8000 -t public
   ```
5. Buka http://localhost:8000. Area yang tersedia di server tiruan antara lain Kupang, Jakarta, Bandung, Surabaya, Kuta, Makassar, Medan, dan Jayapura. Tombol "Bayar sekarang" membuka halaman simulasi pembayaran.
