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
        'action' => 'create_person, update_person, delete_person, create_company, update_company, delete_company',
        'session' => 'JSON field session or cookie session',
        'brand' => 'required, must match the session brand',
        'rights' => 'module CRM and CRUD (CREATE, READ, UPDATE, DELETE)',
        'first_name' => 'create_person, update_person',
        'last_name' => 'create_person, update_person',
        'person_id' => 'update_person, delete_person',
        'name' => 'create_company, update_company',
        'company_id' => 'update_company, delete_company',
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
        'path' => '/crm',
        'session' => 'Required. Send session and brand. Brand must match the session. Module CRM plus CRUD.',
        'actions' => [
            'create_person' => 'first_name, last_name. Writes a person on the session brand.',
            'update_person' => 'person_id, first_name, last_name.',
            'delete_person' => 'person_id. Also removes that person relation rows on this brand.',
            'create_company' => 'name. Writes a company on the session brand.',
            'update_company' => 'company_id, name.',
            'delete_company' => 'company_id. Also removes relation rows for that company on this brand.',
        ],
        'note' => 'Relationship CRUD is /relation, not /crm.',
        'examples' => [
            'create_person' => "const res = await fetch('/crm/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create_person', session: 'SESSION_ID', brand: 'BRANDCODE', first_name: 'Ada', last_name: 'Lovelace' }) }).then(r => r.json());",
            'update_person' => "const res = await fetch('/crm/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'update_person', session: 'SESSION_ID', brand: 'BRANDCODE', person_id: 'PERSON_ID', first_name: 'Ada', last_name: 'Lovelace' }) }).then(r => r.json());",
            'delete_person' => "const res = await fetch('/crm/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete_person', session: 'SESSION_ID', brand: 'BRANDCODE', person_id: 'PERSON_ID' }) }).then(r => r.json());",
            'create_company' => "const res = await fetch('/crm/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create_company', session: 'SESSION_ID', brand: 'BRANDCODE', name: 'Example LLC' }) }).then(r => r.json());",
            'update_company' => "const res = await fetch('/crm/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'update_company', session: 'SESSION_ID', brand: 'BRANDCODE', company_id: 'COMPANY_ID', name: 'Example LLC' }) }).then(r => r.json());",
            'delete_company' => "const res = await fetch('/crm/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete_company', session: 'SESSION_ID', brand: 'BRANDCODE', company_id: 'COMPANY_ID' }) }).then(r => r.json());",
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

function archiveRelations(PDO $db, string $whereSql, array $vals, string $action): void
{
    $q = $db->prepare('SELECT id FROM relation WHERE ' . $whereSql);
    $q->execute($vals);
    $ins = $db->prepare('INSERT INTO relation_history (history_id, id, person_id, company, created_on, created_by, brand, action) SELECT ?, id, person_id, company, created_on, created_by, brand, ? FROM relation WHERE id = ?');
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $ins->execute([Dw::randId(), $action, $row['id']]);
    }
}

$sent = body();
if ($sent === []) {
    help();
}
$action = strtolower(trim((string)($sent['action'] ?? '')));
$actions = ['create_person', 'update_person', 'delete_person', 'create_company', 'update_company', 'delete_company'];
if (!in_array($action, $actions, true)) {
    fail($sent, 'action must be one of ' . implode(', ', $actions));
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

    if ($action === 'create_person') {
        $first = need($sent, 'first_name');
        $last = need($sent, 'last_name');
        $id = Dw::randId();
        $db->beginTransaction();
        $db->prepare('INSERT INTO person (id, first_name, last_name, created, brand) VALUES (?, ?, ?, NOW(), ?)')->execute([$id, $first, $last, $brand]);
        Dw::history($db, 'person', $id, 'INSERT');
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'person_id' => $id]);
        exit;
    }

    if ($action === 'update_person') {
        $id = need($sent, 'person_id');
        $first = need($sent, 'first_name');
        $last = need($sent, 'last_name');
        $db->beginTransaction();
        $own = $db->prepare('SELECT id FROM person WHERE id = ? AND brand = ? FOR UPDATE');
        $own->execute([$id, $brand]);
        if ($own->fetchColumn() === false) {
            $db->rollBack();
            fail($sent, 'person not found on this brand', 404);
        }
        Dw::history($db, 'person', $id, 'UPDATE');
        $db->prepare('UPDATE person SET first_name = ?, last_name = ? WHERE id = ? AND brand = ?')->execute([$first, $last, $id, $brand]);
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'person_id' => $id]);
        exit;
    }

    if ($action === 'delete_person') {
        $id = need($sent, 'person_id');
        $db->beginTransaction();
        $own = $db->prepare('SELECT id FROM person WHERE id = ? AND brand = ? FOR UPDATE');
        $own->execute([$id, $brand]);
        if ($own->fetchColumn() === false) {
            $db->rollBack();
            fail($sent, 'person not found on this brand', 404);
        }
        archiveRelations($db, 'person_id = ? AND brand = ?', [$id, $brand], 'DELETE');
        $db->prepare('DELETE FROM relation WHERE person_id = ? AND brand = ?')->execute([$id, $brand]);
        Dw::history($db, 'person', $id, 'DELETE');
        $db->prepare('DELETE FROM person WHERE id = ? AND brand = ?')->execute([$id, $brand]);
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'person_id' => $id]);
        exit;
    }

    if ($action === 'create_company') {
        $name = need($sent, 'name');
        $id = Dw::randId();
        $db->beginTransaction();
        $db->prepare('INSERT INTO company (id, company_name, brand) VALUES (?, ?, ?)')->execute([$id, $name, $brand]);
        Dw::history($db, 'company', $id, 'INSERT');
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'company_id' => $id]);
        exit;
    }

    if ($action === 'update_company') {
        $id = need($sent, 'company_id');
        $name = need($sent, 'name');
        $db->beginTransaction();
        $own = $db->prepare('SELECT id FROM company WHERE id = ? AND brand = ? FOR UPDATE');
        $own->execute([$id, $brand]);
        if ($own->fetchColumn() === false) {
            $db->rollBack();
            fail($sent, 'company not found on this brand', 404);
        }
        Dw::history($db, 'company', $id, 'UPDATE');
        $db->prepare('UPDATE company SET company_name = ? WHERE id = ? AND brand = ?')->execute([$name, $id, $brand]);
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'company_id' => $id]);
        exit;
    }

    $id = need($sent, 'company_id');
    $db->beginTransaction();
    $own = $db->prepare('SELECT id FROM company WHERE id = ? AND brand = ? FOR UPDATE');
    $own->execute([$id, $brand]);
    if ($own->fetchColumn() === false) {
        $db->rollBack();
        fail($sent, 'company not found on this brand', 404);
    }
    archiveRelations($db, 'company = ? AND brand = ?', [$id, $brand], 'DELETE');
    $db->prepare('DELETE FROM relation WHERE company = ? AND brand = ?')->execute([$id, $brand]);
    Dw::history($db, 'company', $id, 'DELETE');
    $db->prepare('DELETE FROM company WHERE id = ? AND brand = ?')->execute([$id, $brand]);
    $db->commit();
    Dw::jsonOut(200, ['ok' => true, 'company_id' => $id]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fail($sent, 'could not complete ' . $action, 500);
}
