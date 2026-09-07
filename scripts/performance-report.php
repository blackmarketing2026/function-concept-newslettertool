#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/performance-report.php
 *
 * Phase 5: liest reale Produktionsdaten aus und erzeugt einen
 * Performance-Report (durchschnittliche Versandzeit, Queue-Durchsatz,
 * DB Query Performance, Memory Usage).
 *
 * Nutzung: php scripts/performance-report.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Bootstrap;

$app = Bootstrap::boot();
$pdo = $app->pdo();

echo "== Email Marketing System: Performance Report ==\n";
echo 'Erstellt: ' . date(DATE_ATOM) . "\n\n";

// 1. Durchschnittliche Versandzeit pro Email (aus email_queue_log Zeitstempeln)
$avgTimeQuery = $pdo->query(
    "SELECT
        AVG(TIMESTAMPDIFF(MICROSECOND, started.created_at, sent.created_at)) / 1000000 AS avg_seconds,
        COUNT(*) AS sample_size
     FROM email_queue_log started
     JOIN email_queue_log sent ON sent.queue_id = started.queue_id AND sent.event = 'sent'
     WHERE started.event = 'started'
       AND started.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
)->fetch();

printf(
    "Durchschnittliche Versandzeit pro Email: %.3fs (Stichprobe: %d Emails, letzte 7 Tage)\n\n",
    (float) ($avgTimeQuery['avg_seconds'] ?? 0),
    (int) ($avgTimeQuery['sample_size'] ?? 0)
);

// 2. Queue-Durchsatz-Statistik (Emails pro Stunde, letzte 24h)
echo "Queue-Durchsatz (letzte 24h, pro Stunde):\n";
$throughput = $pdo->query(
    "SELECT DATE_FORMAT(sent_at, '%H:00') AS hour, COUNT(*) AS sent_count
     FROM email_queue
     WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
     GROUP BY hour ORDER BY hour"
)->fetchAll();

if ($throughput === []) {
    echo "  (keine Daten - noch kein Versand in den letzten 24h)\n";
} else {
    foreach ($throughput as $row) {
        printf("  %s Uhr: %d Emails %s\n", $row['hour'], $row['sent_count'], str_repeat('█', min(50, (int) $row['sent_count'])));
    }
}
echo "\n";

// 3. Datenbank Query Performance (EXPLAIN auf kritische Queries)
echo "Kritische Query-Performance (EXPLAIN):\n";
$explainQueries = [
    'Pending-Queue Abruf' => "EXPLAIN SELECT * FROM email_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT 50",
    'Retry-Kandidaten' => "EXPLAIN SELECT * FROM email_queue WHERE status = 'failed' AND next_retry <= NOW()",
    'Suppression-Check' => "EXPLAIN SELECT 1 FROM suppression_list WHERE email = 'test@example.com'",
];

foreach ($explainQueries as $label => $sql) {
    $plan = $pdo->query($sql)->fetch();
    printf(
        "  %-25s type=%-8s key=%-20s rows=%s\n",
        $label,
        $plan['type'] ?? 'n/a',
        $plan['key'] ?? 'KEIN INDEX!',
        $plan['rows'] ?? '?'
    );
}
echo "\n";

// 4. Aktuelle Queue-Groesse & Fehlerrate
$summary = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'pending') AS pending,
        SUM(status = 'sent') AS sent,
        SUM(status = 'failed') AS failed,
        SUM(status = 'bounced') AS bounced
     FROM email_queue"
)->fetch();

$errorRate = ((int) $summary['total']) > 0
    ? (((int) $summary['failed'] + (int) $summary['bounced']) / (int) $summary['total']) * 100
    : 0;

printf("Queue-Gesamtstatus: %d Eintraege | Pending: %d | Sent: %d | Failed: %d | Bounced: %d\n", ...array_values($summary));
printf("Fehlerrate: %.2f%%\n\n", $errorRate);

// 5. Memory Usage (dieser Prozess)
printf("Memory Usage (dieser Report-Lauf): %.2f MB (Peak: %.2f MB)\n",
    memory_get_usage(true) / 1024 / 1024,
    memory_get_peak_usage(true) / 1024 / 1024
);

echo "\n== Report Ende ==\n";
