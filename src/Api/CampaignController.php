<?php

declare(strict_types=1);

namespace App\Api;

use App\Audit\AuditLogger;
use App\Email\ComplianceValidator;
use App\Tags\TagManager;
use PDO;

/**
 * Kampagnen / Newsletter-Versand.
 *
 * Endpoints:
 *   POST /api/campaigns              Erstellen (Draft)
 *   GET  /api/campaigns/{id}         Lesen
 *   PUT  /api/campaigns/{id}         Aktualisieren
 *   POST /api/campaigns/{id}/send    Empfaenger aufloesen -> email_queue befuellen
 *   GET  /api/campaigns/{id}/stats   Post-Send Report
 */
final class CampaignController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TagManager $tagManager,
        private readonly ComplianceValidator $complianceValidator,
        private readonly AuditLogger $auditLogger,
        private readonly array $appConfig,
    ) {
    }

    public function index(): void
    {
        $stmt = $this->pdo->query('SELECT * FROM campaigns ORDER BY created_at DESC LIMIT 200');
        Json::respond(['data' => $stmt->fetchAll()]);
    }

    public function show(array $params): void
    {
        $campaign = $this->findOrFail((int) $params['id']);
        Json::respond(['data' => $campaign]);
    }

    public function store(): void
    {
        $body = Json::body();

        if (empty($body['name'])) {
            Json::error('Feld "name" erforderlich.', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO campaigns (name, type, template_id, email_account_id, subject, preview_text, audience_rule, scheduled_at, send_rate_per_minute, use_engagement_tiers, created_by)
             VALUES (:name, :type, :template_id, :email_account_id, :subject, :preview_text, :audience_rule, :scheduled_at, :rate, :tiers, :created_by)'
        );
        $stmt->execute([
            'name' => $body['name'],
            'type' => $body['type'] ?? 'newsletter',
            'template_id' => $body['template_id'] ?? null,
            'email_account_id' => $body['email_account_id'] ?? null,
            'subject' => $body['subject'] ?? null,
            'preview_text' => $body['preview_text'] ?? null,
            'audience_rule' => isset($body['audience_rule']) ? json_encode($body['audience_rule']) : null,
            'scheduled_at' => $body['scheduled_at'] ?? null,
            'rate' => $body['send_rate_per_minute'] ?? 50,
            'tiers' => isset($body['use_engagement_tiers']) ? (bool) $body['use_engagement_tiers'] : true,
            'created_by' => $body['created_by'] ?? 'api',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->auditLogger->log('api', 'campaign.created', 'campaign', $id, ['name' => $body['name']]);

        Json::respond(['data' => ['id' => $id]], 201);
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $this->findOrFail($id);
        $body = Json::body();

        $fields = ['name', 'subject', 'preview_text', 'scheduled_at', 'send_rate_per_minute', 'status'];
        $set = [];
        $values = ['id' => $id];
        foreach ($fields as $field) {
            if (array_key_exists($field, $body)) {
                $set[] = "{$field} = :{$field}";
                $values[$field] = $body[$field];
            }
        }
        if (array_key_exists('audience_rule', $body)) {
            $set[] = 'audience_rule = :audience_rule';
            $values['audience_rule'] = json_encode($body['audience_rule']);
        }

        if ($set !== []) {
            $this->pdo->prepare('UPDATE campaigns SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($values);
            $this->auditLogger->log('api', 'campaign.updated', 'campaign', $id, $body);
        }

        Json::respond(['data' => ['id' => $id, 'updated' => true]]);
    }

    /**
     * Berechnet nur die Empfaenger-Anzahl (Live-Vorschau im GUI: "X Kontakte werden erreicht"),
     * ohne die Queue zu befuellen.
     */
    public function previewAudience(array $params): void
    {
        $campaign = $this->findOrFail((int) $params['id']);
        $rule = json_decode((string) $campaign['audience_rule'], true) ?? [];
        $contactIds = $this->tagManager->resolveAudience($rule);

        Json::respond(['data' => ['count' => count($contactIds)]]);
    }

    /**
     * Loest die Tag-basierte Empfaenger-Auswahl auf und befuellt die
     * email_queue Tabelle. Der eigentliche Versand passiert asynchron
     * durch den Cronjob-Worker (cli/queue-worker.php send).
     */
    public function send(array $params): void
    {
        $campaign = $this->findOrFail((int) $params['id']);

        if ($campaign['status'] !== 'draft' && $campaign['status'] !== 'scheduled') {
            Json::error('Kampagne wurde bereits versendet oder ist nicht sendebereit.', 409);
            return;
        }

        $rule = json_decode((string) $campaign['audience_rule'], true);
        if (! is_array($rule) || empty($rule['include'])) {
            Json::error('Keine Empfaenger-Regel (audience_rule) definiert.', 422);
            return;
        }

        [$subject, $bodyHtml, $bodyText] = $this->resolveTemplate((int) ($campaign['template_id'] ?? 0), $campaign);

        $compliance = $this->complianceValidator->validate(
            $bodyHtml,
            $campaign['sender_email'] ?? $this->appConfig['mail']['from_address'],
            null,
            $this->appConfig['compliance']['impressum_url'],
            $this->appConfig['compliance']['datenschutz_url'],
        );

        if (! $compliance['valid']) {
            Json::error('Compliance-Pruefung fehlgeschlagen: ' . implode(' | ', $compliance['errors']), 422);
            return;
        }

        $contactIds = $this->tagManager->resolveAudience($rule);
        if ($contactIds === []) {
            Json::error('Keine Empfaenger fuer diese Tag-Regel gefunden.', 422);
            return;
        }

        $senderEmail = $this->resolveSenderEmail((int) ($campaign['email_account_id'] ?? 0));

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO email_queue
                (campaign_id, contact_id, recipient_email, subject, body_html, body_text, template_id, sender_email, tracking_id, engagement_tier)
             VALUES (:campaign_id, :contact_id, :email, :subject, :body_html, :body_text, :template_id, :sender_email, :tracking_id, :tier)'
        );

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $contactsStmt = $this->pdo->prepare(
            "SELECT id, email, first_name, last_name, engagement_tier FROM contacts WHERE id IN ({$placeholders}) AND status = 'active'"
        );
        $contactsStmt->execute($contactIds);
        $contacts = $contactsStmt->fetchAll();

        $queued = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($contacts as $contact) {
                $personalizedHtml = $this->personalize($bodyHtml, $contact);
                $personalizedText = $this->personalize($bodyText, $contact);

                $insertStmt->execute([
                    'campaign_id' => $campaign['id'],
                    'contact_id' => $contact['id'],
                    'email' => $contact['email'],
                    'subject' => $subject,
                    'body_html' => $personalizedHtml,
                    'body_text' => $personalizedText,
                    'template_id' => $campaign['template_id'],
                    'sender_email' => $senderEmail,
                    'tracking_id' => bin2hex(random_bytes(16)),
                    'tier' => $contact['engagement_tier'] ?? 'cold',
                ]);
                $queued++;
            }

            $this->pdo->prepare(
                "UPDATE campaigns SET status = 'sending', audience_count = :count, started_at = NOW() WHERE id = :id"
            )->execute(['count' => $queued, 'id' => $campaign['id']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            Json::error('Fehler beim Befuellen der Queue: ' . $e->getMessage(), 500);
            return;
        }

        $this->auditLogger->log('api', 'campaign.send_triggered', 'campaign', (int) $campaign['id'], ['queued' => $queued]);

        Json::respond(['data' => [
            'campaign_id' => $campaign['id'],
            'queued' => $queued,
            'message' => "Newsletter an {$queued} Kontakte in die Queue eingereiht. Versand erfolgt ueber den Cronjob-Worker.",
        ]]);
    }

    /**
     * Post-Send Report / Live-Tracking Dashboard Daten.
     */
    public function stats(array $params): void
    {
        $campaignId = (int) $params['id'];
        $this->findOrFail($campaignId);

        $totals = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'sent') AS sent,
                SUM(status = 'pending' OR status = 'processing') AS in_queue,
                SUM(status = 'failed') AS failed,
                SUM(status = 'bounced') AS bounced
             FROM email_queue WHERE campaign_id = :id"
        );
        $totals->execute(['id' => $campaignId]);
        $summary = $totals->fetch();

        $events = $this->pdo->prepare(
            "SELECT event_type, COUNT(*) AS count FROM email_events WHERE campaign_id = :id GROUP BY event_type"
        );
        $events->execute(['id' => $campaignId]);
        $eventCounts = array_column($events->fetchAll(), 'count', 'event_type');

        $byTag = $this->pdo->prepare(
            "SELECT q.engagement_tier,
                    COUNT(*) AS sent,
                    SUM(CASE WHEN ee.event_type = 'open' THEN 1 ELSE 0 END) AS opens
             FROM email_queue q
             LEFT JOIN email_events ee ON ee.queue_id = q.id AND ee.event_type = 'open'
             WHERE q.campaign_id = :id AND q.status = 'sent'
             GROUP BY q.engagement_tier"
        );
        $byTag->execute(['id' => $campaignId]);

        Json::respond(['data' => [
            'summary' => $summary,
            'events' => [
                'opened' => (int) ($eventCounts['open'] ?? 0),
                'clicked' => (int) ($eventCounts['click'] ?? 0),
                'unsubscribed' => (int) ($eventCounts['unsubscribe'] ?? 0),
                'complained' => (int) ($eventCounts['complaint'] ?? 0),
            ],
            'by_engagement_tier' => $byTag->fetchAll(),
        ]]);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [subject, bodyHtml, bodyText]
     */
    private function resolveTemplate(int $templateId, array $campaign): array
    {
        if ($templateId > 0) {
            $stmt = $this->pdo->prepare('SELECT * FROM templates WHERE id = :id');
            $stmt->execute(['id' => $templateId]);
            $template = $stmt->fetch();
            if ($template !== false) {
                return [
                    $campaign['subject'] ?: $template['subject'],
                    (string) $template['body_html'],
                    (string) ($template['body_text'] ?? ''),
                ];
            }
        }

        return [(string) ($campaign['subject'] ?? ''), '', ''];
    }

    private function resolveSenderEmail(int $emailAccountId): string
    {
        if ($emailAccountId > 0) {
            $stmt = $this->pdo->prepare('SELECT from_email FROM email_accounts WHERE id = :id');
            $stmt->execute(['id' => $emailAccountId]);
            $email = $stmt->fetchColumn();
            if ($email !== false) {
                return $email;
            }
        }

        return $this->appConfig['mail']['from_address'];
    }

    private function personalize(string $content, array $contact): string
    {
        $replacements = [
            '{{first_name}}' => $contact['first_name'] ?? '',
            '{{last_name}}' => $contact['last_name'] ?? '',
            '{{email}}' => $contact['email'] ?? '',
        ];

        return strtr($content, $replacements);
    }

    private function findOrFail(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM campaigns WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $campaign = $stmt->fetch();

        if ($campaign === false) {
            Json::error('Kampagne nicht gefunden.', 404);
            exit;
        }

        return $campaign;
    }
}
