<?php
declare(strict_types=1);
// Customer (end-user) helpers for store domains (2026-11-11, issues #5 #6 #7 #8).
// - The brand comes only from the request Host: domain.domain_name -> domain.code -> brand.code
//   (DwDomain::brandForHost). It is never returned in JSON or shown on a page.
// - Customer sessions: a row in session (rights []) plus a site_session row bound to this exact host,
//   jti starting "cu". The session id travels only in the HttpOnly __Host-dw_cs cookie, never in JSON.
//   Sessions last 30 days from sign-in. Staff /session and the editor __Host-dw_s cookie are untouched.
// - Codes are 6 characters, stored only as keyed hashes, and sent only by email.
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';
require_once '/var/www/html/lib/DwEdit.php';
require_once '/var/www/html/lib/DwSignup.php';
require_once '/var/www/html/lib/DwSignupLog.php';

final class DwCustomer
{
    public const COOKIE = '__Host-dw_cs';
    public const SESSION_DAYS = 30;
    public const PURGE_DAYS = 90;
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function out(int $code, array $payload): void
    {
        header('Cache-Control: no-store');
        Dw::jsonOut($code, $payload);
        exit;
    }

    public static function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
        return $_POST ?: [];
    }

    public static function host(): string
    {
        return DwDomain::requestHost();
    }

    // [brand, host] for a claimed store domain, else null (app host, IPs, unclaimed names).
    public static function brandFromHost(): ?array
    {
        $host = self::host();
        if (DwDomain::isAppHost($host)) {
            return null;
        }
        $brand = DwDomain::brandForHost($host, [Dw::class, 'pdo']);
        return $brand === null ? null : [$brand, $host];
    }

    public static function requireStoreHost(): array
    {
        $r = self::brandFromHost();
        if ($r === null) {
            self::out(404, ['ok' => false, 'error' => 'open this on the store\'s own domain (a domain claimed with /domain/); the store is found from the web address']);
        }
        return $r;
    }

    public static function code(int $len = 6): string
    {
        $a = self::CODE_ALPHABET;
        $s = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= $a[random_int(0, strlen($a) - 1)];
        }
        return $s;
    }

    public static function hash(string ...$parts): string
    {
        return DwEdit::keyedHash('dwcustomer|' . implode('|', $parts));
    }

    public static function email($v): ?string
    {
        $e = strtolower(trim((string)$v));
        if ($e === '' || strlen($e) > 100 || filter_var($e, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return $e;
    }

    // Reserved test addresses (RFC 2606/6761) are never handed to the mail relay.
    public static function isTestAddress(string $email): bool
    {
        $d = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
        return in_array($d, ['example.com', 'example.net', 'example.org'], true)
            || (bool)preg_match('/(^|\.)(test|invalid|example|localhost)$/', $d);
    }

    public static function mail(string $to, string $subject, string $body): bool
    {
        if (self::isTestAddress($to)) {
            return false;
        }
        return Dw::sendMail($to, $subject, $body);
    }

    // Addresses that skip the signup network rule (logged as signup_failure reason network_bypass_test). Exact match only.
    // NETWORK_TEST_EMAILS in DwConfig (comma separated); empty = nobody.
    public static function networkTestEmails(): array
    {
        return array_map('strtolower', DwConfig::list('NETWORK_TEST_EMAILS'));
    }

    // Signup network rule (same lists and settings as /account_setup): US only, no Tor, VPN or datacenter.
    public static function networkBlock(PDO $db, string $module, int $step, ?string $email): void
    {
        $ip = DwSignup::clientIp();
        $risk = DwSignup::ipRisk($ip);
        if ($risk['action'] === 'allow') {
            return;
        }
        // Test bypass (2026-11-13): exactly these addresses skip the network rule so platform staff can run live
        // test orders from any network. Exact match only (case-insensitive), no wildcards, no +tags; every use is logged.
        if ($email !== null && in_array(strtolower(trim($email)), self::networkTestEmails(), true)) {
            DwSignupLog::failure($db, ['reason' => 'network_bypass_test', 'step' => $step, 'http_status' => 200, 'email' => $email, 'kind' => $risk['kind'],
                'error' => 'network ' . $risk['kind'] . ' allowed for the test address', 'detail' => ['module' => $module]]);
            error_log('signup network bypass: ' . $module . ' step ' . $step . ' kind ' . $risk['kind']);
            return;
        }
        $reason = ['tor' => 'blocked_tor', 'vpn' => 'blocked_vpn', 'hosting' => 'blocked_datacenter', 'country' => DwSignup::countryReason()][$risk['kind']] ?? 'blocked_network';
        DwSignupLog::failure($db, ['reason' => $reason, 'step' => $step, 'http_status' => 403, 'email' => $email, 'kind' => $risk['kind'], 'error' => 'network ' . $risk['kind'], 'detail' => ['module' => $module]]);
        self::out(403, ['ok' => false, 'error' => DwSignup::blockMessage()]);
    }

    public static function logFailure(PDO $db, string $module, int $step, string $reason, int $status, ?string $email, string $error): void
    {
        DwSignupLog::failure($db, ['reason' => $reason, 'step' => $step, 'http_status' => $status, 'email' => $email, 'error' => $error, 'detail' => ['module' => $module]]);
    }

    // Signup rate limits, same settings as /account_setup (email per minute, email per hour, IP per hour),
    // counted on end_user_account_setup. null when allowed.
    public static function signupLimit(PDO $db, string $norm, string $ip): ?array
    {
        if (!DwSignup::cfg('enabled') || in_array($ip, (array)DwSignup::cfg('exempt_ips'), true)) {
            return null;
        }
        $count = function (string $col, string $val, int $sec) use ($db): int {
            $q = $db->prepare("SELECT COUNT(*) FROM end_user_account_setup WHERE $col = ? AND created >= NOW() - INTERVAL $sec SECOND");
            $q->execute([$val]);
            return (int)$q->fetchColumn();
        };
        $pm = (int)DwSignup::cfg('email_per_minute');
        if ($pm > 0 && $count('email_normalized', $norm, 60) >= $pm) {
            return ['a code was sent to this email less than a minute ago. Wait a minute and try again', 60, 'email_per_minute'];
        }
        $ph = (int)DwSignup::cfg('email_per_hour');
        if ($ph > 0 && $count('email_normalized', $norm, 3600) >= $ph) {
            return ['too many codes for this email in the last hour. Try again later', 3600, 'email_per_hour'];
        }
        $ih = (int)DwSignup::cfg('ip_per_hour');
        if ($ih > 0 && $ip !== '' && $count('remote_ip', substr($ip, 0, 45), 3600) >= $ih) {
            return ['too many signups from this network in the last hour. Try again later', 3600, 'ip_per_hour'];
        }
        return null;
    }

    // Person on this brand with this email (PRSN), or null.
    public static function personByEmail(PDO $db, string $email, string $brand): ?string
    {
        $q = $db->prepare("SELECT e.owner_id FROM email e JOIN person p ON p.id = e.owner_id AND p.brand = e.brand WHERE e.brand = ? AND e.owner_type = 'PRSN' AND LOWER(e.email) = ? ORDER BY e.created LIMIT 1");
        $q->execute([$brand, strtolower($email)]);
        $id = $q->fetchColumn();
        return is_string($id) && $id !== '' ? $id : null;
    }

    // Signed-in customer on this host: ['session_id', 'person_id', 'brand', 'host'] or null.
    public static function current(PDO $db): ?array
    {
        $sid = (string)($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^[0-9a-f]{50}$/', $sid)) {
            return null;
        }
        $r = self::brandFromHost();
        if ($r === null) {
            return null;
        }
        [$brand, $host] = $r;
        $q = $db->prepare("SELECT s.id, s.person_id, ss.jti FROM site_session ss JOIN session s ON s.id = ss.session_id
            WHERE ss.session_id = ? AND ss.domain = ? AND ss.jti LIKE 'cu%' AND ss.revoked_at IS NULL
              AND ss.created > NOW() - INTERVAL " . self::SESSION_DAYS . " DAY AND s.brand = ? LIMIT 1");
        $q->execute([$sid, $host, $brand]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $db->prepare('UPDATE site_session SET last_seen = NOW() WHERE jti = ?')->execute([$row['jti']]);
        return ['session_id' => (string)$row['id'], 'person_id' => (string)$row['person_id'], 'brand' => $brand, 'host' => $host, 'jti' => (string)$row['jti']];
    }

    // New customer session for this person on this host; sets the cookie. Never returns the id.
    public static function signIn(PDO $db, string $brand, string $host, string $personId): void
    {
        $old = self::current($db);
        $sid = Dw::randId();
        $db->beginTransaction();
        try {
            if ($old !== null) {
                $db->prepare('UPDATE site_session SET revoked_at = NOW() WHERE jti = ?')->execute([$old['jti']]);
            }
            $db->prepare('INSERT INTO session (id, person_id, rights, created, brand) VALUES (?, ?, ?, NOW(), ?)')->execute([$sid, $personId, '[]', $brand]);
            Dw::history($db, 'session', $sid, 'INSERT');
            $db->prepare('INSERT INTO site_session (jti, session_id, domain, created, last_seen) VALUES (?, ?, ?, NOW(), NOW())')
                ->execute(['cu' . bin2hex(random_bytes(15)), $sid, $host]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        setcookie(self::COOKIE, $sid, [
            'expires' => time() + self::SESSION_DAYS * 86400,
            'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    public static function signOut(PDO $db): bool
    {
        $c = self::current($db);
        if ($c !== null) {
            $db->prepare('UPDATE site_session SET revoked_at = NOW() WHERE jti = ?')->execute([$c['jti']]);
        }
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        return $c !== null;
    }

    // Text field: trimmed, max length, null when absent or empty.
    public static function text(array $in, string $key, int $max): ?string
    {
        if (!array_key_exists($key, $in) || $in[$key] === null) {
            return null;
        }
        if (!is_scalar($in[$key])) {
            self::out(400, ['ok' => false, 'error' => $key . ' must be text']);
        }
        $v = trim((string)$in[$key]);
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > $max) {
            self::out(400, ['ok' => false, 'error' => $key . ' is longer than ' . $max . ' characters']);
        }
        return $v;
    }

    // Address fields from input; [values, address_type|null].
    public const ADDRESS_FIELDS = ['line_1' => 100, 'line_2' => 100, 'line_3' => 100, 'city' => 30, 'state' => 30, 'zipcode' => 20, 'country' => 20];

    public static function addressValues(array $in): array
    {
        $v = [];
        foreach (self::ADDRESS_FIELDS as $col => $max) {
            if (array_key_exists($col, $in)) {
                $v[$col] = self::text($in, $col, $max);
            }
        }
        return $v;
    }

    public static function addressType($t): ?string
    {
        $t = strtolower(trim((string)$t));
        return ['billing' => 'Billing', 'shipping' => 'Shipping', 'other' => 'Other'][$t] ?? null;
    }
}
