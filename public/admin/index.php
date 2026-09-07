<?php

declare(strict_types=1);

require __DIR__ . '/_auth.php';

$pdo = $app->pdo();

$queueStats = $pdo->query(
    "SELECT
        SUM(status = 'pending') AS pending,
        SUM(status = 'processing') AS processing,
        SUM(status = 'sent' AND sent_at >= CURDATE()) AS sent_today,
        SUM(status = 'failed') AS failed,
        SUM(status = 'bounced') AS bounced
     FROM email_queue"
)->fetch();

$contactStats = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active,
        SUM(status = 'unconfirmed') AS unconfirmed,
        SUM(status = 'unsubscribed') AS unsubscribed,
        SUM(status = 'bounced') AS bounced
     FROM contacts"
)->fetch();

$recentCampaigns = $pdo->query(
    "SELECT id, name, status, audience_count, created_at FROM campaigns ORDER BY created_at DESC LIMIT 5"
)->fetchAll();

$lastLogs = $pdo->query(
    "SELECT event, details, created_at FROM email_queue_log ORDER BY created_at DESC LIMIT 5"
)->fetchAll();

$health = $app->queueWorker()->healthCheck();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/_header.php';
?>

<h1 class="h3 mb-4">📊 Dashboard</h1>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="text-secondary small">Queue: Pending</div>
            <div class="display-6"><?= (int) ($queueStats['pending'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="text-secondary small">Heute versendet</div>
            <div class="display-6 text-success"><?= (int) ($queueStats['sent_today'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="text-secondary small">Fehlgeschlagen</div>
            <div class="display-6 text-danger"><?= (int) ($queueStats['failed'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="text-secondary small">Bounced</div>
            <div class="display-6 text-warning"><?= (int) ($queueStats['bounced'] ?? 0) ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card p-3 h-100">
            <h2 class="h5">Kontakte</h2>
            <table class="table table-sm mb-0">
                <tr><td>Gesamt</td><td class="text-end fw-bold"><?= (int) ($contactStats['total'] ?? 0) ?></td></tr>
                <tr><td>Aktiv</td><td class="text-end text-success"><?= (int) ($contactStats['active'] ?? 0) ?></td></tr>
                <tr><td>Unbestaetigt (Double Opt-in offen)</td><td class="text-end text-secondary"><?= (int) ($contactStats['unconfirmed'] ?? 0) ?></td></tr>
                <tr><td>Abgemeldet</td><td class="text-end"><?= (int) ($contactStats['unsubscribed'] ?? 0) ?></td></tr>
                <tr><td>Bounced</td><td class="text-end text-danger"><?= (int) ($contactStats['bounced'] ?? 0) ?></td></tr>
            </table>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-3 h-100">
            <h2 class="h5">Cronjob / Queue Health</h2>
            <p class="mb-2">
                Status:
                <?php if ($health['healthy']): ?>
                    <span class="badge bg-success">OK</span>
                <?php else: ?>
                    <span class="badge bg-danger">ALERT</span>
                <?php endif; ?>
            </p>
            <table class="table table-sm mb-0">
                <tr><td>Pending in Queue</td><td class="text-end"><?= $health['pending'] ?></td></tr>
                <tr><td>Wiederhergestellt (stuck processing)</td><td class="text-end"><?= $health['processing_stuck'] ?></td></tr>
                <tr><td>Permanent fehlgeschlagen</td><td class="text-end text-danger"><?= $health['failed_permanent'] ?></td></tr>
            </table>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card p-3">
            <h2 class="h5">Letzte Kampagnen</h2>
            <?php if ($recentCampaigns === []): ?>
                <p class="text-secondary">Noch keine Kampagnen vorhanden.</p>
            <?php else: ?>
                <table class="table table-sm">
                    <thead><tr><th>Name</th><th>Status</th><th>Empfaenger</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentCampaigns as $c): ?>
                        <tr>
                            <td><a href="/admin/campaigns.php?id=<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($c['status']) ?></span></td>
                            <td><?= (int) $c['audience_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-3">
            <h2 class="h5">Letzte Queue-Events</h2>
            <?php if ($lastLogs === []): ?>
                <p class="text-secondary">Noch keine Events.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($lastLogs as $log): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= htmlspecialchars($log['event']) ?></span>
                            <span class="text-secondary small"><?= htmlspecialchars($log['created_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
