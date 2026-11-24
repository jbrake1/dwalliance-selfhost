<?php
declare(strict_types=1);
require_once '/var/www/html/lib/Dw.php';

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $data = is_array($json) ? $json : $_POST;
    if (!isset($data['action']) && isset($_GET['action']) && is_string($_GET['action'])) {
        $data['action'] = $_GET['action'];
    }
    return $data;
}

function shown(array $sent): array
{
    if (array_key_exists('session', $sent)) {
        $sent['session'] = '(sent, not shown)';
    }
    if (array_key_exists('brand', $sent)) {
        $sent['brand'] = '(sent, not shown)';
    }
    return $sent;
}

function expected(): array
{
    return [
        'action' => 'create, update, delete, or read',
        'session' => 'JSON field session or cookie session',
        'brand' => 'required, must match the session brand',
        'rights' => 'module CRM and CRUD (CREATE, READ, UPDATE, DELETE)',
        'person_id' => 'create: required. read: optional filter',
        'company_id' => 'create: required. update: optional. read: optional filter. Stored in relation.company',
        'id' => 'update, delete, and read by id',
    ];
}

function fail(array $sent, string $error, int $code = 400): void
{
    Dw::jsonOut($code, ['ok' => false, 'error' => $error, 'you_sent' => shown($sent), 'expected' => expected()]);
    exit;
}

function help(): void
{
    Dw::jsonOut(200, [
        'path' => '/relation',
        'session' => 'Required. Send session and brand. Brand must match the session. Module CRM plus CRUD.',
        'actions' => [
            'create' => 'person_id, company_id. Writes one relation row and a relation_history INSERT.',
            'update' => 'id, and person_id and/or company_id. Archives the old row, then updates.',
            'delete' => 'id. Archives the row, then deletes it.',
            'read' => 'id, or person_id, or company_id. Returns relation rows for this brand.',
        ],
        'note' => 'Person and company CRUD is /crm, not /relation.',
        'examples' => [
            'create' => "const res = await fetch('/relation/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create', session: 'SESSION_ID', brand: 'BRANDCODE', person_id: 'PERSON_ID', company_id: 'COMPANY_ID' }) }).then(r => r.json());",
            'update' => "const res = await fetch('/relation/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'update', session: 'SESSION_ID', brand: 'BRANDCODE', id: 'RELATION_ID', company_id: 'COMPANY_ID' }) }).then(r => r.json());",
            'delete' => "const res = await fetch('/relation/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', session: 'SESSION_ID', brand: 'BRANDCODE', id: 'RELATION_ID' }) }).then(r => r.json());",
            'read' => "const res = await fetch('/relation/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'read', session: 'SESSION_ID', brand: 'BRANDCODE', person_id: 'PERSON_ID' }) }).then(r => r.json());",
        ],
    ]);
    exit;
}

function need(array $sent, string $key): string
{
    $v = trim((string)($sent[$key] ?? ''));
    if ($v === '') {
        fail($sent, $key . ' is required');
    }
    if (strlen($v) > 50) {
        fail($sent, $key . ' is longer than 50 characters');
    }
    return $v;
}

function archive(PDO $db, string $id, string $action): void
{
    $db->prepare('INSERT INTO relation_history (history_id, id, person_id, company, created_on, created_by, brand, action) SELECT ?, id, person_id, company, created_on, created_by, brand, ? FROM relation WHERE id = ?')->execute([Dw::randId(), $action, $id]);
}

function rowOut(array $row): array
{
    unset($row['brand']);
    if (isset($row['company'])) {
        $row['company_id'] = $row['company'];
    }
    return $row;
}

$sent = body();
if ($sent === []) {
    help();
}
$action = strtolower(trim((string)($sent['action'] ?? '')));
if (!in_array($action, ['create', 'update', 'delete', 'read'], true)) {
    fail($sent, 'action must be create, update, delete, or read');
}

