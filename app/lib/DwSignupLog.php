<?php
declare(strict_types=1);
// Signup failure log (approved 2026-11-04): every failed /account_setup outcome, from
// every node, becomes one row in dw_production.signup_failure (docs/sql/003_signup_failure.sql),
// so failures can be analysed in one place. Signup alert mail now goes out only when a brand is
// created; everything else is logged here instead.
// Rules: logging never breaks or blocks signup (all errors go to error_log), and a row never
// holds a confirmation code, brand code, session id or password.
final class DwSignupLog
{
    // Written at deploy time outside the web root, e.g. the node name and address.
    private const NODE_FILE = '/var/lib/app/data/trust/node';
    private static ?string $node = null;

    public static function node(): string
    {
        if (self::$node === null) {
            $raw = @file_get_contents(self::NODE_FILE);
            $n = is_string($raw) ? trim(preg_replace('/[^\w .:\/-]+/', '', $raw) ?? '') : '';
            self::$node = mb_substr($n !== '' ? $n : ('container ' . (string)gethostname()), 0, 64);
        }
        return self::$node;
    }

    private static function cut($v, int $n): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $n);
    }

    // ip_category: tor | vpn | datacenter | non_us | none ; ip_country: allowed country code(s), "non-US"
    // (not in the allowed list; the country DB keeps only allowed ranges), "LAN", or null when unknown.
    public static function classify(string $ip, ?string $kind = null): array
    {
        $cat = 'none';
        $country = null;
        try {
            $plain = preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) ? $m[1] : $ip;
            if ($plain === '' || !filter_var($plain, FILTER_VALIDATE_IP)) {
                return [$cat, null];
            }
            if (DwSignup::internal($plain) || !filter_var($plain, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return [$cat, 'LAN'];
            }
            if ($kind === null) {
                $kind = DwSignup::ipRisk($plain)['kind'];
            }
            $cat = ['tor' => 'tor', 'vpn' => 'vpn', 'hosting' => 'datacenter', 'country' => 'non_us'][$kind] ?? 'none';
            $in = DwSignup::inCountry($plain);
            $allowed = implode(',', array_map('strtoupper', (array)DwSignup::cfg('countries')));
            $country = $in === null ? null : ($in ? $allowed : ($allowed === 'US' ? 'non-US' : 'other'));
        } catch (Throwable $e) {
            error_log('DwSignupLog: classify failed: ' . get_class($e));
        }
        return [$cat, $country];
    }

    // $f: reason, step, http_status, email, account_setup_id, kind (ipRisk kind if known), detail (array).
    public static function failure(?PDO $db, array $f): void
    {
        try {
            $ip = DwSignup::clientIp();
            [$cat, $country] = self::classify($ip, $f['kind'] ?? null);
            $email = self::cut($f['email'] ?? null, 255);
            $detail = array_filter([
                'error' => self::cut($f['error'] ?? null, 300),
                'remote_addr' => self::cut($_SERVER['REMOTE_ADDR'] ?? null, 64),
                'x_forwarded_for' => self::cut($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null, 200),
                'host' => self::cut($_SERVER['HTTP_HOST'] ?? null, 120),
                'origin' => self::cut($_SERVER['HTTP_ORIGIN'] ?? null, 200),
                'referer' => self::cut($_SERVER['HTTP_REFERER'] ?? null, 200),
            ] + (array)($f['detail'] ?? []), fn($v) => $v !== null && $v !== '');
            // Never log codes, ids of sessions, brand codes or passwords, whatever the caller passed.
            foreach (array_keys($detail) as $k) {
                if (preg_match('/confirm|code|session|brand|pass|token|secret/i', (string)$k) && $k !== 'error') {
                    unset($detail[$k]);
                }
            }
            $db = $db ?? Dw::pdo();
            $sid = self::cut($f['account_setup_id'] ?? null, 50);
            if ($email === null && $sid !== null) {
                // Step 2 before the row was read (dead id, cancelled, bad input): take the email from the row if it still exists.
                $q = $db->prepare('SELECT email FROM account_setup WHERE id = ?');
                $q->execute([$sid]);
                $email = self::cut($q->fetchColumn() ?: null, 255);
            }
            $db->prepare('INSERT INTO signup_failure (node, client_ip, ip_country, ip_category, email_normalized, email, step, reason, http_status, user_agent, account_setup_id, detail) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                self::node(),
                mb_substr($ip, 0, 45),
                $country,
                $cat,
                $email !== null ? mb_substr(DwSignup::normalizeEmail($email), 0, 255) : null,
                $email,
                (int)($f['step'] ?? 0),
                mb_substr((string)($f['reason'] ?? 'unknown'), 0, 40),
                (int)($f['http_status'] ?? http_response_code()),
                self::cut($_SERVER['HTTP_USER_AGENT'] ?? null, 255),
                $sid,
                $detail ? json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            ]);
        } catch (Throwable $e) {
            error_log('DwSignupLog: could not log signup failure ' . (string)($f['reason'] ?? '?') . ' (' . get_class($e) . ')');
        }
    }

    // spendTry() said no: was the id unknown, past 30 minutes, or cancelled by the wrong-code cap?
    public static function deadIdReason(PDO $db, string $id): string
    {
        try {
            $q = $db->prepare('SELECT 1 FROM account_setup WHERE id = ?');
            $q->execute([$id]);
            if (!$q->fetchColumn()) {
                return 'unknown_id';
            }
            $q = $db->prepare("SELECT COUNT(*) FROM signup_failure WHERE account_setup_id = ? AND reason = 'wrong_code'");
            $q->execute([$id]);
            return (int)$q->fetchColumn() >= (int)DwSignup::cfg('wrong_code_max') ? 'cancelled_after_cap' : 'code_expired';
        } catch (Throwable $e) {
            return 'code_expired';
        }
    }
}
