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
        // RajaOngkir Starter/gratis. Isi key rahasia di config/config.php.
        'rajaongkir_key' => '',
        'base_url' => 'https://api.rajaongkir.com/starter',
        'origin_city_name' => 'Kota Kupang, Nusa Tenggara Timur',
        'origin_city_id' => '',
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
