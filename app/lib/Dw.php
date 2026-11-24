<?php
declare(strict_types=1);

require_once __DIR__ . '/DwConfig.php';
require_once __DIR__ . '/DwBrand.php';

class Dw
{
    public static function randId(): string
    {
        return bin2hex(random_bytes(25));
    }

    public static function pdo(): PDO
    {
        $dbSecret = getenv('DW_DB_PASSWORD');
        if (!is_string($dbSecret) || $dbSecret === '') {
            self::jsonOut(500, ['ok' => false, 'error' => 'database is not configured']);
            exit;
        }
        // DW_DB_HOST / DW_DB_NAME / DW_DB_USER override lib/dw_config.php (staging uses its own database).
        $host = DwConfig::get('DB_HOST', '127.0.0.1');
        $name = DwConfig::get('DB_NAME', 'dwalliance');
        $user = DwConfig::get('DB_USER', 'dwalliance');
        return new PDO(
            'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
            $user,
            $dbSecret,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * CRM/DNS database (DNS_DB_HOST / DNS_DB_NAME in DwConfig). Throws if DW_DNS_DB_USER or
     * DW_DNS_DB_PASSWORD are unset, so callers can catch (domain claim must not
     * fail after the name is already committed). Do not jsonOut+exit here.
     * Staging is intentionally not given these credentials so it cannot write
     * test zones into the production DNS database.
     */
    public static function pdoDns(): PDO
    {
        $user = getenv('DW_DNS_DB_USER');
        $dbSecret = getenv('DW_DNS_DB_PASSWORD');
        if (!is_string($user) || $user === '' || !is_string($dbSecret) || $dbSecret === '') {
            throw new RuntimeException('DNS database is not configured');
        }
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        // Optional session sql_mode, e.g. without NO_ZERO_DATE on MySQL 8 so the zero rr_timestamp
        // that tinydns rows use is accepted. Empty: the server default.
        $mode = DwConfig::get('DNS_DB_SQL_MODE');
        if ($mode !== '' && preg_match('/^[A-Z_,]+$/', $mode) === 1) {
            $opts[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION sql_mode = '" . $mode . "'";
        }
        return new PDO(
            'mysql:host=' . DwConfig::get('DNS_DB_HOST', DwConfig::get('DB_HOST', '127.0.0.1')) . ';dbname=' . DwConfig::get('DNS_DB_NAME', 'crm') . ';charset=utf8mb4',
            $user,
            $dbSecret,
            $opts
        );
    }

    // Staging only: when DW_MAIL_OUTBOX is set, mail is written to that folder and the relay is never contacted.
    public static function outbox(string $to, string $subject, string $text, ?string $from = null): ?bool
    {
        $dir = getenv('DW_MAIL_OUTBOX');
        if (!is_string($dir) || $dir === '') {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return false;
        }
        $name = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        $fromHeader = DwBrand::mailFrom();
        if (is_string($from) && $from !== '' && !preg_match('/[\r\n]/', $from)) {
            $fromHeader = $from;
        }
        $msg = "From: " . $fromHeader . "\r\nTo: <" . str_replace(["\r", "\n"], '', $to) . ">\r\nSubject: "
            . str_replace(["\r", "\n"], '', $subject) . "\r\nDate: " . gmdate('D, d M Y H:i:s O') . "\r\n" . $text;
        $ok = @file_put_contents($name, $msg, LOCK_EX) !== false;
        @chmod($name, 0600);
        return $ok;
    }

    /** SMTP relay (SMTP_HOST / SMTP_PORT in DwConfig). False when it cannot be reached. */
    private static function smtpOpen()
    {
        return @fsockopen(DwConfig::get('SMTP_HOST', '127.0.0.1'), (int)DwConfig::get('SMTP_PORT', '25'), $errno, $errstr, 10);
    }

    private static function smtpHelo(): string
    {
        $h = DwConfig::get('SMTP_HELO');
        if ($h === '') {
            $h = (string)gethostname();
        }
        $h = (string)preg_replace('/[^A-Za-z0-9.-]/', '', $h);
        return $h !== '' ? $h : 'localhost';
    }

    public static function sendMail(string $to, string $subject, string $body, ?string $replyTo = null): bool
    {
        $extra = '';
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n<>]/', $replyTo)) {
            $extra = 'Reply-To: <' . $replyTo . ">\r\n";
        }
        $o = self::outbox($to, $subject, $extra . "\r\n" . $body);
        if ($o !== null) {
            return $o;
        }
        $fp = self::smtpOpen();
        if (!$fp) {
            return false;
        }
        $read = function () use ($fp): string {
            $data = '';
            while ($line = fgets($fp, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function (string $line) use ($fp, $read): string {
            fwrite($fp, $line . "\r\n");
            return $read();
        };
        $read();
        $cmd('EHLO ' . self::smtpHelo());
        $cmd('MAIL FROM:<' . DwBrand::supportEmail() . '>');
        $rcpt = $cmd('RCPT TO:<' . $to . '>');
        if (strpos($rcpt, '250') !== 0) {
            $cmd('QUIT');
            fclose($fp);
            return false;
        }
        $cmd('DATA');
        $safeSubject = str_replace(["\r", "\n"], '', $subject);
        $date = gmdate('D, d M Y H:i:s O');
        $messageId = bin2hex(random_bytes(16)) . '@' . DwBrand::domain();
        fwrite($fp, "From: " . DwBrand::mailFrom() . "\r\nTo: <" . $to . ">\r\nSubject: " . $safeSubject . "\r\nDate: " . $date . "\r\nMessage-ID: <" . $messageId . ">\r\n" . $extra . "\r\n" . $body . "\r\n.\r\n");
        $data = $read();
        $cmd('QUIT');
        fclose($fp);
        return strpos($data, '250') === 0;
    }

    // Send a prepared MIME message (headers without From/To/Date/Message-ID, then a blank line, then the body)
    // through the same relay as sendMail. Lines are dot-stuffed. Added 2026-11-04 for magic-link login mail.
    public static function sendMime(string $to, string $subject, string $mime, array $headers = []): bool
    {
        if (preg_match('/[\r\n<>]/', $to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $from = DwBrand::mailFrom();
        $extraHead = '';
        foreach ($headers as $k => $v) {
            if (!is_string($k) || !is_string($v) || preg_match('/[\r\n]/', $k . $v) || !preg_match('/^[A-Za-z0-9-]+$/', $k)) {
                continue;
            }
            if (strcasecmp($k, 'From') === 0) {
                $from = $v;
                continue;
            }
            if (in_array(strtolower($k), ['to', 'subject', 'date', 'message-id'], true)) {
                continue;
            }
            $extraHead .= $k . ': ' . $v . "\r\n";
        }
        $o = self::outbox($to, $subject, $extraHead . $mime, $from);
        if ($o !== null) {
            return $o;
        }
        $fp = self::smtpOpen();
        if (!$fp) {
            return false;
        }
        stream_set_timeout($fp, 15);
        $read = function () use ($fp): string {
            $data = '';
            while ($line = fgets($fp, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function (string $line) use ($fp, $read): string {
            fwrite($fp, $line . "\r\n");
            return $read();
        };
        $read();
        $cmd('EHLO ' . self::smtpHelo());
        $cmd('MAIL FROM:<' . DwBrand::supportEmail() . '>');
        $rcpt = $cmd('RCPT TO:<' . $to . '>');
        if (strpos($rcpt, '250') !== 0) {
            $cmd('QUIT');
            fclose($fp);
            return false;
        }
        $go = $cmd('DATA');
        if (strpos($go, '354') !== 0) {
            $cmd('QUIT');
            fclose($fp);
            return false;
        }
        $safeSubject = str_replace(["\r", "\n"], '', $subject);
        if (preg_match('/[^\x20-\x7e]/', $safeSubject)) {
            $safeSubject = mb_encode_mimeheader($safeSubject, 'UTF-8', 'B', "\r\n");
        }
        $head = "From: " . $from . "\r\nTo: <" . $to . ">\r\nSubject: " . $safeSubject
            . "\r\nDate: " . gmdate('D, d M Y H:i:s O') . "\r\nMessage-ID: <" . bin2hex(random_bytes(16)) . "@" . DwBrand::domain() . ">\r\n" . $extraHead;
        $msg = preg_replace('/\r?\n/', "\r\n", $head . $mime);
        $msg = preg_replace('/^\./m', '..', $msg);
        fwrite($fp, $msg . "\r\n.\r\n");
        $data = $read();
        $cmd('QUIT');
        fclose($fp);
        return strpos($data, '250') === 0;
    }

    public static function session(array $packet = []): ?array
    {
        $id = '';
        if (isset($packet['session']) && is_string($packet['session'])) {
            $id = trim($packet['session']);
        }
        if ($id === '' && isset($_COOKIE['session'])) {
            $id = trim((string)$_COOKIE['session']);
        }
        if ($id === '' || !preg_match('/^[0-9a-f]{50}$/', $id)) {
            return null;
        }
        $q = self::pdo()->prepare('SELECT id, person_id, rights, brand FROM session WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function allows(array $session, string $module, array $rights): bool
    {
        $rows = json_decode((string)($session['rights'] ?? ''), true);
        if (!is_array($rows)) {
            return false;
        }
        foreach ($rows as $row) {
            $right = $row['right_name'] ?? '';
            $mod = $row['module_name'] ?? '';
            if ($right === 'SUPER_ADMIN') {
                return true;
            }
            if ($mod === $module && in_array($right, $rights, true)) {
                return true;
            }
        }
        return false;
    }

    // Rights refresh (2026-11-11, issue #1; from staging 2026-11-04): rebuild session.rights from data_access for every
    // session of this person on this brand (the session table has no revoked flag, so that is all
    // of them) and archive each changed row to session_history as UPDATE, in the pattern
    // session/index.php uses. Call it with the same PDO, inside the transaction that changed
    // data_access, so the grant and the snapshot commit or roll back together. If no transaction
    // is open it opens and commits its own. Returns the number of sessions changed.
    public static function rightsJson(PDO $db, string $personId, string $brand): string
    {
        $q = $db->prepare('SELECT module_name, right_name FROM data_access WHERE person_id = ? AND brand = ?');
        $q->execute([$personId, $brand]);
        return json_encode($q->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES);
    }

    public static function refreshSessionRights(PDO $db, string $personId, string $brand): int
    {
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $sessions = $db->prepare('SELECT id, rights FROM session WHERE person_id = ? AND brand = ? FOR UPDATE');
            $sessions->execute([$personId, $brand]);
            $rows = $sessions->fetchAll(PDO::FETCH_ASSOC);
            $changed = 0;
            if ($rows) {
                $json = self::rightsJson($db, $personId, $brand);
                $want = self::rightsKey($json);
                $upd = $db->prepare('UPDATE session SET rights = ? WHERE id = ? AND person_id = ? AND brand = ?');
                $hist = $db->prepare('INSERT INTO session_history (history_id, id, person_id, rights, created, brand, action, archived_at) SELECT ?, id, person_id, rights, created, brand, ?, NOW() FROM session WHERE id = ?');
                foreach ($rows as $r) {
                    if (self::rightsKey((string)$r['rights']) === $want) {
                        continue;
                    }
                    $upd->execute([$json, $r['id'], $personId, $brand]);
                    $hist->execute([self::randId(), 'UPDATE', $r['id']]);
                    $changed++;
                }
            }
            if ($own) {
                $db->commit();
            }
            return $changed;
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    // Order-independent comparison key for a rights JSON value.
    private static function rightsKey(string $json): string
    {
        $rows = json_decode($json, true);
        $keys = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $keys[] = ($row['module_name'] ?? '') . ':' . ($row['right_name'] ?? '');
        }
        $keys = array_values(array_unique($keys));
        sort($keys);
        return implode(',', $keys);
    }

    // History snapshot (2026-11-11, issue #2): copy the current row of $table (matched on id) into
    // <table>_history with a new history_id, the action, and archived_at = NOW(). Call it with the same PDO
    // inside the open transaction that changes the row, so the write and its history commit or roll back
    // together: after an INSERT, and before an UPDATE or DELETE (the row as it was, as the other modules do).
    // Columns are the base-table columns the history table also has. Returns rows archived (0 = no such row).
    public static function history(PDO $db, string $table, string $id, string $action): int
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,50}$/', $table) || !in_array($action, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            throw new InvalidArgumentException('Dw::history: bad table or action');
        }
        if (!$db->inTransaction()) {
            throw new LogicException('Dw::history must run inside the transaction that changes ' . $table);
        }
        $cols = self::historyColumns($db, $table);
        $list = '`' . implode('`, `', $cols) . '`';
        $q = $db->prepare('INSERT INTO `' . $table . '_history` (history_id, ' . $list . ', action, archived_at) SELECT ?, ' . $list . ', ?, NOW() FROM `' . $table . '` WHERE id = ?');
        $q->execute([self::randId(), $action, $id]);
        return $q->rowCount();
    }

    private static array $historyCols = [];

    private static function historyColumns(PDO $db, string $table): array
    {
        if (isset(self::$historyCols[$table])) {
            return self::$historyCols[$table];
        }
        $q = $db->prepare('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?) ORDER BY ORDINAL_POSITION');
        $q->execute([$table, $table . '_history']);
        $base = [];
        $hist = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['TABLE_NAME'] === $table) {
                $base[] = (string)$r['COLUMN_NAME'];
            } else {
                $hist[(string)$r['COLUMN_NAME']] = true;
            }
        }
        foreach (['history_id', 'id', 'action', 'archived_at'] as $need) {
            if (!isset($hist[$need])) {
                throw new RuntimeException('Dw::history: ' . $table . '_history is missing ' . $need);
            }
        }
        $cols = array_values(array_filter($base, fn($c) => isset($hist[$c]) && !in_array($c, ['history_id', 'action', 'archived_at'], true)));
        if (!in_array('id', $cols, true)) {
            throw new RuntimeException('Dw::history: ' . $table . ' has no id column');
        }
        return self::$historyCols[$table] = $cols;
    }

    // DNS history (2026-11-11, issue #3): crm.dns (MyISAM, Dw::pdoDns()) and dw_production.dns_history
    // (InnoDB, Dw::pdo()) are on different databases, so one transaction cannot cover both. Callers open a
    // transaction on $db, call dnsHistory(), make the crm.dns change, then commit; if the crm.dns change fails
    // they roll back (no orphan history), and if the commit fails they undo the crm.dns change (dnsRestore()).
    // crm.dns uses 0000-00-00 00:00:00 for no timestamp; dw_production is strict, so zero dates are allowed
    // for this insert only.
    public static function dnsHistory(PDO $db, array $row, string $companyId, string $personId, string $action): void
    {
        if (!in_array($action, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            throw new InvalidArgumentException('Dw::dnsHistory: bad action');
        }
        $mode = (string)$db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $relaxed = implode(',', array_filter(explode(',', $mode), fn($m) => $m !== 'NO_ZERO_DATE' && $m !== 'NO_ZERO_IN_DATE'));
        $db->prepare('SET SESSION sql_mode = ?')->execute([$relaxed]);
        try {
            $db->prepare('INSERT INTO dns_history (history_id, rr_id, company_id, person_id, rr_type, rr_fqdn, rr_ip, rr_target, rr_mx_dist, rr_ttl, rr_timestamp, rr_email, rr_serial, rr_refresh, rr_retry, rr_expire, rr_minimum, domain, brand, action, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([
                self::randId(), (int)$row['rr_id'], $companyId, $personId,
                (string)$row['rr_type'], (string)$row['rr_fqdn'], (string)$row['rr_ip'], (string)$row['rr_target'],
                (int)$row['rr_mx_dist'], (int)$row['rr_ttl'], (string)$row['rr_timestamp'], (string)$row['rr_email'],
                (int)$row['rr_serial'], (int)$row['rr_refresh'], (int)$row['rr_retry'], (int)$row['rr_expire'], (int)$row['rr_minimum'],
                (string)$row['domain'], (string)$row['brand'], $action,
            ]);
        } finally {
            $db->prepare('SET SESSION sql_mode = ?')->execute([$mode]);
        }
    }

    // Put a crm.dns row back exactly as it was (same rr_id): undoes an UPDATE or DELETE whose history did not commit.
    public static function dnsRestore(PDO $dns, array $row, string $companyId): void
    {
        $dns->prepare('REPLACE INTO dns (rr_id, rr_type, rr_fqdn, rr_ip, rr_target, rr_mx_dist, rr_ttl, rr_timestamp, rr_email, rr_serial, rr_refresh, rr_retry, rr_expire, rr_minimum, company_id, domain, brand) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            (int)$row['rr_id'], (string)$row['rr_type'], (string)$row['rr_fqdn'], (string)$row['rr_ip'], (string)$row['rr_target'],
            (int)$row['rr_mx_dist'], (int)$row['rr_ttl'], (string)$row['rr_timestamp'], (string)$row['rr_email'],
            (int)$row['rr_serial'], (int)$row['rr_refresh'], (int)$row['rr_retry'], (int)$row['rr_expire'], (int)$row['rr_minimum'],
            (string)($row['company_id'] ?? $companyId), (string)$row['domain'], (string)$row['brand'],
        ]);
    }

    public static function jsonOut(int $code, array $payload): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
