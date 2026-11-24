<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// STAGING (2026-11-04): "edit link" sign-in for customer domains. See /workspace/dw/editlink/DESIGN.md.
//   customer domain D:
//     GET  /_dw/whoami          -> {"edit":true|false}  (never anything else)
//     GET  /_dw/start?return=/p -> sets __Host-dw_state, 302 to app /_dw/handoff
//     GET  /_dw/login?code=&return=/p -> consumes the 60 s single-use code, sets __Host-dw_s, 302 to /p
//     POST /_dw/logout          -> clears __Host-dw_s and revokes that cookie (same-origin only)
//     GET  /_dw/edit.js         -> loader: shows the edit link only when whoami says edit:true
//   app.dwalliance.com:
//     GET  /_dw/handoff?domain=D&state=H&return=/p -> needs __Host-dw_app; 302 to https://D/_dw/login?code=...
//     POST /_dw/signin (form: code, domain, state, return) -> sets __Host-dw_app, 303 back to handoff
// Codes and cookie records live in the shared DB tables site_login_code and site_session (added 2026-11-04),
// so a code minted on one node can be redeemed on any node. Times use the DB clock (NOW()).
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';

final class DwEdit
{
    const SECRET = '/var/lib/app/data/editlink.secret';
    const C_SITE = '__Host-dw_s';
    const C_APP = '__Host-dw_app';
    const C_STATE = '__Host-dw_state';
    const C_HINT = 'dw_ed';           // "1" only; not a secret, lets the page loader skip whoami for everyone else
    const IDLE = 90 * 86400;          // rolling expiry
    const ROLL_AFTER = 86400;         // re-issue at most once a day
    const ABSOLUTE = 400 * 86400;     // hard cap since first issue
    const CODE_TTL = 60;
    const STATE_TTL = 600;

    private static function secret(): string
    {
        $s = trim((string)@file_get_contents(self::SECRET));
        if (strlen($s) < 32) {
            throw new RuntimeException('editlink not configured');
        }
        return $s;
    }

