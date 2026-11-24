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
        'note' => 'create: required text, up to 65000 characters. update: optional new text. search: optional text the note contains',
        'gps' => 'create/update: optional JSON object such as {"lat": 21.3, "lng": -157.8}, or null',
        'date' => 'search: optional created day, YYYY-MM-DD',
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
        'path' => '/notes',
        'session' => 'Send the session id as JSON field session or as cookie session.',
        'actions' => [
            'create' => 'Needs a session with NOTE CREATE or SUPER_ADMIN. note is required; gps is optional. Returns ok, message, id.',
            'update' => 'Needs a session with NOTE UPDATE or SUPER_ADMIN, and id. Sent note and gps replace the old ones.',
            'delete' => 'Needs a session with NOTE DELETE or SUPER_ADMIN, and id. Also removes the note_files links for that note.',
            'read' => 'Needs a session with NOTE READ or SUPER_ADMIN, and id.',
            'search' => 'Needs a session with NOTE READ. Filters are optional: note (contains), date (YYYY-MM-DD). order is ascend or descend, or asc or desc (default descend by created). limit 1 to 100, default 100.',
        ],
        'scope' => 'A person only sees and changes their own notes on this session brand.',
    ]);
    exit;
}

function noteText(array $sent, bool $required): ?string
{
    if (!array_key_exists('note', $sent) || $sent['note'] === null) {
        if ($required) {
            fail($sent, 'note is required');
        }
        return null;
    }
    if (!is_string($sent['note'])) {
        fail($sent, 'note must be text');
    }
    $v = trim($sent['note']);
    if ($required && $v === '') {
        fail($sent, 'note is required');
    }
    if (strlen($v) > 65000) {
        fail($sent, 'note is longer than 65000 characters');
    }
    return $v;
}

// Returns [present, jsonOrNull]
function gpsValue(array $sent): array
{
    if (!array_key_exists('gps', $sent)) {
        return [false, null];
    }
    $g = $sent['gps'];
    if ($g === null || $g === '') {
        return [true, null];
    }
    if (is_string($g)) {
        $g = json_decode($g, true);
    }
    if (!is_array($g) || $g === [] || array_keys($g) === range(0, count($g) - 1)) {
        fail($sent, 'gps must be a JSON object such as {"lat": 21.3, "lng": -157.8}, or null');
    }
    $out = [];
    foreach ($g as $k => $v) {
        if (!is_string($k) || !preg_match('/^[a-z_]{1,20}$/', $k) || count($g) > 10) {
            fail($sent, 'gps keys must be short lowercase names such as lat, lng, accuracy, altitude');
        }
        if (!is_int($v) && !is_float($v)) {
            fail($sent, 'gps values must be numbers');
        }
        $out[$k] = $v;
    }
    foreach (['lat' => 90, 'lng' => 180] as $k => $max) {
        if (!isset($out[$k])) {
            fail($sent, 'gps needs lat and lng');
        }
        if (abs($out[$k]) > $max) {
            fail($sent, 'gps ' . $k . ' is out of range');
        }
    }
    return [true, json_encode($out)];
}

function likeContains(string $value): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

