<?php
declare(strict_types=1);
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';
require_once '/var/www/html/lib/DwSitemap.php';

const WEB_ROOT = '/var/www/html';
const RESERVED = ['lib', 'files', 'session', 'account_setup', 'relation', 'crm', 'dns', 'notes', 'note_files', 'domain', 'internal-files', 'markup', 'permissions', '_dw', 'store', 'address', 'customer_session', 'end_user_account_setup', 'affiliates', 'affiliate', 'link_tree'];
const BLOCKED_EXT = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'htaccess'];
const MIME = [
    'JPG' => 'image/jpeg', 'PNG' => 'image/png', 'GIF' => 'image/gif', 'WEBP' => 'image/webp',
    'SVG' => 'image/svg+xml', 'HTML' => 'text/html', 'CSS' => 'text/css', 'JS' => 'text/javascript',
    'JSON' => 'application/json', 'PDF' => 'application/pdf', 'TXT' => 'text/plain', 'ICO' => 'image/x-icon',
    'WOFF' => 'font/woff', 'WOFF2' => 'font/woff2', 'MP4' => 'video/mp4',
];

// Claimed customer domains get a small HTML page; app.dwalliance.com and unclaimed hosts keep the JSON 404.
const NOT_FOUND_HTML = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Page not found</title>
<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;margin:0;padding:15vh 1.5rem;text-align:center;color:#222;background:#fff}h1{font-size:1.8rem;margin:0 0 .75rem}p{margin:.5rem 0}a{color:#0b5fff}</style>
</head>
<body>
<h1>Page not found</h1>
<p>Sorry, the page you are looking for is not here.</p>
<p><a href="/">Go to the home page</a></p>
</body>
</html>

HTML;
$GLOBALS['DW_HTML_404'] = false;

function notFound(): void
{
    if (!empty($GLOBALS['DW_HTML_404'])) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Content-Type-Options: nosniff');
        echo NOT_FOUND_HTML;
        exit;
    }
    Dw::jsonOut(404, ['ok' => false, 'error' => 'not found']);
    exit;
}

$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
$path = rawurldecode((string)parse_url($uri, PHP_URL_PATH));
$parts = array_values(array_filter(explode('/', $path), static fn ($p) => $p !== ''));
ini_set('default_socket_timeout', '5');
$host = DwDomain::requestHost();
$rawHost = $host;
// Optional *.localhost aliases from host_alias.json (absent on production = no-op).
if (substr($host, -10) === '.localhost') {
    $aliases = json_decode((string)@file_get_contents('/var/lib/app/data/host_alias.json'), true);
    if (is_array($aliases) && isset($aliases[$host]) && is_string($aliases[$host]) && DwDomain::validHost($aliases[$host])) {
        $host = strtolower($aliases[$host]);
    }
}
// Magic-link login (2026-11-04): /session/login and /session/logout on any host. lib/DwLogin.php.
// (/session/ itself is the session/index.php folder, which nginx serves before this file is reached.)
if (count($parts) === 2 && $parts[0] === 'session' && in_array($parts[1], ['login', 'logout'], true)) {
    require_once WEB_ROOT . '/lib/DwLogin.php';
    DwLogin::route($parts, $host, $rawHost);
    exit;
}
// Edit link (2026-11-04): /_dw/* sign-in, whoami and loader endpoints. lib/DwEdit.php.
if (($parts[0] ?? '') === '_dw') {
    require_once WEB_ROOT . '/lib/DwEdit.php';
    DwEdit::route($parts, $host);
    exit;
}

// Affiliate referral tracking (refs #65): ?ref= / ?aff= on any page. First-touch
// 90-day cookie. Failures here must never block the page.
try {
    require_once WEB_ROOT . '/lib/DwAffiliate.php';
    DwAffiliate::captureReferralFromRequest(Dw::pdo());
} catch (Throwable $e) {
}