    private static function b64(string $b): string
    {
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    private static function mac(string $data): string
    {
        return self::b64(hash_hmac('sha256', $data, self::secret(), true));
    }

    // ---- signed cookie: v1.sid.iat.last.jti.mac, MAC binds the host it was issued for ----
    private static function mint(string $host, string $sid, int $iat, string $jti, bool $new = false): string
    {
        if ($new) {
            Dw::pdo()->prepare('INSERT INTO site_session (jti, session_id, domain, created, last_seen) VALUES (?, ?, ?, NOW(), NOW())')
                ->execute([$jti, $sid, $host]);
        } else {
            Dw::pdo()->prepare('UPDATE site_session SET last_seen = NOW() WHERE jti = ?')->execute([$jti]);
        }
        $body = 'v1.' . $sid . '.' . $iat . '.' . time() . '.' . $jti;
        return $body . '.' . self::mac($host . '|' . $body);
    }

    private static function parse(string $host, string $raw): ?array
    {
        $p = explode('.', $raw);
        if (count($p) !== 6 || $p[0] !== 'v1' || !preg_match('/^[0-9a-f]{50}$/', $p[1]) || !ctype_digit($p[2]) || !ctype_digit($p[3]) || !preg_match('/^[0-9a-f]{32}$/', $p[4])) {
            return null;
        }
        $body = implode('.', array_slice($p, 0, 5));
        if (!hash_equals(self::mac($host . '|' . $body), $p[5])) {
            return null;
        }
        $now = time();
        if ($now - (int)$p[3] > self::IDLE || $now - (int)$p[2] > self::ABSOLUTE) {
            return null;
        }
        // Must be a cookie we issued for this session and host, and not revoked (logout / sign out everywhere).
        $q = Dw::pdo()->prepare('SELECT 1 FROM site_session WHERE jti = ? AND session_id = ? AND domain = ? AND revoked_at IS NULL');
        $q->execute([$p[4], $p[1], $host]);
        if (!$q->fetchColumn()) {
            return null;
        }
        return ['sid' => $p[1], 'iat' => (int)$p[2], 'last' => (int)$p[3], 'jti' => $p[4]];
    }

    private static function setCookie(string $name, string $value, int $maxAge, bool $httpOnly = true): void
    {
        setcookie($name, $value, [
            'expires' => $maxAge > 0 ? time() + $maxAge : time() - 3600,
            'path' => '/', 'secure' => true, 'httponly' => $httpOnly, 'samesite' => 'Lax',
        ]);
    }

    private static function sessionRow(string $sid): ?array
    {
        $q = Dw::pdo()->prepare('SELECT id, person_id, rights, brand FROM session WHERE id = ?');
        $q->execute([$sid]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    // Live rights check (not the snapshot in session.rights): SUPER_ADMIN of $brand (all domains),
    // or a domain_editor grant for this host. $host optional for callers that only have brand context;
    // without $host, only SUPER_ADMIN qualifies.
    public static function isEditor(array $s, string $brand, ?string $host = null): bool
    {
        if (!hash_equals((string)$s['brand'], $brand)) {
            return false;
        }
        $q = Dw::pdo()->prepare('SELECT module_name, right_name FROM data_access WHERE person_id = ? AND brand = ?');
        $q->execute([$s['person_id'], $brand]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['right_name'] === 'SUPER_ADMIN') {
                return true;
            }
        }
        if ($host === null || $host === '') {
            return false;
        }
        require_once '/var/www/html/lib/DwPermissions.php';
        return DwPermissions::isDomainEditor((string)$s['person_id'], $brand, $host);
    }

    // Session behind this domain's cookie, rolling it forward when due. Used by whoami and by /markup.
    public static function siteSession(string $host): ?array
    {
        $c = self::parse($host, (string)($_COOKIE[self::C_SITE] ?? ''));
        if ($c === null) {
            return null;
        }
        $s = self::sessionRow($c['sid']);
        if ($s === null) {
            return null;
        }
        if (time() - $c['last'] > self::ROLL_AFTER && !headers_sent()) {
            self::setCookie(self::C_SITE, self::mint($host, $c['sid'], $c['iat'], $c['jti']), self::IDLE);
        }
        return $s;
    }

    private static function json(array $p): void
    {
        header('Cache-Control: no-store');
        header('Vary: Cookie');
        header('X-Content-Type-Options: nosniff');
        Dw::jsonOut(200, $p);
        exit;
    }

    private static function deny(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $msg . "\n";
        exit;
    }

    private static function redirect(string $url, int $code = 302): void
    {
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header('Location: ' . $url, true, $code);
        exit;
    }

    // Only a local path: "/x", never "//x", "/\x", or anything with a scheme or control characters.
    private static function safeReturn(string $r): string
    {
        return ($r !== '' && $r[0] === '/' && !preg_match('#^/[/\\\\]|[\x00-\x1f\x7f\\\\]#', $r) && strlen($r) < 2000) ? $r : '/';
    }

    private static function sameOrigin(string $host): bool
    {
        $src = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($src === '' || $src === 'null') {
            $src = (string)($_SERVER['HTTP_REFERER'] ?? '');
        }
        $h = parse_url($src, PHP_URL_HOST);
        return is_string($h) && hash_equals($host, strtolower($h));
    }

    // ---- Magic-link login (staging 2026-11-04, lib/DwLogin.php) uses the same cookie and site_session rows ----
    // Keyed hash with the edit-link secret, for values that must not be stored in the clear.
    public static function keyedHash(string $data): string
    {
        return hash_hmac('sha256', $data, self::secret());
    }

    // The valid, unrevoked __Host-dw_s cookie on this host, or null.
    public static function currentSiteCookie(string $host): ?array
    {
        return self::parse($host, (string)($_COOKIE[self::C_SITE] ?? ''));
    }

    // Issue a fresh __Host-dw_s for a session on this host (new site_session row), plus the dw_ed hint
    // only when the person is an editor of the brand. Any cookie already on this host is revoked first.
    public static function issueSiteCookie(string $host, string $brand, string $sid): void
    {
        $old = self::currentSiteCookie($host);
        if ($old !== null) {
            Dw::pdo()->prepare('UPDATE site_session SET revoked_at = NOW() WHERE jti = ?')->execute([$old['jti']]);
        }
        self::setCookie(self::C_SITE, self::mint($host, $sid, time(), bin2hex(random_bytes(16)), true), self::IDLE);
        $s = self::sessionRow($sid);
        if ($s !== null && self::isEditor($s, $brand, $host)) {
            self::setCookie(self::C_HINT, '1', self::IDLE, false);
        } else {
            self::setCookie(self::C_HINT, '', -1, false);
        }
    }

    public static function clearSiteCookies(): void
    {
        self::setCookie(self::C_SITE, '', -1);
        self::setCookie(self::C_HINT, '', -1, false);
    }

    // ------------------------------------------------------------------ routes
    public static function route(array $parts, string $host): void
    {
        // nginx reaches 404.php through try_files without the query string, so read it from REQUEST_URI.
        parse_str((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $q);
        $_GET = $q;
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $op = $parts[1] ?? '';
        if (count($parts) !== 2) {
            self::deny(404, 'not found');
        }
        try {
            if (DwDomain::isAppHost($host)) {
                if ($host !== DwBrand::appHost()) {
                    self::deny(404, 'not found');
                }
                if ($op === 'handoff' && $m === 'GET') {
                    self::handoff();
                }
                if ($op === 'signin' && $m === 'POST') {
                    self::appSignin();
                }
                self::deny(404, 'not found');
            }
            $brand = DwDomain::brandForHost($host, [Dw::class, 'pdo']);
            if ($brand === null) {
                self::deny(404, 'not found');
            }
            if ($op === 'whoami' && $m === 'GET') {
                $s = self::siteSession($host);
                self::json(['edit' => $s !== null && self::isEditor($s, $brand, $host)]);
            }
            if ($op === 'edit.js' && $m === 'GET') {
                header('Content-Type: text/javascript; charset=utf-8');
                header('Cache-Control: public, max-age=300');
                readfile('/var/www/html/lib/dw_edit.js');
                exit;
            }
            if ($op === 'start' && $m === 'GET') {
                $state = bin2hex(random_bytes(32));
                self::setCookie(self::C_STATE, $state, self::STATE_TTL);
                $ret = self::safeReturn((string)($_GET['return'] ?? '/'));
                self::redirect('https://' . DwBrand::appHost() . '/_dw/handoff?' . http_build_query(['domain' => $host, 'state' => hash('sha256', $state), 'return' => $ret]));
            }
            if ($op === 'login' && $m === 'GET') {
                self::siteLogin($host, $brand);
            }
            if ($op === 'logout' && $m === 'POST') {
                if (!self::sameOrigin($host)) {
                    self::deny(403, 'cross-site request refused');
                }
                $c = self::parse($host, (string)($_COOKIE[self::C_SITE] ?? ''));
                if ($c !== null) {
                    Dw::pdo()->prepare('UPDATE site_session SET revoked_at = NOW() WHERE jti = ?')->execute([$c['jti']]);
                }
                self::setCookie(self::C_SITE, '', -1);
                self::setCookie(self::C_HINT, '', -1, false);
                self::json(['edit' => false]);
            }
            self::deny(404, 'not found');
        } catch (Throwable $e) {
            error_log('dw_edit: ' . $e->getMessage());
            self::deny(503, 'unavailable');
        }
    }

    private static function handoff(): void
    {
        $domain = strtolower((string)($_GET['domain'] ?? ''));
        $state = (string)($_GET['state'] ?? '');
        $ret = self::safeReturn((string)($_GET['return'] ?? '/'));
        // Open-redirect guard: only claimed customer domains, never the app host, and a state from /_dw/start.
        $brand = (DwDomain::validHost($domain) && !DwDomain::isAppHost($domain)) ? DwDomain::brandForHost($domain, [Dw::class, 'pdo']) : null;
        if ($brand === null || !preg_match('/^[0-9a-f]{64}$/', $state)) {
            self::deny(400, 'unknown site');
        }
        $c = self::parse(DwBrand::appHost(), (string)($_COOKIE[self::C_APP] ?? ''));
        $s = $c ? self::sessionRow($c['sid']) : null;
        if ($s === null || !self::isEditor($s, $brand, $domain)) {
            self::signinPage($domain, $state, $ret, $s !== null);
        }
        $code = bin2hex(random_bytes(32));
        $db = Dw::pdo();
        $db->exec('DELETE FROM site_login_code WHERE expires < NOW() - INTERVAL 1 DAY');   // sweep old codes
        // Only the sha256 of the code is stored; the code itself exists only in this redirect.
        $db->prepare('INSERT INTO site_login_code (code_hash, session_id, domain, state_hash, expires) VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . (int)self::CODE_TTL . ' SECOND)')
            ->execute([hash('sha256', $code), $s['id'], $domain, $state]);
        self::redirect('https://' . $domain . '/_dw/login?' . http_build_query(['code' => $code, 'return' => $ret]));
    }

    private static function siteLogin(string $host, string $brand): void
    {
        $code = (string)($_GET['code'] ?? '');
        $ret = self::safeReturn((string)($_GET['return'] ?? '/'));
        $state = (string)($_COOKIE[self::C_STATE] ?? '');
        $bad = function (): void {
            usleep(300000);
            DwEdit::denyPublic(400, 'This sign-in link has expired or was already used. Go back and try again.');
        };
        if (!preg_match('/^[0-9a-f]{64}$/', $code)) {
            $bad();
        }
        $db = Dw::pdo();
        $h = hash('sha256', $code);
        // Atomic single use across all nodes: only one UPDATE can flip used_at, and only before it expires.
        $u = $db->prepare('UPDATE site_login_code SET used_at = NOW() WHERE code_hash = ? AND used_at IS NULL AND expires > NOW()');
        $u->execute([$h]);
        if ($u->rowCount() !== 1) {
            $bad();
        }
        $q = $db->prepare('SELECT session_id, domain, state_hash FROM site_login_code WHERE code_hash = ?');
        $q->execute([$h]);
        $rec = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rec) || !hash_equals((string)$rec['domain'], $host)
            || $state === '' || !hash_equals((string)$rec['state_hash'], hash('sha256', $state))) {
            $bad();
        }
        $s = self::sessionRow((string)$rec['session_id']);
        if ($s === null || !self::isEditor($s, $brand, $host)) {
            $bad();
        }
        self::setCookie(self::C_STATE, '', -1);
        self::setCookie(self::C_SITE, self::mint($host, $s['id'], time(), bin2hex(random_bytes(16)), true), self::IDLE);
        self::setCookie(self::C_HINT, '1', self::IDLE, false);
        self::redirect($ret);
    }

    public static function denyPublic(int $code, string $msg): void
    {
        self::deny($code, $msg);
    }

    private static function appSignin(): void
    {
        if (!self::sameOrigin(DwBrand::appHost())) {
            self::deny(403, 'cross-site request refused');
        }
        $code = trim((string)($_POST['code'] ?? ''));
        $q = ['domain' => (string)($_POST['domain'] ?? ''), 'state' => (string)($_POST['state'] ?? ''), 'return' => self::safeReturn((string)($_POST['return'] ?? '/'))];
        $s = preg_match('/^[0-9a-f]{50}$/', $code) ? self::sessionRow($code) : null;
        if ($s === null) {
            usleep(400000);
            self::deny(403, 'that code is not valid');
        }
        self::setCookie(self::C_APP, self::mint(DwBrand::appHost(), $s['id'], time(), bin2hex(random_bytes(16)), true), self::IDLE);
        self::redirect('/_dw/handoff?' . http_build_query($q), 303);
    }

    private static function signinPage(string $domain, string $state, string $ret, bool $signedInElsewhere): void
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Sign in to edit</title></head><body style="font-family:system-ui;max-width:520px;margin:10vh auto;padding:0 1rem">'
            . '<h1>Sign in to edit ' . $h($domain) . '</h1>'
            . ($signedInElsewhere ? '<p>You are signed in, but not as an admin of this site.</p>' : '')
            . '<form method="post" action="/_dw/signin"><input type="hidden" name="domain" value="' . $h($domain) . '"><input type="hidden" name="state" value="' . $h($state) . '"><input type="hidden" name="return" value="' . $h($ret) . '">'
            . '<label>Sign-in code from your email<br><input name="code" autocomplete="one-time-code" size="52" required></label> <button>Continue</button></form></body></html>';
        exit;
    }
}
