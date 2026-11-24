<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// Restock notifications (2026-11-15, issue #52).
// Public signup / confirm / poll / unsubscribe live at /store/restock/.
// Staff set brand defaults and per-variant email copy on the same path.
// Hourly cron: infra/restock/send_restock.php. Bounce cron: infra/restock/check_bounces.php.
// DwStore::settle() calls onInvoicePaid() when an invoice first becomes PAID.

require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwCustomer.php';

final class DwRestock
{
    public const DEFAULT_SUBJECT = '{{product_name}} is back in stock';
    public const DEFAULT_BODY =
        "Good news: {{product_name}} is back in stock.\n\n"
        . "Buy it here:\n{{product_url}}\n\n"
        . "If you no longer want these emails, unsubscribe:\n{{unsubscribe_url}}\n";

    private const CONFIRM_SUBJECT = 'Confirm your restock alert for {{product_name}}';
    private const CONFIRM_BODY =
        "Please confirm you want an email when {{product_name}} is back in stock.\n\n"
        . "Click this link (it expires in 7 days if you have not confirmed yet):\n{{confirm_url}}\n\n"
        . "If you did not ask for this, you can ignore this message.\n";

    private static PDO $db;
    private static ?string $brand = null;
    private static ?array $staff = null;

    public static function route(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        self::$db = Dw::pdo();
        if ($method === 'GET') {
            self::routeGet();
            return;
        }
        if ($method !== 'POST') {
            self::out(405, ['ok' => false, 'error' => 'method not allowed']);
        }
        // One-click unsubscribe (RFC 8058) posts to the same URL as the List-Unsubscribe header.
        $unsub = self::requestToken('unsubscribe');
        if ($unsub !== null && (isset($_GET['unsubscribe']) || isset($_POST['unsubscribe'])
            || (string)($_POST['List-Unsubscribe'] ?? '') === 'One-Click'
            || self::rawBodyIsOneClick())) {
            self::unsubscribe($unsub, 'clicked', true);
            return;
        }
        $in = DwCustomer::body();
        $action = strtolower(trim((string)($in['action'] ?? '')));
        if ($action === '' && $unsub !== null) {
            self::unsubscribe($unsub, 'clicked', true);
            return;
        }
        if ($action === 'signup') {
            self::signup($in);
            return;
        }
        if (in_array($action, ['settings_read', 'settings_update', 'variant_email_read', 'variant_email_update', 'list', 'history'], true)) {
            self::authStaff($in, $action === 'settings_update' || $action === 'variant_email_update' ? 'UPDATE' : 'READ');
            if ($action === 'settings_read') {
                self::settingsRead();
            } elseif ($action === 'settings_update') {
                self::settingsUpdate($in);
            } elseif ($action === 'variant_email_read') {
                self::variantEmailRead($in);
            } elseif ($action === 'variant_email_update') {
                self::variantEmailUpdate($in);
            } elseif ($action === 'list') {
                self::listSubs($in);
            } else {
                self::listHistory($in);
            }
            return;
        }
        if ($action === '') {
            self::help();
        }
        self::out(400, ['ok' => false, 'error' => 'action must be one of signup, settings_read, settings_update, variant_email_read, variant_email_update, list, history']);
    }

    private static function routeGet(): void
    {
        $confirm = self::requestToken('confirm');
        if ($confirm !== null) {
            self::confirm($confirm);
            return;
        }
        $unsub = self::requestToken('unsubscribe');
        if ($unsub !== null) {
            self::unsubscribe($unsub, 'clicked', false);
            return;
        }
        $h = self::requestToken('h');
        if ($h !== null) {
            self::poll($h);
            return;
        }
        self::help();
    }

    private static function help(): void
    {
        self::out(200, [
            'ok' => true,
            'path' => '/store/restock/',
            'what' => 'Restock notifications. Visitors POST action=signup. Confirm, poll and unsubscribe are GET (and one-click POST) with unguessable tokens in the query.',
            'actions' => [
                'signup' => 'Public, on the store domain. email, variant_id. Optional honeypot field "website". Returns a poll handle only.',
                'settings_read' => 'Staff. Brand-level default subject, from and body.',
                'settings_update' => 'Staff (STORE UPDATE). restock_subject, restock_from, restock_body (empty clears to the built-in default).',
                'variant_email_read' => 'Staff. variant_id. The variant copy and the effective (resolved) copy.',
                'variant_email_update' => 'Staff (STORE UPDATE). variant_id plus restock_subject, restock_from, restock_body (empty falls back to the brand default).',
                'list' => 'Staff. Optional product_id or variant_id. Subscriber counts and rows for this brand (emails included for staff only).',
                'history' => 'Staff. notification_id or variant_id. Event log.',
            ],
            'get' => [
                '?confirm=TOKEN' => 'Sets validated=YES and shows a short page.',
                '?h=POLL_TOKEN' => 'JSON {ok, validated}. Unknown handles look the same as not-yet-validated.',
                '?unsubscribe=TOKEN' => 'Sets unsubscribed=YES, reason=clicked. Also accepts POST List-Unsubscribe=One-Click.',
            ],
        ]);
    }

    // ------------------------------------------------------------------ public signup / confirm / poll / unsub

