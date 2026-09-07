#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cli/queue-worker.php
 *
 * Entry Point fuer alle 5 Cronjobs aus DEVELOPMENT_1.md:
 *
 *   php cli/queue-worker.php send            [--batch=50]   (alle 5 Minuten)
 *   php cli/queue-worker.php retry                          (alle 30 Minuten)
 *   php cli/queue-worker.php bounce-handler                 (taeglich 2 Uhr)
 *   php cli/queue-worker.php cleanup         [--days=30]    (woechentlich)
 *   php cli/queue-worker.php health-check                   (stuendlich)
 *
 * Lokaler Test (simuliert Cronjob-Timing):
 *   time php cli/queue-worker.php send
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Bootstrap;

$app = Bootstrap::boot();
$logger = $app->logger();

$command = $argv[1] ?? null;
$options = parseOptions(array_slice($argv, 2));

if ($command === null) {
    fwrite(STDERR, "Usage: php cli/queue-worker.php <send|retry|bounce-handler|cleanup|health-check> [--option=value]\n");
    exit(1);
}

// -------------------------------------------------------------------
// Lock-File: verhindert dass zwei Cronjob-Durchlaeufe ueberlappen
// (z.B. wenn ein Versand-Durchlauf laenger als 5 Minuten dauert).
// -------------------------------------------------------------------
$queueConfig = $app->queueConfig();
$lockFile = $queueConfig['lock_file'];
$lockMaxAge = $queueConfig['lock_max_age_seconds'];

if ($command === 'send') {
    if (file_exists($lockFile)) {
        $age = time() - filemtime($lockFile);
        if ($age < $lockMaxAge) {
            $logger->warning('Queue worker already running (lock file present), skipping.', ['age_seconds' => $age]);
            echo "Skipped: another instance is running (lock age: {$age}s).\n";
            exit(0);
        }
        $logger->warning('Stale lock file detected, removing.', ['age_seconds' => $age]);
        unlink($lockFile);
    }

    $lockDir = dirname($lockFile);
    if (! is_dir($lockDir)) {
        mkdir($lockDir, 0755, true);
    }
    file_put_contents($lockFile, (string) getmypid());
    register_shutdown_function(static function () use ($lockFile): void {
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    });
}

try {
    $worker = $app->queueWorker();

    switch ($command) {
        case 'send':
            $batchSize = isset($options['batch']) ? (int) $options['batch'] : null;
            $stats = $worker->processBatch($batchSize);
            echo sprintf(
                "[send] sent=%d failed=%d skipped=%d\n",
                $stats['sent'],
                $stats['failed'],
                $stats['skipped']
            );
            break;

        case 'retry':
            $count = $worker->processRetries();
            echo "[retry] requeued={$count}\n";
            break;

        case 'bounce-handler':
            $bounceHandler = $app->bounceHandler();
            $count = $bounceHandler->syncFromQueue(24);
            echo "[bounce-handler] processed={$count}\n";
            break;

        case 'cleanup':
            $days = isset($options['days']) ? (int) $options['days'] : 30;
            $deleted = $worker->cleanup($days);
            $auditPurged = $app->auditLogger()->purgeExpired(
                $app->appConfig()['compliance']['audit_log_retention_months']
            );
            echo "[cleanup] queue_logs_deleted={$deleted} audit_log_purged={$auditPurged}\n";
            break;

        case 'health-check':
            $health = $worker->healthCheck();
            echo sprintf(
                "[health-check] pending=%d stuck_recovered=%d permanent_failed=%d status=%s\n",
                $health['pending'],
                $health['processing_stuck'],
                $health['failed_permanent'],
                $health['healthy'] ? 'OK' : 'ALERT'
            );

            if (! $health['healthy']) {
                notifyAdmin($app, $health);
            }
            break;

        default:
            fwrite(STDERR, "Unknown command: {$command}\n");
            fwrite(STDERR, "Available: send, retry, bounce-handler, cleanup, health-check\n");
            exit(1);
    }
} catch (Throwable $e) {
    $logger->error('Queue worker command failed.', ['command' => $command, 'error' => $e->getMessage()]);
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}

exit(0);

/**
 * @return array<string, string>
 */
function parseOptions(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $options[$key] = $value;
        }
    }

    return $options;
}

function notifyAdmin(Bootstrap $app, array $health): void
{
    $alertEmail = $app->queueConfig()['monitoring']['alert_email'];
    if ($alertEmail === '') {
        return;
    }

    $subject = '[ALERT] Email Queue Health Check fehlgeschlagen';
    $body = sprintf(
        "Health-Check meldet ein Problem:\n\nPending: %d\nStuck (recovered): %d\nPermanent Failed: %d\n",
        $health['pending'],
        $health['processing_stuck'],
        $health['failed_permanent']
    );

    // Einfacher mail() Fallback fuer Admin-Alerts (kein Queue-Umweg noetig,
    // da dies selten und niedrig-volumig ist).
    @mail($alertEmail, $subject, $body);

    $app->auditLogger()->log('system', 'health_check.alert', null, null, $health);
}
