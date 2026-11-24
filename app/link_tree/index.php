<?php
declare(strict_types=1);
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwLinkTree.php';

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $data = is_array($json) ? $json : $_POST;
    foreach (['action', 'id', 'path'] as $k) {
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
        'action' => 'required: list, read, create, update, or delete',
        'session' => 'JSON field session or cookie session. Required for every action',
        'id' => 'required for update and delete unless path is sent; optional for read',
        'path' => 'the old path, no leading slash, same normalization as /files. required for create; optional filter prefix for list; may identify a row for read, update, and delete',
        'target' => 'required for create: a same-brand path (no leading slash) or an http(s) URL',
        'status' => 'optional, 301 or 302. Default 301',
        'include_live' => 'list: optional true to include live path registrations (target is empty). Default is redirects only',
        'limit' => 'list: optional whole number from 1 to 500. Default 100',
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
        'path' => '/link_tree',
        'session' => 'Send the session id as JSON field session or as cookie session. The session id is never returned.',
        'actions' => [
            'list' => 'Needs a session with FILE READ or SUPER_ADMIN. Lists this brand\'s redirects. Optional path is a prefix filter. include_live true also returns live path registrations (target is empty). Optional limit, 1 to 500, default 100. Returns ok and entries.',
            'read' => 'Needs a session with FILE READ or SUPER_ADMIN, and id or path. Returns ok and entry.',
            'create' => 'Needs a session with FILE CREATE or SUPER_ADMIN, plus path and target. target is a same-brand path or an http(s) URL. Optional status 301 or 302 (default 301). Refused if a file already lives at path (the real file wins). Existing redirects that targeted path are collapsed onto the new target. Returns ok and entry.',
            'update' => 'Needs a session with FILE UPDATE or SUPER_ADMIN, and id or path. Sent fields replace the old ones (target, status, and/or a new path). Returns ok and entry.',
            'delete' => 'Needs a session with FILE DELETE or SUPER_ADMIN, and id or path. Removes that row only. It does not delete the file.',
        ],
        'notes' => '/files create, update, delete and move maintain this table automatically: every uploaded path is registered; a rename or move writes a 301 from the old path to the new one and collapses chains; a delete drops the live registration and does not create a redirect. 404.php looks up a missing path and sends the row\'s status (301 or 302). Manual rows are for extra redirects, including to an http(s) URL.',
        'status' => [301, 302],
        'javascript' => "const res = await fetch('/link_tree/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'list', session: 'SESSION_ID' }) }).then(r => r.json());",
    ]);
    exit;
}

function textField(array $sent, string $key, int $max): ?string
{
    if (!array_key_exists($key, $sent)) {
        return null;
    }
    if (!is_string($sent[$key]) && !is_int($sent[$key])) {
        fail($sent, $key . ' must be a string');
    }
    $v = trim((string)$sent[$key]);
    if (strlen($v) > $max) {
        fail($sent, $key . ' is longer than ' . $max . ' characters');
    }
    return $v;
}

function boolField(array $sent, string $key): ?bool
{
    if (!array_key_exists($key, $sent) || $sent[$key] === '') {
        return null;
    }
    $v = $sent[$key];
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) && ($v === 0 || $v === 1)) {
        return $v === 1;
    }
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if (in_array($s, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no'], true)) {
            return false;
        }
    }
    fail($sent, $key . ' must be true or false');
}

function listLimit(array $sent): int
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
    if ($n === null || $n < 1 || $n > 500) {
        fail($sent, 'limit must be a whole number from 1 to 500');
    }
    return $n;
}

function findRow(PDO $db, string $brand, array $sent, bool $required): ?array
{
    $id = textField($sent, 'id', 50);
    $path = textField($sent, 'path', DwLinkTree::PATH_MAX);
    $row = null;
    if ($id !== null && $id !== '') {
        $row = DwLinkTree::rowById($db, $brand, $id);
    } elseif ($path !== null && $path !== '') {
        $row = DwLinkTree::rowByPath($db, $brand, $path);
    } elseif ($required) {
        fail($sent, 'id or path is required');
    }
    if ($required && $row === null) {
        fail($sent, 'entry not found', 404);
    }
    return $row;
}

$sent = body();
if ($sent === [] || (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && trim((string)($sent['action'] ?? '')) === '')) {
    help();
}

$action = strtolower(trim((string)($sent['action'] ?? '')));
if (!in_array($action, ['list', 'read', 'create', 'update', 'delete'], true)) {
    if ($action === '') {
        help();
    }
    fail($sent, 'action must be list, read, create, update, or delete');
}

try {
    $session = Dw::session($sent);
} catch (Throwable $e) {
    fail($sent, 'database unavailable', 500);
}

$need = ['list' => 'READ', 'read' => 'READ', 'create' => 'CREATE', 'update' => 'UPDATE', 'delete' => 'DELETE'];
if ($session === null) {
    fail($sent, 'a valid session is required for ' . $action, 401);
}
if (!Dw::allows($session, 'FILE', [$need[$action]])) {
    fail($sent, 'this session does not have FILE ' . $need[$action], 403);
}

$brand = (string)$session['brand'];
$by = (string)($session['person_id'] ?? 'link_tree');

