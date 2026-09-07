<?php

declare(strict_types=1);

namespace App\Email;

use App\Audit\AuditLogger;
use App\Tags\TagManager;
use PDO;

/**
 * Bounce & Complaint Management (siehe DEVELOPMENT_1.md).
 * Wird vom Cronjob "bounce-handler" (taeglich 2 Uhr) aufgerufen und kann
 * zusaetzlich von einem SMTP-Feedback-Loop-Webhook (z.B. SendGrid/Mailgun)
 * synchron aufgerufen werden.
 */
final class BounceHandler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TagManager $tagManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function handleSoftBounce(string $email, string $reason): void
    {
        $contactId = $this->contactIdForEmail($email);
        if ($contactId !== null) {
            $this->tagManager->addTagByName($contactId, 'status:soft-bounce', 'automation', $reason);
        }

        $this->auditLogger->log('system', 'bounce.soft', 'contact', $contactId, ['email' => $email, 'reason' => $reason]);
        // Soft bounces werden ueber die normale Retry-Logic im QueueWorker behandelt
        // (Attempt 1 sofort, Attempt 2 nach 1h, Attempt 3 nach 6h).
    }

    public function handleHardBounce(string $email, string $reason): void
    {
        $this->suppress($email, 'hard_bounce', $reason);

        $contactId = $this->contactIdForEmail($email);
        if ($contactId !== null) {
            $this->updateContactStatus($contactId, 'bounced');
            $this->tagManager->addTagByName($contactId, 'status:hard-bounce', 'automation', $reason);
        }

        $this->auditLogger->log('system', 'bounce.hard', 'contact', $contactId, ['email' => $email, 'reason' => $reason]);
    }

    public function handleComplaint(string $email, string $details = ''): void
    {
        $this->suppress($email, 'complained', $details);

        $contactId = $this->contactIdForEmail($email);
        if ($contactId !== null) {
            $this->updateContactStatus($contactId, 'complained');
            $this->tagManager->addTagByName($contactId, 'status:complained', 'automation', $details);
            // Wichtig: Kontakt NICHT erneut kontaktieren (Suppression List greift bereits
            // in ComplianceValidator::isRecipientSuppressed() im QueueWorker).
        }

        $this->auditLogger->log('system', 'complaint.received', 'contact', $contactId, ['email' => $email, 'details' => $details]);
    }

    /**
     * Batch-Verarbeitung: prueft email_queue nach kuerzlich gebounceten Eintraegen
     * und synchronisiert Kontakt-Status + Suppression List. Nuetzlich als
     * taeglicher Sammel-Job, falls Bounces nicht per Webhook in Echtzeit eintreffen.
     */
    public function syncFromQueue(int $lookbackHours = 24): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT recipient_email, error_message
             FROM email_queue
             WHERE status = 'bounced' AND updated_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)"
        );
        $stmt->execute(['hours' => $lookbackHours]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $this->handleHardBounce($row['recipient_email'], $row['error_message'] ?? 'bounced');
        }

        return count($rows);
    }

    private function suppress(string $email, string $reason, string $details): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO suppression_list (email, reason, details) VALUES (:email, :reason, :details)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), details = VALUES(details)'
        );
        $stmt->execute(['email' => $email, 'reason' => $reason, 'details' => $details]);
    }

    private function updateContactStatus(int $contactId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE contacts SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $contactId]);
    }

    private function contactIdForEmail(string $email): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM contacts WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
