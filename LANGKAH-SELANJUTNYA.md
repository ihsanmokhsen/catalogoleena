# Langkah Selanjutnya – Oleena

Checklist pekerjaan setelah Fase 1–5 selesai. Kerjakan berurutan dan centang `[x]` bila sudah.
Detail teknis setiap langkah server ada di [deploy/README.md](deploy/README.md).

---

## 1. Coba dan rapikan di lokal (sekarang)

Jalankan aplikasi: MySQL (`brew services start mysql`), lalu dua server lewat `.claude/launch.json` atau:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8001 dev/mock-server.php
PHP_CLI_SERVER_WORKERS=4 php -d post_max_size=64M -d upload_max_filesize=8M -S localhost:8000 -t public
```

- [ ] Coba belanja dari awal sampai akhir di http://localhost:8000: tambah ke keranjang, cari tujuan, pilih kurir, buat pesanan, bayar di halaman simulasi.
- [ ] Coba juga hasil "Kedaluwarsa" dan "Batalkan" di halaman simulasi.
- [ ] Login ke http://localhost:8000/admin/ (akun lokal ada di komentar atas `database/seed_demo.php`).
- [ ] Di **Admin > Produk**, isi **berat asli** setiap produk dalam gram. Sekarang semua masih 250 g, padahal berat menentukan ongkir.
- [ ] Di **Admin > Produk**, isi **stok asli** setiap produk. Sekarang stok contoh masih 10. Stok berkurang otomatis saat pesanan dibayar, dan stok 0 berarti Sold out.
- [ ] Cek harga setiap produk.
- [ ] Coba alur admin: Dibayar → Dikemas → Dikirim (isi resi) → Selesai.
- [ ] Tentukan **kecamatan lokasi toko di Kupang**, untuk titik asal ongkir.
- [ ] Di **Admin > Pengaturan**, isi nomor WhatsApp admin, upload **QR pembayaran** (QRIS/QR bank), dan isi rekening bank. Lalu coba checkout: QR, total dengan kode unik, dan tombol konfirmasi WhatsApp harus muncul.
- [ ] Catat hal yang ingin diubah dari tampilan atau alur.

## 2. Bereskan Vercel

Situs lama `oleenagoods.vercel.app` sekarang rusak, karena PHP tidak jalan di Vercel.

- [ ] Login ke vercel.com > proyek **oleena.goods** > Deployments, lalu pilih salah satu:
  - **Rollback** ke deployment commit `2d009bf`, supaya katalog lama dengan checkout WhatsApp aktif lagi sampai server siap, **atau**
  - **Hapus proyek**, kalau situs Vercel tidak dipakai lagi.

## 3. Daftar akun layanan

Siapkan data usaha: KTP pemilik, nomor rekening bank atas nama pemilik atau usaha, nomor WhatsApp, dan alamat toko.

- [ ] **RajaOngkir Starter**: aktifkan akun dan simpan API key Starter secara rahasia. Paket Starter menggunakan kota/kabupaten.
- [ ] **Midtrans** (dashboard.midtrans.com): daftar, pakai mode **Sandbox** dulu, simpan Server Key `SB-Mid-server-…`.
- [ ] **Midtrans**: lengkapi data verifikasi bisnis untuk aktivasi Production. Prosesnya bisa beberapa hari, jadi mulai lebih awal.
- [ ] Simpan semua key di tempat aman, misalnya password manager. Jangan kirim key lewat chat atau taruh di repo.

## 4. Siapkan server aaPanel

Ikuti [deploy/README.md](deploy/README.md), bagian "Checklist pengaturan".

- [ ] DNS: record A `oleena` → IP server.
- [ ] Buat situs `oleena.hilmimokhsen.my.id` dengan Running directory `/public`, aktifkan SSL, lalu tempel konfigurasi Nginx.
- [ ] Buat database dan user `oleena`, lalu buat `config/config.php` dengan key **test** (`app.env` jangan diisi `'local'`).
- [ ] Jalankan `schema.sql` dan `seed_products.php`.
- [ ] Isi `shipping.origin_city_name` dengan `Kota Kupang, Nusa Tenggara Timur`, gunakan `shipping.origin_city_id` bila sudah diketahui, dan set `shipping.couriers` ke `jne,jnt,pos`.
- [ ] Buat akun admin server: `php bin/create-admin.php <nama>`, dengan password baru, **bukan** password lokal.
- [ ] Daftarkan Notification URL di Midtrans dan cron `bin/expire-orders.php` di aaPanel.
- [ ] Isi berat produk di `/admin/` server (data lokal tidak ikut terbawa).

## 5. Uji coba di server (mode test)

- [ ] Pastikan katalog tampil lengkap, termasuk font dan warna chip produk (memastikan CSP tidak memblokir apa pun).
- [ ] Cek ongkir ke beberapa tujuan: dalam Kota Kupang, Flores atau Sumba, Jawa, dan Papua. Pastikan JNE, J&T, dan POS muncul dan harganya wajar.
- [ ] Buat pesanan, lalu bayar lewat simulator Midtrans Sandbox (https://simulator.sandbox.midtrans.com).
- [ ] Pastikan status berubah menjadi **Dibayar** di situs dan di admin.
- [ ] Coba buka `https://oleena.hilmimokhsen.my.id/config/config.php`. Hasilnya harus 404 atau ditolak.

## 6. Go-live

- [ ] Midtrans disetujui Production: ganti ke Server Key Production, set `is_production => true`, lalu daftarkan ulang Notification URL di Production.
- [ ] RajaOngkir Starter: pastikan API key valid. Jika key pernah terekspos, lakukan rotate/regenerate lalu isi key baru hanya di `config/config.php` VPS.
- [ ] Lakukan 1 transaksi asli bernominal kecil, lalu refund dari dashboard Midtrans.
- [ ] Umumkan link baru di Instagram dan WhatsApp.

## 7. Rutinitas harian setelah live

- [ ] Saat pembeli mengirim bukti transfer di WhatsApp, cek **mutasi rekening/QRIS** (bukan screenshot saja): nominal harus sama persis termasuk 3 digit terakhir. Lalu buka pesanan di `/admin/` dan klik **Tandai sudah dibayar**.
- [ ] Proses pesanan berstatus **Dibayar**: kemas, lalu ubah ke **Dikemas**.
- [ ] Setelah paket diserahkan ke kurir, isi **nomor resi** dan ubah ke **Dikirim**.
- [ ] Tandai **Selesai** setelah paket diterima.
- [ ] Saat barang baru datang, tambah **stok** di Admin > Produk. Produk dengan stok 0 otomatis tampil Sold out.
- [ ] Bila pesanan bertanda **Stok kurang** (dua pembeli membayar barang terakhir bersamaan), hubungi pembeli untuk menawarkan pengganti atau refund.
- [ ] Awal bulan: buka **Admin > Laporan** dan unduh CSV bulan lalu untuk pembukuan.
- [ ] Refund dilakukan dari dashboard Midtrans. Membatalkan pesanan di admin tidak otomatis mengembalikan dana.

## 8. Ide pengembangan berikutnya (opsional)

- Notifikasi otomatis ke WhatsApp atau email admin saat ada pesanan dibayar.
- Membuat order pengiriman langsung dari admin lewat RajaOngkir, bila layanan dan endpoint yang dibutuhkan tersedia.
