# OLEENA Catalog

Aplikasi katalog dan toko online OLEENA berbasis PHP native, HTML, CSS, JavaScript, dan MySQL/MariaDB.

## Informasi aplikasi

### Fitur pelanggan

- Katalog produk dengan foto, seri, harga, stok, pencarian, dan filter.
- Keranjang dan checkout.
- Pencarian kota/kabupaten, pilihan kurir, dan perhitungan ongkir melalui RajaOngkir Starter.
- Pembayaran QR/transfer manual dengan konfirmasi WhatsApp atau Midtrans.
- Kode pesanan, status pesanan, nomor resi, dan halaman pelacakan.

### Fitur admin

- Login admin dengan password hash.
- Daftar/detail pesanan, filter status, dan konfirmasi pembayaran manual.
- Perubahan status: dibayar, dikemas, dikirim, selesai, atau dibatalkan.
- Pengisian nomor resi.
- Pengelolaan produk, harga, stok, berat, status aktif, dan galeri foto.
- Pengaturan WhatsApp, rekening, QR pembayaran, metode pembayaran, laporan, dan ekspor CSV.

### URL utama

| Halaman | URL lokal |
| --- | --- |
| Katalog | `http://localhost:8000/` |
| Lacak pesanan | `http://localhost:8000/lacak.html` |
| Login admin | `http://localhost:8000/admin/login.php` |
| Panel admin | `http://localhost:8000/admin/` |

## Teknologi dan struktur

- PHP 8.3+ untuk produksi dan PHP CLI untuk development.
- MySQL 8 atau MariaDB 10.6+ dengan PDO MySQL.
- Nginx + PHP-FPM untuk produksi.
- RajaOngkir Starter API untuk ongkir dan Midtrans API/webhook untuk pembayaran opsional.
- Tidak ada `package.json`, Composer, Laravel, atau framework frontend.

```text
bin/       Script CLI
config/    Template dan konfigurasi rahasia
database/  Skema, data produk, dan seeder
deploy/    Panduan/configurasi Nginx
dev/       Server tiruan Biteship/Midtrans
public/    Document root web
src/       Kode PHP bersama
```

**Penting:** webroot harus `public/`. Folder `config/`, `src/`, `bin/`, dan `database/` tidak boleh diakses dari browser.

## Persyaratan

Lokal memerlukan PHP dengan extension `pdo_mysql`, `curl`, `mbstring`, `gd`, `fileinfo`, dan `exif`, serta MySQL/MariaDB aktif.

- Produksi memerlukan Linux/aaPanel, Nginx, PHP-FPM 8.3, MySQL/MariaDB, domain HTTPS, dan API key RajaOngkir Starter. Akun Midtrans diperlukan jika pembayaran Midtrans dipakai.

## Menjalankan secara lokal

### 1. Database

```bash
cd "/Users/ihsanmokhsen/Documents/Proyek Sistem Informasi/catalog_oleena"
brew services start mysql
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS oleena CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Jika perlu membuat user khusus:

```sql
CREATE USER 'oleena'@'localhost' IDENTIFIED BY 'password-lokal-yang-kuat';
GRANT ALL PRIVILEGES ON oleena.* TO 'oleena'@'localhost';
FLUSH PRIVILEGES;
```

### 2. Konfigurasi

```bash
cp config/config.example.php config/config.php
```

Isi nilai lokal berikut di `config/config.php`:

```php
'app' => ['url' => 'http://localhost:8000', 'env' => 'local'],
'db' => [
    'host' => '127.0.0.1', 'port' => 3306,
    'name' => 'oleena', 'user' => 'oleena',
    'pass' => 'password-lokal-yang-kuat',
],
'shipping' => [
    'rajaongkir_key' => 'ISI_KEY_RAJAONGKIR_STARTER',
    'base_url' => 'https://api.rajaongkir.com/starter',
    'origin_city_name' => 'Kota Kupang, Nusa Tenggara Timur',
    'origin_city_id' => '',
    'couriers' => 'jne,jnt,pos',
],
'midtrans' => [
    'server_key' => 'SB-Mid-server-mock',
    'snap_url' => 'http://localhost:8001/snap/v1/transactions',
    'api_url' => 'http://localhost:8001',
    'is_production' => false,
],
```

API key RajaOngkir hanya digunakan server-side dan tidak boleh dimasukkan ke JavaScript,
repository publik, atau dikirim lewat chat. Untuk pengujian lokal tanpa API asli, gunakan
key RajaOngkir Starter yang valid atau sesuaikan mock server secara terpisah.

### 3. Skema, produk, dan data demo

```bash
mysql -u oleena -p oleena < database/schema.sql
php database/seed_products.php
```

Seeder produk mengambil data dari `database/products.js` dan tidak menimpa produk yang sudah ada. Untuk data pesanan demo dan akun admin lokal:

```bash
php database/seed_demo.php
```

Akun demo: `admin` / `oleena-admin-lokal`. Jangan gunakan akun ini di produksi.

### 4. Jalankan dua server

Buka dua terminal.

Terminal 1, server tiruan Biteship/Midtrans:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8001 dev/mock-server.php
```

Terminal 2, aplikasi:

```bash
PHP_CLI_SERVER_WORKERS=4 php \
  -d post_max_size=64M \
  -d upload_max_filesize=8M \
  -S localhost:8000 -t public
```

Buka `http://localhost:8000`. Konfigurasi yang sama tersedia di `.claude/launch.json` untuk VS Code.

### 5. Uji alur

1. Cari produk dan masukkan produk tersedia ke keranjang.
2. Isi nama, WhatsApp, alamat, tujuan, dan kurir.
3. Buat pesanan dan buka pembayaran simulasi.
4. Uji status `expired` dan `cancelled`.
5. Login `/admin/`, ubah status sampai `completed`, dan isi nomor resi.
6. Uji `/lacak.html` dengan kode pesanan dan WhatsApp data demo.

