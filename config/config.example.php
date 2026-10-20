<?php
// Salin file ini menjadi config/config.php lalu isi nilainya.
// config.php tidak ikut ke git dan berada di luar webroot, jadi aman untuk menyimpan kredensial.

return [
    'app' => [
        // URL publik situs, dipakai untuk link kembali setelah pembayaran.
        'url' => 'https://oleena.hilmimokhsen.my.id',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'oleena',
        'user' => 'oleena',
        'pass' => 'ganti-dengan-password-kuat',
    ],
    'shipping' => [
        // Pakai key biteship_test.xxx saat uji coba, ganti biteship_live.xxx saat produksi.
        'biteship_key' => '',
        'base_url' => 'https://api.biteship.com',
        // Area ID lokasi toko di Kupang, NTT. Cari dengan: php bin/find-area.php "nama kecamatan"
        'origin_area_id' => '',
        // Kurir yang dipakai toko: JNE, J&T Express, Lion Parcel (kode Biteship).
        'couriers' => 'jne,jnt,lion',
        // Tambahan berat kemasan per paket (gram).
        'packaging_weight_gram' => 100,
    ],
    'midtrans' => [
        // Dashboard Midtrans > Settings > Access Keys. Server key bersifat RAHASIA.
        'server_key' => '',
        // false = Sandbox (uji coba), true = Production (uang sungguhan).
        'is_production' => false,
    ],
];
