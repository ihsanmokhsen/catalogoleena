# Tahapan Hosting – Oleena

Panduan ringkas memasang Oleena di server aaPanel. Kerjakan berurutan dan centang `[x]` bila sudah.
Penjelasan lebih lengkap ada di [deploy/README.md](deploy/README.md).

- Domain: `oleena.hilmimokhsen.my.id`
- Stack: Nginx + PHP 8.3 + MySQL/MariaDB
- Repo: https://github.com/ihsanmokhsen/catalogoleena

---

## 0. Persiapan

- [ ] **RajaOngkir (Komerce)**: daftar di collaborator.komerce.id lalu salin API key Shipping Cost. Ongkir dihitung sampai kelurahan/kecamatan.
- [ ] **Midtrans** boleh dilewati. Mode default adalah **QR / transfer + konfirmasi WhatsApp**.
- [ ] Bila repo GitHub **private**, siapkan akses `git clone` dari server (deploy key atau token).

## 1. DNS

- [ ] Tambahkan record **A** `oleena` di zona `hilmimokhsen.my.id` yang mengarah ke IP server.

## 2. Ambil kode di server

- [ ] Clone repo:
  ```bash
  cd /www/wwwroot && git clone https://github.com/ihsanmokhsen/catalogoleena.git oleena.hilmimokhsen.my.id
  ```

## 3. Buat situs di aaPanel

- [ ] Website > Add site `oleena.hilmimokhsen.my.id`, pilih PHP 8.3.
- [ ] **Site directory > Running directory: `/public`**. Ini penting supaya `config/` dan `src/` tidak bisa dibuka dari browser.
- [ ] **Matikan `open_basedir` / 防跨站攻击** pada pengaturan situs. Jika tidak dimatikan, tambahkan root project ke daftar allowed path: `/www/wwwroot/oleena.hilmimokhsen.my.id/:/tmp/` (bukan hanya `/public/`). Endpoint PHP di `public/` perlu memuat kode dari `src/` dan konfigurasi dari `config/`.
- [ ] SSL: Let's Encrypt, lalu aktifkan **Force HTTPS**.
- [ ] Config: tempel isi [deploy/nginx-oleena.conf](deploy/nginx-oleena.conf), lalu sesuaikan `fastcgi_pass` dengan socket PHP 8.3 di server.

## 4. Pengaturan PHP 8.3

- [ ] Aktifkan ekstensi: `pdo_mysql`, `curl`, `mbstring`, `gd` (dukungan JPEG/WebP), `fileinfo`, `exif`.
- [ ] Atur nilai: `expose_php = Off`, `upload_max_filesize = 8M`, `post_max_size = 64M`, `max_file_uploads = 20`, `memory_limit = 256M`.
- [ ] Buat folder upload yang bisa ditulis PHP:
  ```bash
  mkdir -p /www/wwwroot/oleena.hilmimokhsen.my.id/public/uploads/products /www/wwwroot/oleena.hilmimokhsen.my.id/public/uploads/settings
  chown -R www:www /www/wwwroot/oleena.hilmimokhsen.my.id/public/uploads
  ```
- [ ] Setelah mengubah `open_basedir`, restart/reload PHP-FPM lalu pastikan `curl -i https://oleena.hilmimokhsen.my.id/api/products.php` mengembalikan JSON, bukan warning `open_basedir restriction`.

## 5. Database dan konfigurasi

- [ ] aaPanel > Databases: buat database `oleena` dengan user `oleena` dan password acak yang kuat (jangan pakai root).
- [ ] Buat file konfigurasi:
  ```bash
  cd /www/wwwroot/oleena.hilmimokhsen.my.id
  cp config/config.example.php config/config.php
  chmod 640 config/config.php
  chown root:www config/config.php
  ```
- [ ] Isi `config/config.php`: `db.pass`, `shipping.rajaongkir_key`, `shipping.api_url` = `https://rajaongkir.komerce.id/api/v1`, dan `shipping.couriers` = `jne,jnt,pos`. **Jangan** isi `app.env` dengan `'local'`.
- [ ] Buat tabel dan impor produk:
  ```bash
  mysql -u oleena -p oleena < database/schema.sql
  php database/seed_products.php
  ```
- [ ] Cari ID lokasi toko dengan `php bin/find-area.php "kecamatan toko"`, lalu isi ke `shipping.origin_id`.
- [ ] Buat akun admin. Pakai password baru minimal 12 karakter, **bukan** password lokal:
  ```bash
  php bin/create-admin.php oleena
  ```

## 6. Cron

- [ ] aaPanel > Cron > Add, tipe Shell Script, jalan **tiap 15 menit**:
  ```bash
  php /www/wwwroot/oleena.hilmimokhsen.my.id/bin/expire-orders.php
  ```

## 7. Isi data di `/admin/`

- [ ] **Pengaturan**: nomor WhatsApp admin, upload QR pembayaran (QRIS/QR bank), dan rekening bank.
- [ ] **Produk**: isi **berat asli** (gram) dan **stok asli** tiap produk. Data lokal tidak ikut ke server, dan foto produk juga di-upload ulang di sini.

## 8. Uji coba

- [ ] Katalog tampil lengkap, termasuk font dan warna (CSP tidak memblokir apa pun).
- [ ] Ongkir ke Kupang, Flores/Sumba, Jawa, dan Papua muncul, dengan JNE, J&T, dan POS.
- [ ] Checkout menampilkan QR, total dengan kode unik, dan tombol konfirmasi WhatsApp.
- [ ] Di admin, **Tandai sudah dibayar** mengubah status dan mengurangi stok.
- [ ] Buka `https://oleena.hilmimokhsen.my.id/config/config.php`. Hasilnya harus 404 atau ditolak.

## 9. Go-live

- [ ] RajaOngkir (Komerce): pastikan API key valid. Jika key pernah terekspos, lakukan rotate/regenerate dan isi key baru hanya di `config/config.php` VPS.
- [ ] Backup aaPanel (Cron > Backup Site & Database) mencakup database `oleena` dan folder `public/uploads/`. Folder uploads tidak ikut git.
- [ ] Vercel: rollback atau hapus proyek lama (situs `oleenagoods.vercel.app` sekarang rusak).
- [ ] Umumkan link baru di Instagram dan WhatsApp.

---

## Update aplikasi nanti

```bash
cd /www/wwwroot/oleena.hilmimokhsen.my.id
git pull
mysql -u oleena -p oleena < database/schema.sql   # aman diulang, tabel yang ada tidak ditimpa
```
