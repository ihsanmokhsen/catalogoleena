# Langkah Selanjutnya – Oleena

Checklist pekerjaan setelah Fase 1–5 selesai. Kerjakan berurutan dan centang `[x]` bila sudah.
Detail teknis setiap langkah server ada di [deploy/README.md](deploy/README.md).

---

## 1. Coba dan rapikan di lokal (sekarang)

Jalankan aplikasi: MySQL (`brew services start mysql`), lalu dua server lewat `.claude/launch.json` atau:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8001 dev/mock-server.php
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000 -t public
```

- [ ] Coba belanja dari awal sampai akhir di http://localhost:8000: tambah ke keranjang, cari tujuan, pilih kurir, buat pesanan, bayar di halaman simulasi.
- [ ] Coba juga hasil "Kedaluwarsa" dan "Batalkan" di halaman simulasi.
- [ ] Login ke http://localhost:8000/admin/ (akun lokal ada di komentar atas `database/seed_demo.php`).
- [ ] Di **Admin > Produk**, isi **berat asli** setiap produk dalam gram. Sekarang semua masih 250 g, padahal berat menentukan ongkir.
- [ ] Di **Admin > Produk**, cek harga dan status Ready/Sold out.
- [ ] Coba alur admin: Dibayar → Dikemas → Dikirim (isi resi) → Selesai.
- [ ] Tentukan **kecamatan lokasi toko di Kupang**, untuk titik asal ongkir.
- [ ] Catat hal yang ingin diubah dari tampilan atau alur.

## 2. Bereskan Vercel

Situs lama `oleenagoods.vercel.app` sekarang rusak, karena PHP tidak jalan di Vercel.

- [ ] Login ke vercel.com > proyek **oleena.goods** > Deployments, lalu pilih salah satu:
  - **Rollback** ke deployment commit `2d009bf`, supaya katalog lama dengan checkout WhatsApp aktif lagi sampai server siap, **atau**
  - **Hapus proyek**, kalau situs Vercel tidak dipakai lagi.

## 3. Daftar akun layanan

Siapkan data usaha: KTP pemilik, nomor rekening bank atas nama pemilik atau usaha, nomor WhatsApp, dan alamat toko.

- [ ] **Biteship** (biteship.com): daftar, aktifkan Testing Mode, simpan API key `biteship_test.…`.
- [ ] **Midtrans** (dashboard.midtrans.com): daftar, pakai mode **Sandbox** dulu, simpan Server Key `SB-Mid-server-…`.
- [ ] **Midtrans**: lengkapi data verifikasi bisnis untuk aktivasi Production. Prosesnya bisa beberapa hari, jadi mulai lebih awal.
- [ ] Simpan semua key di tempat aman, misalnya password manager. Jangan kirim key lewat chat atau taruh di repo.

## 4. Siapkan server aaPanel

Ikuti [deploy/README.md](deploy/README.md), bagian "Checklist pengaturan".

- [ ] DNS: record A `oleena` → IP server.
- [ ] Buat situs `oleena.hilmimokhsen.my.id` dengan Running directory `/public`, aktifkan SSL, lalu tempel konfigurasi Nginx.
- [ ] Buat database dan user `oleena`, lalu buat `config/config.php` dengan key **test** (`app.env` jangan diisi `'local'`).
- [ ] Jalankan `schema.sql` dan `seed_products.php`.
- [ ] Jalankan `php bin/find-area.php "kecamatan toko"`, lalu isi `origin_area_id`.
- [ ] Buat akun admin server: `php bin/create-admin.php <nama>`, dengan password baru, **bukan** password lokal.
- [ ] Daftarkan Notification URL di Midtrans dan cron `bin/expire-orders.php` di aaPanel.
- [ ] Isi berat produk di `/admin/` server (data lokal tidak ikut terbawa).

## 5. Uji coba di server (mode test)

- [ ] Pastikan katalog tampil lengkap, termasuk font dan warna chip produk (memastikan CSP tidak memblokir apa pun).
- [ ] Cek ongkir ke beberapa tujuan: dalam Kota Kupang, Flores atau Sumba, Jawa, dan Papua. Pastikan JNE, J&T, dan Lion Parcel muncul dan harganya wajar.
- [ ] Buat pesanan, lalu bayar lewat simulator Midtrans Sandbox (https://simulator.sandbox.midtrans.com).
- [ ] Pastikan status berubah menjadi **Dibayar** di situs dan di admin.
- [ ] Coba buka `https://oleena.hilmimokhsen.my.id/config/config.php`. Hasilnya harus 404 atau ditolak.

## 6. Go-live

- [ ] Midtrans disetujui Production: ganti ke Server Key Production, set `is_production => true`, lalu daftarkan ulang Notification URL di Production.
- [ ] Biteship: matikan Testing Mode dan ganti ke key `biteship_live.…`. Isi saldo bila diperlukan.
- [ ] Lakukan 1 transaksi asli bernominal kecil, lalu refund dari dashboard Midtrans.
- [ ] Umumkan link baru di Instagram dan WhatsApp.

## 7. Rutinitas harian setelah live

- [ ] Buka `/admin/` dan proses pesanan berstatus **Dibayar**: kemas, lalu ubah ke **Dikemas**.
- [ ] Setelah paket diserahkan ke kurir, isi **nomor resi** dan ubah ke **Dikirim**.
- [ ] Tandai **Selesai** setelah paket diterima.
- [ ] Ubah produk yang habis menjadi **Sold out**.
- [ ] Refund dilakukan dari dashboard Midtrans. Membatalkan pesanan di admin tidak otomatis mengembalikan dana.

## 8. Ide pengembangan berikutnya (opsional)

- Stok berdasarkan jumlah, yang otomatis berkurang saat pesanan dibayar.
- Notifikasi otomatis ke WhatsApp atau email admin saat ada pesanan dibayar.
- Halaman "Lacak pesanan" untuk pembeli, berisi status dan nomor resi.
- Membuat order pengiriman langsung dari admin lewat Biteship, sehingga resi otomatis.
- Laporan penjualan bulanan di admin.
