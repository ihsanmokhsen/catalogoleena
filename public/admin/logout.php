<?php
declare(strict_types=1);

require __DIR__ . '/../../src/admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/');
}
csrf_verify();
$_SESSION = [];
session_destroy();
redirect('/admin/login.php');
