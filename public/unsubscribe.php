<?php

declare(strict_types=1);

/**
 * public/unsubscribe.php
 *
 * 1-Click Unsubscribe (RFC 8058) - Ziel des List-Unsubscribe Headers.
 * Unterstuetzt sowohl GET (Klick im Footer-Link) als auch POST
 * (One-Click via List-Unsubscribe-Post Header, den Gmail/Yahoo automatisch senden).
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Bootstrap;

$app = Bootstrap::boot();
$consentManager = $app->consentManager();

$token = $_GET['token'] ?? $_POST['token'] ?? '';

if ($token === '') {
    http_response_code(400);
    echo 'Fehlender Unsubscribe-Token.';
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$success = $consentManager->optOut($token, $ip);

// One-Click POST (RFC 8058): nur Status-Code zurueckgeben, kein HTML.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code($success ? 200 : 404);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Abmeldung</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 480px; margin: 80px auto; text-align: center; color: #1a1a1a; }
        h1 { font-size: 1.4rem; }
        p { color: #555; }
    </style>
</head>
<body>
    <?php if ($success): ?>
        <h1>✅ Du wurdest erfolgreich abgemeldet.</h1>
        <p>Du erhaeltst ab sofort keine weiteren Newsletter mehr von uns.</p>
    <?php else: ?>
        <h1>⚠️ Abmeldung nicht moeglich</h1>
        <p>Der Link ist ungueltig oder bereits verwendet worden.</p>
    <?php endif; ?>
</body>
</html>
