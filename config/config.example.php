<?php
// Salin file ini menjadi config/config.php lalu isi nilainya.
// config.php tidak ikut ke git dan berada di luar webroot, jadi aman untuk menyimpan kredensial.

return [
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
        // Area ID lokasi toko. Cari dengan: php bin/find-area.php "nama kecamatan"
        'origin_area_id' => '',
        'couriers' => 'jne,jnt,sicepat,anteraja,pos,ninja,lion,sap,idexpress',
        // Tambahan berat kemasan per paket (gram).
        'packaging_weight_gram' => 100,
    ],
];
