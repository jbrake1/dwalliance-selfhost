<?php
declare(strict_types=1);
// STAGING TEST (2026-10-28): client "Suggest an edit" markup requests.
//   GET  /markup/?a=status&brand=B   -> {ok, show, csrf?}  (button shows only for a signed-in person with rights on B)
//   GET  /markup/?a=signin&brand=B   -> sign-in page (emailed code from /session/)
//   POST /markup/  a=signin          -> sets the HttpOnly dw_markup cookie, {ok}
//   POST /markup/  a=signout         -> clears it, {ok}
//   POST /markup/  a=submit          -> stores the request, emails the alert, {ok, id}
//   GET  /markup/                    -> staff list;  ?a=view&id=  detail;  ?a=image&id=  screenshot
//   GET  /markup/?a=api_list | api_get&id= | shot  -> ChatGPT editor (Bearer, refs #67), lib/DwMarkupApi.php
// Config: /var/lib/app/data/markup.json  {"brands":[...],"staff_brand":"...","alert_to":"..."}
//         /var/lib/app/data/markup.secret (CSRF HMAC key)
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';

const MK_CONF = '/var/lib/app/data/markup.json';
const MK_SECRET = '/var/lib/app/data/markup.secret';
const MK_COOKIE = 'dw_markup';
const MK_MAX_IMAGE = 10 * 1024 * 1024;      // 10 MB cap
const MK_MAX_PIXELS = 40000000;             // e.g. 3840 x 2160 x 4 is 33 M
const MK_RATE_10MIN = 10;
const MK_RATE_DAY = 60;
const MK_SESSION_DAYS = 30;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function mkConf(): array
{
    $c = json_decode((string)@file_get_contents(MK_CONF), true);
    if (!is_array($c)) {
        return ['brands' => [], 'staff_brand' => '', 'alert_to' => ''];
    }
    $c['brands'] = array_values(array_filter((array)($c['brands'] ?? []), 'is_string'));
    $c['staff_brand'] = (string)($c['staff_brand'] ?? '');
    $c['alert_to'] = (string)($c['alert_to'] ?? '');
    return $c;
}

function mkJson(int $code, array $p): void
{
    Dw::jsonOut($code, $p);
    exit;
}

function mkFail(int $code, string $msg): void
{
    mkJson($code, ['ok' => false, 'error' => $msg]);
}

// Host header with port, restricted to safe characters (used for links and origin checks).
function mkHostPort(): string
{
    $h = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    return preg_match('/^[a-z0-9.\-]{1,253}(:\d{1,5})?$/', $h) ? $h : '';
}

function mkScheme(): string
{
    $p = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($p === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
        return 'https';
    }
    return 'http';
}

// The brand this request is about. Customer domains: from the Host. App host: the brand parameter.
function mkSiteBrand(?string $given): ?string
{
    $host = DwDomain::requestHost();
    if (DwDomain::isAppHost($host)) {
        $b = (string)$given;
        return preg_match('/^[A-Za-z0-9]{1,10}$/', $b) ? $b : null;
    }
    try {
        return DwDomain::brandForHost($host, [Dw::class, 'pdo']);
    } catch (Throwable $e) {
        return null;
    }
}

// Same-origin check for state-changing requests: Origin (or Referer) host must match the Host.
// nginx passes HTTP_HOST without the port here, so the port is only compared when HTTP_HOST has one.
// Returns the checked origin (scheme://host[:port]) or '' when the request is cross-site.
function mkCheckedOrigin(): string
{
    $hp = mkHostPort();
    if ($hp === '') {
        return '';
    }
    $src = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($src === '' || $src === 'null') {
        $src = (string)($_SERVER['HTTP_REFERER'] ?? '');
    }
    $u = $src === '' ? false : parse_url($src);
    if (!is_array($u) || empty($u['host']) || !in_array($u['scheme'] ?? '', ['http', 'https'], true)) {
        return '';
    }
    $oHost = strtolower($u['host']);
    $oPort = isset($u['port']) ? ':' . (int)$u['port'] : '';
    $ok = strpos($hp, ':') !== false ? hash_equals($hp, $oHost . $oPort) : hash_equals($hp, $oHost);
    return $ok ? $u['scheme'] . '://' . $oHost . $oPort : '';
}

