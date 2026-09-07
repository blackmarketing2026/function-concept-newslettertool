<?php

declare(strict_types=1);

namespace App\Tags;

use App\Audit\AuditLogger;
use PDO;

/**
 * Tag-System - das zentrale Steuerungselement (siehe DEVELOPMENT_1.md
 * "Tag-System Kernparadigma"). Jede Tag-Aenderung wird auditiert.
 */
final class TagManager
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function addTag(int $contactId, int $tagId, string $addedBy = 'manual', ?string $addedByUser = null, ?string $reason = null): void
    {
        // Bereits aktives Tag? Nichts tun (idempotent).
        $exists = $this->pdo->prepare(
            'SELECT id FROM contact_tags WHERE contact_id = :contact_id AND tag_id = :tag_id AND removed_at IS NULL'
        );
        $exists->execute(['contact_id' => $contactId, 'tag_id' => $tagId]);
        if ($exists->fetchColumn() !== false) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO contact_tags (contact_id, tag_id, added_by, added_by_user, added_reason)
             VALUES (:contact_id, :tag_id, :added_by, :added_by_user, :reason)'
        );
        $stmt->execute([
            'contact_id' => $contactId,
            'tag_id' => $tagId,
            'added_by' => $addedBy,
            'added_by_user' => $addedByUser,
            'reason' => $reason,
        ]);

        $this->auditLogger->log($addedByUser ?? $addedBy, 'tag.added', 'contact', $contactId, ['tag_id' => $tagId, 'reason' => $reason]);
    }

    public function removeTag(int $contactId, int $tagId, string $removedBy = 'manual', ?string $reason = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE contact_tags SET removed_at = NOW(), removed_by = :removed_by, removed_reason = :reason
             WHERE contact_id = :contact_id AND tag_id = :tag_id AND removed_at IS NULL'
        );
        $stmt->execute([
            'removed_by' => $removedBy,
            'reason' => $reason,
            'contact_id' => $contactId,
            'tag_id' => $tagId,
        ]);

        if ($stmt->rowCount() > 0) {
            $this->auditLogger->log($removedBy, 'tag.removed', 'contact', $contactId, ['tag_id' => $tagId, 'reason' => $reason]);
        }
    }

    public function addTagByName(int $contactId, string $tagName, string $addedBy = 'automation', ?string $reason = null): void
    {
        $tagId = $this->findOrCreateTagId($tagName);
        $this->addTag($contactId, $tagId, $addedBy, null, $reason);
    }

    public function removeTagByName(int $contactId, string $tagName, string $removedBy = 'automation', ?string $reason = null): void
    {
        $find = $this->pdo->prepare('SELECT id FROM tags WHERE name = :name');
        $find->execute(['name' => $tagName]);
        $tagId = $find->fetchColumn();
        if ($tagId !== false) {
            $this->removeTag($contactId, (int) $tagId, $removedBy, $reason);
        }
    }

    /** @return string[] aktive Tag-Namen des Kontakts */
    public function tagsForContact(int $contactId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.name FROM contact_tags ct
             JOIN tags t ON t.id = ct.tag_id
             WHERE ct.contact_id = :contact_id AND ct.removed_at IS NULL'
        );
        $stmt->execute(['contact_id' => $contactId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Loest die Empfaenger-Zaehlung fuer eine Tag-Regel auf (fuer AND/OR/NOT
     * im Kampagnen-Builder). $rule Beispiel:
     * ['include' => ['newsletter-subscriber', 'engaged:high'], 'exclude' => ['status:hard-bounce']]
     *
     * @return int[] contact_ids
     */
    public function resolveAudience(array $rule): array
    {
        $include = $rule['include'] ?? [];
        $exclude = $rule['exclude'] ?? [];

        if ($include === []) {
            return [];
        }

        // AND-Verknuepfung: Kontakt muss ALLE include-Tags aktiv haben
        $placeholders = implode(',', array_fill(0, count($include), '?'));
        $sql = "SELECT ct.contact_id
                FROM contact_tags ct
                JOIN tags t ON t.id = ct.tag_id
                WHERE t.name IN ($placeholders) AND ct.removed_at IS NULL
                GROUP BY ct.contact_id
                HAVING COUNT(DISTINCT t.name) = ?";

        $params = [...$include, count($include)];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $contactIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($exclude === [] || $contactIds === []) {
            return $contactIds;
        }

        $excludePlaceholders = implode(',', array_fill(0, count($exclude), '?'));
        $excludeSql = "SELECT DISTINCT ct.contact_id
                       FROM contact_tags ct
                       JOIN tags t ON t.id = ct.tag_id
                       WHERE t.name IN ($excludePlaceholders) AND ct.removed_at IS NULL";
        $excludeStmt = $this->pdo->prepare($excludeSql);
        $excludeStmt->execute($exclude);
        $excludedIds = array_map('intval', $excludeStmt->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_diff($contactIds, $excludedIds));
    }

    private function findOrCreateTagId(string $name): int
    {
        $find = $this->pdo->prepare('SELECT id FROM tags WHERE name = :name');
        $find->execute(['name' => $name]);
        $id = $find->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $insert = $this->pdo->prepare('INSERT INTO tags (name, type, is_system) VALUES (:name, "custom", FALSE)');
        $insert->execute(['name' => $name]);

        return (int) $this->pdo->lastInsertId();
    }
}
