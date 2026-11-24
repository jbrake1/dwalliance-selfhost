<?php
declare(strict_types=1);
require_once '/var/www/html/lib/Dw.php';

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $data = is_array($json) ? $json : $_POST;
    foreach (['action', 'id'] as $k) {
        if (!isset($data[$k]) && isset($_GET[$k]) && is_string($_GET[$k])) {
            $data[$k] = $_GET[$k];
        }
    }
    return $data;
}

function shown(array $sent): array
{
    if (array_key_exists('session', $sent)) {
        $sent['session'] = '(sent, not shown)';
    }
    return $sent;
}

function expected(): array
{
    return [
        'action' => 'required: create, update, delete, read, or search',
        'session' => 'JSON field session or cookie session. Required for every action',
        'id' => 'required for update, delete, read',
        'note_id' => 'create: required, one of your notes. update: optional. search: optional exact filter',
        'file_id' => 'create: required, one of your files. update: optional. search: optional exact filter',
        'order' => 'search: optional ascend or descend (asc/desc ok). Default descend, newest first by created',
        'limit' => 'search: optional whole number from 1 to 100. Default 100',
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
        'path' => '/note_files',
        'session' => 'Send the session id as JSON field session or as cookie session.',
        'actions' => [
            'create' => 'Needs a session with NOTE CREATE or SUPER_ADMIN. note_id and file_id are required; both must be yours on this brand. The same note_id + file_id pair can only be linked once. Returns ok, message, id.',
            'update' => 'Needs a session with NOTE UPDATE or SUPER_ADMIN, and id. Optional note_id and/or file_id, checked the same way as create.',
            'delete' => 'Needs a session with NOTE DELETE or SUPER_ADMIN, and id. Removes only the link, not the note or file.',
            'read' => 'Needs a session with NOTE READ or SUPER_ADMIN, and id.',
            'search' => 'Needs a session with NOTE READ. Filters are optional: note_id, file_id (exact). order is ascend or descend, or asc or desc (default descend by created). limit 1 to 100, default 100.',
        ],
        'scope' => 'A person only sees and changes their own links on this session brand.',
    ]);
    exit;
}

function idField(array $sent, string $key): ?string
{
    if (!array_key_exists($key, $sent) || $sent[$key] === null || $sent[$key] === '') {
        return null;
    }
    $v = trim((string)$sent[$key]);
    if (!preg_match('/^[0-9a-f]{1,50}$/', $v)) {
        fail($sent, $key . ' is not a valid id');
    }
    return $v;
}

function searchOrder(array $sent): string
{
    if (!array_key_exists('order', $sent) || $sent['order'] === '' || $sent['order'] === null) {
        return 'DESC';
    }
    $v = strtolower(trim((string)$sent['order']));
    if ($v === 'ascend' || $v === 'asc') {
        return 'ASC';
    }
    if ($v === 'descend' || $v === 'desc') {
        return 'DESC';
    }
    fail($sent, 'order must be ascend or descend');
}

function searchLimit(array $sent): int
{
    if (!array_key_exists('limit', $sent) || $sent['limit'] === '' || $sent['limit'] === null) {
        return 100;
    }
    $v = $sent['limit'];
    $n = null;
    if (is_int($v) || (is_float($v) && floor($v) === $v)) {
        $n = (int)$v;
    } elseif (is_string($v) && preg_match('/^\d{1,4}$/', trim($v))) {
        $n = (int)trim($v);
    }
    if ($n === null || $n < 1 || $n > 100) {
        fail($sent, 'limit must be a whole number from 1 to 100');
    }
    return $n;
}

function checkNote(PDO $db, array $sent, string $noteId, string $me, string $brand): void
{
    $q = $db->prepare('SELECT 1 FROM notes WHERE id = ? AND person_id = ? AND brand = ?');
    $q->execute([$noteId, $me, $brand]);
    if (!$q->fetchColumn()) {
        fail($sent, 'note not found', 404);
    }
}

function checkFile(PDO $db, array $sent, string $fileId, string $me, string $brand): void
{
    $q = $db->prepare('SELECT 1 FROM files WHERE id = ? AND owner_id = ? AND owner_type = ? AND brand = ?');
    $q->execute([$fileId, $me, 'PRSN', $brand]);
    if (!$q->fetchColumn()) {
        fail($sent, 'file not found', 404);
    }
}

function checkDuplicate(PDO $db, array $sent, string $noteId, string $fileId, string $me, string $brand, string $exceptId = ''): void
{
    $q = $db->prepare('SELECT 1 FROM note_files WHERE note_id = ? AND file_id = ? AND person_id = ? AND brand = ? AND id <> ?');
    $q->execute([$noteId, $fileId, $me, $brand, $exceptId]);
    if ($q->fetchColumn()) {
        fail($sent, 'this file is already linked to this note', 409);
    }
}

