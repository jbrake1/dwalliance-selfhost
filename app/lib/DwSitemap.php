<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';

// Automatic /sitemap.xml and /robots.txt for customer domains (2026-10-29).
//
// 404.php calls DwSitemap::isSpecial() / DwSitemap::serve() only when the brand has NO public file of that name at
// the top of its site, so a customer's own robots.txt or sitemap.xml always wins.
//
// The sitemap lists every PUBLIC .html/.htm file of the brand, newest change as <lastmod>, and leaves out PRIVATE
// files, non-HTML files, error pages (404.html, 500.html, error.html ...), pages marked noindex, and paths a customer
// domain cannot serve. It is built lazily on the first request and cached per host under CACHE_ROOT/<brand>/<host>/.
// files_cache_clean.py (infra/nodes/bin) removes CACHE_ROOT/<brand> whenever a files row of that brand is inserted, updated
// or deleted, so the next request rebuilds it. MAX_AGE is only a safety net in case the cleaner is not running.
final class DwSitemap
{
    public const CACHE_ROOT = '/var/lib/app/data/sitemap-cache';
    public const MAX_URLS = 50000;
    private const MAX_AGE = 86400;
    private const SKIP_TOP = ['lib', 'files', 'session', 'account_setup', 'relation', 'crm', 'dns', 'notes', 'note_files', 'domain', 'internal-files', 'markup', 'docs', 'skill'];
    private const ERROR_PAGE = '/^(?:[45]\d\d|50x|error|errors|not[-_]?found)\.html?$/i';

    // True for /robots.txt, /sitemap.xml and /sitemap-N.xml at the top of a customer domain.
    public static function isSpecial(string $path): bool
    {
        return $path === '/robots.txt' || $path === '/sitemap.xml' || preg_match('/^\/sitemap-[1-9]\d{0,3}\.xml$/', $path) === 1;
    }

    public static function serve(string $path, string $host, string $brand, callable $pdo): void
    {
        if (!DwDomain::validHost($host) || !preg_match('/^[A-Za-z0-9]{1,10}$/', $brand)) {
            return; // caller sends its normal 404
        }
        if ($path === '/robots.txt') {
            self::send('text/plain; charset=utf-8', self::robots($host), null);
        }
        $dir = self::CACHE_ROOT . '/' . $brand . '/' . $host;
        $name = substr($path, 1);
        $file = $dir . '/' . $name;
        // Freshness follows sitemap.xml, so a request for a missing /sitemap-N.xml never forces a rebuild.
        $t = @filemtime($dir . '/sitemap.xml');
        if ($t === false || $t < time() - self::MAX_AGE) {
            if (!self::build($dir, $host, $brand, $pdo)) {
                Dw::jsonOut(503, ['ok' => false, 'error' => 'unavailable']);
                exit;
            }
            clearstatcache(true, $file);
        }
        if (!is_file($file)) {
            return; // e.g. /sitemap-3.xml when the site only needs one file
        }
        self::send('application/xml; charset=utf-8', null, $file);
    }

    public static function robots(string $host): string
    {
        return DwBrand::t("# Default robots.txt from DW Alliance. Upload your own robots.txt (PUBLIC, top of the site) to replace it.\n")
            . "User-agent: *\n"
            . "Allow: /\n"
            . "\n"
            . 'Sitemap: https://' . $host . "/sitemap.xml\n";
    }

