<?php
declare(strict_types=1);
require_once '/var/www/html/lib/Dw.php';

function bumpHtmlCacheIfNeeded(PDO $db, ?array $row, ?string $by = 'files', ?string $fileId = null, ?string $fileRel = null): void
{
    if (!is_array($row)) {
        return;
    }
    $type = (string)($row['file_type'] ?? '');
    $vis = (string)($row['public_private'] ?? '');
    $brand = (string)($row['brand'] ?? '');
    $media = in_array($type, ['JPG', 'PNG', 'WEBP', 'PDF'], true);
    $html = $type === 'HTML';
    if ((!$html && !$media) || $vis !== 'PUBLIC' || $brand === '') {
        return;
    }
    require_once '/var/www/html/lib/DwHtmlCache.php';
    $who = $by !== null && $by !== '' ? $by : 'files';
    if ($media && is_string($fileId) && $fileId !== '') {
        DwHtmlCache::bump($db, $brand, $who, 'files', $fileId, $fileRel);
        return;
    }
    DwHtmlCache::bump($db, $brand, $who, 'files');
}

function cacheRelFromRow(array $row): ?string
{
    $name = (string)($row['file_name'] ?? '');
    if ($name === '') {
        return null;
    }
    $path = trim((string)($row['file_path'] ?? ''), '/');
    return $path !== '' ? $path . '/' . $name : $name;
}

// Watermarks add-on gate: only when the change would add owner-marked public files past the free count, without a grant.
// $after is the file row after the change (id, watermark_mode, public_private, file_type). $isNew is a create.
function watermarkGate(PDO $db, array $sent, string $brand, array $after, bool $isNew): void
{
    require_once '/var/www/html/lib/DwWatermark.php';
    require_once '/var/www/html/lib/DwGate.php';
    $opts = $isNew ? ['extra' => $after] : ['files' => [(string)$after['id'] => $after]];
    $c = DwWatermark::ownerMarkRefuseChange($db, $brand, $opts);
    if ($c !== null) {
        Dw::jsonOut(402, DwGate::denyBody($c) + ['you_sent' => shown($sent)]);
        exit;
    }
}

function attachWatermarkGate(PDO $db, string $brand, array $payload): array
{
    require_once '/var/www/html/lib/DwGate.php';
    require_once '/var/www/html/lib/DwWatermark.php';
    $g = DwGate::check($db, $brand, 'watermark');
    $g['usage'] = DwWatermark::ownerUsage($db, $brand);
    $payload['watermark_gate'] = $g;
    return $payload;
}

function watermarkMode(array $sent): ?string
{
    if (!isset($sent['watermark_mode']) || $sent['watermark_mode'] === '') {
        return null;
    }
    $v = strtolower(trim((string)$sent['watermark_mode']));
    if (!in_array($v, ['default', 'on', 'off'], true)) {
        fail($sent, 'watermark_mode must be default, on or off');
    }
    return $v;
}


const FILE_TYPES = ['JPG', 'PNG', 'GIF', 'WEBP', 'SVG', 'HTML', 'CSS', 'JS', 'JSON', 'PDF', 'TXT', 'ICO', 'WOFF', 'WOFF2', 'MP4'];
const EXT_TYPES = ['jpg' => 'JPG', 'jpeg' => 'JPG', 'png' => 'PNG', 'gif' => 'GIF', 'webp' => 'WEBP', 'svg' => 'SVG', 'html' => 'HTML', 'htm' => 'HTML', 'css' => 'CSS', 'js' => 'JS', 'json' => 'JSON', 'pdf' => 'PDF', 'txt' => 'TXT', 'ico' => 'ICO', 'woff' => 'WOFF', 'woff2' => 'WOFF2', 'mp4' => 'MP4'];

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
    if (isset($sent['file_data']) && is_string($sent['file_data'])) {
        $sent['file_data'] = '(' . strlen($sent['file_data']) . ' base64 characters)';
    }
    if (isset($sent['file_text']) && is_string($sent['file_text'])) {
        $sent['file_text'] = '(' . strlen($sent['file_text']) . ' bytes of text)';
    }
    if (isset($_FILES['file']) && is_array($_FILES['file'])) {
        $sent['file'] = '(multipart file ' . (string)($_FILES['file']['name'] ?? '') . ', ' . (int)($_FILES['file']['size'] ?? 0) . ' bytes)';
    }
    return $sent;
}

