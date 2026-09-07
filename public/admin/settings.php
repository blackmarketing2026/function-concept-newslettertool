<?php

declare(strict_types=1);

require __DIR__ . '/_auth.php';

$pdo = $app->pdo();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        foreach (['impressum_url', 'datenschutz_url', 'double_opt_in'] as $key) {
            if (isset($_POST[$key])) {
                $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v')
                    ->execute(['k' => $key, 'v' => $_POST[$key]]);
            }
        }
        $flash = 'Einstellungen gespeichert.';
    }

    if ($action === 'add_email_account') {
        $pdo->prepare(
            'INSERT INTO email_accounts (label, from_email, from_name, reply_to, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password_encrypted, is_default, is_active)
             VALUES (:label, :from_email, :from_name, :reply_to, :host, :port, :enc, :user, :pass, :is_default, 1)'
        )->execute([
            'label' => $_POST['label'] ?? '',
            'from_email' => $_POST['from_email'] ?? '',
            'from_name' => $_POST['from_name'] ?? '',
            'reply_to' => $_POST['reply_to'] ?? null,
            'host' => $_POST['smtp_host'] ?? '',
            'port' => (int) ($_POST['smtp_port'] ?? 587),
            'enc' => $_POST['smtp_encryption'] ?? 'tls',
            'user' => $_POST['smtp_username'] ?? '',
            'pass' => $_POST['smtp_password'] ?? '' ? password_hash($_POST['smtp_password'], PASSWORD_DEFAULT) : null,
            'is_default' => isset($_POST['is_default']) ? 1 : 0,
        ]);
        $flash = 'Email-Konto hinzugefuegt.';
    }

    if ($action === 'add_webhook') {
        $pdo->prepare('INSERT INTO webhooks (name, url, events, secret, is_active) VALUES (:name, :url, :events, :secret, 1)')
            ->execute([
                'name' => $_POST['name'] ?? '',
                'url' => $_POST['url'] ?? '',
                'events' => json_encode(array_filter(array_map('trim', explode(',', (string) ($_POST['events'] ?? ''))))),
                'secret' => bin2hex(random_bytes(16)),
            ]);
        $flash = 'Webhook erstellt.';
    }
}

$settingsRows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$emailAccounts = $pdo->query('SELECT * FROM email_accounts ORDER BY id DESC')->fetchAll();
$webhooks = $pdo->query('SELECT * FROM webhooks ORDER BY id DESC')->fetchAll();
$auditLog = $pdo->query('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 30')->fetchAll();

$pageTitle = 'Einstellungen';
$activeNav = 'settings';
require __DIR__ . '/_header.php';
?>

<h1 class="h3 mb-4">⚙️ Einstellungen</h1>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-compliance">Compliance</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-accounts">Email-Konten</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-webhooks">Webhooks</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-audit">Audit Log</button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-compliance">
        <div class="card p-3">
            <form method="post">
                <input type="hidden" name="action" value="save_settings">
                <div class="mb-3">
                    <label class="form-label">Impressum URL</label>
                    <input type="url" name="impressum_url" class="form-control" value="<?= htmlspecialchars($settingsRows['impressum_url'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Datenschutzerklaerung URL</label>
                    <input type="url" name="datenschutz_url" class="form-control" value="<?= htmlspecialchars($settingsRows['datenschutz_url'] ?? '') ?>">
                </div>
                <div class="mb-3 form-check">
                    <input type="checkbox" name="double_opt_in" value="true" class="form-check-input" id="doi" <?= ($settingsRows['double_opt_in'] ?? 'true') === 'true' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="doi">Double Opt-in erzwingen (DSGVO)</label>
                </div>
                <button class="btn btn-primary">Speichern</button>
            </form>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-accounts">
        <div class="card p-3 mb-3">
            <h2 class="h6">Neues Email-Konto (SMTP-Absender)</h2>
            <form method="post" class="row g-2">
                <input type="hidden" name="action" value="add_email_account">
                <div class="col-md-4"><input class="form-control" name="label" placeholder="Label" required></div>
                <div class="col-md-4"><input class="form-control" name="from_email" type="email" placeholder="from@example.com" required></div>
                <div class="col-md-4"><input class="form-control" name="from_name" placeholder="Absendername" required></div>
                <div class="col-md-4"><input class="form-control" name="smtp_host" placeholder="SMTP Host" required></div>
                <div class="col-md-2"><input class="form-control" name="smtp_port" type="number" value="587" required></div>
                <div class="col-md-2">
                    <select name="smtp_encryption" class="form-select"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">Keine</option></select>
                </div>
                <div class="col-md-4"><input class="form-control" name="smtp_username" placeholder="SMTP Username"></div>
                <div class="col-md-4"><input class="form-control" name="smtp_password" type="password" placeholder="SMTP Password"></div>
                <div class="col-12"><button class="btn btn-primary btn-sm">Hinzufuegen</button></div>
            </form>
        </div>
        <table class="table table-sm">
            <thead><tr><th>Label</th><th>From</th><th>SMTP</th></tr></thead>
            <tbody>
            <?php foreach ($emailAccounts as $ea): ?>
                <tr><td><?= htmlspecialchars($ea['label']) ?></td><td><?= htmlspecialchars($ea['from_email']) ?></td><td><?= htmlspecialchars($ea['smtp_host']) ?>:<?= (int) $ea['smtp_port'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="tab-pane fade" id="tab-webhooks">
        <div class="card p-3 mb-3">
            <h2 class="h6">Neuer Webhook</h2>
            <form method="post" class="row g-2">
                <input type="hidden" name="action" value="add_webhook">
                <div class="col-md-4"><input class="form-control" name="name" placeholder="Name" required></div>
                <div class="col-md-4"><input class="form-control" name="url" type="url" placeholder="https://..." required></div>
                <div class="col-md-4"><input class="form-control" name="events" placeholder="email.sent, contact.unsubscribed"></div>
                <div class="col-12"><button class="btn btn-primary btn-sm">Erstellen</button></div>
            </form>
        </div>
        <table class="table table-sm">
            <thead><tr><th>Name</th><th>URL</th><th>Events</th></tr></thead>
            <tbody>
            <?php foreach ($webhooks as $wh): ?>
                <tr><td><?= htmlspecialchars($wh['name']) ?></td><td><?= htmlspecialchars($wh['url']) ?></td><td><?= htmlspecialchars(implode(', ', json_decode((string) $wh['events'], true) ?? [])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="tab-pane fade" id="tab-audit">
        <table class="table table-sm">
            <thead><tr><th>Zeit</th><th>Akteur</th><th>Aktion</th><th>Entitaet</th></tr></thead>
            <tbody>
            <?php foreach ($auditLog as $log): ?>
                <tr>
                    <td class="text-secondary small"><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= htmlspecialchars($log['actor']) ?></td>
                    <td><?= htmlspecialchars($log['action']) ?></td>
                    <td><?= htmlspecialchars(($log['entity_type'] ?? '') . ' #' . ($log['entity_id'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
