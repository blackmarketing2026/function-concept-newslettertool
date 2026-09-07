<?php

declare(strict_types=1);

require __DIR__ . '/_auth.php';

use App\Tags\TagManager;

$pdo = $app->pdo();
$tagManager = $app->tagManager();

$flash = null;

// --- Actions -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_tag' && ! empty($_POST['contact_id']) && ! empty($_POST['tag'])) {
        $tagManager->addTagByName((int) $_POST['contact_id'], trim((string) $_POST['tag']), 'manual', "Admin: {$_SESSION['admin_username']}");
        $flash = 'Tag hinzugefuegt.';
    }

    if ($action === 'remove_tag' && ! empty($_POST['contact_id']) && ! empty($_POST['tag'])) {
        $tagManager->removeTagByName((int) $_POST['contact_id'], trim((string) $_POST['tag']), 'manual');
        $flash = 'Tag entfernt.';
    }

    if ($action === 'import_csv' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'rb');
        $header = fgetcsv($handle);
        $emailCol = array_search('email', array_map('strtolower', $header), true);
        $tagsToApply = array_filter(array_map('trim', explode(',', (string) ($_POST['import_tags'] ?? ''))));
        $imported = 0;
        if ($emailCol !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                $email = trim($row[$emailCol] ?? '');
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $stmt = $pdo->prepare('INSERT IGNORE INTO contacts (email, status, source) VALUES (:email, "unconfirmed", "csv_import")');
                $stmt->execute(['email' => $email]);
                $findId = $pdo->prepare('SELECT id FROM contacts WHERE email = :email');
                $findId->execute(['email' => $email]);
                $contactId = (int) $findId->fetchColumn();
                foreach ($tagsToApply as $tag) {
                    $tagManager->addTagByName($contactId, $tag, 'import', 'CSV Import via Admin GUI');
                }
                $imported++;
            }
        }
        fclose($handle);
        $flash = "{$imported} Kontakte importiert.";
    }
}

// --- Listing -------------------------------------------------------------
$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(email LIKE :s OR first_name LIKE :s OR last_name LIKE :s)';
    $params['s'] = "%{$search}%";
}
if ($statusFilter !== '') {
    $where[] = 'status = :status';
    $params['status'] = $statusFilter;
}
$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$total = (int) (function () use ($pdo, $whereSql, $params) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contacts {$whereSql}");
    $stmt->execute($params);
    return $stmt->fetchColumn();
})();

$stmt = $pdo->prepare("SELECT * FROM contacts {$whereSql} ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$contacts = $stmt->fetchAll();

$totalPages = (int) ceil($total / $perPage);

$pageTitle = 'Kontakte';
$activeNav = 'contacts';
require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">👥 Kontakte <span class="text-secondary fs-6">(<?= $total ?>)</span></h1>
    <div>
        <a href="/api/contacts/export" class="btn btn-outline-secondary btn-sm">CSV Export</a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">CSV Import</button>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<form class="row g-2 mb-3" method="get">
    <div class="col-auto">
        <input type="text" name="q" class="form-control" placeholder="Suche (Email, Name)" value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="col-auto">
        <select name="status" class="form-select">
            <option value="">Alle Status</option>
            <?php foreach (['unconfirmed', 'active', 'unsubscribed', 'bounced', 'complained', 'suppressed'] as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-primary">Filtern</button></div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
        <tr><th>Email</th><th>Name</th><th>Status</th><th>Tags</th><th>Engagement</th><th>Erstellt</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($contacts as $c): ?>
            <?php $tags = $tagManager->tagsForContact((int) $c['id']); ?>
            <tr>
                <td><?= htmlspecialchars($c['email']) ?></td>
                <td><?= htmlspecialchars(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))) ?></td>
                <td><span class="badge bg-<?= $c['status'] === 'active' ? 'success' : ($c['status'] === 'bounced' || $c['status'] === 'complained' ? 'danger' : 'secondary') ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                <td>
                    <?php foreach ($tags as $tag): ?>
                        <span class="badge bg-light text-dark border badge-tag"><?= htmlspecialchars($tag) ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="remove_tag">
                                <input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>">
                                <input type="hidden" name="tag" value="<?= htmlspecialchars($tag) ?>">
                                <button type="submit" style="border:none;background:none;color:#c00;padding:0 2px;" title="Tag entfernen">×</button>
                            </form>
                        </span>
                    <?php endforeach; ?>
                    <form method="post" class="d-inline-flex gap-1 mt-1">
                        <input type="hidden" name="action" value="add_tag">
                        <input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>">
                        <input type="text" name="tag" class="form-control form-control-sm" style="width:140px" placeholder="+ Tag">
                        <button class="btn btn-sm btn-outline-secondary">OK</button>
                    </form>
                </td>
                <td><span class="badge bg-<?= $c['engagement_tier'] === 'hot' ? 'danger' : ($c['engagement_tier'] === 'warm' ? 'warning' : 'secondary') ?>"><?= htmlspecialchars($c['engagement_tier']) ?></span></td>
                <td class="text-secondary small"><?= htmlspecialchars($c['created_at']) ?></td>
                <td></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($contacts === []): ?>
            <tr><td colspan="7" class="text-center text-secondary py-4">Keine Kontakte gefunden.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $p ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<!-- CSV Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">CSV Import</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="import_csv">
                    <div class="mb-3">
                        <label class="form-label">CSV-Datei (Spalte "email" erforderlich)</label>
                        <input type="file" name="csv_file" accept=".csv" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tags fuer alle importierten Kontakte (kommagetrennt)</label>
                        <input type="text" name="import_tags" class="form-control" placeholder="newsletter-subscriber, segment:trial">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Importieren</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
