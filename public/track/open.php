<?php

declare(strict_types=1);

/**
 * public/track/open.php?t=<tracking_id>
 *
 * 1x1 Transparent-Pixel fuer Open-Tracking. Wird im Template als
 * <img src="https://example.com/track/open.php?t={{tracking_id}}" width="1" height="1">
 * eingebunden.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Support\Bootstrap;

$app = Bootstrap::boot();
$pdo = $app->pdo();

$trackingId = $_GET['t'] ?? '';

if ($trackingId !== '') {
    try {
        $stmt = $pdo->prepare('SELECT id, campaign_id, contact_id FROM email_queue WHERE tracking_id = :tid');
        $stmt->execute(['tid' => $trackingId]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $insert = $pdo->prepare(
                'INSERT INTO email_events (queue_id, campaign_id, contact_id, event_type, user_agent, ip_address)
                 VALUES (:queue_id, :campaign_id, :contact_id, "open", :ua, :ip)'
            );
            $insert->execute([
                'queue_id' => $row['id'],
                'campaign_id' => $row['campaign_id'],
                'contact_id' => $row['contact_id'],
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            if ($row['contact_id'] !== null) {
                $pdo->prepare(
                    'UPDATE contacts SET last_opened_at = NOW(), open_count = open_count + 1,
                     engagement_tier = "hot" WHERE id = :id'
                )->execute(['id' => $row['contact_id']]);
            }
        }
    } catch (Throwable) {
        // Tracking darf niemals einen Fehler nach aussen werfen - Pixel muss immer laden.
    }
}

// 1x1 transparentes GIF ausliefern
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');