try {
    $session = Dw::session($sent);
} catch (Throwable $e) {
    fail($sent, 'database unavailable', 500);
}
if ($session === null) {
    fail($sent, 'a valid session is required', 401);
}
$brand = need($sent, 'brand');
if ($brand !== (string)$session['brand']) {
    fail($sent, 'brand does not match this session', 403);
}
if (!Dw::allows($session, 'CRM', ['CREATE', 'READ', 'UPDATE', 'DELETE'])) {
    fail($sent, 'this session needs module CRM and CRUD (CREATE, READ, UPDATE, DELETE)', 403);
}

try {
    $db = Dw::pdo();
    $by = (string)$session['person_id'];

    if ($action === 'create') {
        $personId = need($sent, 'person_id');
        $companyId = need($sent, 'company_id');
        $exists = $db->prepare('SELECT id FROM relation WHERE person_id = ? AND company = ? AND brand = ? LIMIT 1');
        $exists->execute([$personId, $companyId, $brand]);
        $already = $exists->fetch(PDO::FETCH_ASSOC);
        if ($already) {
            Dw::jsonOut(200, ['ok' => true, 'id' => $already['id'], 'existed' => true]);
            exit;
        }
        $id = Dw::randId();
        $db->beginTransaction();
        $db->prepare('INSERT INTO relation (id, person_id, company, created_on, created_by, brand) VALUES (?, ?, ?, NOW(), ?, ?)')->execute([$id, $personId, $companyId, $by, $brand]);
        archive($db, $id, 'INSERT');
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'id' => $id, 'person_id' => $personId, 'company_id' => $companyId]);
        exit;
    }

    if ($action === 'read') {
        $id = trim((string)($sent['id'] ?? ''));
        $personId = trim((string)($sent['person_id'] ?? ''));
        $companyId = trim((string)($sent['company_id'] ?? ''));
        if ($id === '' && $personId === '' && $companyId === '') {
            fail($sent, 'read needs id, person_id, or company_id');
        }
        $sql = 'SELECT id, person_id, company, created_on, created_by, brand FROM relation WHERE brand = ?';
        $vals = [$brand];
        if ($id !== '') {
            $sql .= ' AND id = ?';
            $vals[] = $id;
        }
        if ($personId !== '') {
            $sql .= ' AND person_id = ?';
            $vals[] = $personId;
        }
        if ($companyId !== '') {
            $sql .= ' AND company = ?';
            $vals[] = $companyId;
        }
        $q = $db->prepare($sql);
        $q->execute($vals);
        $rows = [];
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = rowOut($row);
        }
        Dw::jsonOut(200, ['ok' => true, 'relations' => $rows]);
        exit;
    }

    $id = need($sent, 'id');
    $q = $db->prepare('SELECT id FROM relation WHERE id = ? AND brand = ?');
    $q->execute([$id, $brand]);
    if (!$q->fetch()) {
        fail($sent, 'relation not found on this brand', 404);
    }

    if ($action === 'delete') {
        $db->beginTransaction();
        archive($db, $id, 'DELETE');
        $db->prepare('DELETE FROM relation WHERE id = ? AND brand = ?')->execute([$id, $brand]);
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'id' => $id]);
        exit;
    }

    $sets = [];
    $vals = [];
    if (trim((string)($sent['person_id'] ?? '')) !== '') {
        $sets[] = 'person_id = ?';
        $vals[] = need($sent, 'person_id');
    }
    if (trim((string)($sent['company_id'] ?? '')) !== '') {
        $sets[] = 'company = ?';
        $vals[] = need($sent, 'company_id');
    }
    if ($sets === []) {
        fail($sent, 'nothing to update: send person_id or company_id');
    }
    $vals[] = $id;
    $vals[] = $brand;
    $db->beginTransaction();
    archive($db, $id, 'UPDATE');
    $db->prepare('UPDATE relation SET ' . implode(', ', $sets) . ' WHERE id = ? AND brand = ?')->execute($vals);
    $db->commit();
    Dw::jsonOut(200, ['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fail($sent, 'could not complete ' . $action, 500);
}
