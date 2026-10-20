<?php
declare(strict_types=1);

// Cari Area ID Biteship untuk mengisi shipping.origin_area_id di config.
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

$data = biteship_request('GET', '/v1/maps/areas', ['countries' => 'ID', 'input' => $query, 'type' => 'single']);
foreach ($data['areas'] ?? [] as $area) {
    echo $area['id'] . "  " . $area['name'] . "\n";
}
