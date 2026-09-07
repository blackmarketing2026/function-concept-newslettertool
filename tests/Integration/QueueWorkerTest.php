<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Audit\AuditLogger;
use App\Email\ComplianceValidator;
use App\Queue\QueueWorker;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMailer;

/**
 * Integration-Test gegen eine echte MySQL/MariaDB Test-Datenbank.
 *
 * Benoetigt Umgebungsvariablen (z.B. in phpunit.xml.dist <php><env> oder CI-Secrets):
 *   TEST_DB_HOST, TEST_DB_PORT, TEST_DB_DATABASE, TEST_DB_USERNAME, TEST_DB_PASSWORD
 *
 * Ohne diese Variablen wird die Testklasse uebersprungen (markTestSkipped),
 * damit `composer test` auch ohne lokale Datenbank lauffaehig bleibt.
 *
 * Lokales Setup:
 *   mysql -u root -e "CREATE DATABASE email_marketing_test;"
 *   TEST_DB_DATABASE=email_marketing_test TEST_DB_USERNAME=root php vendor/bin/phpunit tests/Integration
 */
final class QueueWorkerTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $database = getenv('TEST_DB_DATABASE');
        if ($database === false || $database === '') {
            $this->markTestSkipped('TEST_DB_* Umgebungsvariablen nicht gesetzt - Integration-Test uebersprungen.');
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

        $this->resetSchema();
    }

    private function resetSchema(): void
    {
        $tables = ['email_queue_log', 'email_events', 'email_queue', 'campaigns', 'email_accounts', 'templates', 'suppression_list', 'audit_log', 'contacts'];
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->pdo->exec("TRUNCATE TABLE {$table}");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function makeWorker(FakeMailer $mailer): QueueWorker
    {
        $config = require __DIR__ . '/../../config/email-queue.php';
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        return new QueueWorker(
            $this->pdo,
            $mailer,
            new ComplianceValidator(),
            new AuditLogger($this->pdo),
            $logger,
            $config,
        );
    }

    private function createCampaign(): int
    {
        $this->pdo->exec("INSERT INTO campaigns (name, status) VALUES ('Test Campaign', 'sending')");

        return (int) $this->pdo->lastInsertId();
    }

    private function seedPendingEmails(int $campaignId, int $count): array
    {
        $emails = [];
        $stmt = $this->pdo->prepare(
            "INSERT INTO email_queue (campaign_id, recipient_email, subject, body_html, tracking_id, status)
             VALUES (:cid, :email, 'Test Subject', '<p>Hello <a href=\"https://x/unsubscribe\">unsubscribe</a></p>', :tid, 'pending')"
        );

        for ($i = 0; $i < $count; $i++) {
            $email = "fake-user-{$i}@example.test";
            $stmt->execute(['cid' => $campaignId, 'email' => $email, 'tid' => bin2hex(random_bytes(8))]);
            $emails[] = $email;
        }

        return $emails;
    }

    public function test_processes_100_fake_emails_successfully(): void
    {
        $campaignId = $this->createCampaign();
        $this->seedPendingEmails($campaignId, 100);

        $mailer = new FakeMailer();
        $worker = $this->makeWorker($mailer);

        $stats = $worker->processBatch(100);

        $this->assertSame(100, $stats['sent']);
        $this->assertSame(0, $stats['failed']);
        $this->assertCount(100, $mailer->sentMessages);

        $remainingPending = (int) $this->pdo->query("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'")->fetchColumn();
        $this->assertSame(0, $remainingPending);
    }

    public function test_respects_batch_size(): void
    {
        $campaignId = $this->createCampaign();
        $this->seedPendingEmails($campaignId, 100);

        $mailer = new FakeMailer();
        $worker = $this->makeWorker($mailer);

        $stats = $worker->processBatch(30);

        $this->assertSame(30, $stats['sent']);
        $remainingPending = (int) $this->pdo->query("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'")->fetchColumn();
        $this->assertSame(70, $remainingPending);
    }

    public function test_hard_bounce_suppresses_recipient_permanently(): void
    {
        $campaignId = $this->createCampaign();
        $emails = $this->seedPendingEmails($campaignId, 5);

        $mailer = new FakeMailer();
        $mailer->hardBounceEmails = [$emails[0]];
        $worker = $this->makeWorker($mailer);

        $stats = $worker->processBatch(5);

        $this->assertSame(4, $stats['sent']);
        $this->assertSame(1, $stats['failed']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM suppression_list WHERE email = :e AND reason = "hard_bounce"');
        $stmt->execute(['e' => $emails[0]]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $status = $this->pdo->prepare("SELECT status FROM email_queue WHERE recipient_email = :e");
        $status->execute(['e' => $emails[0]]);
        $this->assertSame('bounced', $status->fetchColumn());
    }

    public function test_soft_bounce_schedules_retry_with_backoff(): void
    {
        $campaignId = $this->createCampaign();
        $emails = $this->seedPendingEmails($campaignId, 3);

        $mailer = new FakeMailer();
        $mailer->softBounceEmails = [$emails[1]];
        $worker = $this->makeWorker($mailer);

        $worker->processBatch(3);

        $row = $this->pdo->prepare('SELECT status, attempt_count, next_retry FROM email_queue WHERE recipient_email = :e');
        $row->execute(['e' => $emails[1]]);
        $result = $row->fetch();

        $this->assertSame('failed', $result['status']);
        $this->assertSame(1, (int) $result['attempt_count']);
        $this->assertNotNull($result['next_retry']);
    }

    public function test_permanently_failed_after_max_attempts(): void
    {
        $campaignId = $this->createCampaign();
        $emails = $this->seedPendingEmails($campaignId, 1);

        $mailer = new FakeMailer();
        $mailer->softBounceEmails = $emails;
        $worker = $this->makeWorker($mailer);

        // 3 Versuche (max_attempts) durchspielen: send -> retry -> send -> retry -> send
        $worker->processBatch(1);
        $this->pdo->exec("UPDATE email_queue SET status = 'pending', next_retry = NULL");
        $worker->processBatch(1);
        $this->pdo->exec("UPDATE email_queue SET status = 'pending', next_retry = NULL");
        $worker->processBatch(1);

        $row = $this->pdo->prepare('SELECT status, attempt_count, max_attempts FROM email_queue WHERE recipient_email = :e');
        $row->execute(['e' => $emails[0]]);
        $result = $row->fetch();

        $this->assertSame('failed', $result['status']);
        $this->assertSame((int) $result['max_attempts'], (int) $result['attempt_count']);
    }

    public function test_suppressed_recipient_is_skipped_without_sending(): void
    {
        $campaignId = $this->createCampaign();
        $emails = $this->seedPendingEmails($campaignId, 1);

        $this->pdo->prepare('INSERT INTO suppression_list (email, reason) VALUES (:e, "complained")')->execute(['e' => $emails[0]]);

        $mailer = new FakeMailer();
        $worker = $this->makeWorker($mailer);

        $stats = $worker->processBatch(1);

        $this->assertSame(0, $stats['sent']);
        $this->assertCount(0, $mailer->sentMessages);
    }

    public function test_health_check_recovers_stuck_processing_emails(): void
    {
        $campaignId = $this->createCampaign();
        $this->seedPendingEmails($campaignId, 1);

        $this->pdo->exec("UPDATE email_queue SET status = 'processing', updated_at = DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        $worker = $this->makeWorker(new FakeMailer());
        $health = $worker->healthCheck();

        $this->assertSame(1, $health['processing_stuck']);

        $status = $this->pdo->query("SELECT status FROM email_queue LIMIT 1")->fetchColumn();
        $this->assertSame('pending', $status);
    }
}
