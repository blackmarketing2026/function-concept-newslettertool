<?php

declare(strict_types=1);

/**
 * public/track/click.php?t=<tracking_id>&u=<url-encoded-ziel>
 *
 * Click-Tracking mit anschliessendem Redirect zum eigentlichen Link.
 * Links in Templates werden beim Versand automatisch umgeschrieben zu
 * https://example.com/track/click.php?t={{tracking_id}}&u=<encoded original url>
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Support\Bootstrap;

$app = Bootstrap::boot();
$pdo = $app->pdo();

$trackingId = $_GET['t'] ?? '';
$targetUrl = $_GET['u'] ?? '';

// Sicherheits-Check: nur http(s) Redirects erlauben, um Open-Redirect-Missbrauch zu verhindern.
$isValidUrl = filter_var($targetUrl, FILTER_VALIDATE_URL) !== false
    && (str_starts_with($targetUrl, 'http://') || str_starts_with($targetUrl, 'https://'));

if ($trackingId !== '' && $isValidUrl) {
    try {
        $stmt = $pdo->prepare('SELECT id, campaign_id, contact_id FROM email_queue WHERE tracking_id = :tid');
        $stmt->execute(['tid' => $trackingId]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $insert = $pdo->prepare(
                'INSERT INTO email_events (queue_id, campaign_id, contact_id, event_type, url, user_agent, ip_address)
                 VALUES (:queue_id, :campaign_id, :contact_id, "click", :url, :ua, :ip)'
            );
            $insert->execute([
                'queue_id' => $row['id'],
                'campaign_id' => $row['campaign_id'],
                'contact_id' => $row['contact_id'],
                'url' => $targetUrl,
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            if ($row['contact_id'] !== null) {
                $pdo->prepare(
                    'UPDATE contacts SET last_clicked_at = NOW(), click_count = click_count + 1,
                     engagement_tier = "hot" WHERE id = :id'
                )->execute(['id' => $row['contact_id']]);
            }
        }
    } catch (Throwable) {
        // Tracking-Fehler duerfen den Redirect nicht verhindern.
    }
}

$redirectTo = $isValidUrl ? $targetUrl : ($app->appConfig()['url'] ?: '/');
header('Location: ' . $redirectTo, true, 302);
exit;