function searchDate(array $sent): ?string
{
    if (!array_key_exists('date', $sent) || $sent['date'] === '' || $sent['date'] === null) {
        return null;
    }
    if (!is_string($sent['date'])) {
        fail($sent, 'date must be YYYY-MM-DD');
    }
    $v = trim($sent['date']);
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $v) {
        fail($sent, 'date must be YYYY-MM-DD');
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

function outRow(array $r): array
{
    return [
        'id' => $r['id'],
        'person_id' => $r['person_id'],
        'note' => $r['note'],
        'gps' => $r['gps'] === null ? null : json_decode((string)$r['gps'], true),
        'created' => $r['created'],
    ];
}

function archive(PDO $db, string $id, string $action): void
{
    $db->prepare('INSERT INTO notes_history (history_id, id, person_id, note, gps, brand, created, created_by, action) SELECT ?, id, person_id, note, gps, brand, created, created_by, ? FROM notes WHERE id = ?')->execute([Dw::randId(), $action, $id]);
}

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
        $text = noteText($sent, true);
        [, $gps] = gpsValue($sent);
        $noteId = Dw::randId();
        $db->beginTransaction();
        $db->prepare('INSERT INTO notes (id, person_id, note, gps, brand, created, created_by) VALUES (?, ?, ?, ?, ?, NOW(), ?)')->execute([$noteId, $me, $text, $gps, $brand, $me]);
        archive($db, $noteId, 'INSERT');
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'message' => 'OK create success', 'id' => $noteId]);
        exit;
    }

    if ($action === 'search') {
        $text = noteText($sent, false);
        $date = searchDate($sent);
        $order = searchOrder($sent);
        $limit = searchLimit($sent);
        $sql = 'SELECT id, person_id, note, gps, created FROM notes WHERE person_id = ? AND brand = ?';
        $vals = [$me, $brand];
        if ($text !== null && $text !== '') {
            $sql .= " AND note LIKE ? ESCAPE '\\\\'";
            $vals[] = likeContains($text);
        }
        if ($date !== null) {
            $sql .= ' AND created >= ? AND created < ?';
            $vals[] = $date . ' 00:00:00';
            $vals[] = DateTimeImmutable::createFromFormat('!Y-m-d', $date)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        $sql .= ' ORDER BY created ' . $order . ', id ' . $order . ' LIMIT ' . $limit;
        $q = $db->prepare($sql);
        $q->execute($vals);
        Dw::jsonOut(200, ['ok' => true, 'notes' => array_map('outRow', $q->fetchAll(PDO::FETCH_ASSOC))]);
        exit;
    }

    $q = $db->prepare('SELECT id, person_id, note, gps, created FROM notes WHERE id = ? AND person_id = ? AND brand = ?');
    $q->execute([$id, $me, $brand]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fail($sent, 'note not found', 404);
    }

    if ($action === 'read') {
        Dw::jsonOut(200, ['ok' => true, 'note' => outRow($row)]);
        exit;
    }

    if ($action === 'delete') {
        $db->beginTransaction();
        $links = $db->prepare('SELECT id FROM note_files WHERE note_id = ? AND person_id = ? AND brand = ?');
        $links->execute([$id, $me, $brand]);
        foreach ($links->fetchAll(PDO::FETCH_COLUMN) as $linkId) {
            $db->prepare('INSERT INTO note_files_history (history_id, id, person_id, note_id, file_id, brand, created, created_by, action) SELECT ?, id, person_id, note_id, file_id, brand, created, created_by, ? FROM note_files WHERE id = ?')->execute([Dw::randId(), 'DELETE', $linkId]);
            $db->prepare('DELETE FROM note_files WHERE id = ?')->execute([$linkId]);
        }
        archive($db, $id, 'DELETE');
        $db->prepare('DELETE FROM notes WHERE id = ? AND person_id = ? AND brand = ?')->execute([$id, $me, $brand]);
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'message' => 'OK delete success', 'id' => $id]);
        exit;
    }

    $sets = [];
    $vals = [];
    $text = noteText($sent, false);
    if ($text !== null) {
        if ($text === '') {
            fail($sent, 'note cannot be empty');
        }
        $sets[] = 'note = ?';
        $vals[] = $text;
    }
    [$hasGps, $gps] = gpsValue($sent);
    if ($hasGps) {
        $sets[] = 'gps = ?';
        $vals[] = $gps;
    }
    if ($sets === []) {
        fail($sent, 'nothing to update: send note or gps');
    }
    array_push($vals, $id, $me, $brand);
    $db->beginTransaction();
    archive($db, $id, 'UPDATE');
    $db->prepare('UPDATE notes SET ' . implode(', ', $sets) . ' WHERE id = ? AND person_id = ? AND brand = ?')->execute($vals);
    $db->commit();
    Dw::jsonOut(200, ['ok' => true, 'message' => 'OK update success', 'id' => $id]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fail($sent, 'could not complete ' . $action, 500);
}