function expected(): array
{
    return [
        'action' => 'required: create, update, delete, read, search, or move',
        'session' => 'JSON field session or cookie session. Required for create, update, delete, search, and for read of a PRIVATE file',
        'id' => 'required for update, delete, read',
        'file' => 'create: multipart field file, or JSON file_data as base64. update: optional new bytes the same way',
        'file_name' => 'search: optional text contained in the file name. create/update: optional, up to 100 characters',
        'file_path' => 'optional, up to 200 characters',
        'file_type' => 'search: optional exact type. create/update: optional, one of ' . implode(',', FILE_TYPES) . '. Default from the file_name extension, else TXT',
        'file_size' => 'search: optional exact size in bytes',
        'date' => 'search: optional created day, YYYY-MM-DD',
        'order' => 'search: optional ascend or descend (asc/desc ok). Default descend, newest first by created',
        'limit' => 'search: optional whole number from 1 to 100',
        'public_private' => 'optional, PUBLIC or PRIVATE. Default PRIVATE',
        'watermark_mode' => 'optional, default, on or off. Owner mark only (the DW promo mark on a free site cannot be turned off here). Default default (follow the domain, then the global default)',
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
        'path' => '/files',
        'session' => 'Send the session id as JSON field session or as cookie session.',
        'actions' => [
            'create' => 'Needs a session with FILE CREATE or SUPER_ADMIN. Send multipart field file, or JSON file_data as base64. Optional file_name, file_path, file_type, public_private (default PRIVATE), watermark_mode (default, on or off; default default). Returns ok, message, id.',
            'update' => 'Needs a session with FILE UPDATE or SUPER_ADMIN, and id. Sent fields replace the old ones. New file bytes replace file_data. watermark_mode (default, on or off) is the owner-mark switch only.',
            'delete' => 'Needs a session with FILE DELETE or SUPER_ADMIN, and id.',
            'read' => 'Needs id. A PUBLIC file needs no session. A PRIVATE file needs a session with FILE READ, READ_PRIVATE, or SUPER_ADMIN. file_data comes back as base64; decode file_data from base64. The brand code is never returned; owner_id and owner_type only to a session on the file\'s brand.',
            'search' => 'Needs a session with FILE READ. Filters are optional: file_name, file_type, file_size, date. With none, it lists your newest files (up to limit, default 100). file_name matches a name that contains the text. file_type is exact. file_size is the exact byte count. date is the created day, YYYY-MM-DD. order is ascend or descend, or asc or desc (default descend, newest first by created). limit is optional, 1 to 100. Returns metadata only. A person only gets files they uploaded on this session brand.',
            'move' => 'Needs a session with FILE UPDATE or SUPER_ADMIN, plus path and new_path (no leading slash). Moves a single file or every file under a folder, writes 301 redirects from the old paths, and collapses any chain so earlier paths go straight to the new one. Returns ok and moved (the number of files).',
        ],
        'chatgpt_editor' => [
            'auth' => DwBrand::t('Instead of a session you may send Authorization: Bearer <access token> from DW Alliance OAuth (/oauth/authorize/, /oauth/token/). Same rights as the person who connected it. Spec: /openapi/files.json'),
            'draft' => 'FILE CREATE. New content as a PRIVATE draft: file_text (text files) or file_data (base64) or multipart file, plus file_name/file_path, or replaces (a live file id; found by name and folder when omitted). Returns id and preview_url. Nothing goes live.',
            'publish' => 'FILE UPDATE. id of a draft. The replaced file gets the draft content and becomes PUBLIC (earlier version kept in history), or a new draft becomes PUBLIC. Returns live_url.',
            'preview' => 'FILE READ. id of a draft. A fresh preview_url.',
            'discard' => 'FILE UPDATE. id of a draft. Deletes the draft only.',
            'versions' => 'FILE READ. id. Earlier versions from file history.',
            'restore' => 'FILE CREATE. id and history_id. That version comes back as a draft to preview and publish.',
            'read format' => 'read with format text returns file_text (UTF-8) instead of base64 file_data for HTML, CSS, JS, JSON, TXT and SVG.',
            'search options' => 'all_owners true lists every file on the brand (SUPER_ADMIN or FILE READ_PRIVATE). in_path limits to one folder (exact, "" = top folder).',
        ],
        'created' => 'created is database server time; created_utc_offset in read and search answers gives its offset from UTC, e.g. -08:00.',
        'file_types' => FILE_TYPES,
        'public_private' => ['PUBLIC', 'PRIVATE'],
        'watermark_mode' => ['default', 'on', 'off'],
        'notes' => 'watermark_mode is the owner mark only. The DW promo mark on a free site cannot be turned off per file. Admin UI: GET /files/. create, update, delete and move also update /link_tree: every uploaded path is registered; a rename or move writes a 301 from the old path to the new one; a delete drops the live registration and does not create a redirect.',
        'javascript' => "const res = await fetch('/files/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'read', id: 'FILE_ID' }) }).then(r => r.json()); const bytes = Uint8Array.from(atob(res.file.file_data), c => c.charCodeAt(0));",
    ]);
    exit;
}