if (DwDomain::isAppHost($host)) {
    // app.dwalliance.com: /BRAND/path/file, unchanged.
    if (count($parts) < 2) {
        notFound();
    }
    $brandCode = $parts[0];
    if (strlen($brandCode) > 10 || !preg_match('/^[A-Za-z0-9]+$/', $brandCode) || in_array(strtolower($brandCode), RESERVED, true)) {
        notFound();
    }
    $fileParts = array_slice($parts, 1);
} else {
    // Customer domain: brand comes from the Host, the whole path is the file path.
    try {
        $brandCode = DwDomain::brandForHost($host, [Dw::class, 'pdo']);
    } catch (Throwable $e) {
        Dw::jsonOut(503, ['ok' => false, 'error' => 'unavailable']);
        exit;
    }
    if ($brandCode === null) {
        notFound();
    }
    // Store sites answer on the bare domain only (2026-11-13): GET/HEAD on www.<domain> gets a 301 to
    // https://<domain>/same/path when <domain> is claimed by the same brand and the brand sells something
    // (at least one active product). Other tenants keep serving www as before. *.localhost aliases are skipped.
    if (strncmp($host, 'www.', 4) === 0 && $rawHost === $host && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        $bare = substr($host, 4);
        try {
            if (DwDomain::brandForHost($bare, [Dw::class, 'pdo']) === $brandCode) {
                $q = Dw::pdo()->prepare('SELECT 1 FROM product WHERE brand = ? AND active = 1 LIMIT 1');
                $q->execute([$brandCode]);
                if ($q->fetchColumn()) {
                    // The front proxy turns "/" into "/index.html"; send people back to "/".
                    $to = $uri === '/index.html' || strncmp($uri, '/index.html?', 12) === 0 ? '/' . substr($uri, 11) : $uri;
                    header('Location: https://' . $bare . ($to === '' ? '/' : $to), true, 301);
                    header('Cache-Control: public, max-age=3600');
                    exit;
                }
            }
        } catch (Throwable $e) {
            // Database trouble: keep serving www rather than failing the page.
        }
    }
    $GLOBALS['DW_HTML_404'] = true;
    $fileParts = $parts;
    // Multi-domain (2026-11-03): if the brand has PUBLIC files under a folder named after this domain
    // (www. stripped), serve from that folder; otherwise serve the brand top level as before.
    $site = preg_replace('/^www\./', '', strtolower($host));
    try {
        $s = Dw::pdo()->prepare("SELECT 1 FROM files WHERE brand = ? AND public_private = 'PUBLIC' AND (TRIM(BOTH '/' FROM COALESCE(file_path, '')) = ? OR TRIM(LEADING '/' FROM file_path) LIKE ?) LIMIT 1");
        $s->execute([$brandCode, $site, str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $site) . '/%']);
        if ($s->fetchColumn()) {
            array_unshift($fileParts, $site);
        }
    } catch (Throwable $e) {
        Dw::jsonOut(503, ['ok' => false, 'error' => 'unavailable']);
        exit;
    }
    // A path ending in "/" (including "/" itself) serves that folder's index.html.
    if (count($fileParts) === 0 || substr($path, -1) === '/') {
        $fileParts[] = 'index.html';
    }
}
// Path safety check (after host lookup so customer domains get the HTML 404).
foreach ($parts as $p) {
    if ($p === '.' || $p === '..' || strpos($p, '..') !== false || $p[0] === '.' || strpbrk($p, "\\\0") !== false) {
        notFound();
    }
}
$fileName = $fileParts[count($fileParts) - 1];
$filePath = implode('/', array_slice($fileParts, 0, -1));
if (in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), BLOCKED_EXT, true)) {
    notFound();
}

