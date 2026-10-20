<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/payments.php';

api_boot(['GET']);
rate_limit('order-status', 60, 60);

try {
    $order = order_authorize($_GET['code'] ?? null, $_GET['token'] ?? null);
} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage()], 404, ['Cache-Control' => 'no-store']);
}

json_response(['order' => order_public((int) $order['id'])], 200, ['Cache-Control' => 'no-store']);
