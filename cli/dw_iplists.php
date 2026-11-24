<?php
// Refresh the signup IP lists. Root-only CLI, run inside the app container from host cron:
//   php dw_iplists.php            tor, vpn, hosting; country only when older than country_max_age_days
//   php dw_iplists.php country    force the country DB      php dw_iplists.php tor vpn ...
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) { fwrite(STDERR, "root only\n"); exit(2); }
require '/var/www/html/lib/DwSignup.php';
$names = array_slice($argv, 1);
$force = in_array('country', $names, true);
foreach ($names ?: ['tor', 'vpn', 'hosting', 'country'] as $n) {
    if ($n === 'country') {
        $ok = DwSignup::refreshCountry($force);
        $m = json_decode((string)@file_get_contents(DwSignup::DIR . '/country.json'), true) ?: [];
        echo date('c') . " country: " . ($ok ? 'ok' : 'NOT refreshed') . ', ' . ($m['source'] ?? '?') . ', v4=' . ($m['ranges4'] ?? 0) . ' v6=' . ($m['ranges6'] ?? 0) . "\n";
        continue;
    }
    $ok = DwSignup::refresh($n); $d = @include DwSignup::DIR . '/' . $n . '.php';
    echo date('c') . " $n: " . ($ok ? 'refreshed' : 'NOT refreshed') . ', exact=' . count($d['exact'] ?? []) . ' ranges=' . count($d['ranges'] ?? []) . "\n";
}
// php-fpm (www-data) must read these and may refresh tor/vpn/hosting if cron misses.
foreach (glob(DwSignup::DIR . '/*') as $f) { @chown($f, 'www-data'); @chgrp($f, 'www-data'); @chmod($f, 0640); }
@chown(DwSignup::DIR, 'www-data'); @chgrp(DwSignup::DIR, 'www-data'); @chmod(DwSignup::DIR, 02750);
