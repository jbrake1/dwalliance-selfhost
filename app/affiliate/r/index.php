<?php
declare(strict_types=1);

/**
 * Short affiliate share link: /affiliate/r/?ref=CODE (or ?aff=) then a 302
 * to a same-site path. Tracking also works via ?ref= on any other page;
 * this path is a reliable cache-bypass landing that always runs PHP.
 */

require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwAffiliate.php';

header('Cache-Control: no-store');

try {
    $db = Dw::pdo();
    DwAffiliate::captureReferralFromRequest($db, 'link');
} catch (Throwable $e) {
    // Never fail the redirect because tracking failed.
}

$to = (string)($_GET['to'] ?? '/');
$target = '/';
if ($to !== '' && str_starts_with($to, '/') && !str_starts_with($to, '//') && !str_contains($to, '\\')) {
    $target = $to;
}

header('Location: ' . $target, true, 302);
exit;