## Deployment produksi aaPanel

### Source code dan web server

```bash
cd /www/wwwroot
git clone <URL_REPOSITORY> oleena.hilmimokhsen.my.id
cd /www/wwwroot/oleena.hilmimokhsen.my.id
```

Buat situs PHP 8.3, set **Running directory** ke `/public`, aktifkan SSL/Force HTTPS, lalu terapkan `deploy/nginx-oleena.conf` dengan socket PHP-FPM yang sesuai.

### PHP, upload, database, dan admin

Aktifkan `pdo_mysql`, `curl`, `mbstring`, `gd`, `fileinfo`, dan `exif`. Gunakan `expose_php=Off`, `upload_max_filesize=8M`, `post_max_size=64M`, `max_file_uploads=20`, dan `memory_limit=256M`.

```bash
mkdir -p public/uploads/products public/uploads/settings
chown -R www:www public/uploads
mysql -u oleena -p oleena < database/schema.sql
cp config/config.example.php config/config.php
chmod 640 config/config.php
chown root:www config/config.php
php database/seed_products.php
php bin/create-admin.php oleena
```

Isi `config/config.php` dengan URL HTTPS, database, API key RajaOngkir Starter,
`origin_city_id` Kota Kupang (opsional karena dapat dicari berdasarkan nama), kurir
`jne,jnt,pos`, dan Server Key Midtrans bila diperlukan. Jangan gunakan key mock di produksi.

### Pengaturan toko dan cron

Di `/admin/`, isi WhatsApp, QRIS/QR, rekening, metode pembayaran, harga, stok, berat gram, dan foto. Berat harus akurat karena menentukan ongkir.

Tambahkan cron setiap 15 menit:

```bash
php /www/wwwroot/oleena.hilmimokhsen.my.id/bin/expire-orders.php
```

Script ini menandai `pending_payment` yang melewati batas waktu sebagai `expired` dan membersihkan cache lama.

### RajaOngkir dan Midtrans

Ongkir menggunakan RajaOngkir Starter dengan asal Kota Kupang dan kurir JNE, J&T, serta POS.
Pastikan API key RajaOngkir diisi hanya pada `config/config.php`. Webhook Midtrans diarahkan
ke `/api/midtrans-notification.php` melalui HTTPS bila pembayaran Midtrans dipakai.

Saat go-live, rotate API key RajaOngkir bila pernah terekspos, ganti Server Key Midtrans,
set `is_production => true`, daftarkan ulang Notification URL Production, dan lakukan
transaksi kecil untuk pengujian.

## Alur status pesanan

```text
pending_payment → paid → processing → shipped → completed
```

Status lain adalah `cancelled` dan `expired`. Pembayaran manual harus diverifikasi pada mutasi rekening/QRIS sebelum **Tandai sudah dibayar**. Pembatalan tidak otomatis melakukan refund. Jika **Stok kurang**, hubungi pelanggan atau lakukan refund dari dashboard penyedia pembayaran.

## Keamanan dan backup

- Jangan commit `config/config.php`, API key, Server Key, password database, atau password admin.
- Gunakan `public/` sebagai webroot dan HTTPS di produksi.
- Admin memakai session, CSRF token, cookie `HttpOnly`/`SameSite=Strict`, dan rate limit login.
- Endpoint publik memiliki rate limit; harga, stok, berat, ongkir, dan total dihitung ulang di server.
- Backup database, `public/uploads/products/`, `public/uploads/settings/`, dan `config/config.php` secara terenkripsi. Jangan masukkan `config.php` ke repository.

```bash
mysqldump -u oleena -p oleena > oleena-$(date +%F).sql
```

## Update aplikasi

```bash
cd /www/wwwroot/oleena.hilmimokhsen.my.id
git pull
mysql -u oleena -p oleena < database/schema.sql
```

Backup database sebelum update.

## Troubleshooting

- **`config/config.php belum dibuat`:** jalankan `cp config/config.example.php config/config.php`, lalu isi konfigurasi.
- **Database gagal:** pastikan MySQL aktif, kredensial benar, user memiliki hak database, dan `pdo_mysql` aktif.
- **Ongkir tidak muncul:** pastikan `shipping.rajaongkir_key` valid, `base_url` mengarah ke RajaOngkir Starter, asal Kota Kupang dan kurir `jne,jnt,pos` terkonfigurasi, serta berat produk benar.
- **Upload gagal:** periksa folder upload, izin PHP-FPM, batas upload, `gd`, dan `fileinfo`.
- **File rahasia bisa dibuka:** pastikan document root `/public` dan gunakan `deploy/nginx-oleena.conf`.
- **Midtrans tidak update:** periksa Server Key, environment, Notification URL HTTPS, endpoint webhook, log PHP, dan tabel `payment_notifications`.

## Catatan deployment dan referensi

Vercel tidak digunakan untuk aplikasi utama karena aplikasi memerlukan PHP, PHP-FPM, dan MySQL. Deployment yang didukung adalah Nginx + PHP-FPM seperti aaPanel.

- [`config/config.example.php`](config/config.example.php)
- [`database/schema.sql`](database/schema.sql)
- [`database/products.js`](database/products.js)
- [`dev/mock-server.php`](dev/mock-server.php)
- [`deploy/nginx-oleena.conf`](deploy/nginx-oleena.conf)
- [`TAHAPAN-HOSTING.md`](TAHAPAN-HOSTING.md)
- [`LANGKAH-SELANJUTNYA.md`](LANGKAH-SELANJUTNYA.md)