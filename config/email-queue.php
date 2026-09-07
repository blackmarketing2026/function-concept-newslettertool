<?php

declare(strict_types=1);

// config/email-queue.php
// Alle Parameter fuer den Queue-Worker & Rate-Limiting.

return [
    // Versand-Konfiguration
    'batch_size' => (int) ($_ENV['QUEUE_BATCH_SIZE'] ?? 50),   // Emails pro Cronjob-Durchlauf
    'max_retries' => 3,                    // 3 Versuche bevor permanent failed
    'retry_delay' => 3600,                 // 1 Stunde warten vor Retry
    'max_concurrent_sending' => 1,         // Seriell (nicht parallel) - Shared Hosting sicher
    'send_timeout' => 30,                  // 30 Sekunden Timeout pro Email

    // Retry-Backoff Stufen (Sekunden), siehe Retry Logic in DEVELOPMENT_1.md
    'retry_backoff' => [1 => 0, 2 => 3600, 3 => 21600],

    // Rate Limiting (ISP-Grenzen beachten)
    'rate_limit' => [
        'per_minute' => (int) ($_ENV['RATE_LIMIT_PER_MINUTE'] ?? 50),
        'per_hour' => (int) ($_ENV['RATE_LIMIT_PER_HOUR'] ?? 1000),
        'per_day' => (int) ($_ENV['RATE_LIMIT_PER_DAY'] ?? 5000),
    ],

    // Engagement-basiertes Versenden: Reihenfolge Hot -> Warm -> Cold
    'engagement_tier_order' => [
        'hot' => 30,   // Letzte 30 Tage aktiv -> zuerst
        'warm' => 90,  // Letzte 90 Tage aktiv
        'cold' => 999, // Aelter -> zuletzt
    ],

    // Monitoring & Alerts
    'monitoring' => [
        'alert_threshold_failures' => (float) ($_ENV['ALERT_THRESHOLD_FAILURES'] ?? 0.1), // >10% Fehlerrate
        'check_interval' => 300, // Health Check alle 5 Minuten
        'alert_email' => $_ENV['ALERT_EMAIL'] ?? 'admin@example.com',
        'circuit_breaker_threshold' => 0.3, // bei >30% Fehlerrate abbrechen & monitoren
    ],

    // Lock-File fuer Cronjob (verhindert Doppel-Execution)
    'lock_file' => __DIR__ . '/../storage/queue-worker.lock',
    'lock_max_age_seconds' => 600, // Stale-Lock nach 10 Minuten ignorieren
];