function fileBytes(array $sent): ?string
{
    if (isset($_FILES['file']) && is_array($_FILES['file'])) {
        if (($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            fail($sent, 'multipart file upload failed');
        }
        $data = file_get_contents($_FILES['file']['tmp_name']);
        return $data === false ? null : $data;
    }
    if (isset($sent['file_data'])) {
        if (!is_string($sent['file_data'])) {
            fail($sent, 'file_data must be a base64 string');
        }
        $data = base64_decode($sent['file_data'], true);
        if ($data === false) {
            fail($sent, 'file_data is not valid base64');
        }
        return $data;
    }
    // ChatGPT editor (refs #67): plain UTF-8 text for text files, instead of base64.
    if (isset($sent['file_text'])) {
        if (!is_string($sent['file_text'])) {
            fail($sent, 'file_text must be a string');
        }
        return $sent['file_text'];
    }
    return null;
}

function typeFor(array $sent, ?string $name): ?string
{
    if (isset($sent['file_type']) && $sent['file_type'] !== '') {
        $t = strtoupper(trim((string)$sent['file_type']));
        if (!in_array($t, FILE_TYPES, true)) {
            fail($sent, 'file_type must be one of ' . implode(',', FILE_TYPES));
        }
        return $t;
    }
    if ($name !== null && $name !== '') {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return EXT_TYPES[$ext] ?? 'TXT';
    }
    return null;
}

function visibility(array $sent): ?string
{
    if (!isset($sent['public_private']) || $sent['public_private'] === '') {
        return null;
    }
    $v = strtoupper(trim((string)$sent['public_private']));
    if ($v !== 'PUBLIC' && $v !== 'PRIVATE') {
        fail($sent, 'public_private must be PUBLIC or PRIVATE');
    }
    return $v;
}

function textField(array $sent, string $key, int $max): ?string
{
    if (!array_key_exists($key, $sent)) {
        return null;
    }
    $v = trim((string)$sent[$key]);
    if (strlen($v) > $max) {
        fail($sent, $key . ' is longer than ' . $max . ' characters');
    }
    return $v;
}

function searchSize(array $sent): ?int
{
    if (!array_key_exists('file_size', $sent) || $sent['file_size'] === '' || $sent['file_size'] === null) {
        return null;
    }
    $v = $sent['file_size'];
    if (is_int($v) || (is_float($v) && floor($v) === $v)) {
        if ($v < 0 || $v > 2000000000) {
            fail($sent, 'file_size must be a byte count');
        }
        return (int)$v;
    }
    if (is_string($v) && preg_match('/^\d{1,10}$/', trim($v))) {
        return (int)trim($v);
    }
    fail($sent, 'file_size must be a byte count');
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
    $errors = DateTimeImmutable::getLastErrors();
    $bad = !$day instanceof DateTimeImmutable
        || $day->format('Y-m-d') !== $v
        || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0));
    if ($bad) {
        fail($sent, 'date must be YYYY-MM-DD');
    }
    return $day->format('Y-m-d');
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

