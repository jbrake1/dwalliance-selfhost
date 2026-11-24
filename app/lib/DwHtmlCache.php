<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// Host-keyed on-disk cache of PUBLIC HTML with the banner (and editor loader) already
// inserted (refs #49). Lives under /var/lib/app/data/html-cache/<host>/<request-path>,
// outside the document root. nginx serves a hit directly (try_files / if -f rewrite
// into the internal location /internal-html-cache/), with no PHP. 404.php only runs
// on a miss: it builds the page, writes the cache atomically, and serves the body.
//
// Invalidation: every banner / entitlement / /files / permissions change records a
// row in html_cache_purge (shared DB). A per-minute cron on each node reads new rows
// since that node's last-seen id and deletes the affected host/brand/file cache
// (HTML and watermarked JPG/PNG/WEBP/PDF; refs #51), or the whole cache for a
// global change, plus brands whose No Banner expiry just passed.
// Lock and last-seen state are per-node, not shared.

require_once '/var/www/html/lib/Dw.php';

final class DwHtmlCache
{
    public const ROOT = '/var/lib/app/data/html-cache';
    public const ACCEL = '/internal-html-cache/';
    public const EPOCH_GLOBAL = 'banner_cache_epoch';
    public const EPOCH_PREFIX = 'banner_cache_brand_';
    public const HEADER = 'X-DW-Html-Cache';
    public const WEB_ROOT = '/var/www/html';
    /** @var string|null CLI test hook: override the cache root. Ignored unless PHP_SAPI is cli. */
    public static ?string $testRoot = null;

    public static function root(): string
    {
        return (PHP_SAPI === 'cli' && is_string(self::$testRoot) && self::$testRoot !== '') ? self::$testRoot : self::ROOT;
    }