try {
    $db = Dw::pdo();
    $b = $db->prepare('SELECT code FROM brand WHERE code = ? LIMIT 1');
    $b->execute([$brandCode]);
    if (!$b->fetchColumn()) {
        notFound();
    }
    $q = $db->prepare("SELECT id, file_type, watermark_mode, OCTET_LENGTH(file_data) AS file_size, CRC32(file_data) AS crc FROM files WHERE brand = ? AND file_name = ? AND TRIM(BOTH '/' FROM COALESCE(file_path, '')) = ? AND public_private = 'PUBLIC' ORDER BY created DESC, id DESC LIMIT 1");
    $q->execute([$brandCode, $fileName, $filePath]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    Dw::jsonOut(503, ['ok' => false, 'error' => 'unavailable']);
    exit;
}
if (!$row) {
    // Customer domain without its own robots.txt / sitemap.xml: serve the automatic one (lib/DwSitemap.php).
    if (!empty($GLOBALS['DW_HTML_404']) && DwSitemap::isSpecial($path)) {
        DwSitemap::serve($path, $host, $brandCode, [Dw::class, 'pdo']);
    }
    // Link tree (2026-11-23): missing path with a redirect row gets 301/302. Fail open to the normal 404.
    try {
        require_once WEB_ROOT . '/lib/DwLinkTree.php';
        $look = $filePath !== '' ? $filePath . '/' . $fileName : $fileName;
        $sitePrefix = null;
        if (isset($site) && is_string($site) && $site !== '' && isset($fileParts[0]) && $fileParts[0] === $site) {
            $sitePrefix = $site;
        }
        $redir = DwLinkTree::redirectFor($db, $brandCode, $look, [
            'host' => $host,
            'request_path' => $path,
            'request_uri' => $uri,
            'is_app_host' => DwDomain::isAppHost($host),
            'site_prefix' => $sitePrefix,
        ]);
        if (is_array($redir) && !empty($redir['location'])) {
            header('Location: ' . $redir['location'], true, (int)$redir['status']);
            header('Cache-Control: no-cache');
            exit;
        }
    } catch (Throwable $e) {
    }
    notFound();
}

// Editor loader (permissions): inject when per-domain editor_enabled is on,
// or when the brand is still listed in markup.json (fallback via DwPermissions::editorEnabled).
// DW banner (lib/DwBanner.php, refs #45 / #49): on free sites every HTML page gets the promo bar.
// PUBLIC HTML is written to a host-keyed disk cache with the banner (and editor loader) already
// inserted. nginx serves a hit directly from /var/lib/app/data/html-cache/<host>/<request-path>
// with no PHP. This branch only runs on a miss. The try_files path under WEB_ROOT/<brand>/ is
// unlinked so app.dwalliance.com cannot serve a host-agnostic copy. Checkout paths still skip
// the banner (DwBanner::applies). The HTML 404 page is not cached. PRIVATE files never reach
// this branch.
if ($row['file_type'] === 'HTML') {
    require_once '/var/www/html/lib/DwPermissions.php';
    require_once '/var/www/html/lib/DwBanner.php';
    require_once '/var/www/html/lib/DwHtmlCache.php';
    DwHtmlCache::unlinkLegacy($brandCode, $filePath, $fileName);

    $htmlRow = $db->prepare("SELECT file_data FROM files WHERE id = ? AND public_private = 'PUBLIC' LIMIT 1");
    $htmlRow->execute([(string)$row['id']]);
    $blob = $htmlRow->fetchColumn();
    if (!is_string($blob)) {
        notFound();
    }

    $editorOn = false;
    try {
        $editorOn = DwPermissions::editorEnabled($brandCode, $host);
    } catch (Throwable $e) {
        $editorOn = false;
    }
    $bannerCfgOn = DwBanner::enabled();
    $bannerOn = $bannerCfgOn && DwBanner::applies($host, $brandCode, DwDomain::isAppHost($host), $path);
    $bannerStyle = '';
    $html = $blob;
    if ($bannerOn) {
        $bannerStyle = DwBanner::styleFor($db, $brandCode, DwDomain::isAppHost($host) ? null : DwBanner::bare($host));
        $html = DwBanner::insert($html, DwBanner::html($bannerStyle));
    }
    if ($editorOn) {
        $tag = '<script>(function(){if(/(?:^|;\s*)dw_ed=1(?:;|$)/.test(document.cookie)||location.hash==="#suggest-edit"){var s=document.createElement("script");s.src="/_dw/edit.js";s.async=true;document.head.appendChild(s);}})();</script>';
        $pos = strripos($html, '</body>');
        $html = $pos === false ? $html . $tag : substr_replace($html, $tag . "\n", $pos, 0);
    }
    if (class_exists('DwAffiliate')) {
        $html = DwAffiliate::injectKeepRefScript($html);
    }


    $rel = DwHtmlCache::relFromRequestPath($path);
    $paths = ($rel !== null) ? DwHtmlCache::paths($rawHost, $brandCode, $rel['dir'], $rel['file']) : null;
    if ($paths !== null) {
        $meta = [
            'host' => $paths['host'],
            'brand' => $brandCode,
            'file_id' => (string)$row['id'],
            'banner_on' => (int)$bannerOn,
            'banner_style' => $bannerStyle,
            'editor_on' => (int)$editorOn,
        ];
        try {
            $meta['valid_until'] = DwHtmlCache::nextChangeAt($db, $brandCode, DwDomain::isAppHost($host) ? null : DwBanner::bare($host));
        } catch (Throwable $e) {
            $meta['valid_until'] = null;
        }
        DwHtmlCache::write($paths, $html, $meta);
    }

    $etag = '"' . substr(sha1($html), 0, 32) . '"';
    if ($paths !== null && is_file($paths['html']) && !is_link($paths['html'])) {
        $mt = @filemtime($paths['html']);
        $sz = @filesize($paths['html']);
        if (is_int($mt) && is_int($sz)) {
            $etag = sprintf('"%x-%x"', $mt, $sz);
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Vary: Sec-Fetch-Dest');
    header('X-Content-Type-Options: nosniff');
    header(DwHtmlCache::HEADER . ': miss');
    header('ETag: ' . $etag);
    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm !== '' && in_array($etag, array_map('trim', explode(',', $inm)), true)) {
        http_response_code(304);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    echo $html;
    exit;
}

// PUBLIC JPG/PNG/WEBP/PDF (refs #51): never modify the DB original. On a miss,
// apply the DW promo mark (free sites only) and/or the owner mark, write the
// result to the host-keyed cache (same tree as HTML), and serve it. nginx
// serves a later hit with no PHP. Tiny images, SVG and ICO skip this branch
// (SVG/ICO never enter it; tiny images are cached as the original bytes).
if (in_array((string)$row['file_type'], ['JPG', 'PNG', 'WEBP', 'PDF'], true)) {
    require_once '/var/www/html/lib/DwBanner.php';
    require_once '/var/www/html/lib/DwHtmlCache.php';
    require_once '/var/www/html/lib/DwWatermark.php';
    DwHtmlCache::unlinkLegacy($brandCode, $filePath, $fileName);

    if (!isset($row['file_data'])) {
        $blobQ = $db->prepare("SELECT file_data FROM files WHERE id = ? AND public_private = 'PUBLIC' LIMIT 1");
        $blobQ->execute([(string)$row['id']]);
        $row['file_data'] = $blobQ->fetchColumn();
        if (!is_string($row['file_data'])) {
            notFound();
        }
    }
    $blob = (string)$row['file_data'];
    $appHost = DwDomain::isAppHost($host);
    $plan = DwWatermark::plan($db, $rawHost, $brandCode, $appHost, $path, $row, $appHost ? null : DwBanner::bare($host));
    $body = DwWatermark::apply($blob, (string)$row['file_type'], $plan, $db, $brandCode);

    $rel = DwHtmlCache::relFromRequestPath($path);
    $paths = ($rel !== null) ? DwHtmlCache::paths($rawHost, $brandCode, $rel['dir'], $rel['file']) : null;
    if ($paths !== null) {
        $meta = [
            'host' => $paths['host'],
            'brand' => $brandCode,
            'file_id' => (string)$row['id'],
            'kind' => 'media',
            'file_type' => (string)$row['file_type'],
            'dw' => (int)!empty($plan['dw']),
            'owner' => $plan['owner'] !== null ? 1 : 0,
        ];
        DwHtmlCache::write($paths, $body, $meta);
    }

    $ctype = MIME[$row['file_type']] ?? 'application/octet-stream';
    $etag = '"' . substr(sha1($body), 0, 32) . '"';
    if ($paths !== null && is_file($paths['html']) && !is_link($paths['html'])) {
        $mt = @filemtime($paths['html']);
        $sz = @filesize($paths['html']);
        if (is_int($mt) && is_int($sz)) {
            $etag = sprintf('"%x-%x"', $mt, $sz);
        }
    }
    header('Content-Type: ' . $ctype);
    header('Cache-Control: no-cache');
    header('X-Content-Type-Options: nosniff');
    header(DwHtmlCache::HEADER . ': miss');
    header('ETag: ' . $etag);
    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm !== '' && in_array($etag, array_map('trim', explode(',', $inm)), true)) {
        http_response_code(304);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    echo $body;
    exit;
}

if (!isset($row['file_data'])) {
    $blobQ = $db->prepare("SELECT file_data FROM files WHERE id = ? AND public_private = 'PUBLIC' LIMIT 1");
    $blobQ->execute([(string)$row['id']]);
    $row['file_data'] = $blobQ->fetchColumn();
    if (!is_string($row['file_data'])) {
        notFound();
    }
}
$rel = $brandCode . ($filePath !== '' ? '/' . $filePath : '');
$dir = WEB_ROOT . '/' . $rel;
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    Dw::jsonOut(500, ['ok' => false, 'error' => 'could not store the file']);
    exit;
}
$target = $dir . '/' . $fileName;
$tmp = $target . '.tmp' . bin2hex(random_bytes(4));
if (file_put_contents($tmp, (string)$row['file_data']) === false || !rename($tmp, $target)) {
    @unlink($tmp);
    Dw::jsonOut(500, ['ok' => false, 'error' => 'could not store the file']);
    exit;
}
@chmod($target, 0644);

$accel = '/internal-files/' . implode('/', array_map('rawurlencode', explode('/', $rel . '/' . $fileName)));
// Zip MIME fix (2026-11-04): the file_type enum has no ZIP or WEBMANIFEST, so these upload as TXT. For TXT rows only,
// pick the type from the extension. A .zip is sent as a download. Other types are unchanged.
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$ctype = MIME[$row['file_type']] ?? 'application/octet-stream';
if ($row['file_type'] === 'TXT') {
    $ctype = ['zip' => 'application/zip', 'webmanifest' => 'application/manifest+json', 'xml' => 'application/xml'][$ext] ?? $ctype;
}
if ($ctype === 'application/zip') {
    header('Content-Disposition: attachment; filename="' . str_replace(['"', '\\', "\r", "\n"], '', $fileName) . '"');
}
header('Content-Type: ' . $ctype);
header('X-Accel-Redirect: ' . $accel);
exit;
