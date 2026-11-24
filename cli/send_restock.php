<?php
// CLI only. Hourly restock mailer (refs #52).
// Detects variants that went from out of stock to in stock since the last run,
// then sends one email per product to confirmed, still-subscribed, non-bounced
// addresses. flock + MySQL GET_LOCK so it is safe if more than one node runs it.
// Production plan: install the cron on one app node only. Staging: its own container.
//
//   php /var/www/html/infra/restock/send_restock.php [--dry-run]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once '/var/www/html/lib/DwRestock.php';
$dry = in_array('--dry-run', $argv, true);
try {
    DwRestock::cronSend($dry);
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' restock send failed: ' . $e->getMessage() . "\n");
    exit(1);
}