    /**
     * Record a purge in the shared queue. A null or empty brand is a global purge
     * (every tenant). Pass $fileId (and optional $fileRel, the cache-relative path)
     * for a single-file purge (refs #51). Safe inside an open transaction. The
     * per-node cron applies the row to that node's disk; this method does not
     * delete files itself.
     */
    public static function bump(PDO $db, ?string $brand, string $by = 'system', string $reason = 'bump', ?string $fileId = null, ?string $fileRel = null): void
    {
        $fileId = is_string($fileId) ? trim($fileId) : '';
        $fileRel = is_string($fileRel) ? ltrim(str_replace('\\', '/', trim($fileRel)), '/') : '';
        if ($fileRel !== '' && (str_contains($fileRel, '..') || strlen($fileRel) > 300)) {
            $fileRel = '';
        }
        if ($fileId !== '' && $brand !== null && $brand !== '') {
            $scope = 'file';
        } else {
            $scope = ($brand === null || $brand === '') ? 'global' : 'brand';
            $fileId = '';
            $fileRel = '';
        }
        $b = null;
        if ($scope === 'brand' || $scope === 'file') {
            $b = self::safeBrand($brand);
            if ($b === null) {
                return;
            }
        }
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $reason = trim($reason);
            if ($reason === '' || strlen($reason) > 80) {
                $reason = $reason === '' ? 'bump' : substr($reason, 0, 80);
            }
            $by = $by !== '' ? $by : 'system';
            $i = $db->prepare('INSERT INTO html_cache_purge (created, scope, brand, file_id, file_rel, reason, created_by_person_id) VALUES (?,?,?,?,?,?,?)');
            $i->execute([date('Y-m-d H:i:s'), $scope, $b, $fileId !== '' ? $fileId : null, $fileRel !== '' ? $fileRel : null, $reason, $by]);
            if ($own) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('DwHtmlCache::bump failed: ' . get_class($e));
            if (!$own) {
                throw $e;
            }
        }
    }

    /** @return list<array{id:string,scope:string,brand:?string,file_id:?string,file_rel:?string,reason:string,created:string}> */
    public static function newPurges(PDO $db, int $afterId): array
    {
        $q = $db->prepare('SELECT id, scope, brand, file_id, file_rel, reason, created FROM html_cache_purge WHERE id > ? ORDER BY id ASC LIMIT 2000');
        $q->execute([$afterId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** Apply one queue row to this node's disk. Idempotent. */
    public static function applyPurge(PDO $db, array $row): int
    {
        $scope = (string)($row['scope'] ?? '');
        if ($scope === 'global') {
            return self::purgeAll();
        }
        if ($scope === 'brand') {
            return self::purgeBrand((string)($row['brand'] ?? ''), $db);
        }
        if ($scope === 'file') {
            return self::purgeFile((string)($row['brand'] ?? ''), (string)($row['file_id'] ?? ''), $db, isset($row['file_rel']) ? (string)$row['file_rel'] : null);
        }
        return 0;
    }

    /** Brands whose no_banner starts_at or grace_until just crossed "now". */
    public static function brandsExpiredSince(PDO $db, string $since): array
    {
        $q = $db->prepare(
            "SELECT DISTINCT brand FROM brand_entitlement
              WHERE entitlement = 'no_banner' AND status IN ('ACTIVE','CANCELLED')
                AND brand IS NOT NULL AND brand <> ''
                AND (
                  (grace_until IS NOT NULL AND grace_until <= NOW() AND grace_until > ?)
                  OR (starts_at IS NOT NULL AND starts_at <= NOW() AND starts_at > ?)
                )"
        );
        $q->execute([$since, $since]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) ?: [] as $b) {
            $s = self::safeBrand((string)$b);
            if ($s !== null) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** @return array{global:int, brand:int} */
    public static function epochs(PDO $db, string $brand): array
    {
        $keys = [self::EPOCH_GLOBAL];
        $bk = self::epochKey($brand);
        if ($bk !== null) {
            $keys[] = $bk;
        }
        $in = implode(',', array_fill(0, count($keys), '?'));
        $q = $db->prepare("SELECT setting_name, setting_value FROM app_setting WHERE setting_name IN ($in)");
        $q->execute($keys);
        $got = ['global' => 0, 'brand' => 0];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (int)$r['setting_value'];
            if ($r['setting_name'] === self::EPOCH_GLOBAL) {
                $got['global'] = $n;
            } else {
                $got['brand'] = $n;
            }
        }
        return $got;
    }

    /** banner.json mtime and enabled flag, so a per-node kill switch invalidates that node's cache. */
    public static function cfgStamp(): string
    {
        $path = '/var/lib/app/data/banner.json';
        $mt = is_file($path) ? (string)@filemtime($path) : '0';
        $sz = is_file($path) ? (string)@filesize($path) : '0';
        return $mt . ':' . $sz;
    }

    /**
     * Next time an in-force or upcoming no_banner row would change visibility for this site, or null.
     * Used so paid_through / grace_until expiry (and a future starts_at) invalidate without an event.
     */
    public static function nextChangeAt(PDO $db, string $brand, ?string $bareHost): ?string
    {
        $dom = 'be.domain_id IS NULL';
        $args = [$brand, $brand];
        if ($bareHost !== null && $bareHost !== '') {
            $dom = '(be.domain_id IS NULL OR be.domain_id IN (SELECT d.id FROM domain d WHERE d.code = ? AND d.domain_name IN (?, ?)))';
            $args = [$brand, $brand, $bareHost, 'www.' . $bareHost, $brand, $brand, $bareHost, 'www.' . $bareHost];
        }
        $sql = "SELECT MIN(ts) FROM (
            SELECT be.grace_until AS ts FROM brand_entitlement be
              WHERE be.brand = ? AND be.entitlement = 'no_banner' AND be.status IN ('ACTIVE','CANCELLED')
                AND be.starts_at <= NOW() AND be.grace_until IS NOT NULL AND be.grace_until > NOW()
                AND $dom
            UNION ALL
            SELECT be.starts_at AS ts FROM brand_entitlement be
              WHERE be.brand = ? AND be.entitlement = 'no_banner' AND be.status IN ('ACTIVE','CANCELLED')
                AND be.starts_at > NOW() AND $dom
        ) x";
        $q = $db->prepare($sql);
        $q->execute($args);
        $v = $q->fetchColumn();
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Cheap PUBLIC-file identity (no blob). Null if the row is gone.
     * @return array{id:string, file_type:string, file_size:int, crc:string}|null
     */
    public static function fileFingerprint(array $row): ?array
    {
        if (!isset($row['id'], $row['file_type'])) {
            return null;
        }
        return [
            'id' => (string)$row['id'],
            'file_type' => (string)$row['file_type'],
            'file_size' => (int)($row['file_size'] ?? 0),
            'crc' => (string)($row['crc'] ?? ''),
        ];
    }

    /**
     * Map a request path (/ or /about/ or /index.html) to a safe relative cache path.
     * @return array{dir:string, file:string}|null
     */
    public static function relFromRequestPath(string $path): ?array
    {
        if ($path === '' || $path === '/') {
            $path = '/index.html';
        } elseif (str_ends_with($path, '/')) {
            $path .= 'index.html';
        }
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            return null;
        }
        $trim = ltrim($path, '/');
        if ($trim === '') {
            return null;
        }
        $parts = explode('/', $trim);
        $file = (string)array_pop($parts);
        return self::safeRel(implode('/', $parts), $file);
    }

    /**
     * Cache path is <root>/<host>/<request-path> so nginx can map $host$uri with no brand lookup.
     * $filePath/$fileName must be the request path (for the app host this includes the brand segment).
     * @return array{dir:string, html:string, meta:string, accel:string, hostDir:string, host:string, brand:string}|null
     */
    public static function paths(string $host, string $brand, string $filePath, string $fileName): ?array
    {
        $host = self::safeHost($host);
        $brand = self::safeBrand($brand);
        $rel = self::safeRel($filePath, $fileName);
        if ($host === null || $brand === null || $rel === null) {
            return null;
        }
        $hostDir = self::root() . '/' . $host;
        $dir = $hostDir . ($rel['dir'] !== '' ? '/' . $rel['dir'] : '');
        $html = $dir . '/' . $rel['file'];
        $accelRel = ($rel['dir'] !== '' ? implode('/', array_map('rawurlencode', explode('/', $rel['dir']))) . '/' : '')
            . rawurlencode($rel['file']);
        return [
            'dir' => $dir,
            'html' => $html,
            'meta' => $html . '.dwmeta',
            'accel' => self::ACCEL . rawurlencode($host) . '/' . $accelRel,
            'hostDir' => $hostDir,
            'host' => $host,
            'brand' => $brand,
        ];
    }

    /** @param array<string,mixed> $meta */
    public static function isFresh(?array $meta, array $want, string $htmlPath): bool
    {
        if (!is_array($meta) || !is_file($htmlPath) || is_link($htmlPath)) {
            return false;
        }
        foreach (['host', 'brand', 'file_id', 'file_size', 'crc', 'global_epoch', 'brand_epoch', 'cfg_stamp', 'html_sha1'] as $k) {
            if (!array_key_exists($k, $meta) || (string)$meta[$k] !== (string)($want[$k] ?? '')) {
                return false;
            }
        }
        $until = $meta['valid_until'] ?? null;
        if (is_string($until) && $until !== '' && strcmp($until, date('Y-m-d H:i:s')) <= 0) {
            return false;
        }
        $sha = @hash_file('sha1', $htmlPath);
        return is_string($sha) && hash_equals((string)$meta['html_sha1'], $sha);
    }

    /**
     * Atomic write: tmp files, 0644, rename HTML then meta. Returns false on failure (caller should still serve).
     * @param array<string,mixed> $meta
     */
    public static function write(array $paths, string $html, array $meta): bool
    {
        $dir = $paths['dir'];
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $meta['html_sha1'] = sha1($html);
        $nonce = bin2hex(random_bytes(4));
        $tmpH = $paths['html'] . '.tmp' . $nonce;
        $tmpM = $paths['meta'] . '.tmp' . $nonce;
        $ok = false;
        try {
            if (file_put_contents($tmpH, $html) === false) {
                return false;
            }
            @chmod($tmpH, 0644);
            $json = json_encode($meta, JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || file_put_contents($tmpM, $json) === false) {
                return false;
            }
            @chmod($tmpM, 0644);
            if (!@rename($tmpH, $paths['html'])) {
                return false;
            }
            $tmpH = '';
            if (!@rename($tmpM, $paths['meta'])) {
                @unlink($paths['html']);
                return false;
            }
            $tmpM = '';
            $ok = true;
            $hostDir = (string)($paths['hostDir'] ?? '');
            $host = (string)($paths['host'] ?? '');
            $brand = (string)($paths['brand'] ?? ($meta['brand'] ?? ''));
            if ($ok && $hostDir !== '' && $brand !== '' && !self::isAppCacheHost($host)) {
                $marker = $hostDir . '/.brand';
                if (!is_file($marker)) {
                    @file_put_contents($marker, $brand);
                    @chmod($marker, 0644);
                }
            }
        } finally {
            if ($tmpH !== '' && is_file($tmpH)) {
                @unlink($tmpH);
            }
            if ($tmpM !== '' && is_file($tmpM)) {
                @unlink($tmpM);
            }
        }
        return $ok;
    }

    public static function readMeta(string $metaPath): ?array
    {
        $raw = @file_get_contents($metaPath);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    /** Remove the host-agnostic try_files copy so app.dwalliance.com cannot serve the wrong body. */
    public static function unlinkLegacy(string $brand, string $filePath, string $fileName): void
    {
        $brand = self::safeBrand($brand);
        $rel = self::safeRel($filePath, $fileName);
        if ($brand === null || $rel === null) {
            return;
        }
        $path = self::WEB_ROOT . '/' . $brand . ($filePath !== '' ? '/' . $rel['dir'] : '') . '/' . $rel['file'];
        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }

    public static function isAppCacheHost(string $host): bool
    {
        $host = strtolower($host);
        return $host === DwBrand::appHost() || $host === 'localhost' || str_ends_with($host, '.lan');
    }

    /**
     * Drop one cached file (HTML or watermarked media) for a brand on every host
     * of that brand, plus the app-host subtree. $fileRel is the cache-relative
     * path recorded at bump time (no leading slash). When it is empty the current
     * files row is used; if that row is already gone, this is a no-op (callers
     * that delete a file should pass file_rel, or bump at brand scope).
     */
    public static function purgeFile(string $brand, string $fileId, ?PDO $db = null, ?string $fileRel = null): int
    {
        $brand = self::safeBrand($brand);
        if ($brand === null || !is_dir(self::root())) {
            return 0;
        }
        $rels = [];
        $fileRel = is_string($fileRel) ? ltrim(str_replace('\\', '/', trim($fileRel)), '/') : '';
        if ($fileRel !== '' && !str_contains($fileRel, '..') && strlen($fileRel) <= 300) {
            $rels[$fileRel] = true;
            $parts = explode('/', $fileRel);
            // Multi-domain folder in the DB is not in the customer-host request path.
            if (count($parts) >= 2 && str_contains($parts[0], '.')) {
                $rels[implode('/', array_slice($parts, 1))] = true;
            }
        } elseif ($db instanceof PDO && $fileId !== '') {
            try {
                $q = $db->prepare("SELECT file_name, TRIM(BOTH '/' FROM COALESCE(file_path, '')) AS file_path FROM files WHERE id = ? AND brand = ? LIMIT 1");
                $q->execute([$fileId, $brand]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if (is_array($r) && (string)$r['file_name'] !== '') {
                    $fp = (string)$r['file_path'];
                    $rels[$fp !== '' ? $fp . '/' . $r['file_name'] : (string)$r['file_name']] = true;
                    $parts = $fp === '' ? [] : explode('/', $fp);
                    if ($parts !== [] && str_contains($parts[0], '.')) {
                        $rest = implode('/', array_slice($parts, 1));
                        $rels[$rest !== '' ? $rest . '/' . $r['file_name'] : (string)$r['file_name']] = true;
                    }
                }
            } catch (Throwable $e) {
                // Fall through with whatever $rels we have.
            }
        }
        if ($rels === []) {
            return 0;
        }
        $n = 0;
        $hosts = [];
        if ($db instanceof PDO) {
            try {
                $q = $db->prepare('SELECT domain_name FROM domain WHERE code = ?');
                $q->execute([$brand]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) ?: [] as $h) {
                    $s = self::safeHost((string)$h);
                    if ($s !== null) {
                        $hosts[$s] = true;
                    }
                }
            } catch (Throwable $e) {
            }
        }
        $root = self::root();
        foreach (scandir($root) ?: [] as $h) {
            if ($h === '.' || $h === '..' || !is_dir($root . '/' . $h) || is_link($root . '/' . $h)) {
                continue;
            }
            $mine = isset($hosts[$h]);
            if (!$mine && !self::isAppCacheHost($h)) {
                $marker = @file_get_contents($root . '/' . $h . '/.brand');
                $mine = is_string($marker) && trim($marker) === $brand;
            }
            if (!$mine && !self::isAppCacheHost($h)) {
                continue;
            }
            foreach (array_keys($rels) as $rel) {
                $base = self::isAppCacheHost($h) ? ($root . '/' . $h . '/' . $brand . '/' . $rel) : ($root . '/' . $h . '/' . $rel);
                foreach ([$base, $base . '.dwmeta'] as $path) {
                    if (is_file($path) && !is_link($path) && @unlink($path)) {
                        $n++;
                    }
                }
            }
        }
        return $n;
    }

    /** Drop cached HTML (and watermarked media) for one brand (every host) on this node. Idempotent. */
    public static function purgeBrand(string $brand, ?PDO $db = null): int
    {
        $brand = self::safeBrand($brand);
        if ($brand === null || !is_dir(self::root())) {
            return 0;
        }
        $known = [];
        if ($db instanceof PDO) {
            try {
                $q = $db->prepare('SELECT domain_name FROM domain WHERE code = ?');
                $q->execute([$brand]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) ?: [] as $h) {
                    $s = self::safeHost((string)$h);
                    if ($s !== null) {
                        $known[$s] = true;
                    }
                }
            } catch (Throwable $e) {
                // Fall back to .brand markers and the app-host subtree.
            }
        }
        $n = 0;
        $root = self::root();
        foreach (scandir($root) ?: [] as $h) {
            if ($h === '.' || $h === '..') {
                continue;
            }
            $dir = $root . '/' . $h;
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            if (self::isAppCacheHost($h)) {
                $sub = $dir . '/' . $brand;
                if (is_dir($sub) && !is_link($sub)) {
                    $n += self::purgeTree($sub);
                }
                continue;
            }
            $marker = @file_get_contents($dir . '/.brand');
            $mine = isset($known[$h]) || (is_string($marker) && trim($marker) === $brand);
            if ($mine) {
                $n += self::purgeTree($dir);
            }
        }
        return $n;
    }

    /** Drop every host-keyed HTML cache file on this node. */
    public static function purgeAll(): int
    {
        if (!is_dir(self::root())) {
            return 0;
        }
        return self::purgeTree(self::root());
    }

    private static function purgeTree(string $dir): int
    {
        $n = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $p = $f->getPathname();
            if ($f->isLink()) {
                continue;
            }
            if ($f->isFile()) {
                $name = $f->getFilename();
                if ($name === '.brand' || str_ends_with($name, '.dwmeta') || str_contains($name, '.tmp')
                    || (bool)preg_match('/\.(html?|jpe?g|png|webp|pdf)$/i', $name)) {
                    if (@unlink($p)) {
                        $n++;
                    }
                }
            } elseif ($f->isDir()) {
                @rmdir($p);
            }
        }
        return $n;
    }

    private static function epochKey(?string $brand): ?string
    {
        if ($brand === null || $brand === '') {
            return self::EPOCH_GLOBAL;
        }
        $b = self::safeBrand($brand);
        return $b === null ? null : self::EPOCH_PREFIX . $b;
    }

    public static function safeHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '' || strlen($host) > 253 || str_contains($host, '..') || !preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $host)) {
            return null;
        }
        return $host;
    }

    public static function safeBrand(string $brand): ?string
    {
        return preg_match('/^[A-Za-z0-9]{1,10}$/', $brand) ? $brand : null;
    }

    /** @return array{dir:string, file:string}|null */
    private static function safeRel(string $filePath, string $fileName): ?array
    {
        if ($fileName === '' || str_contains($fileName, '/') || str_contains($fileName, '..') || str_contains($fileName, "\0") || $fileName[0] === '.') {
            return null;
        }
        if ($filePath === '') {
            return ['dir' => '', 'file' => $fileName];
        }
        if (str_contains($filePath, '..') || str_contains($filePath, "\0") || str_contains($filePath, '\\')) {
            return null;
        }
        foreach (explode('/', $filePath) as $p) {
            if ($p === '' || $p === '.' || $p === '..' || $p[0] === '.') {
                return null;
            }
        }
        return ['dir' => $filePath, 'file' => $fileName];
    }
}
