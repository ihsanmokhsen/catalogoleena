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
];
