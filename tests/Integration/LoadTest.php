<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Audit\AuditLogger;
use App\Email\ComplianceValidator;
use App\Queue\QueueWorker;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMailer;

/**
 * Phase 5 Load-Test (siehe DEVELOPMENT_1.md "Load Testing & Launch"):
 * fuegt 3.000 Emails in die Queue ein und misst Durchsatz/Speicherverbrauch.
 *
 * Laeuft NICHT automatisch in der normalen Testsuite (Gruppe "slow"), da es
 * mehrere Sekunden dauert. Gezielt ausfuehren mit:
 *   php vendor/bin/phpunit --group slow tests/Integration/LoadTest.php
 *
 * Benoetigt dieselben TEST_DB_* Variablen wie QueueWorkerTest.
 */
#[Group('slow')]
final class LoadTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $database = getenv('TEST_DB_DATABASE');
        if ($database === false || $database === '') {
            $this->markTestSkipped('TEST_DB_* Umgebungsvariablen nicht gesetzt - Load-Test uebersprungen.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: 3306),
            $database,
        );

        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['email_queue_log', 'email_events', 'email_queue', 'campaigns'] as $table) {
            $this->pdo->exec("TRUNCATE TABLE {$table}");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function test_processes_3000_emails_within_performance_budget(): void
    {
        $this->pdo->exec("INSERT INTO campaigns (name, status) VALUES ('Load Test', 'sending')");
        $campaignId = (int) $this->pdo->lastInsertId();

        $insertStart = microtime(true);
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare(
            "INSERT INTO email_queue (campaign_id, recipient_email, subject, body_html, tracking_id, status)
             VALUES (:cid, :email, 'Load Test', '<p>Hi <a href=\"https://x/unsubscribe\">unsubscribe</a></p>', :tid, 'pending')"
        );
        for ($i = 0; $i < 3000; $i++) {
            $stmt->execute(['cid' => $campaignId, 'email' => "load-{$i}@example.test", 'tid' => bin2hex(random_bytes(8))]);
        }
        $this->pdo->commit();
        $insertDuration = microtime(true) - $insertStart;

        $config = require __DIR__ . '/../../config/email-queue.php';
        $logger = new Logger('load-test');
        $logger->pushHandler(new NullHandler());
        $worker = new QueueWorker($this->pdo, new FakeMailer(), new ComplianceValidator(), new AuditLogger($this->pdo), $logger, $config);

        $totalSent = 0;
        $batches = 0;
        $memoryStart = memory_get_usage(true);
        $sendStart = microtime(true);

        do {
            $stats = $worker->processBatch(200); // groesseres Batch fuer den Lasttest
            $totalSent += $stats['sent'];
            $batches++;
        } while ($stats['sent'] + $stats['failed'] > 0 && $batches < 30);

        $sendDuration = microtime(true) - $sendStart;
        $memoryUsed = memory_get_usage(true) - $memoryStart;

        fwrite(STDOUT, sprintf(
            "\n[LoadTest] Insert 3000 rows: %.3fs | Process %d emails in %d batches: %.3fs (%.1f emails/sec) | Memory: %.1f MB\n",
            $insertDuration,
            $totalSent,
            $batches,
            $sendDuration,
            $sendDuration > 0 ? $totalSent / $sendDuration : 0,
            $memoryUsed / 1024 / 1024,
        ));

        $this->assertSame(3000, $totalSent);
        // Performance-Budget: darf auf einer normalen Dev-Maschine nicht laenger als 60s dauern
        // (Produktions-Cronjob verarbeitet nur batch_size=50 pro 5-Minuten-Fenster, also weit unkritischer).
        $this->assertLessThan(60.0, $sendDuration, 'Queue-Durchsatz zu langsam - Indizes pruefen.');
    }
}