try {
    $db = Dw::pdo();

    if ($action === 'list') {
        $prefix = textField($sent, 'path', DwLinkTree::PATH_MAX);
        $includeLive = boolField($sent, 'include_live') ?? false;
        $limit = listLimit($sent);
        $sql = 'SELECT id, path, target, status, source, created, updated FROM link_tree WHERE brand = ?';
        $args = [$brand];
        if (!$includeLive) {
            $sql .= ' AND target IS NOT NULL AND target <> \'\'';
        }
        if ($prefix !== null && $prefix !== '') {
            $norm = DwLinkTree::normalize($prefix);
            if ($norm === null) {
                fail($sent, 'path is not a valid file path');
            }
            if ($norm !== '') {
                $sql .= ' AND (path = ? OR path LIKE ?)';
                $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $norm) . '/%';
                $args[] = $norm;
                $args[] = $like;
            }
        }
        $sql .= ' ORDER BY path ASC LIMIT ' . $limit;
        $q = $db->prepare($sql);
        $q->execute($args);
        $entries = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $entries[] = DwLinkTree::publicRow($row);
        }
        Dw::jsonOut(200, ['ok' => true, 'entries' => $entries]);
        exit;
    }

    if ($action === 'read') {
        $row = findRow($db, $brand, $sent, true);
        Dw::jsonOut(200, ['ok' => true, 'entry' => DwLinkTree::publicRow($row)]);
        exit;
    }

    if ($action === 'create') {
        $path = textField($sent, 'path', DwLinkTree::PATH_MAX);
        $target = textField($sent, 'target', DwLinkTree::TARGET_MAX);
        if ($path === null || $path === '') {
            fail($sent, 'path is required for create');
        }
        if ($target === null || $target === '') {
            fail($sent, 'target is required for create');
        }
        $status = array_key_exists('status', $sent) && $sent['status'] !== '' && $sent['status'] !== null
            ? DwLinkTree::statusOf($sent['status'])
            : DwLinkTree::STATUS_DEFAULT;
        if ($status === 0) {
            fail($sent, 'status must be 301 or 302');
        }
        $existing = DwLinkTree::rowByPath($db, $brand, $path);
        if ($existing) {
            fail($sent, 'an entry already exists at this path; use update', 409);
        }
        $db->beginTransaction();
        try {
            $entry = DwLinkTree::setRedirect($db, $brand, $path, $target, $status, 'manual', $by);
            $db->commit();
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            fail($sent, $e->getMessage());
        }
        Dw::jsonOut(200, ['ok' => true, 'message' => 'OK create success', 'entry' => $entry]);
        exit;
    }

    if ($action === 'update') {
        $row = findRow($db, $brand, $sent, true);
        $newPath = textField($sent, 'path', DwLinkTree::PATH_MAX);
        // If the row was found by path and no new path is intended, don't treat the identifier as a rename.
        $idSent = textField($sent, 'id', 50);
        $renameTo = null;
        if ($idSent !== null && $idSent !== '' && $newPath !== null && $newPath !== '' && $newPath !== (string)$row['path']) {
            $renameTo = $newPath;
        }
        $target = textField($sent, 'target', DwLinkTree::TARGET_MAX);
        $statusIn = array_key_exists('status', $sent) && $sent['status'] !== '' && $sent['status'] !== null
            ? DwLinkTree::statusOf($sent['status'])
            : null;
        if ($statusIn === 0) {
            fail($sent, 'status must be 301 or 302');
        }
        if ($renameTo === null && $target === null && $statusIn === null) {
            fail($sent, 'nothing to update: send target, status, or a new path (with id)');
        }
        $usePath = $renameTo !== null ? $renameTo : (string)$row['path'];
        $useTarget = $target !== null ? $target : (string)($row['target'] ?? '');
        $useStatus = $statusIn !== null ? $statusIn : (DwLinkTree::statusOf($row['status']) ?: DwLinkTree::STATUS_DEFAULT);
        if ($useTarget === '') {
            fail($sent, 'target is required to keep this as a redirect; delete the entry instead');
        }
        if ($renameTo !== null && $renameTo !== (string)$row['path']) {
            $clash = DwLinkTree::rowByPath($db, $brand, $renameTo);
            if ($clash && (string)$clash['id'] !== (string)$row['id']) {
                fail($sent, 'an entry already exists at this path', 409);
            }
        }
        $db->beginTransaction();
        try {
            if ($renameTo !== null && $renameTo !== (string)$row['path']) {
                $db->prepare('UPDATE link_tree SET path = ?, updated = NOW(), updated_by = ? WHERE id = ? AND brand = ?')
                    ->execute([$usePath, $by, $row['id'], $brand]);
            }
            $entry = DwLinkTree::setRedirect($db, $brand, $usePath, $useTarget, $useStatus, 'manual', $by);
            $db->commit();
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            fail($sent, $e->getMessage());
        }
        Dw::jsonOut(200, ['ok' => true, 'message' => 'OK update success', 'entry' => $entry]);
        exit;
    }

    if ($action === 'delete') {
        $row = findRow($db, $brand, $sent, true);
        $db->beginTransaction();
        $ok = DwLinkTree::deleteRow($db, $brand, (string)$row['id']);
        $db->commit();
        if (!$ok) {
            fail($sent, 'entry not found', 404);
        }
        Dw::jsonOut(200, ['ok' => true, 'message' => 'OK delete success', 'id' => $row['id']]);
        exit;
    }
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fail($sent, 'could not complete ' . $action, 500);
}
