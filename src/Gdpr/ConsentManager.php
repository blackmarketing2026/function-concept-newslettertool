<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Audit\AuditLogger;
use PDO;
use Random\RandomException;

/**
 * Double Opt-in & Consent-Tracking (DSGVO).
 */
final class ConsentManager
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Erstellt einen Kontakt (falls nicht vorhanden) und generiert einen
     * Confirmation-Token fuer die Double-Opt-in Bestaetigungsmail.
     *
     * @throws RandomException
     */
    public function requestOptIn(string $email, array $attributes = [], string $source = 'signup_form', ?string $ip = null): string
    {
        $token = bin2hex(random_bytes(32));
        $unsubToken = bin2hex(random_bytes(32));

        $stmt = $this->pdo->prepare(
            'INSERT INTO contacts (email, first_name, last_name, status, confirmation_token, unsubscribe_token, source)
             VALUES (:email, :first_name, :last_name, "unconfirmed", :token, :unsub_token, :source)
             ON DUPLICATE KEY UPDATE confirmation_token = VALUES(confirmation_token), status = "unconfirmed"'
        );
        $stmt->execute([
            'email' => $email,
            'first_name' => $attributes['first_name'] ?? null,
            'last_name' => $attributes['last_name'] ?? null,
            'token' => $token,
            'unsub_token' => $unsubToken,
            'source' => $source,
        ]);

        $contactId = (int) $this->pdo->lastInsertId();
        if ($contactId === 0) {
            $find = $this->pdo->prepare('SELECT id FROM contacts WHERE email = :email');
            $find->execute(['email' => $email]);
            $contactId = (int) $find->fetchColumn();
        }

        $this->logConsent($contactId, 'opt_in_requested', $ip, $source);

        return $token;
    }

    /**
     * Bestaetigt die Double-Opt-in Anfrage anhand des Tokens.
     */
    public function confirmOptIn(string $token, ?string $ip = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM contacts WHERE confirmation_token = :token AND status = "unconfirmed"');
        $stmt->execute(['token' => $token]);
        $contactId = $stmt->fetchColumn();

        if ($contactId === false) {
            return false;
        }

        $update = $this->pdo->prepare(
            'UPDATE contacts SET status = "active", confirmed_at = NOW(), confirmation_token = NULL WHERE id = :id'
        );
        $update->execute(['id' => $contactId]);

        $this->logConsent((int) $contactId, 'opt_in_confirmed', $ip, null);
        $this->auditLogger->log('system', 'contact.opt_in_confirmed', 'contact', (int) $contactId);

        return true;
    }

    public function optOut(string $unsubscribeToken, ?string $ip = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, email FROM contacts WHERE unsubscribe_token = :token');
        $stmt->execute(['token' => $unsubscribeToken]);
        $contact = $stmt->fetch();

        if ($contact === false) {
            return false;
        }

        $update = $this->pdo->prepare(
            'UPDATE contacts SET status = "unsubscribed", unsubscribed_at = NOW() WHERE id = :id'
        );
        $update->execute(['id' => $contact['id']]);

        $suppress = $this->pdo->prepare(
            'INSERT INTO suppression_list (email, reason) VALUES (:email, "unsubscribed")
             ON DUPLICATE KEY UPDATE reason = "unsubscribed"'
        );
        $suppress->execute(['email' => $contact['email']]);

        $this->logConsent((int) $contact['id'], 'opt_out', $ip, null);
        $this->auditLogger->log('system', 'contact.unsubscribed', 'contact', (int) $contact['id']);

        return true;
    }

    /**
     * DSGVO "Recht auf Loeschung": entfernt den Kontakt vollstaendig.
     * consent_log/contact_tags haben ON DELETE CASCADE.
     */
    public function eraseContact(int $contactId, string $requestedBy): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM contacts WHERE id = :id');
        $stmt->execute(['id' => $contactId]);

        $deleted = $stmt->rowCount() === 1;
        if ($deleted) {
            $this->auditLogger->log($requestedBy, 'contact.erased_gdpr_request', 'contact', $contactId);
        }

        return $deleted;
    }

    private function logConsent(int $contactId, string $action, ?string $ip, ?string $source): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO consent_log (contact_id, action, ip_address, source) VALUES (:contact_id, :action, :ip, :source)'
        );
        $stmt->execute(['contact_id' => $contactId, 'action' => $action, 'ip' => $ip, 'source' => $source]);
    }
}
