<?php

declare(strict_types=1);

namespace App\Api;

use App\Audit\AuditLogger;
use PDO;

/**
 * Template CRUD + Preview-Generierung mit Beispiel-Variablen.
 */
final class TemplateController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function index(): void
    {
        $stmt = $this->pdo->query('SELECT id, name, subject, preview_text, updated_at FROM templates ORDER BY updated_at DESC');
        Json::respond(['data' => $stmt->fetchAll()]);
    }

    public function show(array $params): void
    {
        Json::respond(['data' => $this->findOrFail((int) $params['id'])]);
    }

    public function store(): void
    {
        $body = Json::body();
        if (empty($body['name'])) {
            Json::error('Feld "name" erforderlich.', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO templates (name, subject, preview_text, body_html, body_text, variables)
             VALUES (:name, :subject, :preview_text, :body_html, :body_text, :variables)'
        );
        $stmt->execute([
            'name' => $body['name'],
            'subject' => $body['subject'] ?? null,
            'preview_text' => $body['preview_text'] ?? null,
            'body_html' => $body['body_html'] ?? '',
            'body_text' => $body['body_text'] ?? '',
            'variables' => json_encode($body['variables'] ?? ['first_name', 'last_name', 'email']),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->auditLogger->log('api', 'template.created', 'template', $id);

        Json::respond(['data' => ['id' => $id]], 201);
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $this->findOrFail($id);
        $body = Json::body();

        $fields = ['name', 'subject', 'preview_text', 'body_html', 'body_text'];
        $set = [];
        $values = ['id' => $id];
        foreach ($fields as $field) {
            if (array_key_exists($field, $body)) {
                $set[] = "{$field} = :{$field}";
                $values[$field] = $body[$field];
            }
        }

        if ($set !== []) {
            $this->pdo->prepare('UPDATE templates SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($values);
            $this->auditLogger->log('api', 'template.updated', 'template', $id);
        }

        Json::respond(['data' => ['id' => $id, 'updated' => true]]);
    }

    public function destroy(array $params): void
    {
        $id = (int) $params['id'];
        $this->findOrFail($id);
        $this->pdo->prepare('DELETE FROM templates WHERE id = :id')->execute(['id' => $id]);
        $this->auditLogger->log('api', 'template.deleted', 'template', $id);

        Json::respond(['data' => ['deleted' => true]]);
    }

    /**
     * Rendert eine Vorschau mit Beispiel-Daten (fuer Desktop/Mobile Preview im GUI).
     */
    public function preview(array $params): void
    {
        $template = $this->findOrFail((int) $params['id']);

        $sample = [
            '{{first_name}}' => 'Max',
            '{{last_name}}' => 'Mustermann',
            '{{email}}' => 'max@example.com',
            '{{company}}' => 'Beispiel GmbH',
        ];

        Json::respond(['data' => [
            'subject' => strtr((string) $template['subject'], $sample),
            'body_html' => strtr((string) $template['body_html'], $sample),
        ]]);
    }

    private function findOrFail(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $template = $stmt->fetch();

        if ($template === false) {
            Json::error('Template nicht gefunden.', 404);
            exit;
        }

        return $template;
    }
}
