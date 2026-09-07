<?php

declare(strict_types=1);

namespace App\Api;

use App\Audit\AuditLogger;
use App\Tags\TagManager;
use PDO;

/**
 * CRUD + Search + Tags + CSV Import/Export fuer Kontakte.
 *
 * Endpoints:
 *   GET    /api/contacts              Liste (Suche, Filter, Pagination)
 *   GET    /api/contacts/{id}         Detail
 *   POST   /api/contacts              Erstellen
 *   PUT    /api/contacts/{id}         Aktualisieren
 *   DELETE /api/contacts/{id}         Loeschen (DSGVO Recht auf Loeschung)
 *   POST   /api/contacts/{id}/tags    Tag hinzufuegen {"tag": "segment:vip"}
 *   DELETE /api/contacts/{id}/tags/{tag}
 *   POST   /api/contacts/import       CSV Upload (multipart/form-data, Feld "file")
 *   GET    /api/contacts/export       CSV Export (?tags=a,b&status=active)
 */
final class ContactController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TagManager $tagManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function index(): void
    {
        $search = trim((string) ($_GET['q'] ?? ''));
        $status = $_GET['status'] ?? null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = '(email LIKE :search OR first_name LIKE :search OR last_name LIKE :search)';
            $params['search'] = "%{$search}%";
        }
        if ($status !== null && $status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM contacts {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT * FROM contacts {$whereSql} ORDER BY created_at DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $contacts = $stmt->fetchAll();

        foreach ($contacts as &$contact) {
            $contact['tags'] = $this->tagManager->tagsForContact((int) $contact['id']);
        }

        Json::respond([
            'data' => $contacts,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function show(array $params): void
    {
        $contact = $this->findOrFail((int) $params['id']);
        $contact['tags'] = $this->tagManager->tagsForContact((int) $contact['id']);
        Json::respond(['data' => $contact]);
    }

    public function store(): void
    {
        $body = Json::body();
        if (empty($body['email']) || ! filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            Json::error('Gueltige Email-Adresse erforderlich.', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO contacts (email, first_name, last_name, company, status, source, custom_fields)
             VALUES (:email, :first_name, :last_name, :company, :status, :source, :custom_fields)'
        );
        $stmt->execute([
            'email' => $body['email'],
            'first_name' => $body['first_name'] ?? null,
            'last_name' => $body['last_name'] ?? null,
            'company' => $body['company'] ?? null,
            'status' => $body['status'] ?? 'unconfirmed',
            'source' => $body['source'] ?? 'api',
            'custom_fields' => isset($body['custom_fields']) ? json_encode($body['custom_fields']) : null,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->auditLogger->log('api', 'contact.created', 'contact', $id);

        Json::respond(['data' => ['id' => $id]], 201);
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $this->findOrFail($id);
        $body = Json::body();

        $fields = ['first_name', 'last_name', 'company', 'status'];
        $set = [];
        $values = ['id' => $id];
        foreach ($fields as $field) {
            if (array_key_exists($field, $body)) {
                $set[] = "{$field} = :{$field}";
                $values[$field] = $body[$field];
            }
        }

        if ($set !== []) {
            $sql = 'UPDATE contacts SET ' . implode(', ', $set) . ' WHERE id = :id';
            $this->pdo->prepare($sql)->execute($values);
            $this->auditLogger->log('api', 'contact.updated', 'contact', $id, $body);
        }

        Json::respond(['data' => ['id' => $id, 'updated' => true]]);
    }

    public function destroy(array $params): void
    {
        $id = (int) $params['id'];
        $this->findOrFail($id);

        $this->pdo->prepare('DELETE FROM contacts WHERE id = :id')->execute(['id' => $id]);
        $this->auditLogger->log('api', 'contact.deleted', 'contact', $id);

        Json::respond(['data' => ['deleted' => true]]);
    }

    public function addTag(array $params): void
    {
        $id = (int) $params['id'];
        $this->findOrFail($id);
        $body = Json::body();

        if (empty($body['tag'])) {
            Json::error('Feld "tag" erforderlich.', 422);
            return;
        }

        $this->tagManager->addTagByName($id, $body['tag'], 'api', $body['reason'] ?? null);
        Json::respond(['data' => ['tag_added' => $body['tag']]]);
    }

    public function removeTag(array $params): void
    {
        $id = (int) $params['id'];
        $this->findOrFail($id);
        $tag = urldecode($params['tag']);

        $this->tagManager->removeTagByName($id, $tag, 'api');
        Json::respond(['data' => ['tag_removed' => $tag]]);
    }

    /**
     * CSV Import mit Preview/Wizard-Unterstuetzung. Erwartet multipart/form-data
     * mit Feld "file" (CSV, erste Zeile = Header) und optional "mapping"
     * (JSON: {"csv_column": "db_field"}) und "tags" (kommagetrennt, werden allen
     * importierten Kontakten zugewiesen).
     */
    public function import(): void
    {
        if (! isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Json::error('CSV-Datei ("file") erforderlich.', 422);
            return;
        }

        $mapping = isset($_POST['mapping']) ? json_decode((string) $_POST['mapping'], true) : null;
        $tags = isset($_POST['tags']) && $_POST['tags'] !== '' ? explode(',', (string) $_POST['tags']) : [];

        $handle = fopen($_FILES['file']['tmp_name'], 'rb');
        if ($handle === false) {
            Json::error('CSV-Datei konnte nicht gelesen werden.', 500);
            return;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            Json::error('CSV-Datei ist leer.', 422);
            return;
        }

        // Automatisches Mapping, falls keins uebergeben: CSV-Spalte == DB-Feld-Name
        if ($mapping === null) {
            $mapping = array_combine($header, $header);
        }

        $importStmt = $this->pdo->prepare(
            'INSERT INTO contact_imports (filename, total_rows, status, column_mapping, applied_tags)
             VALUES (:filename, 0, "processing", :mapping, :tags)'
        );
        $importStmt->execute([
            'filename' => $_FILES['file']['name'],
            'mapping' => json_encode($mapping),
            'tags' => json_encode($tags),
        ]);
        $importId = (int) $this->pdo->lastInsertId();

        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $total = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $total++;
            $rowAssoc = array_combine($header, $row);
            if ($rowAssoc === false) {
                $errors++;
                continue;
            }

            $email = null;
            foreach ($mapping as $csvCol => $dbField) {
                if ($dbField === 'email' && isset($rowAssoc[$csvCol])) {
                    $email = trim($rowAssoc[$csvCol]);
                }
            }

            if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO contacts (email, first_name, last_name, company, status, source, import_id)
                     VALUES (:email, :first_name, :last_name, :company, "unconfirmed", "csv_import", :import_id)
                     ON DUPLICATE KEY UPDATE first_name = COALESCE(VALUES(first_name), first_name)'
                );
                $stmt->execute([
                    'email' => $email,
                    'first_name' => $this->mappedValue($mapping, $rowAssoc, 'first_name'),
                    'last_name' => $this->mappedValue($mapping, $rowAssoc, 'last_name'),
                    'company' => $this->mappedValue($mapping, $rowAssoc, 'company'),
                    'import_id' => $importId,
                ]);

                $contactId = (int) $this->pdo->lastInsertId();
                if ($contactId === 0) {
                    $find = $this->pdo->prepare('SELECT id FROM contacts WHERE email = :email');
                    $find->execute(['email' => $email]);
                    $contactId = (int) $find->fetchColumn();
                }

                foreach ($tags as $tagName) {
                    $tagName = trim($tagName);
                    if ($tagName !== '') {
                        $this->tagManager->addTagByName($contactId, $tagName, 'import', "CSV Import #{$importId}");
                    }
                }

                $imported++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        fclose($handle);

        $this->pdo->prepare(
            'UPDATE contact_imports SET total_rows = :total, imported_count = :imported, skipped_count = :skipped,
             error_count = :errors, status = "completed", completed_at = NOW() WHERE id = :id'
        )->execute([
            'total' => $total,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'id' => $importId,
        ]);

        $this->auditLogger->log('api', 'contacts.imported', 'contact_import', $importId, compact('total', 'imported', 'skipped', 'errors'));

        Json::respond(['data' => [
            'import_id' => $importId,
            'total' => $total,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ]]);
    }

    /**
     * CSV Export mit flexibler Spaltenauswahl (?columns=email,first_name,last_name&tags=x&status=active).
     */
    public function export(): void
    {
        $columns = isset($_GET['columns'])
            ? array_filter(explode(',', (string) $_GET['columns']))
            : ['email', 'first_name', 'last_name', 'company', 'status', 'created_at'];

        $allowedColumns = ['id', 'email', 'first_name', 'last_name', 'company', 'status', 'engagement_tier', 'created_at'];
        $columns = array_values(array_intersect($columns, $allowedColumns));
        if ($columns === []) {
            $columns = ['email'];
        }

        $where = [];
        $params = [];
        if (! empty($_GET['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $_GET['status'];
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $columnList = implode(', ', $columns);

        $stmt = $this->pdo->prepare("SELECT {$columnList} FROM contacts {$whereSql} ORDER BY created_at DESC");
        $stmt->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contacts-export-' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'wb');
        fputcsv($out, $columns);
        while ($row = $stmt->fetch()) {
            fputcsv($out, $row);
        }
        fclose($out);
    }

    private function mappedValue(array $mapping, array $row, string $dbField): ?string
    {
        foreach ($mapping as $csvCol => $mappedField) {
            if ($mappedField === $dbField && isset($row[$csvCol])) {
                return trim($row[$csvCol]) ?: null;
            }
        }

        return null;
    }

    private function findOrFail(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contacts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $contact = $stmt->fetch();

        if ($contact === false) {
            Json::error('Kontakt nicht gefunden.', 404);
            exit;
        }

        return $contact;
    }
}
