<?php

declare(strict_types=1);

require __DIR__ . '/_auth.php';

use App\Email\ComplianceValidator;

$pdo = $app->pdo();
$tagManager = $app->tagManager();
$complianceValidator = $app->complianceValidator();
$appConfig = $app->appConfig();

$templates = $pdo->query('SELECT id, name, subject FROM templates ORDER BY name')->fetchAll();
$emailAccounts = $pdo->query('SELECT id, label, from_email FROM email_accounts WHERE is_active = 1')->fetchAll();
$allTags = $pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$flash = null;
$audienceCount = null;
$createdCampaignId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $emailAccountId = (int) ($_POST['email_account_id'] ?? 0);
    $includeTags = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['include_tags'] ?? '')))));
    $excludeTags = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['exclude_tags'] ?? '')))));
    $sendRate = max(1, (int) ($_POST['send_rate'] ?? 50));

    $rule = ['include' => $includeTags, 'exclude' => $excludeTags];
    $contactIds = $includeTags !== [] ? $tagManager->resolveAudience($rule) : [];
    $audienceCount = count($contactIds);

    if (($_POST['step'] ?? '') === 'preview') {
        // Nur Vorschau anzeigen, noch nicht senden.
    }

    if (($_POST['step'] ?? '') === 'send' && $name !== '' && $templateId > 0 && $contactIds !== []) {
        $stmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
        $stmt->execute(['id' => $templateId]);
        $template = $stmt->fetch();

        $senderEmail = $appConfig['mail']['from_address'];
        $senderName = $appConfig['mail']['from_name'];
        if ($emailAccountId > 0) {
            $accStmt = $pdo->prepare('SELECT from_email, from_name FROM email_accounts WHERE id = :id');
            $accStmt->execute(['id' => $emailAccountId]);
            $acc = $accStmt->fetch();
            if ($acc) {
                $senderEmail = $acc['from_email'];
                $senderName = $acc['from_name'];
            }
        }

        $compliance = $complianceValidator->validate(
            (string) $template['body_html'],
            $senderEmail,
            null,
            $appConfig['compliance']['impressum_url'],
            $appConfig['compliance']['datenschutz_url'],
        );

        if (! $compliance['valid']) {
            $flash = ['type' => 'danger', 'text' => 'Versand blockiert: ' . implode(' | ', $compliance['errors'])];
        } else {
            $pdo->prepare(
                'INSERT INTO campaigns (name, type, status, template_id, email_account_id, subject, audience_rule, send_rate_per_minute, created_by)
                 VALUES (:name, "newsletter", "draft", :template_id, :email_account_id, :subject, :rule, :rate, :user)'
            )->execute([
                'name' => $name,
                'template_id' => $templateId,
                'email_account_id' => $emailAccountId ?: null,
                'subject' => $template['subject'],
                'rule' => json_encode($rule),
                'rate' => $sendRate,
                'user' => $_SESSION['admin_username'],
            ]);
            $campaignId = (int) $pdo->lastInsertId();

            $contactsStmt = $pdo->prepare(
                'SELECT id, email, first_name, last_name, engagement_tier, unsubscribe_token FROM contacts
                 WHERE id IN (' . implode(',', array_fill(0, count($contactIds), '?')) . ") AND status = 'active'"
            );
            $contactsStmt->execute($contactIds);
            $contacts = $contactsStmt->fetchAll();

            $insertStmt = $pdo->prepare(
                'INSERT INTO email_queue (campaign_id, contact_id, recipient_email, subject, body_html, body_text, template_id, sender_email, tracking_id, engagement_tier)
                 VALUES (:cid, :contact_id, :email, :subject, :html, :text, :tid, :sender, :tracking, :tier)'
            );

            $queued = 0;
            foreach ($contacts as $contact) {
                $replacements = [
                    '{{first_name}}' => $contact['first_name'] ?? '',
                    '{{last_name}}' => $contact['last_name'] ?? '',
                    '{{email}}' => $contact['email'],
                    '{{unsubscribe_url}}' => rtrim($appConfig['url'], '/') . '/unsubscribe.php?token=' . urlencode($contact['unsubscribe_token'] ?? ''),
                ];
                $insertStmt->execute([
                    'cid' => $campaignId,
                    'contact_id' => $contact['id'],
                    'email' => $contact['email'],
                    'subject' => strtr($template['subject'], $replacements),
                    'html' => strtr($template['body_html'], $replacements),
                    'text' => strtr((string) $template['body_text'], $replacements),
                    'tid' => $templateId,
                    'sender' => $senderEmail,
                    'tracking' => bin2hex(random_bytes(16)),
                    'tier' => $contact['engagement_tier'] ?: 'cold',
                ]);
                $queued++;
            }

            $pdo->prepare("UPDATE campaigns SET status='sending', audience_count=:c, started_at=NOW() WHERE id=:id")
                ->execute(['c' => $queued, 'id' => $campaignId]);

            $app->auditLogger()->log($_SESSION['admin_username'], 'newsletter.sent', 'campaign', $campaignId, ['queued' => $queued]);

            $createdCampaignId = $campaignId;
            $flash = ['type' => 'success', 'text' => "Newsletter an {$queued} Kontakte in die Queue eingereiht. Der Versand laeuft ueber den Cronjob-Worker."];
        }
    }
}

