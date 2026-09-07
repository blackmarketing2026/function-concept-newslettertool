<?php

declare(strict_types=1);

namespace App\Audit;

use PDO;

/**
 * DSGVO Audit-Log: Wer, Wann, Welche Aktion, Welche Entitaet.
 * 24 Monate Aufbewahrung (siehe cleanup()).
 */
final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(string $actor, string $action, ?string $entityType = null, ?int $entityId = null, array $details = [], ?string $ipAddress = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (actor, action, entity_type, entity_id, details, ip_address)
             VALUES (:actor, :action, :entity_type, :entity_id, :details, :ip_address)'
        );

        $stmt->execute([
            'actor' => $actor,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details === [] ? null : json_encode($details),
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Loescht Audit-Log-Eintraege aelter als die konfigurierte Aufbewahrungsfrist.
     */
    public function purgeExpired(int $retentionMonths = 24): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :months MONTH)');
        $stmt->execute(['months' => $retentionMonths]);

        return $stmt->rowCount();
    }

    public function recentForEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM audit_log WHERE entity_type = :type AND entity_id = :id ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue('type', $entityType);
        $stmt->bindValue('id', $entityId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
