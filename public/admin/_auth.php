<?php

declare(strict_types=1);

/**
 * public/admin/_auth.php
 * Session-Guard: an den Anfang jeder geschuetzten Admin-Seite includen.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Support\Bootstrap;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$app = Bootstrap::boot();

if (empty($_SESSION['admin_authenticated'])) {
    header('Location: /admin/login.php');
    exit;
}
