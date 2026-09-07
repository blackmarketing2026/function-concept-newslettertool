<?php

declare(strict_types=1);

require __DIR__ . '/_auth.php';

$pdo = $app->pdo();
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_template') {
        $id = (int) ($_POST['id'] ?? 0);
        $data = [
            'name' => $_POST['name'] ?? '',
            'subject' => $_POST['subject'] ?? '',
            'preview_text' => $_POST['preview_text'] ?? '',
            'body_html' => $_POST['body_html'] ?? '',
            'body_text' => $_POST['body_text'] ?? '',
        ];

        if ($id > 0) {
            $pdo->prepare('UPDATE templates SET name=:name, subject=:subject, preview_text=:preview_text, body_html=:body_html, body_text=:body_text WHERE id=:id')
                ->execute([...$data, 'id' => $id]);
            $flash = 'Template aktualisiert.';
        } else {
            $pdo->prepare('INSERT INTO templates (name, subject, preview_text, body_html, body_text, variables) VALUES (:name,:subject,:preview_text,:body_html,:body_text,:vars)')
                ->execute([...$data, 'vars' => json_encode(['first_name', 'last_name', 'email'])]);
            $id = (int) $pdo->lastInsertId();
            $flash = 'Template erstellt.';
        }
        $app->auditLogger()->log($_SESSION['admin_username'], 'template.saved', 'template', $id);
        header('Location: templates.php?edit=' . $id);
        exit;
    }

    if ($action === 'delete_template' && ! empty($_POST['id'])) {
        $pdo->prepare('DELETE FROM templates WHERE id = :id')->execute(['id' => (int) $_POST['id']]);
        header('Location: templates.php');
        exit;
    }
}

$editTemplate = null;
if ($editId !== null) {
    $stmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editTemplate = $stmt->fetch() ?: null;
}

$templates = $pdo->query('SELECT id, name, subject, updated_at FROM templates ORDER BY updated_at DESC')->fetchAll();

$pageTitle = 'Templates';
$activeNav = 'templates';
require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">🎨 Templates</h1>
    <a href="templates.php" class="btn btn-primary btn-sm">+ Neues Template</a>
</div>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="row">
    <div class="col-md-4">
        <div class="list-group mb-3">
            <?php foreach ($templates as $t): ?>
                <a href="templates.php?edit=<?= (int) $t['id'] ?>" class="list-group-item list-group-item-action <?= $editId === (int) $t['id'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($t['name']) ?>
                    <div class="small text-secondary"><?= htmlspecialchars($t['subject'] ?? '') ?></div>
                </a>
            <?php endforeach; ?>
            <?php if ($templates === []): ?>
                <p class="text-secondary p-2">Noch keine Templates.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card p-3">
            <form method="post">
                <input type="hidden" name="action" value="save_template">
                <input type="hidden" name="id" value="<?= (int) ($editTemplate['id'] ?? 0) ?>">
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editTemplate['name'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" class="form-control" value="<?= htmlspecialchars($editTemplate['subject'] ?? '') ?>" placeholder="Nutze {{first_name}}, {{company}}, etc.">
                </div>
                <div class="mb-3">
                    <label class="form-label">Preview Text</label>
                    <input type="text" name="preview_text" class="form-control" value="<?= htmlspecialchars($editTemplate['preview_text'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">HTML Body</label>
                    <textarea name="body_html" class="form-control" rows="12" style="font-family: monospace;"><?= htmlspecialchars($editTemplate['body_html'] ?? "<p>Hallo {{first_name}},</p>\n<p>...</p>\n<p><a href=\"{{unsubscribe_url}}\">Abmelden</a></p>") ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Text Body (Fallback)</label>
                    <textarea name="body_text" class="form-control" rows="4"><?= htmlspecialchars($editTemplate['body_text'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Speichern</button>
                <?php if ($editTemplate): ?>
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePreview()">Vorschau</button>
                    <form method="post" class="d-inline" onsubmit="return confirm('Template wirklich loeschen?');">
                        <input type="hidden" name="action" value="delete_template">
                        <input type="hidden" name="id" value="<?= (int) $editTemplate['id'] ?>">
                        <button class="btn btn-outline-danger">Loeschen</button>
                    </form>
                <?php endif; ?>
            </form>

            <?php if ($editTemplate): ?>
                <div id="previewBox" class="border rounded mt-3 p-3" style="display:none; background:#fff;">
                    <?= $editTemplate['body_html'] ?>
                </div>
                <script>
                    function togglePreview() {
                        const box = document.getElementById('previewBox');
                        box.style.display = box.style.display === 'none' ? 'block' : 'none';
                    }
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