$pageTitle = 'Newsletter';
$activeNav = 'newsletter';
require __DIR__ . '/_header.php';
?>

<h1 class="h3 mb-4">✉️ Newsletter (Quick-Send)</h1>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['text']) ?>
        <?php if ($createdCampaignId): ?>
            <a href="campaigns.php?id=<?= $createdCampaignId ?>" class="alert-link">Live-Tracking ansehen →</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" class="card p-4">
    <h2 class="h5">Schritt 1: Template</h2>
    <div class="mb-3">
        <select name="template_id" class="form-select" required>
            <option value="">-- Template waehlen --</option>
            <?php foreach ($templates as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (int) ($_POST['template_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['name']) ?> — <?= htmlspecialchars($t['subject'] ?? '') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($templates === []): ?>
            <div class="form-text text-danger">Keine Templates vorhanden. <a href="templates.php">Erst ein Template anlegen</a>.</div>
        <?php endif; ?>
    </div>
    <div class="mb-3">
        <label class="form-label">Absender-Konto</label>
        <select name="email_account_id" class="form-select">
            <option value="0">Standard (.env Konfiguration)</option>
            <?php foreach ($emailAccounts as $ea): ?>
                <option value="<?= (int) $ea['id'] ?>"><?= htmlspecialchars($ea['label']) ?> (<?= htmlspecialchars($ea['from_email']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>

    <hr>
    <h2 class="h5">Schritt 2: Tag-basierte Empfaenger</h2>
    <div class="mb-3">
        <label class="form-label">Kampagnen-Name</label>
        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="z.B. Product Update August 2026" required>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Tags einschliessen (AND-Verknuepfung)</label>
            <input type="text" name="include_tags" class="form-control" value="<?= htmlspecialchars($_POST['include_tags'] ?? '') ?>" placeholder="newsletter-subscriber, engaged:high" list="tagList">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Tags ausschliessen</label>
            <input type="text" name="exclude_tags" class="form-control" value="<?= htmlspecialchars($_POST['exclude_tags'] ?? '') ?>" placeholder="status:hard-bounce" list="tagList">
        </div>
    </div>
    <datalist id="tagList">
        <?php foreach ($allTags as $t): ?><option value="<?= htmlspecialchars($t) ?>"><?php endforeach; ?>
    </datalist>

    <button type="submit" name="step" value="preview" class="btn btn-outline-primary mb-3">Empfaenger-Anzahl pruefen</button>

    <?php if ($audienceCount !== null): ?>
        <div class="alert alert-info">📬 <?= $audienceCount ?> Kontakte werden erreicht.</div>
    <?php endif; ?>

    <hr>
    <h2 class="h5">Schritt 3: Versand-Optionen</h2>
    <div class="mb-3">
        <label class="form-label">Rate Limiting (Emails/Minute)</label>
        <input type="number" name="send_rate" class="form-control" style="max-width:150px" value="<?= htmlspecialchars($_POST['send_rate'] ?? '50') ?>">
    </div>

    <button type="submit" name="step" value="send" class="btn btn-danger" onclick="return confirm('Newsletter jetzt an alle passenden Kontakte einreihen?');">
        🚀 Newsletter versenden
    </button>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
