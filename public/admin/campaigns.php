<?php

declare(strict_types=1);

require __DIR__ . '/_auth.php';

$pdo = $app->pdo();
$campaignId = isset($_GET['id']) ? (int) $_GET['id'] : null;

$pageTitle = 'Kampagnen';
$activeNav = 'campaigns';
require __DIR__ . '/_header.php';

if ($campaignId !== null) {
    $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = :id');
    $stmt->execute(['id' => $campaignId]);
    $campaign = $stmt->fetch();

    if ($campaign === false) {
        echo '<div class="alert alert-danger">Kampagne nicht gefunden.</div>';
    } else {
        $totals = $pdo->prepare(
            "SELECT COUNT(*) AS total, SUM(status='sent') AS sent, SUM(status IN ('pending','processing')) AS in_queue,
                    SUM(status='failed') AS failed, SUM(status='bounced') AS bounced
             FROM email_queue WHERE campaign_id = :id"
        );
        $totals->execute(['id' => $campaignId]);
        $stats = $totals->fetch();

        $events = $pdo->prepare("SELECT event_type, COUNT(*) AS c FROM email_events WHERE campaign_id = :id GROUP BY event_type");
        $events->execute(['id' => $campaignId]);
        $eventCounts = array_column($events->fetchAll(), 'c', 'event_type');
        ?>
        <a href="/admin/campaigns.php" class="btn btn-sm btn-outline-secondary mb-3">← Zurueck</a>
        <h1 class="h3"><?= htmlspecialchars($campaign['name']) ?></h1>
        <p><span class="badge bg-secondary"><?= htmlspecialchars($campaign['status']) ?></span></p>

        <div class="row g-3 mb-4">
            <div class="col-md-3"><div class="card p-3"><div class="text-secondary small">Versendet</div><div class="display-6"><?= (int) $stats['sent'] ?></div></div></div>
            <div class="col-md-3"><div class="card p-3"><div class="text-secondary small">In Queue</div><div class="display-6"><?= (int) $stats['in_queue'] ?></div></div></div>
            <div class="col-md-3"><div class="card p-3"><div class="text-secondary small">Fehlgeschlagen</div><div class="display-6 text-danger"><?= (int) $stats['failed'] ?></div></div></div>
            <div class="col-md-3"><div class="card p-3"><div class="text-secondary small">Bounced</div><div class="display-6 text-warning"><?= (int) $stats['bounced'] ?></div></div></div>
        </div>

        <div class="card p-3">
            <h2 class="h5">Engagement</h2>
            <table class="table table-sm">
                <tr><td>Geoeffnet</td><td class="text-end"><?= (int) ($eventCounts['open'] ?? 0) ?></td></tr>
                <tr><td>Geklickt</td><td class="text-end"><?= (int) ($eventCounts['click'] ?? 0) ?></td></tr>
                <tr><td>Abgemeldet</td><td class="text-end"><?= (int) ($eventCounts['unsubscribe'] ?? 0) ?></td></tr>
                <tr><td>Spam-Beschwerde</td><td class="text-end"><?= (int) ($eventCounts['complaint'] ?? 0) ?></td></tr>
            </table>
        </div>
        <?php
    }
} else {
    $campaigns = $pdo->query('SELECT * FROM campaigns ORDER BY created_at DESC LIMIT 100')->fetchAll();
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">📢 Kampagnen</h1>
        <a href="/admin/newsletter.php" class="btn btn-primary btn-sm">+ Neue Kampagne / Newsletter</a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light"><tr><th>Name</th><th>Typ</th><th>Status</th><th>Empfaenger</th><th>Erstellt</th></tr></thead>
            <tbody>
            <?php foreach ($campaigns as $c): ?>
                <tr>
                    <td><a href="/admin/campaigns.php?id=<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a></td>
                    <td><?= htmlspecialchars($c['type']) ?></td>
                    <td><span class="badge bg-<?= $c['status'] === 'sent' ? 'success' : ($c['status'] === 'sending' ? 'info' : 'secondary') ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                    <td><?= (int) $c['audience_count'] ?></td>
                    <td class="text-secondary small"><?= htmlspecialchars($c['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($campaigns === []): ?>
                <tr><td colspan="5" class="text-center text-secondary py-4">Noch keine Kampagnen. <a href="/admin/newsletter.php">Jetzt erste Kampagne erstellen</a>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

require __DIR__ . '/_footer.php';
