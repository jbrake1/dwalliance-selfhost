<?php
// CLI only. Bounce checker for restock mail (refs #52).
// Reads a drop folder (and optional IMAP mailbox) of bounce messages, marks
// matching subscriber addresses bounced=YES, and stops mailing them.
// Config (not in the repo; lives outside the web root):
//   /var/lib/app/data/restock-mail.json
//     { "maildir": "/var/lib/app/data/restock-bounces",
//       "imap": { "host": "", "port": 993, "user": "", "mailbox": "INBOX",
//                 "encryption": "ssl", "password_file": "/root/restock-imap-password" } }
// Staging can drop a .eml into the maildir to simulate a bounce. Password file
// is mode 600 and is never printed.
//
//   php /var/www/html/infra/restock/check_bounces.php [--dry-run]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once '/var/www/html/lib/DwRestock.php';
$dry = in_array('--dry-run', $argv, true);
try {
    DwRestock::cronBounces($dry);
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' restock bounce check failed: ' . $e->getMessage() . "\n");
    exit(1);
}
