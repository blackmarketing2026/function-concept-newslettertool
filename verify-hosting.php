<?php

declare(strict_types=1);

/**
 * verify-hosting.php
 *
 * Prueft, ob das Shared-Hosting alle Anforderungen aus DEVELOPMENT_1.md erfuellt:
 * PHP-Version, benoetigte Extensions, Schreibrechte, DB-Verbindung, Cronjob-Faehigkeit.
 *
 * Nutzung: php verify-hosting.php
 */

$results = [];
$hasFailure = false;

function check(string $label, bool $passed, string $detail = ''): array
{
    return ['label' => $label, 'passed' => $passed, 'detail' => $detail];
}

// 1. PHP Version (8.2 minimum, 8.3 empfohlen)
$phpVersion = PHP_VERSION;
$passed = version_compare($phpVersion, '8.2.0', '>=');
$results[] = check('PHP Version >= 8.2', $passed, 'Installiert: ' . $phpVersion);
if (! $passed) {
    $hasFailure = true;
}

// 2. Benoetigte Extensions
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'curl'];
foreach ($requiredExtensions as $ext) {
    $loaded = extension_loaded($ext);
    $results[] = check("Extension: {$ext}", $loaded);
    if (! $loaded) {
        $hasFailure = true;
    }
}

// 3. Composer Dependencies installiert
$vendorExists = file_exists(__DIR__ . '/vendor/autoload.php');
$results[] = check('Composer Dependencies installiert (vendor/)', $vendorExists,
    $vendorExists ? '' : 'composer install ausfuehren');
if (! $vendorExists) {
    $hasFailure = true;
}

// 4. .env vorhanden
$envExists = file_exists(__DIR__ . '/.env');
$results[] = check('.env Datei vorhanden', $envExists,
    $envExists ? '' : '.env.example nach .env kopieren und ausfuellen');
if (! $envExists) {
    $hasFailure = true;
}

// 5. Schreibrechte fuer logs/ und storage/
foreach (['logs', 'storage'] as $dir) {
    $path = __DIR__ . '/' . $dir;
    $writable = is_dir($path) && is_writable($path);
    $results[] = check("Schreibrechte: {$dir}/", $writable,
        $writable ? '' : "chmod 755 {$dir}/ (oder 775, je nach Hoster) ausfuehren");
    if (! $writable) {
        $hasFailure = true;
    }
}

// 6. Datenbankverbindung (nur wenn .env vorhanden)
if ($envExists && $vendorExists) {
    require __DIR__ . '/vendor/autoload.php';
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();
        $config = require __DIR__ . '/config/database.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );
        $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        $results[] = check('Datenbankverbindung', true, 'Server-Version: ' . $version);

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $schemaOk = count($tables) >= 10;
        $results[] = check('Schema installiert (>=10 Tabellen)', $schemaOk,
            $schemaOk ? count($tables) . ' Tabellen gefunden' : 'php setup-db.php ausfuehren');
        if (! $schemaOk) {
            $hasFailure = true;
        }
    } catch (Throwable $e) {
        $results[] = check('Datenbankverbindung', false, $e->getMessage());
        $hasFailure = true;
    }
} else {
    $results[] = check('Datenbankverbindung', false, 'Uebersprungen (.env oder vendor/ fehlt)');
    $hasFailure = true;
}

// 7. Cronjob-Faehigkeit: pruefe ob CLI SAPI + php-Binary auffindbar
$isCli = PHP_SAPI === 'cli';
$results[] = check('Ausgefuehrt via CLI (fuer Cronjob-Test)', $isCli,
    $isCli ? 'php Binary: ' . PHP_BINARY : 'Fuer echten Cronjob-Test per SSH ausfuehren');

// 8. date.timezone gesetzt
$tz = ini_get('date.timezone');
$results[] = check('date.timezone konfiguriert', $tz !== '', 'Aktuell: ' . ($tz ?: 'nicht gesetzt'));

// ---- Ausgabe ----
$isCliOutput = PHP_SAPI === 'cli';
if (! $isCliOutput) {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "== Email Marketing System: Hosting-Verifikation ==\n\n";
foreach ($results as $r) {
    $icon = $r['passed'] ? '✅' : '❌';
    echo sprintf("%s %s%s\n", $icon, $r['label'], $r['detail'] ? ' — ' . $r['detail'] : '');
}

echo "\n";
if ($hasFailure) {
    echo "❌ Es gibt offene Punkte. Bitte oben markierte Fehler beheben und erneut pruefen.\n";
    exit(1);
}

echo "🟢 Alle Checks bestanden. Hosting ist bereit fuer den Produktivbetrieb.\n";
exit(0);
