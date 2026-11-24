<?php
// CLI only. Clears HTML disk caches (refs #49):
//   - host-keyed copies under /var/lib/app/data/html-cache (banner already inserted)
//   - leftover try_files copies under /var/www/html/<brand>/... that match a PUBLIC HTML row
// Run after a kill-switch change if you want a cold cache, or after a deploy. Not required
// for ordinary banner on/off (those bump a DB epoch and each node rebuilds on the next request).
// Never touches app code. Usage (inside the app container):
//   php /var/www/html/infra/banner/purge_html_cache.php [--dry-run]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwHtmlCache.php';
$dry = in_array('--dry-run', $argv, true);
$root = '/var/www/html';
$q = Dw::pdo()->query("SELECT DISTINCT brand, TRIM(BOTH '/' FROM COALESCE(file_path, '')) AS p, file_name FROM files
    WHERE public_private = 'PUBLIC' AND file_type = 'HTML'");
$n = 0; $seen = 0;
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $b = (string)$r['brand']; $p = (string)$r['p']; $f = (string)$r['file_name'];
    if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $b) || $f === '' || str_contains($f, '/') || str_contains($p, '..') || str_contains($f, '..')) continue;
    $seen++;
    $path = $root . '/' . $b . ($p !== '' ? '/' . $p : '') . '/' . $f;
    if (is_file($path) && !is_link($path)) {
        $n++;
        if (!$dry) @unlink($path);
    }
}
$hostN = 0;
if (!$dry) {
    $hostN = DwHtmlCache::purgeAll();
}
fwrite(STDOUT, ($dry ? 'would remove ' : 'removed ') . $n . " legacy HTML copies (of $seen PUBLIC HTML rows)"
    . ($dry ? "\n" : " and $hostN host-keyed cache files\n"));
