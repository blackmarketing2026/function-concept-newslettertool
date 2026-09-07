<?php

declare(strict_types=1);

require __DIR__ . '/_auth.php';

$pdo = $app->pdo();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_tag' && ! empty($_POST['name'])) {
        $stmt = $pdo->prepare('INSERT IGNORE INTO tags (name, type, description, color) VALUES (:name, :type, :desc, :color)');
        $stmt->execute([
            'name' => trim((string) $_POST['name']),
            'type' => $_POST['type'] ?? 'custom',
            'desc' => $_POST['description'] ?? null,
            'color' => $_POST['color'] ?? '#6c757d',
        ]);
        $flash = 'Tag erstellt.';
    }

    if ($action === 'delete_tag' && ! empty($_POST['tag_id'])) {
        $stmt = $pdo->prepare('DELETE FROM tags WHERE id = :id AND is_system = FALSE');
        $stmt->execute(['id' => (int) $_POST['tag_id']]);
        $flash = $stmt->rowCount() > 0 ? 'Tag geloescht.' : 'System-Tags koennen nicht geloescht werden.';
    }
}

$tags = $pdo->query(
    "SELECT t.*, (SELECT COUNT(*) FROM contact_tags ct WHERE ct.tag_id = t.id AND ct.removed_at IS NULL) AS contact_count
     FROM tags t ORDER BY t.type, t.name"
)->fetchAll();

$grouped = [];
foreach ($tags as $tag) {
    $grouped[$tag['type']][] = $tag;
}

$pageTitle = 'Tags & Segmente';
$activeNav = 'tags';
require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">🏷️ Tags & Segmente</h1>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newTagModal">+ Neuer Tag</button>
</div>

<p class="text-secondary">Tags sind das zentrale Steuerungselement: Sie bestimmen Newsletter-Empfaenger, Automation-Trigger und Suppression.</p>

<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<?php foreach ($grouped as $type => $tagsInType): ?>
    <div class="card p-3 mb-3">
        <h2 class="h6 text-uppercase text-secondary"><?= htmlspecialchars($type) ?></h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Name</th><th>Beschreibung</th><th>Kontakte</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tagsInType as $tag): ?>
                    <tr>
                        <td><span class="badge" style="background-color: <?= htmlspecialchars($tag['color']) ?>"><?= htmlspecialchars($tag['name']) ?></span></td>
                        <td class="text-secondary small"><?= htmlspecialchars($tag['description'] ?? '') ?></td>
                        <td><a href="/admin/contacts.php?q=&status=" class="badge bg-light text-dark border"><?= (int) $tag['contact_count'] ?></a></td>
                        <td>
                            <?php if (! $tag['is_system']): ?>
                                <form method="post" onsubmit="return confirm('Tag wirklich loeschen?');">
                                    <input type="hidden" name="action" value="delete_tag">
                                    <input type="hidden" name="tag_id" value="<?= (int) $tag['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Loeschen</button>
                                </form>
                            <?php else: ?>
                                <span class="badge bg-secondary">System</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<div class="modal fade" id="newTagModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Neuer Tag</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_tag">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" placeholder="z.B. segment:vip" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Typ</label>
                        <select name="type" class="form-select">
                            <option value="segment">segment</option>
                            <option value="engagement">engagement</option>
                            <option value="campaign">campaign</option>
                            <option value="custom" selected>custom</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Beschreibung</label>
                        <input type="text" name="description" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Farbe</label>
                        <input type="color" name="color" class="form-control form-control-color" value="#6c757d">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Erstellen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
