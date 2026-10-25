<?php
declare(strict_types=1);

// Cari ID lokasi RajaOngkir untuk mengisi shipping.origin_id di config.
// Pakai: php bin/find-area.php "Oebobo"

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/shipping.php';

$query = trim($argv[1] ?? '');
if (mb_strlen($query) < 3) {
    fwrite(STDERR, "Pakai: php bin/find-area.php \"nama kecamatan\"\n");
    exit(1);
}

foreach (shipping_search_areas($query) as $area) {
    echo $area['id'] . "  " . $area['name'] . "\n";
}
