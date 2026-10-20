<?php
declare(strict_types=1);

// Tandai pesanan yang lewat batas bayar sebagai kedaluwarsa.
// Jadwalkan di aaPanel > Cron, misalnya tiap 15 menit: php /www/wwwroot/oleena.hilmimokhsen.my.id/bin/expire-orders.php
// Bila Midtrans tetap mengirim pelunasan untuk pesanan ini, webhook akan mengubahnya menjadi "paid".

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

$count = db()->exec("UPDATE orders SET status = 'expired' WHERE status = 'pending_payment' AND expires_at < NOW()");
db()->exec('DELETE FROM api_cache WHERE expires_at < NOW()');
db()->exec('DELETE FROM api_rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY');

echo date('Y-m-d H:i:s') . " $count pesanan kedaluwarsa.\n";
