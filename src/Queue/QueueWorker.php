<?php

declare(strict_types=1);

namespace App\Queue;

use App\Audit\AuditLogger;
use App\Email\ComplianceValidator;
use Monolog\Logger;
use PDO;

/**
 * Kern der Versand-Architektur (siehe DEVELOPMENT_1.md "Queue-basierter Email-Versand").
 *
 * Workflow pro Durchlauf:
 *   1. pending Emails lesen (Batch, Engagement-Tier-sortiert: hot -> warm -> cold)
 *   2. Status auf 'processing' setzen (verhindert Doppelversand bei Overlap)
 *   3. Rate-Limit pruefen (per_minute / per_hour / per_day)
 *   4. Versenden ueber Mailer
 *   5. Status aktualisieren (sent / failed / bounced)
 *   6. Retry-Logic: 3 Versuche mit Backoff (sofort, 1h, 6h)
 *   7. Circuit Breaker: bei >30% Fehlerrate im Batch abbrechen
 */
final class QueueWorker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MailerInterface $mailer,
        private readonly ComplianceValidator $complianceValidator,
        private readonly AuditLogger $auditLogger,
        private readonly Logger $logger,
        private readonly array $config, // config/email-queue.php
    ) {
    }

    /**
     * Hauptversand-Durchlauf. Wird vom Cronjob "send" alle 5 Minuten aufgerufen.
     *
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function processBatch(?int $batchSizeOverride = null): array
    {
        $batchSize = $batchSizeOverride ?? $this->config['batch_size'];

        $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        if (! $this->withinRateLimit()) {
            $this->logger->warning('Rate limit reached, skipping this run.');
            return $stats;
        }

        $rows = $this->fetchPendingBatch($batchSize);
        if ($rows === []) {
            $this->logger->info('No pending emails in queue.');
            return $stats;
        }

        $failuresInBatch = 0;

        foreach ($rows as $row) {
            if (! $this->markProcessing((int) $row['id'])) {
                // Ein anderer Worker-Prozess hat die Zeile bereits uebernommen.
                $stats['skipped']++;
                continue;
            }

            // Circuit Breaker: bei zu hoher Fehlerrate im laufenden Batch abbrechen
            $processedSoFar = $stats['sent'] + $stats['failed'];
            if ($processedSoFar >= 10 && ($failuresInBatch / max($processedSoFar, 1)) > $this->config['monitoring']['circuit_breaker_threshold']) {
                $this->logger->critical('Circuit breaker tripped - aborting batch due to high failure rate.', [
                    'failures' => $failuresInBatch,
                    'processed' => $processedSoFar,
                ]);
                $this->resetToPending((int) $row['id']);
                break;
            }

            if ($this->complianceValidator->isRecipientSuppressed($this->pdo, $row['recipient_email'])) {
                $this->markResult((int) $row['id'], 'failed', 'Recipient is on suppression list.');
                $stats['skipped']++;
                $this->logEvent((int) $row['id'], 'failed', ['reason' => 'suppressed']);
                continue;
            }

            $message = new MailerMessage(
                toEmail: $row['recipient_email'],
                fromEmail: $row['sender_email'] ?: 'newsletter@example.com',
                fromName: $row['sender_name'] ?? '',
                replyTo: $row['reply_to'] ?? null,
                subject: $row['subject'],
                bodyHtml: (string) $row['body_html'],
                bodyText: (string) ($row['body_text'] ?? ''),
                trackingId: $row['tracking_id'],
                unsubscribeToken: $row['unsubscribe_token'] ?? $row['tracking_id'],
            );

            $result = $this->mailer->send($message);

            if ($result->success) {
                $this->markResult((int) $row['id'], 'sent', null);
                $this->logEvent((int) $row['id'], 'sent');
                $stats['sent']++;
                continue;
            }

            $failuresInBatch++;
            $stats['failed']++;

            if ($result->bounceType === 'hard') {
                $this->markResult((int) $row['id'], 'bounced', $result->errorMessage);
                $this->logEvent((int) $row['id'], 'bounced', ['error' => $result->errorMessage]);
                $this->suppressEmail($row['recipient_email'], 'hard_bounce', $result->errorMessage ?? '');
                continue;
            }

            $this->handleRetryOrFail((int) $row['id'], (int) $row['attempt_count'], (int) $row['max_attempts'], $result->errorMessage ?? 'Unknown error');
        }

        $this->logger->info('Batch complete.', $stats);

        if ($stats['failed'] > 0 && ($stats['failed'] / max($stats['sent'] + $stats['failed'], 1)) > $this->config['monitoring']['alert_threshold_failures']) {
            $this->auditLogger->log('system', 'queue.high_failure_rate', 'email_queue', null, $stats);
        }

        return $stats;
    }

    /**
     * Retry-Durchlauf: sucht Emails mit status='failed', attempt_count < max_attempts
     * und next_retry <= now(), setzt sie zurueck auf 'pending'.
     * Wird vom Cronjob "retry" alle 30 Minuten aufgerufen.
     */
    public function processRetries(): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE email_queue
             SET status = 'pending'
             WHERE status = 'failed'
               AND attempt_count < max_attempts
               AND (next_retry IS NULL OR next_retry <= NOW())"
        );
        $stmt->execute();
        $count = $stmt->rowCount();

        $this->logger->info('Retry sweep complete.', ['requeued' => $count]);

        return $count;
    }

    /**
     * Health-Check fuer Cronjob #5 (stuendlich): prueft Queue-Stau und Fehlerraten.
     *
     * @return array{pending: int, processing_stuck: int, failed_permanent: int, healthy: bool}
     */
    public function healthCheck(): array
    {
        $pending = (int) $this->pdo->query("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'")->fetchColumn();

        // "processing" laenger als 30 Minuten = vermutlich abgebrochener Worker
        $stuck = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM email_queue WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        )->fetchColumn();

        $permanentFailed = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM email_queue WHERE status = 'failed' AND attempt_count >= max_attempts"
        )->fetchColumn();

        // Stuck Emails automatisch zuruecksetzen (Graceful Recovery nach abgebrochenem Cronjob)
        if ($stuck > 0) {
            $this->pdo->exec(
                "UPDATE email_queue SET status = 'pending' WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            );
            $this->logger->warning('Recovered stuck processing emails.', ['count' => $stuck]);
        }

        $healthy = $pending < 10000 && $permanentFailed < 500;

        return [
            'pending' => $pending,
            'processing_stuck' => $stuck,
            'failed_permanent' => $permanentFailed,
            'healthy' => $healthy,
        ];
    }

    /**
     * Loescht alte Log-Eintraege. Wird vom Cronjob "cleanup" woechentlich aufgerufen.
     */
    public function cleanup(int $days = 30): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM email_queue_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
        $stmt->execute(['days' => $days]);

        return $stmt->rowCount();
    }

    // -----------------------------------------------------------------
    // Interne Helfer
    // -----------------------------------------------------------------

    private function fetchPendingBatch(int $batchSize): array
    {
        // Engagement-Tier-Sortierung: hot zuerst, dann warm, dann cold (siehe engagement_tier_order)
        $stmt = $this->pdo->prepare(
            "SELECT q.*, ea.from_name AS sender_name, ea.reply_to AS reply_to, c.unsubscribe_token AS unsubscribe_token
             FROM email_queue q
             LEFT JOIN campaigns cam ON cam.id = q.campaign_id
             LEFT JOIN email_accounts ea ON ea.id = cam.email_account_id
             LEFT JOIN contacts c ON c.id = q.contact_id
             WHERE q.status = 'pending'
             ORDER BY FIELD(q.engagement_tier, 'hot', 'warm', 'cold'), q.created_at ASC
             LIMIT :limit"
        );
        $stmt->bindValue('limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function markProcessing(int $queueId): bool
    {
        // Atomarer UPDATE mit WHERE status='pending' verhindert Race Conditions
        // zwischen mehreren Worker-Instanzen (z.B. bei ueberlappenden Cronjobs).
        $stmt = $this->pdo->prepare("UPDATE email_queue SET status = 'processing', attempt_count = attempt_count + 1 WHERE id = :id AND status = 'pending'");
        $stmt->execute(['id' => $queueId]);

        if ($stmt->rowCount() === 1) {
            $this->logEvent($queueId, 'started');
            return true;
        }

        return false;
    }

    private function resetToPending(int $queueId): void
    {
        $stmt = $this->pdo->prepare("UPDATE email_queue SET status = 'pending' WHERE id = :id");
        $stmt->execute(['id' => $queueId]);
    }

    private function markResult(int $queueId, string $status, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE email_queue SET status = :status, sent_at = IF(:status = "sent", NOW(), sent_at), error_message = :error WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'error' => $error, 'id' => $queueId]);
    }

    private function handleRetryOrFail(int $queueId, int $attemptCount, int $maxAttempts, string $errorMessage): void
    {
        $backoff = $this->config['retry_backoff'];

        if ($attemptCount >= $maxAttempts) {
            $this->markResult($queueId, 'failed', $errorMessage);
            $this->logEvent($queueId, 'failed', ['error' => $errorMessage, 'final' => true]);
            return;
        }

        $delaySeconds = $backoff[$attemptCount + 1] ?? $this->config['retry_delay'];

        $stmt = $this->pdo->prepare(
            "UPDATE email_queue
             SET status = 'failed', error_message = :error, next_retry = DATE_ADD(NOW(), INTERVAL :delay SECOND)
             WHERE id = :id"
        );
        $stmt->execute(['error' => $errorMessage, 'delay' => $delaySeconds, 'id' => $queueId]);

        $this->logEvent($queueId, 'retrying', ['error' => $errorMessage, 'next_attempt_in_seconds' => $delaySeconds]);
    }

    private function suppressEmail(string $email, string $reason, string $details): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO suppression_list (email, reason, details) VALUES (:email, :reason, :details)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), details = VALUES(details)'
        );
        $stmt->execute(['email' => $email, 'reason' => $reason, 'details' => $details]);

        $updateContact = $this->pdo->prepare("UPDATE contacts SET status = 'bounced' WHERE email = :email");
        $updateContact->execute(['email' => $email]);

        $this->auditLogger->log('system', 'contact.suppressed', 'contact', null, ['email' => $email, 'reason' => $reason]);
    }

    private function logEvent(int $queueId, string $event, array $details = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_queue_log (queue_id, event, details) VALUES (:queue_id, :event, :details)'
        );
        $stmt->execute([
            'queue_id' => $queueId,
            'event' => $event,
            'details' => $details === [] ? null : json_encode($details),
        ]);
    }

    private function withinRateLimit(): bool
    {
        $perMinute = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        )->fetchColumn();

        if ($perMinute >= $this->config['rate_limit']['per_minute']) {
            return false;
        }

        $perHour = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        )->fetchColumn();

        if ($perHour >= $this->config['rate_limit']['per_hour']) {
            return false;
        }

        $perDay = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )->fetchColumn();

        return $perDay < $this->config['rate_limit']['per_day'];
    }
}