function archive(PDO $db, string $id, string $action): void
{
    $db->prepare('INSERT INTO note_files_history (history_id, id, person_id, note_id, file_id, brand, created, created_by, action) SELECT ?, id, person_id, note_id, file_id, brand, created, created_by, ? FROM note_files WHERE id = ?')->execute([Dw::randId(), $action, $id]);
}

const COLS = 'id, person_id, note_id, file_id, created';

$sent = body();
if ($sent === []) {
    help();
}
$action = strtolower(trim((string)($sent['action'] ?? '')));
if (!in_array($action, ['create', 'update', 'delete', 'read', 'search'], true)) {
    fail($sent, 'action must be create, update, delete, read, or search');
}
$id = trim((string)($sent['id'] ?? ''));
if (!in_array($action, ['create', 'search'], true) && $id === '') {
    fail($sent, 'id is required for ' . $action);
}

try {
    $session = Dw::session($sent);
} catch (Throwable $e) {
    fail($sent, 'database unavailable', 500);
}
if ($session === null) {
    fail($sent, 'a valid session is required for ' . $action, 401);
}
$need = ['create' => 'CREATE', 'update' => 'UPDATE', 'delete' => 'DELETE', 'read' => 'READ', 'search' => 'READ'];
if (!Dw::allows($session, 'NOTE', [$need[$action]])) {
    fail($sent, 'this session does not have NOTE ' . $need[$action], 403);
}
$me = (string)$session['person_id'];
$brand = (string)$session['brand'];

try {
    $db = Dw::pdo();

    if ($action === 'create') {
        $noteId = idField($sent, 'note_id');
        $fileId = idField($sent, 'file_id');
        if ($noteId === null || $fileId === null) {
            fail($sent, 'note_id and file_id are required');
        }
        checkNote($db, $sent, $noteId, $me, $brand);
        checkFile($db, $sent, $fileId, $me, $brand);
        checkDuplicate($db, $sent, $noteId, $fileId, $me, $brand);
        $linkId = Dw::randId();
        $db->beginTransaction();
        $db->prepare('INSERT INTO note_files (id, person_id, note_id, file_id, brand, created, created_by) VALUES (?, ?, ?, ?, ?, NOW(), ?)')->execute([$linkId, $me, $noteId, $fileId, $brand, $me]);
        archive($db, $linkId, 'INSERT');
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'message' => 'OK create success', 'id' => $linkId]);
        exit;
    }

    if ($action === 'search') {
        $noteId = idField($sent, 'note_id');
        $fileId = idField($sent, 'file_id');
        $order = searchOrder($sent);
        $limit = searchLimit($sent);
        $sql = 'SELECT ' . COLS . ' FROM note_files WHERE person_id = ? AND brand = ?';
        $vals = [$me, $brand];
        if ($noteId !== null) {
            $sql .= ' AND note_id = ?';
            $vals[] = $noteId;
        }
        if ($fileId !== null) {
            $sql .= ' AND file_id = ?';
            $vals[] = $fileId;
        }
        $sql .= ' ORDER BY created ' . $order . ', id ' . $order . ' LIMIT ' . $limit;
        $q = $db->prepare($sql);
        $q->execute($vals);
        Dw::jsonOut(200, ['ok' => true, 'note_files' => $q->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $q = $db->prepare('SELECT ' . COLS . ' FROM note_files WHERE id = ? AND person_id = ? AND brand = ?');
    $q->execute([$id, $me, $brand]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fail($sent, 'note_file not found', 404);
    }

    if ($action === 'read') {
        Dw::jsonOut(200, ['ok' => true, 'note_file' => $row]);
        exit;
    }

    if ($action === 'delete') {
        $db->beginTransaction();
        archive($db, $id, 'DELETE');
        $db->prepare('DELETE FROM note_files WHERE id = ? AND person_id = ? AND brand = ?')->execute([$id, $me, $brand]);
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'message' => 'OK delete success', 'id' => $id]);
        exit;
    }

    $noteId = idField($sent, 'note_id');
    $fileId = idField($sent, 'file_id');
    if ($noteId === null && $fileId === null) {
        fail($sent, 'nothing to update: send note_id or file_id');
    }
    if ($noteId !== null) {
        checkNote($db, $sent, $noteId, $me, $brand);
    }
    if ($fileId !== null) {
        checkFile($db, $sent, $fileId, $me, $brand);
    }
    $newNote = $noteId ?? $row['note_id'];
    $newFile = $fileId ?? $row['file_id'];
    checkDuplicate($db, $sent, $newNote, $newFile, $me, $brand, $id);
    $db->beginTransaction();
    archive($db, $id, 'UPDATE');
    $db->prepare('UPDATE note_files SET note_id = ?, file_id = ? WHERE id = ? AND person_id = ? AND brand = ?')->execute([$newNote, $newFile, $id, $me, $brand]);
    $db->commit();
    Dw::jsonOut(200, ['ok' => true, 'message' => 'OK update success', 'id' => $id]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fail($sent, 'could not complete ' . $action, 500);
}
