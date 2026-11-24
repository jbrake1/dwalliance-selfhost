<?php
// CLI only. Applies the shared html_cache_purge queue to THIS node's disk cache,
// then purges brands whose No Banner starts_at / grace_until just passed, and
// any leftover files whose .dwmeta valid_until has expired (refs #49).
//
// Run from cron every minute on each app node and on staging (separate containers,
// separate data dirs). State and the lock live on this node's data volume
// (/var/lib/app/data/html-cache-sweep.{lock,state}), so production and staging on
// one host cannot trip over each other. Idempotent: deleting already-gone files is a
// no-op; last-seen purge id only advances after a row is applied.
//
// Usage (inside the app container):
//   php /var/www/html/infra/banner/sweep_html_cache.php [--dry-run]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwHtmlCache.php';

$dry = in_array('--dry-run', $argv, true);
$dataDir = dirname(DwHtmlCache::ROOT);
if (PHP_SAPI === 'cli' && is_string(DwHtmlCache::$testRoot) && DwHtmlCache::$testRoot !== '') {
    $dataDir = DwHtmlCache::$testRoot;
}
$lockPath = $dataDir . '/html-cache-sweep.lock';
$statePath = $dataDir . '/html-cache-sweep.state';
if (!is_dir($dataDir) && !@mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
    fwrite(STDERR, "cannot create data dir\n");
    exit(1);
}

$lock = @fopen($lockPath, 'c+');
if ($lock === false) {
    fwrite(STDERR, "cannot open lock\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, date('c') . " already running on this node\n");
    exit(0);
}
$started = microtime(true);
$removed = 0;
$applied = 0;
$expired = 0;
$cfg = 0;
$stale = 0;
$lastId = 0;
$lastExpiry = '1970-01-01 00:00:00';
$cfgStamp = '';
$status = 'ok';
try {
    $prev = [];
    if (is_file($statePath)) {
        $j = json_decode((string)@file_get_contents($statePath), true);
        if (is_array($j)) {
            $prev = $j;
        }
    }
    $lastId = (int)($prev['last_purge_id'] ?? 0);
    $lastExpiry = (string)($prev['last_expiry_at'] ?? '1970-01-01 00:00:00');
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lastExpiry)) {
        $lastExpiry = '1970-01-01 00:00:00';
    }
    $cfgStamp = (string)($prev['cfg_stamp'] ?? '');

    $db = Dw::pdo();

    foreach (DwHtmlCache::newPurges($db, $lastId) as $row) {
        $n = $dry ? 0 : DwHtmlCache::applyPurge($db, $row);
        $removed += $n;
        $applied++;
        $lastId = max($lastId, (int)$row['id']);
        fwrite(STDOUT, date('c') . ' purge id=' . (int)$row['id'] . ' scope=' . $row['scope']
            . ($row['brand'] ? (' brand=' . $row['brand']) : '')
            . ' removed=' . $n . "\n");
    }

    $now = date('Y-m-d H:i:s');
    // Overlap the previous window by 5 minutes so a starts_at we already
    // noticed (e.g. a grant in the same minute) cannot advance last_expiry_at
    // past a grace_until that is then written a few seconds later with no bump.
    $since = date('Y-m-d H:i:s', min(strtotime($lastExpiry) ?: 0, time() - 300));
    foreach (DwHtmlCache::brandsExpiredSince($db, $since) as $brand) {
        $n = $dry ? 0 : DwHtmlCache::purgeBrand($brand, $db);
        $removed += $n;
        $expired++;
        fwrite(STDOUT, date('c') . ' expiry brand=' . $brand . ' removed=' . $n . "\n");
    }
    $lastExpiry = $now;

    $stamp = DwHtmlCache::cfgStamp();
    if ($cfgStamp !== '' && $stamp !== $cfgStamp) {
        $n = $dry ? 0 : DwHtmlCache::purgeAll();
        $removed += $n;
        $cfg = 1;
        fwrite(STDOUT, date('c') . ' banner.json changed, purged all removed=' . $n . "\n");
    }
    $cfgStamp = $stamp;

    // Safety net: leftover files whose baked valid_until has passed.
    $root = DwHtmlCache::root();
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink() || !str_ends_with($f->getFilename(), '.dwmeta')) {
                continue;
            }
            $meta = DwHtmlCache::readMeta($f->getPathname());
            $until = is_array($meta) ? ($meta['valid_until'] ?? null) : null;
            if (!is_string($until) || $until === '' || strcmp($until, $now) > 0) {
                continue;
            }
            $html = substr($f->getPathname(), 0, -strlen('.dwmeta'));
            $stale++;
            if (!$dry) {
                @unlink($html);
                @unlink($f->getPathname());
                $removed += 2;
            }
        }
    }

    fwrite(STDOUT, date('c') . ($dry ? ' dry-run' : '')
        . " applied=$applied expired_brands=$expired cfg=$cfg stale=$stale removed=$removed last_id=$lastId\n");
} catch (Throwable $e) {
    $status = 'error';
    fwrite(STDERR, date('c') . ' sweep failed: ' . $e->getMessage() . "\n");
} finally {
    $state = [
        'ts' => date('c'),
        'last_purge_id' => $lastId,
        'last_expiry_at' => $lastExpiry,
        'cfg_stamp' => $cfgStamp,
        'removed' => $removed,
        'applied' => $applied,
        'expired_brands' => $expired,
        'dry' => $dry,
        'status' => $status,
        'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
        'root' => DwHtmlCache::root(),
    ];
    // On error, still persist last_purge_id already applied (advanced per row).
    @file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    @chmod($statePath, 0644);
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit($status === 'ok' ? 0 : 1);
