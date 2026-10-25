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
        // RajaOngkir Komerce (https://collaborator.komerce.id). Isi key rahasia di config/config.php.
        'rajaongkir_key' => '',
        'api_url' => 'https://rajaongkir.komerce.id/api/v1',
        // ID lokasi toko di Kupang. Cari dengan: php bin/find-area.php "nama kecamatan"
        // Bila kosong, dicari otomatis dari origin_name (hasil pertama).
        'origin_id' => '',
        'origin_name' => 'Kelapa Lima Kupang',
        // Kode kurir RajaOngkir, misalnya: jne,jnt,pos.
        'couriers' => 'jne,jnt,pos',
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
