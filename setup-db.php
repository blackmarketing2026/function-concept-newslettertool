<?php

declare(strict_types=1);

/**
 * setup-db.php
 *
 * Einmaliges Setup-Skript fuer die Datenbank auf dem Hosting.
 * Liest database/schema.sql ein und fuehrt es gegen die in .env
 * konfigurierte Datenbank aus. Idempotent (CREATE TABLE IF NOT EXISTS).
 *
 * Nutzung (SSH):
 *   php setup-db.php
 *
 * Nutzung (Browser, falls kein SSH verfuegbar):
 *   https://example.com/setup-db.php?confirm=1
 *   -> Datei danach unbedingt loeschen oder umbenennen!
 */

require __DIR__ . '/vendor/autoload.php';

$isCli = PHP_SAPI === 'cli';

if (! $isCli) {
    // Browser-Schutz: erfordert expliziten Confirm-Parameter
    if (($_GET['confirm'] ?? '') !== '1') {
        http_response_code(403);
        echo "Sicherheitscheck: Rufe diese Datei mit ?confirm=1 auf, um das DB-Setup auszufuehren.\n";
        echo "WICHTIG: Loesche setup-db.php danach vom Server!";
        exit(1);
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function out(string $message): void
{
    echo $message . (PHP_SAPI === 'cli' ? PHP_EOL : "\n");
}

try {
    if (! file_exists(__DIR__ . '/.env')) {
        throw new RuntimeException('.env Datei fehlt. Bitte .env.example kopieren und ausfuellen.');
    }

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $config = require __DIR__ . '/config/database.php';

    out('== Email Marketing System: Datenbank-Setup ==');
    out(sprintf('Verbinde zu %s:%d/%s ...', $config['host'], $config['port'], $config['database']));

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
    out('Verbindung erfolgreich.');

    $schemaPath = __DIR__ . '/database/schema.sql';
    if (! file_exists($schemaPath)) {
        throw new RuntimeException('database/schema.sql nicht gefunden.');
    }

    $sql = file_get_contents($schemaPath);
    if ($sql === false) {
        throw new RuntimeException('Schema-Datei konnte nicht gelesen werden.');
    }

    // Statements splitten (einfacher Split an Semikolon-Zeilenenden; Schema enthaelt kein
    // verschachteltes ';' innerhalb von Strings/JSON-Defaults).
    $statements = array_filter(array_map('trim', explode(";\n", $sql)));

    out(sprintf('Fuehre %d SQL-Statements aus ...', count($statements)));

    $pdo->beginTransaction();
    $executed = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        $pdo->exec($statement);
        $executed++;
    }
    $pdo->commit();

    out(sprintf('Fertig. %d Statements ausgefuehrt.', $executed));

    // Verifikation: Tabellen zaehlen
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    out(sprintf('Tabellen in Datenbank: %d', count($tables)));
    foreach ($tables as $table) {
        out(' - ' . $table);
    }

    out('');
    out('✅ Datenbank-Setup erfolgreich abgeschlossen.');
    out('Naechster Schritt: php verify-hosting.php ausfuehren.');
} catch (Throwable $e) {
    out('❌ FEHLER: ' . $e->getMessage());
    exit(1);
}