function searchLimit(array $sent): ?int
{
    if (!array_key_exists('limit', $sent) || $sent['limit'] === '' || $sent['limit'] === null) {
        return null;
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

// 2026-11-11 (#16): created is database server time with no zone; report its UTC offset, e.g. "-08:00".
function utcOffset(PDO $db): string
{
    $sec = (int)$db->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
    $sec = (int)(round($sec / 900) * 900);
    return ($sec < 0 ? '-' : '+') . sprintf('%02d:%02d', intdiv(abs($sec), 3600), intdiv(abs($sec) % 3600, 60));
}

function archive(PDO $db, string $id, string $action): void
{
    $db->prepare('INSERT INTO files_history (history_id, id, file_data, file_name, file_path, file_type, created, brand, owner_id, owner_type, public_private, watermark_mode, action) SELECT ?, id, file_data, file_name, file_path, file_type, created, brand, owner_id, owner_type, public_private, watermark_mode, ? FROM files WHERE id = ?')->execute([Dw::randId(), $action, $id]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    filesPage();
}

$sent = body();
if ($sent === [] && empty($_FILES)) {
    help();
}
$action = strtolower(trim((string)($sent['action'] ?? '')));
if (!in_array($action, ['create', 'update', 'delete', 'read', 'search', 'move', 'draft', 'preview', 'publish', 'discard', 'versions', 'restore'], true)) {
    fail($sent, 'action must be create, update, delete, read, search, move, draft, preview, publish, discard, versions, or restore');
}
$id = trim((string)($sent['id'] ?? ''));
if (!in_array($action, ['create', 'search', 'draft', 'move'], true) && $id === '') {
    fail($sent, 'id is required for ' . $action);
}

try {
    $session = Dw::session($sent);
} catch (Throwable $e) {
    fail($sent, 'database unavailable', 500);
}
// ChatGPT editor (refs #67): Authorization: Bearer <OAuth access token> when no session is sent.
// Same person, brand and live rights; callers with a session never reach this.
if ($session === null) {
    require_once '/var/www/html/lib/DwOAuth.php';
    try {
        $session = DwOAuth::filesSession($action, $sent);
    } catch (Throwable $e) {
        fail($sent, 'database unavailable', 500);
    }
}

$need = ['create' => 'CREATE', 'update' => 'UPDATE', 'delete' => 'DELETE', 'search' => 'READ', 'move' => 'UPDATE',
    'draft' => 'CREATE', 'restore' => 'CREATE', 'publish' => 'UPDATE', 'discard' => 'UPDATE', 'preview' => 'READ', 'versions' => 'READ'];
if (isset($need[$action])) {
    if ($session === null) {
        fail($sent, 'a valid session is required for ' . $action, 401);
    }
    if (!Dw::allows($session, 'FILE', [$need[$action]])) {
        fail($sent, 'this session does not have FILE ' . $need[$action], 403);
    }
}

if (in_array($action, ['draft', 'preview', 'publish', 'discard', 'versions', 'restore'], true)) {
    require_once '/var/www/html/lib/DwFilesEditor.php';
    try {
        DwFilesEditor::handle($action, $id, $sent, $session);
    } catch (Throwable $e) {
        // An open transaction on the editor's own connection rolls back when the connection closes.
        error_log('files editor: ' . get_class($e) . ' ' . $e->getMessage());
        fail($sent, 'server error', 500);
    }
    exit;
}

try {
    $db = Dw::pdo();

    if ($action === 'create') {
        $bytes = fileBytes($sent);
        if ($bytes === null) {
            fail($sent, 'file is required: multipart field file, or JSON file_data as base64');
        }
        $name = textField($sent, 'file_name', 100);
        if (($name === null || $name === '') && isset($_FILES['file']['name'])) {
            $name = substr((string)$_FILES['file']['name'], 0, 100);
        }
        $path = textField($sent, 'file_path', 200);
        $type = typeFor($sent, $name) ?? 'TXT';
        $vis = visibility($sent) ?? 'PRIVATE';
        $wmSent = watermarkMode($sent);
        $wm = $wmSent ?? 'default';
        $newId = Dw::randId();
        watermarkGate($db, $sent, (string)$session['brand'], ['id' => $newId, 'watermark_mode' => $wm, 'public_private' => $vis, 'file_type' => $type, 'created' => date('Y-m-d H:i:s')], true);
        $fileId = $newId;
        $db->beginTransaction();
        $db->prepare('INSERT INTO files (id, file_data, file_name, file_path, file_type, created, brand, owner_id, owner_type, public_private, watermark_mode) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)')->execute([$fileId, $bytes, $name, $path, $type, $session['brand'], $session['person_id'], 'PRSN', $vis, $wm]);
        archive($db, $fileId, 'INSERT');
        $rel = cacheRelFromRow(['file_name' => $name, 'file_path' => $path]);
        bumpHtmlCacheIfNeeded($db, ['file_type' => $type, 'public_private' => $vis, 'brand' => $session['brand']], (string)$session['person_id'], $fileId, $rel);
        require_once '/var/www/html/lib/DwLinkTree.php';
        DwLinkTree::onSaved($db, (string)$session['brand'], null, ['file_name' => $name, 'file_path' => $path], (string)($session['person_id'] ?? 'files'));
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'message' => 'OK upload success', 'id' => $fileId]);
        exit;
    }

    if ($action === 'search') {
        $name = textField($sent, 'file_name', 100);
        $type = null;
        if (isset($sent['file_type']) && trim((string)$sent['file_type']) !== '') {
            $type = typeFor($sent, null);
        }
        $size = searchSize($sent);
        $date = searchDate($sent);
        $order = searchOrder($sent);
        $limit = searchLimit($sent);
        if ($limit === null && ($name === null || $name === '') && $type === null && $size === null && $date === null) {
            $limit = 100;
        }
        $sql = 'SELECT id, file_name, file_path, file_type, OCTET_LENGTH(file_data) AS file_size, created, owner_id, owner_type, public_private, watermark_mode FROM files WHERE owner_id = ? AND owner_type = ? AND brand = ?';
        $vals = [$session['person_id'], 'PRSN', $session['brand']];
        // ChatGPT editor (refs #67): all_owners (SUPER_ADMIN or FILE READ_PRIVATE) lists the whole brand; in_path = one folder.
        if (!empty($sent['all_owners']) && $sent['all_owners'] !== 'false') {
            if (!Dw::allows($session, 'FILE', ['READ_PRIVATE'])) {
                fail($sent, 'all_owners needs SUPER_ADMIN or FILE READ_PRIVATE', 403);
            }
            $sql = 'SELECT id, file_name, file_path, file_type, OCTET_LENGTH(file_data) AS file_size, created, owner_id, owner_type, public_private, watermark_mode FROM files WHERE brand = ? AND NOT EXISTS (SELECT 1 FROM file_draft fd WHERE fd.id = files.id AND fd.status = \'DRAFT\')';
            $vals = [$session['brand']];
        }
        if (array_key_exists('in_path', $sent) && $sent['in_path'] !== null) {
            $sql .= " AND TRIM(BOTH '/' FROM COALESCE(file_path, '')) = ?";
            $vals[] = trim((string)$sent['in_path'], '/');
        }
        if ($name !== null && $name !== '') {
            $sql .= " AND file_name LIKE ? ESCAPE '\\\\'";
            $vals[] = likeContains($name);
        }
        if ($type !== null) {
            $sql .= ' AND file_type = ?';
            $vals[] = $type;
        }
        if ($size !== null) {
            $sql .= ' AND OCTET_LENGTH(file_data) = ?';
            $vals[] = $size;
        }
        if ($date !== null) {
            $sql .= ' AND created >= ? AND created < ?';
            $vals[] = $date . ' 00:00:00';
            $vals[] = DateTimeImmutable::createFromFormat('!Y-m-d', $date)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        $sql .= ' ORDER BY created ' . $order;
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        $q = $db->prepare($sql);
        $q->execute($vals);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $siteDomain = null;
        if (!empty($session['oauth_grant'])) {
            require_once '/var/www/html/lib/DwFilesEditor.php';
            $siteDomain = DwFilesEditor::siteDomain($db, $session);
        }
        foreach ($rows as &$row) {
            $row['file_size'] = (int)$row['file_size'];
            if ($siteDomain !== null && $row['public_private'] === 'PUBLIC') {
                $row['public_url'] = DwFilesEditor::liveUrl($siteDomain, $row);
            }
        }
        unset($row);
        Dw::jsonOut(200, attachWatermarkGate($db, (string)$session['brand'], ['ok' => true, 'created_utc_offset' => utcOffset($db), 'files' => $rows]));
        exit;
    }


    if ($action === 'move') {
        require_once '/var/www/html/lib/DwLinkTree.php';
        $from = textField($sent, 'path', 200);
        $to = textField($sent, 'new_path', 200);
        if ($from === null || $from === '') {
            $from = textField($sent, 'from_path', 200);
        }
        if ($to === null || $to === '') {
            $to = textField($sent, 'to_path', 200);
        }
        if ($from === null || $from === '' || $to === null || $to === '') {
            fail($sent, 'move needs path and new_path');
        }
        $fromN = DwLinkTree::normalize($from);
        $toN = DwLinkTree::normalize($to);
        if ($fromN === null || $fromN === '' || $toN === null || $toN === '') {
            fail($sent, 'path and new_path must be valid file paths');
        }
        if ($fromN === $toN) {
            fail($sent, 'path and new_path are the same');
        }
        if (str_starts_with($toN . '/', $fromN . '/')) {
            fail($sent, 'new_path cannot be inside path');
        }
        if (strlen($toN) > 200) {
            fail($sent, 'new_path is longer than 200 characters');
        }
        $brand = (string)$session['brand'];
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $fromN) . '/%';
        $q = $db->prepare("SELECT id, file_name, file_path, file_type, public_private, brand FROM files WHERE brand = ? AND (TRIM(BOTH '/' FROM COALESCE(file_path, '')) = ? OR TRIM(BOTH '/' FROM COALESCE(file_path, '')) LIKE ? OR (file_name = ? AND TRIM(BOTH '/' FROM COALESCE(file_path, '')) = ?))");
        $fromName = str_contains($fromN, '/') ? substr($fromN, strrpos($fromN, '/') + 1) : $fromN;
        $fromDir = str_contains($fromN, '/') ? substr($fromN, 0, strrpos($fromN, '/')) : '';
        $q->execute([$brand, $fromN, $like, $fromName, $fromDir]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $isFolder = false;
        foreach ($rows as $r) {
            $dir = trim((string)$r['file_path'], '/');
            if ($dir === $fromN || str_starts_with($dir . '/', $fromN . '/')) {
                $isFolder = true;
                break;
            }
        }
        $moves = [];
        foreach ($rows as $r) {
            $oldRel = DwLinkTree::rel($r['file_path'] ?? '', $r['file_name'] ?? '');
            $oldN = DwLinkTree::normalize($oldRel) ?? '';
            $dir = trim((string)$r['file_path'], '/');
            $name = (string)$r['file_name'];
            if ($isFolder && ($dir === $fromN || str_starts_with($dir . '/', $fromN . '/'))) {
                $rest = $dir === $fromN ? '' : substr($dir, strlen($fromN) + 1);
                $newDir = $rest === '' ? $toN : $toN . '/' . $rest;
                $newName = $name;
            } elseif ($oldN === $fromN) {
                $slash = strrpos($toN, '/');
                $newName = $slash === false ? $toN : substr($toN, $slash + 1);
                $newDir = $slash === false ? '' : substr($toN, 0, $slash);
            } else {
                continue;
            }
            if (strlen($newDir) > 200 || strlen((string)$newName) > 100 || $newName === '') {
                fail($sent, 'new_path would make a file_path or file_name too long');
            }
            $moves[] = ['row' => $r, 'new_dir' => $newDir, 'new_name' => $newName, 'old' => $oldN];
        }
        if ($moves === []) {
            fail($sent, 'no files at that path', 404);
        }
        $movingIds = [];
        foreach ($moves as $m) {
            $movingIds[(string)$m['row']['id']] = true;
        }
        foreach ($moves as $m) {
            $c = $db->prepare("SELECT id FROM files WHERE brand = ? AND file_name = ? AND TRIM(BOTH '/' FROM COALESCE(file_path, '')) = ? LIMIT 1");
            $c->execute([$brand, $m['new_name'], $m['new_dir']]);
            $cid = $c->fetchColumn();
            if ($cid && !isset($movingIds[(string)$cid])) {
                fail($sent, 'a file already exists at the new path');
            }
        }
        $db->beginTransaction();
        $by = (string)($session['person_id'] ?? 'files');
        foreach ($moves as $m) {
            $id = (string)$m['row']['id'];
            $prev = $db->prepare('SELECT file_type, public_private, brand, file_name, file_path FROM files WHERE id = ? FOR UPDATE');
            $prev->execute([$id]);
            $prevRow = $prev->fetch(PDO::FETCH_ASSOC) ?: $m['row'];
            archive($db, $id, 'UPDATE');
            $db->prepare('UPDATE files SET file_path = ?, file_name = ? WHERE id = ?')->execute([$m['new_dir'] === '' ? null : $m['new_dir'], $m['new_name'], $id]);
            $afterRow = ['file_type' => $prevRow['file_type'] ?? null, 'public_private' => $prevRow['public_private'] ?? null, 'brand' => $brand, 'file_name' => $m['new_name'], 'file_path' => $m['new_dir']];
            bumpHtmlCacheIfNeeded($db, $prevRow, $by, $id, cacheRelFromRow($prevRow));
            bumpHtmlCacheIfNeeded($db, $afterRow, $by, $id, cacheRelFromRow($afterRow));
            DwLinkTree::onSaved($db, $brand, $prevRow, $afterRow, $by);
        }
        if ($isFolder) {
            DwLinkTree::movedPrefix($db, $brand, $fromN, $toN, $by);
        }
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'message' => 'OK move success', 'moved' => count($moves)]);
        exit;
    }

    $q = $db->prepare('SELECT id, file_data, file_name, file_path, file_type, created, brand, owner_id, owner_type, public_private, watermark_mode FROM files WHERE id = ?');
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fail($sent, 'file not found', 404);
    }
    // 2026-11-04: writes and PRIVATE reads only on the session's own brand. Another brand's file answers exactly
    // like a missing one. PUBLIC reads are unchanged.
    if ($session !== null && !hash_equals((string)$session['brand'], (string)$row['brand'])
        && ($action !== 'read' || $row['public_private'] === 'PRIVATE')) {
        fail($sent, 'file not found', 404);
    }

    if ($action === 'read') {
        if ($row['public_private'] === 'PRIVATE') {
            if ($session === null || !Dw::allows($session, 'FILE', ['READ', 'READ_PRIVATE'])) {
                fail($sent, 'this file is PRIVATE and needs a session with FILE READ or READ_PRIVATE', 403);
            }
        }
        // 2026-11-11 (#14): never return the brand code; owner only to a session on the same brand.
        $sameBrand = $session !== null && hash_equals((string)$session['brand'], (string)$row['brand']);
        unset($row['brand']);
        if (!$sameBrand) {
            unset($row['owner_id'], $row['owner_type']);
        }
        // ChatGPT editor (refs #67): format text gives file_text (UTF-8) for text files instead of base64.
        if (strtolower((string)($sent['format'] ?? '')) === 'text' && in_array($row['file_type'], ['HTML', 'CSS', 'JS', 'JSON', 'TXT', 'SVG'], true)
            && mb_check_encoding((string)$row['file_data'], 'UTF-8')) {
            $row['file_text'] = (string)$row['file_data'];
            unset($row['file_data']);
            Dw::jsonOut(200, ['ok' => true, 'note' => 'file_text is the file content', 'created_utc_offset' => utcOffset($db), 'file' => $row]);
            exit;
        }
        $row['file_data'] = base64_encode((string)$row['file_data']);
        Dw::jsonOut(200, ['ok' => true, 'note' => 'decode file_data from base64', 'created_utc_offset' => utcOffset($db), 'file' => $row]);
        exit;
    }

    if ($action === 'delete') {
        $db->beginTransaction();
        $prev = $db->prepare('SELECT file_type, public_private, brand, file_name, file_path FROM files WHERE id = ? FOR UPDATE');
        $prev->execute([$id]);
        $prevRow = $prev->fetch(PDO::FETCH_ASSOC) ?: null;
        archive($db, $id, 'DELETE');
        $db->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
        $rel = is_array($prevRow) ? cacheRelFromRow($prevRow) : null;
        bumpHtmlCacheIfNeeded($db, is_array($prevRow) ? $prevRow : null, (string)($session['person_id'] ?? 'files'), $id, $rel);
        require_once '/var/www/html/lib/DwLinkTree.php';
        DwLinkTree::onSaved($db, (string)$session['brand'], is_array($prevRow) ? $prevRow : null, null, (string)($session['person_id'] ?? 'files'));
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'message' => 'OK delete success', 'id' => $id]);
        exit;
    }

    $sets = [];
    $vals = [];
    $bytes = fileBytes($sent);
    if ($bytes !== null) {
        $sets[] = 'file_data = ?';
        $vals[] = $bytes;
    }
    $name = textField($sent, 'file_name', 100);
    if ($name !== null) {
        $sets[] = 'file_name = ?';
        $vals[] = $name;
    }
    $path = textField($sent, 'file_path', 200);
    if ($path !== null) {
        $sets[] = 'file_path = ?';
        $vals[] = $path;
    }
    if (isset($sent['file_type']) && $sent['file_type'] !== '') {
        $sets[] = 'file_type = ?';
        $vals[] = typeFor($sent, null);
    }
    $vis = visibility($sent);
    if ($vis !== null) {
        $sets[] = 'public_private = ?';
        $vals[] = $vis;
    }
    $wm = watermarkMode($sent);
    if ($wm !== null) {
        $sets[] = 'watermark_mode = ?';
        $vals[] = $wm;
    }
    $cur = $db->prepare('SELECT id, watermark_mode, public_private, file_type, created FROM files WHERE id = ?');
    $cur->execute([$id]);
    $curRow = $cur->fetch(PDO::FETCH_ASSOC);
    if ($curRow) {
        $after = $curRow;
        if ($wm !== null) {
            $after['watermark_mode'] = $wm;
        }
        if ($vis !== null) {
            $after['public_private'] = $vis;
        }
        if (isset($sent['file_type']) && $sent['file_type'] !== '') {
            $after['file_type'] = typeFor($sent, null);
        }
        watermarkGate($db, $sent, (string)$session['brand'], $after, false);
    }
    if ($sets === []) {
        fail($sent, 'nothing to update: send file, file_data, file_name, file_path, file_type, public_private, or watermark_mode');
    }
    $vals[] = $id;
    $db->beginTransaction();
    $prev = $db->prepare('SELECT file_type, public_private, brand, file_name, file_path FROM files WHERE id = ? FOR UPDATE');
    $prev->execute([$id]);
    $prevRow = $prev->fetch(PDO::FETCH_ASSOC) ?: null;
    archive($db, $id, 'UPDATE');
    $db->prepare('UPDATE files SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    $after = $db->prepare('SELECT file_type, public_private, brand, file_name, file_path FROM files WHERE id = ?');
    $after->execute([$id]);
    $afterRow = $after->fetch(PDO::FETCH_ASSOC) ?: $prevRow;
    $by = (string)($session['person_id'] ?? 'files');
    bumpHtmlCacheIfNeeded($db, is_array($prevRow) ? $prevRow : null, $by, $id, is_array($prevRow) ? cacheRelFromRow($prevRow) : null);
    bumpHtmlCacheIfNeeded($db, is_array($afterRow) ? $afterRow : null, $by, $id, is_array($afterRow) ? cacheRelFromRow($afterRow) : null);
    require_once '/var/www/html/lib/DwLinkTree.php';
    DwLinkTree::onSaved($db, (string)$session['brand'], is_array($prevRow) ? $prevRow : null, is_array($afterRow) ? $afterRow : null, $by);
    $db->commit();
    Dw::jsonOut(200, ['ok' => true, 'message' => 'OK update success', 'id' => $id]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fail($sent, 'could not complete ' . $action, 500);
}

function filesPage(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    require_once '/var/www/html/lib/DwBrand.php';
    DwBrand::themeOutput(); // page text follows the configured brand (lib/DwBrand.php)
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Files — DW Alliance</title>
<style>
  :root { color-scheme: light dark; --fg:#1a1a1a; --muted:#555; --line:#ddd; --card:#f7f7f7; --accent:#0b5fff; --danger:#b00020; }
  @media (prefers-color-scheme: dark) { :root { --fg:#f2f2f2; --muted:#b0b0b0; --line:#333; --card:#1c1c1c; --accent:#6ea8ff; --danger:#ff8a80; } }
  body{margin:0;font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;color:var(--fg)}
  main{max-width:58rem;margin:0 auto;padding:2rem 1.25rem 4rem}
  h1{font-size:1.75rem;margin:0 0 .5rem} h2{font-size:1.15rem;margin:1.75rem 0 .6rem}
  .muted{color:var(--muted)} a{color:var(--accent)}
  .card{border:1px solid var(--line);background:var(--card);border-radius:.6rem;padding:.9rem 1rem;margin:.75rem 0}
  label{display:block;margin:.5rem 0 .2rem;font-size:.9rem}
  input,button,select{font:inherit;padding:.5rem .7rem;border-radius:.45rem;border:1px solid var(--line);background:transparent;color:var(--fg)}
  button{background:var(--accent);color:#fff;border-color:var(--accent);cursor:pointer}
  button.secondary{background:transparent;color:var(--accent)}
  table{width:100%;border-collapse:collapse;font-size:.92rem}
  th,td{text-align:left;padding:.45rem .3rem;border-bottom:1px solid var(--line);vertical-align:middle}
  .row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:end;margin:.4rem 0}
  .err{color:var(--danger)} .ok{color:green}
  code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.88rem}
</style>
</head>
<body>
<main>
  <h1>Files</h1>
  <p class="muted">Upload or edit a file and choose the <strong>owner watermark</strong> (default, on or off). This only affects your own mark — the style is set per domain on <a href="/permissions/">/permissions/</a>. The DW Alliance promo mark on a free site cannot be turned off here; only an active paid No Banner subscription removes it. Sign in with the magic link; this page never asks for a session id.</p>
  <p class="muted"><a href="/">Home</a> · <a href="/session/login/">Sign in</a> · <a href="/permissions/">Domain settings</a> · API help: <code>POST /files/</code> with an empty body.</p>
  <div class="card" id="signed-out" hidden>
    <h2>Sign in required</h2>
    <p class="muted">Use the magic-link sign-in for this site, then return here.</p>
    <p><a href="/session/login/">Sign in</a></p>
    <p id="status-out" class="muted" role="status"></p>
  </div>
  <div id="panel" hidden>
    <p id="status" class="muted" role="status"></p>
    <div class="card">
      <h2>Upload</h2>
      <div class="row">
        <div><label for="up-file">File</label><input id="up-file" type="file"></div>
        <div><label for="up-path">Path (optional)</label><input id="up-path" type="text" placeholder="assets/img" maxlength="200"></div>
        <div><label for="up-vis">Visibility</label>
          <select id="up-vis"><option value="PRIVATE">Private</option><option value="PUBLIC">Public</option></select>
        </div>
        <div><label for="up-wm">Owner watermark</label>
          <select id="up-wm">
            <option value="default">Default (follow domain, then global)</option>
            <option value="on">On</option>
            <option value="off">Off</option>
          </select>
        </div>
        <button type="button" id="btn-up">Upload</button>
      </div>
    </div>
    <div class="card">
      <h2>Your files</h2>
      <p class="muted">Owner watermark applies to public JPG, PNG, WEBP and PDF files. Off on a file beats On on the domain. It never hides the DW promo mark on a free site.</p>
      <div id="file-list"></div>
    </div>
    <p class="muted"><button type="button" class="secondary" id="btn-reload">Reload</button></p>
  </div>
</main>
<script>
(function(){
  const $ = (id) => document.getElementById(id);
  const status = (msg, ok) => { const el=$('status'); if(!el) return; el.textContent=msg; el.className=ok===true?'ok':(ok===false?'err':'muted'); };
  const statusOut = (msg, ok) => { const el=$('status-out'); if(!el) return; el.textContent=msg; el.className=ok===true?'ok':(ok===false?'err':'muted'); };
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  async function apiJson(body){
    const r = await fetch('/files/', { method:'POST', credentials:'include', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || j.ok === false) {
      const e = new Error(j.error || ('HTTP ' + r.status)); e.status = r.status; throw e;
    }
    return j;
  }

  function modeSelect(id, current){
    return '<select data-id="'+id+'" class="wm-select">'+
      [['default','Default'],['on','On'],['off','Off']].map(o => '<option value="'+o[0]+'"'+(current===o[0]?' selected':'')+'>'+o[1]+'</option>').join('')+
      '</select>';
  }

  function render(files){
    const rows = (files||[]).map(f => {
      const path = [f.file_path, f.file_name].filter(Boolean).join('/');
      return '<tr><td>'+escapeHtml(path)+'<div class="muted">'+escapeHtml(f.file_type||'')+' · '+(f.public_private||'')+' · '+(f.file_size||0)+' bytes</div></td><td>'+modeSelect(f.id, f.watermark_mode||'default')+'</td></tr>';
    }).join('');
    $('file-list').innerHTML = '<table><thead><tr><th>File</th><th>Owner watermark</th></tr></thead><tbody>'+(rows || '<tr><td colspan="2" class="muted">No files yet.</td></tr>')+'</tbody></table>';
  }

  async function reload(){
    const data = await apiJson({action:'search', limit:100});
    render(data.files||[]);
    const g = data.watermark_gate, o = (g && g.offer) || {};
    let gb = $('wm-gate');
    if (!gb) { gb = document.createElement('p'); gb.id = 'wm-gate'; gb.className = 'muted'; $('file-list').before(gb); }
    gb.textContent = '';
    if (g) {
      const msg = o.message || ((o.name||'Watermarks')+' (owner watermark) is a paid add-on'+(o.price ? ' ($'+o.price+'/month)' : '')+'.');
      const used = g.usage ? (' '+g.usage.used+' of '+g.usage.free_count+' free watermarked files in use.') : '';
      gb.appendChild(document.createTextNode(msg + used + (g.state === 'grace' && g.notice ? ' '+g.notice : '') + ' '));
      if (!g.allowed) {
        const a = document.createElement('a'); a.href = o.subscribe_url || 'https://dwalliance.com/pricing.html'; a.textContent = 'Subscribe'; gb.appendChild(a);
      }
    }
    return data;
  }

  async function boot(){
    try {
      await reload();
      $('signed-out').hidden = true;
      $('panel').hidden = false;
    } catch(e){
      $('panel').hidden = true;
      $('signed-out').hidden = false;
      statusOut(e.status===401||e.status===403 ? (e.message||'Sign in required.') : (e.message||'Could not load.'), false);
    }
  }

  $('btn-reload').addEventListener('click', async () => { try { await reload(); status('Reloaded.', true); } catch(e){ status(e.message, false); } });
  $('btn-up').addEventListener('click', async () => {
    const f = $('up-file').files && $('up-file').files[0];
    if (!f) { status('Choose a file first.', false); return; }
    const fd = new FormData();
    fd.append('action','create');
    fd.append('file', f);
    const path = $('up-path').value.trim();
    if (path) fd.append('file_path', path);
    fd.append('public_private', $('up-vis').value);
    fd.append('watermark_mode', $('up-wm').value);
    try {
      const r = await fetch('/files/', { method:'POST', credentials:'include', body: fd });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || j.ok === false) throw new Error(j.error || ('HTTP '+r.status));
      $('up-file').value = '';
      status('Uploaded.', true);
      await reload();
    } catch(e){ status(e.message, false); }
  });
  document.addEventListener('change', async (ev) => {
    const t = ev.target;
    if (!(t instanceof HTMLSelectElement) || !t.classList.contains('wm-select')) return;
    try {
      await apiJson({action:'update', id:t.dataset.id, watermark_mode:t.value});
      status('Owner watermark saved.', true);
    } catch(e){ status(e.message, false); boot(); }
  });
  boot();
})();
</script>
</body>
</html>
HTML;
    exit;
}