    private static function signup(array $in): void
    {
        $brand = self::needPublicBrand();
        $ip = self::clientIp();
        // Honeypot: look successful, never sign up, never mail.
        $pot = trim((string)($in['website'] ?? $in['company_url'] ?? $in['hp'] ?? ''));
        if ($pot !== '') {
            self::out(200, ['ok' => true, 'handle' => self::token()]);
        }
        $email = DwCustomer::email($in['email'] ?? '');
        $vid = trim((string)($in['variant_id'] ?? ''));
        if ($email === null || $vid === '' || !preg_match('/^[0-9a-f]{50}$/', $vid)) {
            // Same wording whether the email or the variant is bad, so this does not probe subscribers.
            self::out(400, ['ok' => false, 'error' => 'enter a valid email for an item that can be watched']);
        }
        $limited = self::rateLimited($email, $ip);
        if ($limited !== null) {
            self::out(429, ['ok' => false, 'error' => $limited, 'retry_after' => 60]);
        }
        $v = self::one(
            'SELECT v.id, v.product_id, v.name AS variant_name, v.stock, p.name AS product_name, p.slug, p.active
               FROM product_variant v JOIN product p ON p.id = v.product_id
              WHERE v.id = ? AND v.brand = ? AND p.brand = ?',
            [$vid, $brand, $brand]
        );
        if (!$v || !(int)$v['active']) {
            self::out(400, ['ok' => false, 'error' => 'enter a valid email for an item that can be watched']);
        }
        // Only sold-out tracked items can be watched.
        if ($v['stock'] === null || (int)$v['stock'] > 0) {
            self::out(400, ['ok' => false, 'error' => 'this item is in stock']);
        }
        $host = self::signupHost($brand);
        $now = self::now();
        self::$db->beginTransaction();
        try {
            $row = self::one('SELECT * FROM restock_notifications WHERE variant_id = ? AND email = ? FOR UPDATE', [$vid, $email]);
            $confirm = self::token();
            $poll = self::token();
            $unsub = self::token();
            if ($row) {
                // Re-subscribe: always issue a new confirm + poll handle. Reset bounce / unsub after a fresh confirm only.
                $sets = [
                    'confirm_token' => $confirm,
                    'poll_token' => $poll,
                    'product_id' => $v['product_id'],
                    'signup_host' => $host,
                    'signup_ip' => $ip,
                    'created_at' => $now,
                ];
                if ($row['unsubscribed'] === 'YES' || $row['validated'] !== 'YES' || $row['bounced'] === 'YES') {
                    $sets['validated'] = 'NO';
                    $sets['unsubscribed'] = 'NO';
                    $sets['unsubscribe_reason'] = null;
                    // bounced stays YES until they confirm again (cleared in confirm()).
                } else {
                    // Already an active subscriber: still send a confirm so the popup can close on the click,
                    // without changing validated until they click.
                    $sets['unsubscribe_token'] = $unsub;
                }
                if (($row['unsubscribe_token'] ?? '') === '') {
                    $sets['unsubscribe_token'] = $unsub;
                } else {
                    $unsub = (string)$row['unsubscribe_token'];
                }
                self::q('UPDATE restock_notifications SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($sets)))
                    . ' WHERE id = ?', [...array_values($sets), $row['id']]);
                $id = (string)$row['id'];
            } else {
                $id = Dw::randId();
                self::q(
                    'INSERT INTO restock_notifications (id, variant_id, product_id, brand, email, validated, confirm_token, poll_token, unsubscribe_token, created_at, unsubscribed, bounced, signup_host, signup_ip)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$id, $vid, $v['product_id'], $brand, $email, 'NO', $confirm, $poll, $unsub, $now, 'NO', 'NO', $host, $ip]
                );
            }
            self::logEvent($id, 'signup', $ip !== '' ? 'ip' : null);
            self::$db->commit();
        } catch (Throwable $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            error_log('restock signup: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
            self::out(500, ['ok' => false, 'error' => 'could not complete signup']);
        }
        self::sendConfirm($id);
        self::out(200, ['ok' => true, 'handle' => $poll]);
    }

    private static function confirm(string $token): void
    {
        $row = self::one('SELECT * FROM restock_notifications WHERE confirm_token = ?', [$token]);
        if (!$row) {
            self::htmlPage('Link expired', 'This confirmation link is not valid, or it has expired. You can request a new restock alert from the product page.', 400);
        }
        // created_at is stored in the same local clock as the rest of the app (NOW()).
        $age = time() - strtotime((string)$row['created_at']);
        if ($row['validated'] !== 'YES' && $age > 7 * 24 * 3600) {
            self::htmlPage('Link expired', 'This confirmation link has expired. Request a new restock alert from the product page.', 400);
        }
        self::$db->beginTransaction();
        try {
            $row = self::one('SELECT * FROM restock_notifications WHERE id = ? FOR UPDATE', [$row['id']]);
            if ($row['validated'] !== 'YES') {
                self::q(
                    "UPDATE restock_notifications SET validated = 'YES', bounced = 'NO', unsubscribed = 'NO', unsubscribe_reason = NULL WHERE id = ?",
                    [$row['id']]
                );
                self::logEvent((string)$row['id'], 'validated', null);
            }
            self::$db->commit();
        } catch (Throwable $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            error_log('restock confirm: ' . substr($e->getMessage(), 0, 200));
            self::htmlPage('Try again', 'We could not confirm this alert. Please try the link again in a moment.', 500);
        }
        self::htmlPage('You are signed up', 'You can close this tab. If you still have the product page open, that popup will close on its own. We will email you when this item is back in stock.');
    }

    private static function poll(string $handle): void
    {
        // Unknown and not-yet-validated handles both return validated=false so this cannot be used
        // to probe whether an email is on the list.
        $row = self::one("SELECT validated FROM restock_notifications WHERE poll_token = ?", [$handle]);
        $yes = is_array($row) && ($row['validated'] ?? '') === 'YES';
        header('Cache-Control: no-store');
        self::out(200, ['ok' => true, 'validated' => $yes]);
    }

    private static function unsubscribe(string $token, string $reason, bool $oneClick): void
    {
        $row = self::one('SELECT * FROM restock_notifications WHERE unsubscribe_token = ?', [$token]);
        if (!$row) {
            if ($oneClick) {
                // RFC 8058: still answer 200 so mail clients do not retry, without revealing anything.
                self::out(200, ['ok' => true]);
            }
            self::htmlPage('Already unsubscribed', 'This unsubscribe link is not valid, or it has already been used. You will not get restock emails from it.', 200);
        }
        self::$db->beginTransaction();
        try {
            $row = self::one('SELECT * FROM restock_notifications WHERE id = ? FOR UPDATE', [$row['id']]);
            if ($row['unsubscribed'] !== 'YES') {
                self::q(
                    "UPDATE restock_notifications SET unsubscribed = 'YES', unsubscribe_reason = ? WHERE id = ?",
                    [$reason, $row['id']]
                );
                self::logEvent((string)$row['id'], 'unsubscribe', $reason . ($oneClick ? ',one-click' : ''));
            }
            self::$db->commit();
        } catch (Throwable $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            error_log('restock unsubscribe: ' . substr($e->getMessage(), 0, 200));
            if ($oneClick) {
                self::out(200, ['ok' => true]);
            }
            self::htmlPage('Try again', 'We could not unsubscribe this address. Please try the link again in a moment.', 500);
        }
        if ($oneClick) {
            self::out(200, ['ok' => true]);
        }
        $name = self::productName((string)$row['product_id'], (string)$row['brand']);
        $label = $name !== '' ? $name : 'this item';
        self::htmlPage('Unsubscribed', 'You will no longer get restock emails for ' . $label . '. If you change your mind, you can sign up again on the product page.');
    }

    // ------------------------------------------------------------------ purchase hook

    public static function onInvoicePaid(PDO $db, string $invId): void
    {
        if ($invId === '' || !preg_match('/^[0-9a-f]{50}$/', $invId)) {
            return;
        }
        $inv = $db->prepare('SELECT id, person_id, brand FROM invoice WHERE id = ?');
        $inv->execute([$invId]);
        $i = $inv->fetch(PDO::FETCH_ASSOC);
        if (!$i) {
            return;
        }
        $emails = self::personEmails($db, (string)$i['person_id'], (string)$i['brand']);
        if ($emails === []) {
            return;
        }
        $lines = $db->prepare('SELECT variant_id FROM invoice_product WHERE invoice_id = ?');
        $lines->execute([$invId]);
        $vids = array_values(array_unique(array_filter(array_map(fn($r) => (string)$r['variant_id'], $lines->fetchAll(PDO::FETCH_ASSOC)))));
        if ($vids === []) {
            return;
        }
        $already = $db->prepare("SELECT 1 FROM restock_notification_history WHERE event = 'purchase' AND detail = ? LIMIT 1");
        $already->execute(['invoice:' . $invId]);
        if ($already->fetchColumn()) {
            return;
        }
        $inE = implode(',', array_fill(0, count($emails), '?'));
        $inV = implode(',', array_fill(0, count($vids), '?'));
        $q = $db->prepare("SELECT id FROM restock_notifications WHERE brand = ? AND email IN ($inE) AND variant_id IN ($inV) AND unsubscribed = 'NO' FOR UPDATE");
        $own = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $own = true;
        }
        try {
            $q->execute(array_merge([(string)$i['brand']], $emails, $vids));
            $ids = $q->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $db->prepare("UPDATE restock_notifications SET unsubscribed = 'YES', unsubscribe_reason = 'purchased' WHERE id = ?")->execute([$id]);
                self::logEventOn($db, (string)$id, 'purchase', 'invoice:' . $invId);
                self::logEventOn($db, (string)$id, 'unsubscribe', 'purchased');
            }
            if ($ids === []) {
                // Record a purchase event against no row? Skip. Still mark the invoice handled via a sentinel?
                // If there were no subscribers, a later replay should also no-op. No history row to key off.
                // That is fine: the query finds nothing again.
            }
            if ($own) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('restock onInvoicePaid: ' . substr($e->getMessage(), 0, 200));
        }
    }

    // ------------------------------------------------------------------ cron: detect + send

    public static function cronSend(bool $dry = false): int
    {
        self::$db = Dw::pdo();
        $dataDir = self::dataDir();
        $lockPath = $dataDir . '/restock-send.lock';
        $lock = self::flockPath($lockPath);
        if ($lock === null) {
            fwrite(STDOUT, date('c') . " already running on this node\n");
            return 0;
        }
        $got = self::$db->query("SELECT GET_LOCK('dw_restock_send', 0)")->fetchColumn();
        if ((string)$got !== '1') {
            fwrite(STDOUT, date('c') . " another node holds the send lock\n");
            return 0;
        }
        $sent = 0;
        $events = 0;
        try {
            $events = self::detectTransitions($dry);
            if (!$dry) {
                $sent = self::processEvents();
            }
            fwrite(STDOUT, date('c') . " restock send events_new=$events emails_sent=$sent dry=" . ($dry ? '1' : '0') . "\n");
        } finally {
            try {
                self::$db->query("SELECT RELEASE_LOCK('dw_restock_send')");
            } catch (Throwable $e) {
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $sent;
    }

    private static function detectTransitions(bool $dry): int
    {
        $now = self::now();
        $rows = self::all(
            'SELECT v.id AS variant_id, v.brand, v.product_id, v.stock
               FROM product_variant v JOIN product p ON p.id = v.product_id AND p.brand = v.brand
              WHERE p.active = 1',
            []
        );
        $n = 0;
        self::$db->beginTransaction();
        try {
            foreach ($rows as $r) {
                $vid = (string)$r['variant_id'];
                $in = self::isInStock($r['stock']);
                $prev = self::one('SELECT in_stock, stock FROM restock_stock_state WHERE variant_id = ? FOR UPDATE', [$vid]);
                if (!$prev) {
                    if (!$dry) {
                        self::q(
                            'INSERT INTO restock_stock_state (variant_id, brand, product_id, in_stock, stock, updated_at) VALUES (?,?,?,?,?,?)',
                            [$vid, $r['brand'], $r['product_id'], $in ? 1 : 0, $r['stock'], $now]
                        );
                    }
                    continue;
                }
                $wasOut = (int)$prev['in_stock'] === 0;
                if ($wasOut && $in) {
                    if (!$dry) {
                        $id = Dw::randId();
                        self::q(
                            "INSERT INTO restock_event (id, brand, product_id, variant_id, detected_at, status) VALUES (?,?,?,?,?,'pending')",
                            [$id, $r['brand'], $r['product_id'], $vid, $now]
                        );
                    }
                    $n++;
                }
                if (!$dry) {
                    self::q(
                        'UPDATE restock_stock_state SET brand = ?, product_id = ?, in_stock = ?, stock = ?, updated_at = ? WHERE variant_id = ?',
                        [$r['brand'], $r['product_id'], $in ? 1 : 0, $r['stock'], $now, $vid]
                    );
                }
            }
            self::$db->commit();
        } catch (Throwable $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            throw $e;
        }
        return $n;
    }

    private static function processEvents(): int
    {
        // Reclaim stale "sending" rows after 15 minutes, then claim pending.
        self::q("UPDATE restock_event SET status = 'pending', started_at = NULL WHERE status = 'sending' AND started_at < (NOW() - INTERVAL 15 MINUTE)", []);
        $events = self::all("SELECT * FROM restock_event WHERE status = 'pending' ORDER BY detected_at, id", []);
        $sent = 0;
        foreach ($events as $ev) {
            $claim = self::$db->prepare("UPDATE restock_event SET status = 'sending', started_at = ? WHERE id = ? AND status = 'pending'");
            $claim->execute([self::now(), $ev['id']]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            try {
                $n = self::sendEvent($ev);
                $sent += $n;
                self::q("UPDATE restock_event SET status = 'sent', finished_at = ? WHERE id = ?", [self::now(), $ev['id']]);
            } catch (Throwable $e) {
                error_log('restock event ' . $ev['id'] . ': ' . substr($e->getMessage(), 0, 200));
                self::q("UPDATE restock_event SET status = 'failed', finished_at = ? WHERE id = ?", [self::now(), $ev['id']]);
            }
        }
        return $sent;
    }

    private static function sendEvent(array $ev): int
    {
        $subs = self::all(
            "SELECT * FROM restock_notifications
              WHERE variant_id = ? AND validated = 'YES' AND unsubscribed = 'NO' AND bounced = 'NO'",
            [$ev['variant_id']]
        );
        // One email per product per address: if this address already got a restock_email_sent
        // for this product in this same clock minute window from a sibling event, skip.
        // Also skip if this notification already has a send logged for this event id.
        $sent = 0;
        $groups = [];
        foreach ($subs as $s) {
            $key = strtolower((string)$s['email']) . '|' . (string)$s['product_id'];
            $groups[$key][] = $s;
        }
        foreach ($groups as $group) {
            $primary = $group[0];
            if (self::alreadySentForProduct((string)$primary['email'], (string)$ev['product_id'], (string)$ev['detected_at'])) {
                continue;
            }
            if (!self::maySend((string)$primary['email'])) {
                fwrite(STDOUT, date('c') . " skip send (not allowed)\n");
                continue;
            }
            $ok = self::sendRestockMail($primary, $ev, $group);
            if ($ok) {
                $sent++;
                foreach ($group as $s) {
                    self::q('UPDATE restock_notifications SET notified_at = ? WHERE id = ?', [self::now(), $s['id']]);
                    self::logEvent((string)$s['id'], 'restock_email_sent', 'event:' . $ev['id']);
                }
            }
        }
        return $sent;
    }

    private static function alreadySentForProduct(string $email, string $productId, string $detectedAt): bool
    {
        // Same product, same restock wave (events detected within 20 minutes): one email.
        // A later out-then-in (a new event wave) can mail again.
        $r = self::one(
            "SELECT 1
               FROM restock_notification_history h
               JOIN restock_notifications n ON n.id = h.notification_id
              WHERE h.event = 'restock_email_sent'
                AND n.email = ? AND n.product_id = ?
                AND h.detail IN (
                    SELECT CONCAT('event:', id) FROM restock_event
                     WHERE product_id = ? AND status IN ('sending','sent')
                       AND detected_at BETWEEN (? - INTERVAL 20 MINUTE) AND (? + INTERVAL 20 MINUTE)
                )
              LIMIT 1",
            [$email, $productId, $productId, $detectedAt, $detectedAt]
        );
        return $r !== null;
    }

    // ------------------------------------------------------------------ cron: bounces

    public static function cronBounces(bool $dry = false): int
    {
        self::$db = Dw::pdo();
        $dataDir = self::dataDir();
        $lock = self::flockPath($dataDir . '/restock-bounces.lock');
        if ($lock === null) {
            fwrite(STDOUT, date('c') . " bounce checker already running\n");
            return 0;
        }
        $n = 0;
        try {
            $cfg = self::mailCfg();
            $dir = (string)($cfg['maildir'] ?? ($dataDir . '/restock-bounces'));
            $n += self::processBounceDir($dir, $dry);
            if (!empty($cfg['imap']['host'])) {
                $n += self::processImap($cfg, $dry);
            }
            fwrite(STDOUT, date('c') . " restock bounces marked=$n dry=" . ($dry ? '1' : '0') . "\n");
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $n;
    }

    public static function parseBounceRecipients(string $raw): array
    {
        $found = [];
        $add = function (string $e) use (&$found): void {
            $e = DwCustomer::email($e);
            if ($e !== null) {
                $found[$e] = true;
            }
        };
        // DSN message/delivery-status
        if (preg_match_all('/^Action:\s*failed\b/im', $raw, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $chunk = substr($raw, max(0, $hit[1] - 400), 800);
                if (preg_match('/(?:Final|Original)-Recipient:\s*(?:rfc822;)\s*([^\s;]+)/i', $chunk, $r)) {
                    $add($r[1]);
                }
            }
        }
        if (preg_match_all('/(?:Final|Original)-Recipient:\s*(?:rfc822;)\s*([^\s;]+)/i', $raw, $rs)) {
            $hasFailed = preg_match('/^Action:\s*failed\b/im', $raw) === 1
                || preg_match('/X-Failed-Recipients:/i', $raw) === 1
                || preg_match('/^Status:\s*[45]\./im', $raw) === 1
                || preg_match('/mailer-daemon|postmaster@|MAILER-DAEMON/i', $raw) === 1;
            if ($hasFailed) {
                foreach ($rs[1] as $e) {
                    $add($e);
                }
            }
        }
        if (preg_match_all('/^X-Failed-Recipients:\s*(.+)$/im', $raw, $xs)) {
            foreach ($xs[1] as $line) {
                foreach (preg_split('/[,\s]+/', trim($line)) as $e) {
                    if ($e !== '') {
                        $add($e);
                    }
                }
            }
        }
        return array_keys($found);
    }

    public static function isBounceMessage(string $raw): bool
    {
        if (preg_match('/^Action:\s*failed\b/im', $raw)) {
            return true;
        }
        if (preg_match('/^X-Failed-Recipients:/im', $raw)) {
            return true;
        }
        if (preg_match('/Content-Type:\s*multipart\/report;\s*report-type\s*=\s*"?delivery-status"?/i', $raw)) {
            return true;
        }
        if (preg_match('/^From:.*(?:mailer-daemon|postmaster)/im', $raw) && preg_match('/(delivery failure|undeliverable|returned mail|delivery status notification)/i', $raw)) {
            return true;
        }
        return false;
    }

    private static function processBounceDir(string $dir, bool $dry): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $done = rtrim($dir, '/') . '/processed';
        if (!$dry && !is_dir($done) && !@mkdir($done, 0700, true) && !is_dir($done)) {
            fwrite(STDERR, "cannot create bounce processed dir\n");
            return 0;
        }
        $n = 0;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === 'processed') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $raw = (string)@file_get_contents($path);
            if ($raw === '' || !self::isBounceMessage($raw)) {
                continue;
            }
            $emails = self::parseBounceRecipients($raw);
            foreach ($emails as $e) {
                $n += self::markBounced($e, $dry);
            }
            if (!$dry) {
                @rename($path, $done . '/' . $name);
            }
        }
        return $n;
    }

    private static function processImap(array $cfg, bool $dry): int
    {
        $imap = $cfg['imap'];
        if (!function_exists('imap_open')) {
            fwrite(STDERR, "php-imap is not installed; mailbox bounce check skipped (maildir still works)\n");
            return 0;
        }
        $host = (string)$imap['host'];
        $port = (int)($imap['port'] ?? 993);
        $user = (string)($imap['user'] ?? '');
        $box = (string)($imap['mailbox'] ?? 'INBOX');
        $enc = strtolower((string)($imap['encryption'] ?? 'ssl'));
        $secret = self::imapSecret($imap);
        if ($host === '' || $user === '' || $secret === '') {
            fwrite(STDERR, "imap bounce config incomplete; skipped\n");
            return 0;
        }
        $flags = $enc === 'tls' ? '/tls/novalidate-cert' : ($enc === 'none' ? '/notls' : '/ssl/novalidate-cert');
        $mbox = '{' . $host . ':' . $port . $flags . '}' . $box;
        $conn = @imap_open($mbox, $user, $secret, 0, 1);
        // Do not log the secret or the raw server greeting.
        if ($conn === false) {
            fwrite(STDERR, "imap open failed\n");
            return 0;
        }
        $n = 0;
        try {
            $nMsg = imap_num_msg($conn);
            $max = min($nMsg, 200);
            for ($i = 1; $i <= $max; $i++) {
                $raw = imap_fetchheader($conn, $i) . "\r\n" . imap_body($conn, $i);
                if (!self::isBounceMessage($raw)) {
                    continue;
                }
                foreach (self::parseBounceRecipients($raw) as $e) {
                    $n += self::markBounced($e, $dry);
                }
                if (!$dry) {
                    imap_setflag_full($conn, (string)$i, '\\Seen \\Deleted');
                }
            }
            if (!$dry) {
                imap_expunge($conn);
            }
        } finally {
            imap_close($conn);
        }
        return $n;
    }

    private static function imapSecret(array $imap): string
    {
        $file = (string)($imap['password_file'] ?? '');
        if ($file !== '' && is_readable($file)) {
            return trim((string)file_get_contents($file));
        }
        $env = getenv('DW_RESTOCK_IMAP_PASSWORD');
        return is_string($env) ? $env : '';
    }

    private static function markBounced(string $email, bool $dry): int
    {
        $rows = self::all("SELECT id FROM restock_notifications WHERE email = ? AND bounced = 'NO'", [$email]);
        if ($rows === [] || $dry) {
            return $dry ? count($rows) : 0;
        }
        foreach ($rows as $r) {
            self::q("UPDATE restock_notifications SET bounced = 'YES' WHERE id = ?", [$r['id']]);
            self::logEvent((string)$r['id'], 'bounce', null);
        }
        return count($rows);
    }

    // ------------------------------------------------------------------ staff

    private static function settingsRead(): void
    {
        $row = self::one('SELECT restock_subject, restock_from, restock_body FROM restock_brand_setting WHERE brand = ?', [self::$brand]);
        self::out(200, ['ok' => true, 'settings' => [
            'brand' => self::$brand,
            'restock_subject' => $row['restock_subject'] ?? null,
            'restock_from' => $row['restock_from'] ?? null,
            'restock_body' => $row['restock_body'] ?? null,
            'effective' => self::effectiveCopy(self::$brand, null),
        ]]);
    }

    private static function settingsUpdate(array $in): void
    {
        $set = self::copyFromInput($in);
        if ($set === []) {
            self::out(400, ['ok' => false, 'error' => 'send restock_subject, restock_from or restock_body']);
        }
        $by = (string)self::$staff['person_id'];
        $now = self::now();
        self::$db->beginTransaction();
        try {
            $row = self::one('SELECT id FROM restock_brand_setting WHERE brand = ? FOR UPDATE', [self::$brand]);
            if ($row) {
                Dw::history(self::$db, 'restock_brand_setting', (string)$row['id'], 'UPDATE');
                $bits = [];
                $args = [];
                foreach ($set as $k => $v) {
                    $bits[] = "$k = ?";
                    $args[] = $v;
                }
                $bits[] = 'last_updated = ?';
                $args[] = $now;
                $bits[] = 'last_updated_by = ?';
                $args[] = $by;
                $args[] = $row['id'];
                self::q('UPDATE restock_brand_setting SET ' . implode(', ', $bits) . ' WHERE id = ?', $args);
            } else {
                $id = Dw::randId();
                self::q(
                    'INSERT INTO restock_brand_setting (id, brand, restock_subject, restock_from, restock_body, created, created_by, last_updated, last_updated_by) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$id, self::$brand, $set['restock_subject'] ?? null, $set['restock_from'] ?? null, $set['restock_body'] ?? null, $now, $by, $now, $by]
                );
                Dw::history(self::$db, 'restock_brand_setting', $id, 'INSERT');
            }
            self::$db->commit();
        } catch (Throwable $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            throw $e;
        }
        self::settingsRead();
    }

    private static function variantEmailRead(array $in): void
    {
        $vid = self::needId($in, 'variant_id');
        $v = self::one('SELECT id, product_id, restock_subject, restock_from, restock_body FROM product_variant WHERE id = ? AND brand = ?', [$vid, self::$brand]);
        if (!$v) {
            self::out(404, ['ok' => false, 'error' => 'variant not found on this brand']);
        }
        self::out(200, ['ok' => true, 'variant_id' => $vid, 'product_id' => $v['product_id'],
            'restock_subject' => $v['restock_subject'], 'restock_from' => $v['restock_from'], 'restock_body' => $v['restock_body'],
            'effective' => self::effectiveCopy(self::$brand, $v)]);
    }

    private static function variantEmailUpdate(array $in): void
    {
        $vid = self::needId($in, 'variant_id');
        $v = self::one('SELECT id FROM product_variant WHERE id = ? AND brand = ?', [$vid, self::$brand]);
        if (!$v) {
            self::out(404, ['ok' => false, 'error' => 'variant not found on this brand']);
        }
        $set = self::copyFromInput($in);
        if ($set === []) {
            self::out(400, ['ok' => false, 'error' => 'send restock_subject, restock_from or restock_body']);
        }
        $set['last_updated'] = self::now();
        $set['last_updated_by'] = self::$staff['person_id'];
        self::$db->beginTransaction();
        try {
            Dw::history(self::$db, 'product_variant', $vid, 'UPDATE');
            $bits = [];
            $args = [];
            foreach ($set as $k => $v) {
                $bits[] = "$k = ?";
                $args[] = $v;
            }
            $args[] = $vid;
            $args[] = self::$brand;
            self::q('UPDATE product_variant SET ' . implode(', ', $bits) . ' WHERE id = ? AND brand = ?', $args);
            self::$db->commit();
        } catch (Throwable $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            throw $e;
        }
        self::variantEmailRead($in);
    }

    private static function listSubs(array $in): void
    {
        $w = ['n.brand = ?'];
        $a = [self::$brand];
        $vid = trim((string)($in['variant_id'] ?? ''));
        $pid = trim((string)($in['product_id'] ?? ''));
        if ($vid !== '') {
            if (!preg_match('/^[0-9a-f]{50}$/', $vid)) {
                self::out(400, ['ok' => false, 'error' => 'variant_id is not a valid id']);
            }
            $w[] = 'n.variant_id = ?';
            $a[] = $vid;
        }
        if ($pid !== '') {
            if (!preg_match('/^[0-9a-f]{50}$/', $pid)) {
                self::out(400, ['ok' => false, 'error' => 'product_id is not a valid id']);
            }
            $w[] = 'n.product_id = ?';
            $a[] = $pid;
        }
        $rows = self::all(
            'SELECT n.id, n.variant_id, n.product_id, n.email, n.validated, n.unsubscribed, n.unsubscribe_reason, n.bounced, n.created_at, n.notified_at, p.name AS product_name, v.name AS variant_name
               FROM restock_notifications n
               JOIN product p ON p.id = n.product_id
               JOIN product_variant v ON v.id = n.variant_id
              WHERE ' . implode(' AND ', $w) . ' ORDER BY n.created_at DESC LIMIT 500',
            $a
        );
        self::out(200, ['ok' => true, 'subscribers' => $rows, 'count' => count($rows)]);
    }

    private static function listHistory(array $in): void
    {
        $nid = trim((string)($in['notification_id'] ?? ''));
        $vid = trim((string)($in['variant_id'] ?? ''));
        if ($nid !== '') {
            if (!preg_match('/^[0-9a-f]{50}$/', $nid)) {
                self::out(400, ['ok' => false, 'error' => 'notification_id is not a valid id']);
            }
            $rows = self::all(
                'SELECT h.id, h.notification_id, h.event, h.detail, h.created_at
                   FROM restock_notification_history h
                   JOIN restock_notifications n ON n.id = h.notification_id
                  WHERE h.notification_id = ? AND n.brand = ?
                  ORDER BY h.created_at, h.id',
                [$nid, self::$brand]
            );
            self::out(200, ['ok' => true, 'history' => $rows]);
        }
        if ($vid !== '') {
            if (!preg_match('/^[0-9a-f]{50}$/', $vid)) {
                self::out(400, ['ok' => false, 'error' => 'variant_id is not a valid id']);
            }
            $rows = self::all(
                'SELECT h.id, h.notification_id, h.event, h.detail, h.created_at
                   FROM restock_notification_history h
                   JOIN restock_notifications n ON n.id = h.notification_id
                  WHERE n.variant_id = ? AND n.brand = ?
                  ORDER BY h.created_at DESC, h.id DESC LIMIT 500',
                [$vid, self::$brand]
            );
            self::out(200, ['ok' => true, 'history' => $rows]);
        }
        self::out(400, ['ok' => false, 'error' => 'send notification_id or variant_id']);
    }

    // ------------------------------------------------------------------ mail

    private static function sendConfirm(string $id): void
    {
        $row = self::one(
            'SELECT n.*, p.name AS product_name, p.slug, v.name AS variant_name
               FROM restock_notifications n
               JOIN product p ON p.id = n.product_id
               JOIN product_variant v ON v.id = n.variant_id
              WHERE n.id = ?',
            [$id]
        );
        if (!$row || !self::maySend((string)$row['email'])) {
            return;
        }
        $store = self::storeName((string)$row['brand']);
        $confirmUrl = self::absUrl((string)$row['signup_host'], '/store/restock/?confirm=' . $row['confirm_token']);
        $vars = [
            'product_name' => (string)$row['product_name'],
            'variant_name' => (string)$row['variant_name'],
            'store_name' => $store,
            'confirm_url' => $confirmUrl,
            'product_url' => self::productUrl((string)$row['signup_host'], (string)$row['slug']),
            'unsubscribe_url' => self::absUrl((string)$row['signup_host'], '/store/restock/?unsubscribe=' . $row['unsubscribe_token']),
        ];
        $subject = self::fill(self::CONFIRM_SUBJECT, $vars);
        $body = self::fill(self::CONFIRM_BODY, $vars);
        $from = self::safeFrom(null, $store);
        self::deliver((string)$row['email'], $subject, $body, $from, $vars['unsubscribe_url']);
    }

    private static function sendRestockMail(array $primary, array $ev, array $group): bool
    {
        $p = self::one('SELECT name, slug FROM product WHERE id = ? AND brand = ?', [$primary['product_id'], $primary['brand']]);
        if (!$p) {
            return false;
        }
        $vrow = self::one('SELECT name, restock_subject, restock_from, restock_body FROM product_variant WHERE id = ?', [$primary['variant_id']]);
        $copy = self::effectiveCopy((string)$primary['brand'], $vrow);
        $store = self::storeName((string)$primary['brand']);
        $host = (string)($primary['signup_host'] ?: self::brandHost((string)$primary['brand']));
        $unsub = self::absUrl($host, '/store/restock/?unsubscribe=' . $primary['unsubscribe_token']);
        $vars = [
            'product_name' => (string)$p['name'],
            'variant_name' => (string)($vrow['name'] ?? ''),
            'store_name' => $store,
            'product_url' => self::productUrl($host, (string)$p['slug']),
            'unsubscribe_url' => $unsub,
        ];
        $subject = self::fill($copy['restock_subject'], $vars);
        $body = self::fill($copy['restock_body'], $vars);
        if (stripos($body, $unsub) === false) {
            $body = rtrim($body) . "\n\nUnsubscribe: " . $unsub . "\n";
        }
        $from = self::safeFrom($copy['restock_from'], $store);
        return self::deliver((string)$primary['email'], $subject, $body, $from, $unsub);
    }

    private static function deliver(string $to, string $subject, string $text, string $from, string $unsubUrl): bool
    {
        $unsubUrl = self::headerSafe($unsubUrl);
        $from = self::headerSafe($from);
        $extra = "List-Unsubscribe: <" . $unsubUrl . ">\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\nAuto-Submitted: auto-generated\r\n";
        $text = preg_replace('/\r\n?/', "\n", $text);
        $b = rtrim(chunk_split(base64_encode($text), 76, "\r\n"));
        $mime = $extra
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . $b . "\r\n";
        return Dw::sendMime($to, $subject, $mime, ['From' => $from]);
    }

    public static function effectiveCopy(string $brand, ?array $variant): array
    {
        $b = self::one('SELECT restock_subject, restock_from, restock_body FROM restock_brand_setting WHERE brand = ?', [$brand]) ?? [];
        $pick = function (string $k, string $fallback) use ($variant, $b): string {
            $v = trim((string)($variant[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
            $d = trim((string)($b[$k] ?? ''));
            return $d !== '' ? $d : $fallback;
        };
        return [
            'restock_subject' => $pick('restock_subject', self::DEFAULT_SUBJECT),
            'restock_from' => $pick('restock_from', ''),
            'restock_body' => $pick('restock_body', self::DEFAULT_BODY),
        ];
    }

    public static function fill(string $tpl, array $vars): string
    {
        return (string)preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : '';
        }, $tpl);
    }

    public static function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isInStock($stock): bool
    {
        return $stock === null || (int)$stock > 0;
    }

    // ------------------------------------------------------------------ helpers

    private static function maySend(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || DwCustomer::isTestAddress($email)) {
            return false;
        }
        $list = getenv('DW_RESTOCK_ALLOWLIST');
        if (is_string($list) && trim($list) !== '') {
            $want = array_map('strtolower', array_map('trim', explode(',', $list)));
            return in_array($email, $want, true);
        }
        // Staging (or any non-production database) never mails the open internet unless
        // an allowlist is set, or mail is being captured to DW_MAIL_OUTBOX.
        // The live database is RESTOCK_LIVE_DB_NAME in DwConfig (dw_production on the hosted service).
        $dbName = DwConfig::get('DB_NAME', 'dw_production');
        if ($dbName !== DwConfig::get('RESTOCK_LIVE_DB_NAME', 'dw_production') && getenv('DW_RESTOCK_ALLOW_ALL') !== '1') {
            $outbox = getenv('DW_MAIL_OUTBOX');
            return is_string($outbox) && $outbox !== '';
        }
        return true;
    }

    private static function safeFrom(?string $requested, string $storeName): string
    {
        $fallbackName = $storeName !== '' ? $storeName : DwBrand::name();
        $fallback = self::headerSafe($fallbackName) . ' <' . DwBrand::supportEmail() . '>';
        $s = trim((string)$requested);
        if ($s === '') {
            return $fallback;
        }
        $name = '';
        $email = $s;
        if (preg_match('/^(.+)<([^>]+)>$/', $s, $m)) {
            $name = trim($m[1], " \t\"");
            $email = trim($m[2]);
        }
        $email = DwCustomer::email($email);
        if ($email === null) {
            return $fallback;
        }
        // Only @dwalliance.com addresses may appear in From (the relay is support@).
        // Anything else becomes Reply-To-worthy copy on the display name, From stays support@.
        $name = $name !== '' ? $name : $fallbackName;
        $name = self::headerSafe($name);
        if (preg_match('/@' . DwBrand::domainPattern() . '$/i', $email)) {
            return $name . ' <' . $email . '>';
        }
        return $fallback;
    }

    private static function headerSafe(string $s): string
    {
        $s = str_replace(["\r", "\n"], '', $s);
        return trim($s);
    }

    private static function absUrl(string $host, string $path): string
    {
        $base = getenv('DW_RESTOCK_PUBLIC_BASE');
        if (is_string($base) && $base !== '') {
            return rtrim($base, '/') . $path;
        }
        $host = strtolower(trim($host));
        $host = preg_replace('/^https?:\/\//', '', $host);
        $host = preg_replace('/[\/\s].*$/', '', $host);
        if ($host === '' || !preg_match('/^[a-z0-9.-]+(?::\d+)?$/', $host)) {
            $host = DwBrand::domain();
        }
        return 'https://' . $host . $path;
    }

    private static function productUrl(string $host, string $slug): string
    {
        $slug = trim($slug, '/');
        return self::absUrl($host, '/store/' . rawurlencode($slug) . '.html');
    }

    private static function signupHost(string $brand): string
    {
        $req = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $req = preg_replace('/:\d+$/', '', $req);
        if ($req !== '' && self::hostBelongs($brand, $req)) {
            return $req;
        }
        return self::brandHost($brand) ?: $req;
    }

    private static function hostBelongs(string $brand, string $host): bool
    {
        $bare = preg_replace('/^www\./', '', $host);
        $r = self::one('SELECT 1 FROM domain WHERE code = ? AND domain_name IN (?, ?, ?) LIMIT 1', [$brand, $host, $bare, 'www.' . $bare]);
        return $r !== null;
    }

    private static function brandHost(string $brand): string
    {
        $r = self::one("SELECT domain_name FROM domain WHERE code = ? AND domain_name NOT LIKE 'www.%' ORDER BY domain_name LIMIT 1", [$brand]);
        return $r ? (string)$r['domain_name'] : '';
    }

    private static function storeName(string $brand): string
    {
        $r = self::one(
            'SELECT c.company_name FROM brand b JOIN company c ON c.id = b.dw_company_id WHERE b.code = ?',
            [$brand]
        );
        $n = trim((string)($r['company_name'] ?? ''));
        return $n !== '' ? $n : 'the store';
    }

    private static function productName(string $productId, string $brand): string
    {
        $r = self::one('SELECT name FROM product WHERE id = ? AND brand = ?', [$productId, $brand]);
        return trim((string)($r['name'] ?? ''));
    }

    private static function personEmails(PDO $db, string $personId, string $brand): array
    {
        $q = $db->prepare("SELECT email FROM email WHERE owner_type = 'PRSN' AND owner_id = ? AND brand = ?");
        $q->execute([$personId, $brand]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $e) {
            $n = DwCustomer::email((string)$e);
            if ($n !== null) {
                $out[$n] = true;
            }
        }
        return array_keys($out);
    }

    private static function rateLimited(string $email, string $ip): ?string
    {
        $msg = 'too many restock requests just now. Try again in a minute';
        $nEmailMin = (int)(self::one('SELECT COUNT(*) AS n FROM restock_notifications WHERE email = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)', [$email])['n'] ?? 0);
        if ($nEmailMin >= 3) {
            return $msg;
        }
        $nEmailHour = (int)(self::one('SELECT COUNT(*) AS n FROM restock_notification_history h JOIN restock_notifications n ON n.id = h.notification_id WHERE n.email = ? AND h.event = ? AND h.created_at >= (NOW() - INTERVAL 1 HOUR)', [$email, 'signup'])['n'] ?? 0);
        if ($nEmailHour >= 8) {
            return $msg;
        }
        if ($ip !== '') {
            $nIpMin = (int)(self::one('SELECT COUNT(*) AS n FROM restock_notifications WHERE signup_ip = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)', [$ip])['n'] ?? 0);
            if ($nIpMin >= 8) {
                return $msg;
            }
            $nIpHour = (int)(self::one('SELECT COUNT(*) AS n FROM restock_notifications WHERE signup_ip = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)', [$ip])['n'] ?? 0);
            if ($nIpHour >= 30) {
                return $msg;
            }
        }
        return null;
    }

    private static function copyFromInput(array $in): array
    {
        $set = [];
        foreach (['restock_subject' => 200, 'restock_from' => 200, 'restock_body' => 20000] as $k => $max) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = $in[$k];
            if ($v === null || $v === '') {
                $set[$k] = null;
                continue;
            }
            if (!is_scalar($v)) {
                self::out(400, ['ok' => false, 'error' => $k . ' must be text']);
            }
            $s = trim((string)$v);
            if (strlen($s) > $max) {
                self::out(400, ['ok' => false, 'error' => $k . ' is too long']);
            }
            if ($k === 'restock_from' && $s !== '') {
                $check = $s;
                if (preg_match('/^(.+)<([^>]+)>$/', $s, $m)) {
                    $check = trim($m[2]);
                }
                if (DwCustomer::email($check) === null) {
                    self::out(400, ['ok' => false, 'error' => 'restock_from must be an email or Name <email>']);
                }
            }
            $set[$k] = $s;
        }
        return $set;
    }

    private static function needPublicBrand(): string
    {
        try {
            $r = DwCustomer::brandFromHost();
        } catch (Throwable $e) {
            $r = null;
        }
        if (!is_array($r) || empty($r[0])) {
            self::out(400, ['ok' => false, 'error' => 'call this on the store\'s own domain']);
        }
        self::$brand = (string)$r[0];
        return self::$brand;
    }

    private static function authStaff(array $in, string $right): void
    {
        $s = Dw::session($in);
        if ($s === null) {
            self::out(401, ['ok' => false, 'error' => 'a valid session is required']);
        }
        $b = trim((string)($in['brand'] ?? ''));
        if ($b === '' || !hash_equals((string)$s['brand'], $b)) {
            self::out(403, ['ok' => false, 'error' => 'brand does not match this session']);
        }
        $q = self::$db->prepare("SELECT 1 FROM data_access WHERE person_id = ? AND brand = ? AND (right_name = 'SUPER_ADMIN' OR (module_name = 'STORE' AND right_name IN (?, 'WRITE'))) LIMIT 1");
        $q->execute([(string)$s['person_id'], $b, $right]);
        if (!$q->fetchColumn()) {
            self::out(403, ['ok' => false, 'error' => 'needs SUPER_ADMIN or STORE ' . $right . ' on this brand']);
        }
        self::$staff = $s;
        self::$brand = $b;
    }

    private static function needId(array $in, string $k): string
    {
        $v = trim((string)($in[$k] ?? ''));
        if ($v === '' || !preg_match('/^[0-9a-f]{50}$/', $v)) {
            self::out(400, ['ok' => false, 'error' => $k . ' is required']);
        }
        return $v;
    }

    private static function requestToken(string $key): ?string
    {
        $v = $_GET[$key] ?? $_POST[$key] ?? '';
        if (is_array($v)) {
            return null;
        }
        $v = trim((string)$v);
        return preg_match('/^[0-9a-f]{64}$/', $v) ? $v : null;
    }

    private static function rawBodyIsOneClick(): bool
    {
        $raw = (string)file_get_contents('php://input');
        return $raw === 'List-Unsubscribe=One-Click' || str_contains($raw, 'List-Unsubscribe=One-Click');
    }

    private static function clientIp(): string
    {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return strlen($ip) > 45 ? substr($ip, 0, 45) : $ip;
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function dataDir(): string
    {
        $d = getenv('DW_RESTOCK_DATA') ?: '/var/lib/app/data';
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return rtrim($d, '/');
    }

    private static function mailCfg(): array
    {
        $path = self::dataDir() . '/restock-mail.json';
        if (!is_readable($path)) {
            return [];
        }
        $j = json_decode((string)file_get_contents($path), true);
        return is_array($j) ? $j : [];
    }

    private static function flockPath(string $path)
    {
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            throw new RuntimeException('cannot open lock ' . basename($path));
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }
        return $fp;
    }

    private static function logEvent(string $notificationId, string $event, ?string $detail): void
    {
        self::logEventOn(self::$db, $notificationId, $event, $detail);
    }

    private static function logEventOn(PDO $db, string $notificationId, string $event, ?string $detail): void
    {
        $db->prepare('INSERT INTO restock_notification_history (id, notification_id, event, detail, created_at) VALUES (?,?,?,?,?)')
            ->execute([Dw::randId(), $notificationId, $event, $detail !== null ? mb_substr($detail, 0, 255) : null, date('Y-m-d H:i:s')]);
    }

    private static function q(string $sql, array $a = []): PDOStatement
    {
        $s = self::$db->prepare($sql);
        $s->execute($a);
        return $s;
    }

    private static function one(string $sql, array $a = []): ?array
    {
        $r = self::q($sql, $a)->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private static function all(string $sql, array $a = []): array
    {
        return self::q($sql, $a)->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function out(int $code, array $p): void
    {
        DwCustomer::out($code, $p);
    }

    private static function htmlPage(string $title, string $body, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $t = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $b = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $t . '</title>'
            . '<style>body{margin:0;font:16px/1.55 system-ui,-apple-system,Segoe UI,sans-serif;color:#1a1a1a;background:#fafafa}'
            . 'main{max-width:32rem;margin:12vh auto;padding:2rem 1.25rem}h1{font-size:1.4rem;margin:0 0 .75rem}</style>'
            . '</head><body><main><h1>' . $t . '</h1><p>' . $b . '</p></main></body></html>';
        exit;
    }
}