function mkSameOrigin(): bool
{
    return mkCheckedOrigin() !== '';
}

function mkSecret(): string
{
    $s = trim((string)@file_get_contents(MK_SECRET));
    if (strlen($s) < 32) {
        mkFail(500, 'not configured');
    }
    return $s;
}

function mkCsrf(string $sessionId): string
{
    return hash_hmac('sha256', 'markup-csrf|' . $sessionId, mkSecret());
}

// Session from the dw_markup cookie (an id from the emailed-code flow), not older than MK_SESSION_DAYS.
function mkSession(): ?array
{
    $id = trim((string)($_COOKIE[MK_COOKIE] ?? ''));
    if (!preg_match('/^[0-9a-f]{50}$/', $id)) {
        // Edit link (2026-11-04): on a customer domain, the HttpOnly __Host-dw_s cookie from /_dw/login also works.
        $host = DwDomain::requestHost();
        if (DwDomain::isAppHost($host)) {
            return null;
        }
        require_once '/var/www/html/lib/DwEdit.php';
        $s = DwEdit::siteSession($host);
        if ($s === null) {
            return null;
        }
        $id = (string)$s['id'];
    }
    $q = Dw::pdo()->prepare('SELECT s.id, s.person_id, s.rights, s.brand, (SELECT e.email FROM email e WHERE e.owner_id = s.person_id AND e.owner_type = \'PRSN\' AND e.brand = s.brand LIMIT 1) AS email FROM session s WHERE s.id = ? AND s.created > NOW() - INTERVAL ' . MK_SESSION_DAYS . ' DAY');
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mkCanSubmit(?array $s, ?string $brand): bool
{
    if ($s === null || $brand === null || !hash_equals((string)$s['brand'], $brand)) {
        return false;
    }
    require_once '/var/www/html/lib/DwEdit.php';
    require_once '/var/www/html/lib/DwPermissions.php';
    $host = DwDomain::requestHost();
    if (!DwPermissions::editorEnabled($brand, $host)) {
        return false;
    }
    // SUPER_ADMIN (all domains) or domain_editor for this host. Domain editors need no NOTE/FILE rights;
    // submit writes rows as the platform, not via those modules' ACL.
    return DwEdit::isEditor($s, $brand, DwDomain::isAppHost($host) ? null : $host);
}

function mkIsStaff(?array $s): bool
{
    $staff = mkConf()['staff_brand'];
    return $s !== null && $staff !== '' && hash_equals((string)$s['brand'], $staff) && Dw::allows($s, 'NOTE', ['READ']);
}

function mkSetCookie(string $value, int $maxAge): void
{
    setcookie(MK_COOKIE, $value, [
        'expires' => $maxAge > 0 ? time() + $maxAge : time() - 3600,
        'path' => '/markup',
        'secure' => mkScheme() === 'https',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mkHst(string $dbTime): string
{
    try {
        $d = new DateTime($dbTime, new DateTimeZone(DwConfig::get('DB_TIMEZONE', 'UTC'))); // the database server clock (DB_TIMEZONE in DwConfig)
        $d->setTimezone(new DateTimeZone('Pacific/Honolulu'));
        return $d->format('M j, Y g:i A') . ' HST';
    } catch (Throwable $e) {
        return $dbTime;
    }
}

function mkPage(string $title, string $body, bool $withScript = false): void
{
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    require_once '/var/www/html/lib/DwBrand.php';
    DwBrand::themeOutput(); // page text follows the configured brand (lib/DwBrand.php)
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . h($title) . '</title>'
        . '<style>body{font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;margin:0;background:#f5f6f4;color:#1d2a20}'
        . 'header{background:#1f4d2b;color:#fff;padding:14px 22px;display:flex;justify-content:space-between;align-items:center}header a{color:#d9f0de}'
        . 'main{max-width:1100px;margin:0 auto;padding:22px}h1{font-size:1.35rem;margin:0}table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
        . 'th,td{padding:10px 12px;text-align:left;vertical-align:top;border-bottom:1px solid #e5e8e3}th{background:#eef2ec;font-size:.85rem;text-transform:uppercase;letter-spacing:.03em;color:#4a5a4d}'
        . 'td img{width:220px;max-height:140px;object-fit:cover;object-position:top;border:1px solid #d7ddd5;border-radius:6px}.muted{color:#68756a;font-size:.88rem}.url{word-break:break-all}'
        . '.card{background:#fff;border-radius:10px;padding:18px 20px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:18px}.shot{max-width:100%;border:1px solid #d7ddd5;border-radius:8px}'
        . 'input,button{font:inherit;padding:9px 12px;border-radius:7px;border:1px solid #b9c4b8}button{background:#1f4d2b;color:#fff;border-color:#1f4d2b;cursor:pointer}'
        . 'code{background:#eef2ec;padding:1px 5px;border-radius:4px}.pill{display:inline-block;padding:2px 9px;border-radius:99px;background:#fff3c4;font-size:.8rem}pre{white-space:pre-wrap;margin:0}</style></head><body>'
        . $body . ($withScript ? '<script src="/markup/signin.js"></script>' : '') . '</body></html>';
    exit;
}

// ---------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$a = (string)($_GET['a'] ?? ($_POST['a'] ?? ''));

try {
    // ChatGPT editor (refs #67): Bearer JSON reads of this brand's requests, and signed screenshot links.
    if ($method === 'GET' && in_array($a, ['api_list', 'api_get', 'shot'], true)) {
        require_once '/var/www/html/lib/DwMarkupApi.php';
        DwMarkupApi::route($a);
    }

    if ($method === 'GET' && $a === 'status') {
        $brand = mkSiteBrand($_GET['brand'] ?? null);
        $s = mkSession();
        if (!mkCanSubmit($s, $brand)) {
            mkJson(200, ['ok' => true, 'show' => false]);
        }
        mkJson(200, ['ok' => true, 'show' => true, 'csrf' => mkCsrf($s['id'])]);
    }

    if ($method === 'GET' && $a === 'signin') {
        $brand = mkSiteBrand($_GET['brand'] ?? null);
        $staff = isset($_GET['staff']);
        if ($staff) {
            $brand = mkConf()['staff_brand'];
        }
        if ($brand === null) {
            mkPage('Not available', '<main><div class="card">Edit requests are not turned on for this site.</div></main>');
        }
        if (!$staff) {
            require_once '/var/www/html/lib/DwPermissions.php';
            $host = DwDomain::requestHost();
            if (!DwPermissions::editorEnabled($brand, $host) && !in_array($brand, mkConf()['brands'], true)) {
                mkPage('Not available', '<main><div class="card">Edit requests are not turned on for this site.</div></main>');
            }
        }
        $ret = (string)($_GET['return'] ?? '');
        $retOk = $ret !== '' && $ret[0] === '/' && (strlen($ret) < 2 || ($ret[1] !== '/' && $ret[1] !== '\\'));
        mkPage('Sign in', '<header><h1>DW Alliance &middot; Suggest an edit</h1></header><main><div class="card" id="mk-signin" data-brand="' . h($brand) . '" data-return="' . h($retOk ? $ret : ($staff ? '/markup/' : '/')) . '">'
            . '<p>Sign in to mark up pages and send edit requests.</p>'
            . '<form id="mk-email"><label>Your email<br><input type="email" name="email" required autocomplete="email" size="32"></label> <button type="submit">Email me a sign-in code</button></form>'
            . '<form id="mk-code" style="margin-top:14px"><label>Code from the email<br><input name="code" required pattern="[0-9a-f]{50}" size="56" autocomplete="one-time-code" spellcheck="false"></label> <button type="submit">Sign in</button></form>'
            . '<p id="mk-msg" class="muted" role="status"></p></div></main>', true);
    }

    if ($method === 'POST' && ($a === 'signin' || $a === 'signout' || $a === 'submit')) {
        if (!mkSameOrigin()) {
            mkFail(403, 'cross-site request refused');
        }
        if (($_SERVER['HTTP_X_DW_MARKUP'] ?? '') !== '1') {
            mkFail(403, 'missing request header');
        }
    }

    if ($method === 'POST' && $a === 'signout') {
        mkSetCookie('', 0);
        mkJson(200, ['ok' => true]);
    }

    if ($method === 'POST' && $a === 'signin') {
        $in = json_decode((string)file_get_contents('php://input', false, null, 0, 4096), true);
        $code = is_array($in) ? trim((string)($in['code'] ?? '')) : '';
        $brand = is_array($in) ? (string)($in['brand'] ?? '') : '';
        if (!preg_match('/^[0-9a-f]{50}$/', $code)) {
            mkFail(400, 'that code does not look right');
        }
        $_COOKIE[MK_COOKIE] = $code;
        $s = mkSession();
        $siteBrand = $brand === mkConf()['staff_brand'] ? $brand : mkSiteBrand($brand);
        if ($s === null || !(mkCanSubmit($s, $siteBrand) || mkIsStaff($s))) {
            usleep(400000);
            mkFail(403, 'that code is not valid for this site');
        }
        mkSetCookie($code, MK_SESSION_DAYS * 86400);
        mkJson(200, ['ok' => true]);
    }

    if ($method === 'POST' && $a === 'submit') {
        $brand = mkSiteBrand($_POST['brand'] ?? null);
        $s = mkSession();
        if (!mkCanSubmit($s, $brand)) {
            mkFail(401, 'sign in first');
        }
        if (!hash_equals(mkCsrf($s['id']), (string)($_SERVER['HTTP_X_DW_CSRF'] ?? ''))) {
            mkFail(403, 'bad token, reload the page');
        }
        $db = Dw::pdo();
        $r = $db->prepare('SELECT SUM(created > NOW() - INTERVAL 10 MINUTE), COUNT(*) FROM markup_request WHERE person_id = ? AND created > NOW() - INTERVAL 1 DAY');
        $r->execute([$s['person_id']]);
        [$n10, $nDay] = array_map('intval', $r->fetch(PDO::FETCH_NUM));
        if ($n10 >= MK_RATE_10MIN || $nDay >= MK_RATE_DAY) {
            header('Retry-After: 600');
            mkFail(429, 'too many requests, try again later');
        }
        $f = $_FILES['image'] ?? null;
        if (is_array($f) && in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            mkFail(413, 'screenshot is too large');
        }
        if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            mkFail(400, 'screenshot missing');
        }
        if ((int)$f['size'] <= 0 || (int)$f['size'] > MK_MAX_IMAGE) {
            mkFail(413, 'screenshot is too large');
        }
        $bytes = (string)file_get_contents((string)$f['tmp_name']);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $info = @getimagesizefromstring($bytes);
        $types = ['image/png' => ['PNG', 'png', IMAGETYPE_PNG], 'image/jpeg' => ['JPG', 'jpg', IMAGETYPE_JPEG]];
        if (!isset($types[$mime]) || !is_array($info) || $info[2] !== $types[$mime][2]
            || $info[0] < 50 || $info[1] < 50 || $info[0] * $info[1] > MK_MAX_PIXELS) {
            mkFail(415, 'screenshot must be a PNG or JPEG image');
        }
        [$fileType, $ext] = $types[$mime];

        $clean = static fn (string $v, int $max): string => mb_substr((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v), 0, $max);
        $note = trim($clean((string)($_POST['note'] ?? ''), 5000));
        $pageUrl = $clean((string)($_POST['page_url'] ?? ''), 2000);
        $pu = parse_url($pageUrl);
        if (!is_array($pu) || !in_array($pu['scheme'] ?? '', ['http', 'https'], true) || empty($pu['host'])) {
            mkFail(400, 'page_url is not valid');
        }
        $viewport = (string)($_POST['viewport'] ?? '');
        $viewport = preg_match('/^\d{2,5}x\d{2,5}(@\d(\.\d{1,2})?)?$/', $viewport) ? $viewport : null;
        $ua = $clean((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
        $sel = json_decode((string)($_POST['selectors'] ?? '[]'), true);
        $selectors = [];
        foreach (is_array($sel) ? array_slice($sel, 0, 30) : [] as $one) {
            if (is_string($one) && $one !== '') {
                $selectors[] = $clean($one, 300);
            }
        }

        $q = $db->prepare('SELECT company FROM relation WHERE person_id = ? AND brand = ? ORDER BY created_on LIMIT 1');
        $q->execute([$s['person_id'], $brand]);
        $company = $q->fetchColumn() ?: null;

        $me = $s['person_id'];
        $reqId = Dw::randId();
        $fileId = Dw::randId();
        $noteId = Dw::randId();
        $linkId = Dw::randId();
        $noteText = "[Edit request] " . $pageUrl . "\n"
            . ($viewport ? "Viewport: $viewport\n" : '')
            . ($selectors ? 'Marked: ' . implode(', ', array_slice($selectors, 0, 10)) . "\n" : '')
            . "\n" . ($note !== '' ? $note : '(no note)');
        $fileName = 'markup-' . date('Ymd-His') . '-' . substr($reqId, 0, 8) . '.' . $ext;

        $db->beginTransaction();
        $db->prepare("INSERT INTO files (id, file_data, file_name, file_path, file_type, created, brand, owner_id, owner_type, public_private) VALUES (?, ?, ?, 'markup', ?, NOW(), ?, ?, 'PRSN', 'PRIVATE')")
            ->execute([$fileId, $bytes, $fileName, $fileType, $brand, $me]);
        $db->prepare('INSERT INTO files_history (history_id, id, file_data, file_name, file_path, file_type, created, brand, owner_id, owner_type, public_private, action) SELECT ?, id, file_data, file_name, file_path, file_type, created, brand, owner_id, owner_type, public_private, ? FROM files WHERE id = ?')
            ->execute([Dw::randId(), 'INSERT', $fileId]);
        $db->prepare('INSERT INTO notes (id, person_id, note, gps, brand, created, created_by) VALUES (?, ?, ?, NULL, ?, NOW(), ?)')
            ->execute([$noteId, $me, $noteText, $brand, $me]);
        $db->prepare('INSERT INTO notes_history (history_id, id, person_id, note, gps, brand, created, created_by, action) SELECT ?, id, person_id, note, gps, brand, created, created_by, ? FROM notes WHERE id = ?')
            ->execute([Dw::randId(), 'INSERT', $noteId]);
        $db->prepare('INSERT INTO note_files (id, person_id, note_id, file_id, brand, created, created_by) VALUES (?, ?, ?, ?, ?, NOW(), ?)')
            ->execute([$linkId, $me, $noteId, $fileId, $brand, $me]);
        $db->prepare('INSERT INTO note_files_history (history_id, id, person_id, note_id, file_id, brand, created, created_by, action) SELECT ?, id, person_id, note_id, file_id, brand, created, created_by, ? FROM note_files WHERE id = ?')
            ->execute([Dw::randId(), 'INSERT', $linkId]);
        $db->prepare('INSERT INTO markup_request (id, company_id, brand, person_id, note_id, file_id, note_file_id, page_url, viewport, user_agent, selectors, status, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'NEW\', NOW())')
            ->execute([$reqId, $company, $brand, $me, $noteId, $fileId, $linkId, $pageUrl, $viewport, $ua, json_encode($selectors, JSON_UNESCAPED_SLASHES)]);
        Dw::history($db, 'markup_request', $reqId, 'INSERT');
        $db->commit();

                require_once '/var/www/html/lib/DwPermissions.php';
        $recipients = DwPermissions::suggestionRecipients($brand);
        if ($recipients !== []) {
            $link = mkCheckedOrigin() . '/markup/?a=view&id=' . $reqId;
            $body = "New edit request from " . ($s['email'] ?? $me) . "\n\n"
                . "Page: $pageUrl\n" . ($viewport ? "Viewport: $viewport\n" : '') . "\n"
                . ($note !== '' ? $note : '(no note)') . "\n\n"
                . "View it (staff sign-in): $link\n";
            $body = (string)preg_replace('/^\./m', ' .', str_replace(["\r\n", "\r"], "\n", $body));
            $body = str_replace("\n", "\r\n", $body);
            $subj = 'Edit request: ' . mb_substr((string)($pu['host'] ?? '') . ($pu['path'] ?? ''), 0, 120);
            foreach ($recipients as $to) {
                @Dw::sendMail($to, $subj, $body);
            }
        }
mkJson(200, ['ok' => true, 'id' => $reqId]);
    }

    // ----- staff pages -----
    if ($method === 'GET' && in_array($a, ['', 'list', 'view', 'image'], true)) {
        $s = mkSession();
        if (!mkIsStaff($s)) {
            if ($a === 'image') {
                http_response_code(404);
                exit;
            }
            mkPage('Edit requests', '<header><h1>Edit requests</h1></header><main><div class="card">Staff sign-in needed. <a href="/markup/?a=signin&amp;staff=1&amp;return=' . h(rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/markup/'))) . '">Sign in</a></div></main>');
        }
        $db = Dw::pdo();
        $id = (string)($_GET['id'] ?? '');
        if ($a === 'image') {
            $q = $db->prepare('SELECT f.file_data, f.file_type FROM markup_request m JOIN files f ON f.id = m.file_id WHERE m.id = ?');
            $q->execute([$id]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                exit;
            }
            header('Content-Type: ' . ($row['file_type'] === 'PNG' ? 'image/png' : 'image/jpeg'));
            header("Content-Security-Policy: default-src 'none'");
            header('Cache-Control: private, no-store');
            echo $row['file_data'];
            exit;
        }
        $top = '<header><h1>Edit requests <span class="pill">staging test</span></h1><span>' . h($s['email'] ?? '') . ' &middot; <a href="/markup/">All requests</a></span></header>';
        if ($a === 'view') {
            $q = $db->prepare('SELECT m.*, n.note, (SELECT e.email FROM email e WHERE e.owner_id = m.person_id AND e.owner_type = \'PRSN\' AND e.brand = m.brand LIMIT 1) AS email FROM markup_request m JOIN notes n ON n.id = m.note_id WHERE m.id = ?');
            $q->execute([$id]);
            $m = $q->fetch(PDO::FETCH_ASSOC);
            if (!$m) {
                mkPage('Not found', $top . '<main><div class="card">No such request.</div></main>');
            }
            $sel = json_decode((string)$m['selectors'], true) ?: [];
            $selHtml = $sel ? '<ul>' . implode('', array_map(static fn ($x) => '<li><code>' . h((string)$x) . '</code></li>', $sel)) . '</ul>' : '<span class="muted">none</span>';
            mkPage('Edit request', $top . '<main><div class="card"><p class="muted">' . h(mkHst($m['created'])) . ' &middot; ' . h($m['email'] ?? $m['person_id']) . ' &middot; brand ' . h($m['brand']) . ' &middot; ' . h($m['status']) . '</p>'
                . '<p class="url"><b>Page:</b> <a href="' . h($m['page_url']) . '" rel="noopener noreferrer">' . h($m['page_url']) . '</a></p>'
                . '<p><b>Viewport:</b> ' . h($m['viewport'] ?? '') . '<br><b>Browser:</b> <span class="muted">' . h($m['user_agent'] ?? '') . '</span></p>'
                . '<p><b>Note</b></p><pre>' . h($m['note']) . '</pre><p><b>Marked elements</b></p>' . $selHtml . '</div>'
                . '<div class="card"><img class="shot" alt="Screenshot with markup" src="/markup/?a=image&amp;id=' . h($m['id']) . '"></div></main>');
        }
        $rows = $db->query('SELECT m.id, m.brand, m.page_url, m.status, m.created, m.viewport, n.note, (SELECT e.email FROM email e WHERE e.owner_id = m.person_id AND e.owner_type = \'PRSN\' AND e.brand = m.brand LIMIT 1) AS email FROM markup_request m JOIN notes n ON n.id = m.note_id ORDER BY m.created DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        $tr = '';
        foreach ($rows as $m) {
            $text = trim((string)preg_replace('/^.*?\n\n/s', '', (string)$m['note']));
            $tr .= '<tr><td><a href="/markup/?a=view&amp;id=' . h($m['id']) . '"><img alt="screenshot" loading="lazy" src="/markup/?a=image&amp;id=' . h($m['id']) . '"></a></td>'
                . '<td><div class="url"><a href="/markup/?a=view&amp;id=' . h($m['id']) . '">' . h($m['page_url']) . '</a></div><p>' . h(mb_substr($text, 0, 300)) . '</p></td>'
                . '<td>' . h($m['email'] ?? '') . '<div class="muted">brand ' . h($m['brand']) . '</div></td><td>' . h(mkHst($m['created'])) . '<div class="muted">' . h($m['status']) . '</div></td></tr>';
        }
        mkPage('Edit requests', $top . '<main>' . ($tr === '' ? '<div class="card">No edit requests yet.</div>' : '<table><thead><tr><th>Screenshot</th><th>Page and note</th><th>From</th><th>When</th></tr></thead><tbody>' . $tr . '</tbody></table>') . '</main>');
    }

    mkFail(404, 'not found');
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('markup: ' . $e->getMessage());
    mkFail(500, 'server error');
}