    // Returns [loc, unix time] for every page that belongs in the sitemap, homepage first.
    public static function urls(string $host, string $brand, callable $pdo): array
    {
        $q = $pdo()->prepare(
            "SELECT f.file_name, TRIM(BOTH '/' FROM COALESCE(f.file_path, '')) AS fp,"
            . " UNIX_TIMESTAMP(GREATEST(f.created, COALESCE((SELECT MAX(h.archived_at) FROM files_history h"
            . "   WHERE h.id = f.id AND h.action IN ('INSERT', 'UPDATE')), f.created))) AS ts,"
            . " SUBSTRING(f.file_data, 1, 32768) AS head"
            . " FROM files f WHERE f.brand = ? AND f.public_private = 'PUBLIC' AND f.file_type = 'HTML'"
        );
        $q->execute([$brand]);
        $out = [];
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            $name = (string)$r['file_name'];
            $fp = (string)$r['fp'];
            if (!preg_match('/\.html?$/i', $name) || preg_match(self::ERROR_PAGE, $name)) {
                continue;
            }
            $segs = $fp === '' ? [] : explode('/', $fp);
            if ($segs !== [] && in_array(strtolower($segs[0]), self::SKIP_TOP, true)) {
                continue;
            }
            $ok = true;
            foreach (array_merge($segs, [$name]) as $s) {
                if ($s === '' || $s[0] === '.' || strpos($s, '..') !== false || strpbrk($s, "\\\0") !== false) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok || self::noindex((string)$r['head'])) {
                continue;
            }
            $enc = array_map('rawurlencode', $segs);
            $urlPath = ($enc === [] ? '' : implode('/', $enc) . '/') . (strtolower($name) === 'index.html' ? '' : rawurlencode($name));
            $loc = 'https://' . $host . '/' . $urlPath;
            $ts = (int)$r['ts'];
            if (!isset($out[$loc]) || $out[$loc] < $ts) {
                $out[$loc] = $ts;
            }
        }
        uksort($out, static function (string $a, string $b): int {
            return [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b];
        });
        $list = [];
        foreach ($out as $loc => $ts) {
            $list[] = [$loc, $ts];
        }
        return $list;
    }

    // True when the page has <meta name="robots|googlebot" content="...noindex..."> (attributes in any order).
    private static function noindex(string $head): bool
    {
        if (stripos($head, 'noindex') === false || !preg_match_all('/<meta\b[^>]*>/i', $head, $m)) {
            return false;
        }
        foreach ($m[0] as $tag) {
            if (preg_match('/\bname\s*=\s*["\']?\s*(?:robots|googlebot)\b/i', $tag) && preg_match('/\bcontent\s*=\s*["\'][^"\']*\bnoindex\b/i', $tag)) {
                return true;
            }
        }
        return false;
    }

    // Writes sitemap.xml (and sitemap-N.xml parts when there are more than MAX_URLS pages) into $dir.
    private static function build(string $dir, string $host, string $brand, callable $pdo): bool
    {
        try {
            $urls = self::urls($host, $brand, $pdo);
        } catch (Throwable $e) {
            return false;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        foreach (glob($dir . '/sitemap*.xml') ?: [] as $old) {
            @unlink($old);
        }
        $chunks = array_chunk($urls, self::MAX_URLS);
        if (count($chunks) <= 1) {
            return self::write($dir . '/sitemap.xml', self::urlset($chunks[0] ?? []));
        }
        $idx = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($chunks as $i => $chunk) {
            $n = $i + 1;
            if (!self::write($dir . '/sitemap-' . $n . '.xml', self::urlset($chunk))) {
                return false;
            }
            $newest = max(array_column($chunk, 1));
            $idx .= '  <sitemap><loc>' . self::x('https://' . $host . '/sitemap-' . $n . '.xml') . '</loc><lastmod>' . gmdate('Y-m-d\TH:i:s\Z', $newest) . "</lastmod></sitemap>\n";
        }
        return self::write($dir . '/sitemap.xml', $idx . "</sitemapindex>\n");
    }

    private static function urlset(array $urls): string
    {
        $s = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$loc, $ts]) {
            $s .= '  <url><loc>' . self::x($loc) . '</loc><lastmod>' . gmdate('Y-m-d\TH:i:s\Z', $ts) . "</lastmod></url>\n";
        }
        return $s . "</urlset>\n";
    }

    private static function x(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function write(string $file, string $data): bool
    {
        $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $data) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        @chmod($file, 0644);
        return true;
    }

    private static function send(string $type, ?string $body, ?string $file): void
    {
        $etag = '"' . ($file !== null ? sha1_file($file) : sha1((string)$body)) . '"';
        header('Content-Type: ' . $type);
        header('Cache-Control: public, max-age=300');
        header('X-Content-Type-Options: nosniff');
        header('ETag: ' . $etag);
        if ($file !== null) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)filemtime($file)) . ' GMT');
        }
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }
        if ($file !== null) {
            readfile($file);
        } else {
            echo $body;
        }
        exit;
    }
}
