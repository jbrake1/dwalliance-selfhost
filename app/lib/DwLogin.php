<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// STAGING (2026-11-04): email magic-link login, routed from 404.php on any claimed customer domain.
//   POST /session/login   email (+ domain when called on app.dwalliance.com)  -> always the same generic
//                         answer; if the email belongs to a person with LOGIN rights (or SUPER_ADMIN) on
//                         the host's brand, a single-use link is emailed. Rate limited (429 + Retry-After).
//   GET  /session/login?t=TOKEN -> checks the token (unused, unexpired, issued for this host), burns it,
//                         creates a fresh `session` row (same as /session/), sets the edit-link cookie
//                         __Host-dw_s (HttpOnly, Secure, SameSite=Lax, host-only, 90 d rolling, 400 d cap)
//                         and 303s to "/" so the token is gone from the address bar.
//   GET  /session/login   -> small sign-in form.
//   POST /session/logout  -> same-origin only; deletes the server-side session, revokes its cookies.
//   GET  /session/logout  -> confirm page whose button POSTs (no state change on GET).
// Storage: no new tables. Tokens are rows in site_login_code (sha256 of the token only), the cookie is a
// site_session row, exactly as the edit link uses them. Magic-link rows are told apart from edit-link
// codes by state_hash = HMAC(editlink.secret, "ml-email|" + normalized email); edit-link rows hold
// sha256(state cookie). Rate-limit counters are extra unredeemable rows (session_id = '').
// The token, session id and brand code never appear in any HTTP response body or JSON.
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';
require_once '/var/www/html/lib/DwEdit.php';
require_once '/var/www/html/lib/DwSignup.php';

final class DwLogin
{
    const TTL = 900;                              // 15 minutes
    const CONFIG = '/var/lib/app/data/magiclink.json';
    const TEMPLATES = '/var/lib/app/data/email';
    const LOGO_URL = 'https://dwalliance.com/assets/email/dw-logo.png'; // in templates; DwBrand::t() maps it to LOGO_URL config
    const GENERIC = 'If that email can sign in to this site, a sign-in link is on its way. It works once and expires in 15 minutes.';
    private const DEFAULTS = [
        'enabled' => true,
        'email_per_minute' => 1, 'email_per_hour' => 3, 'ip_per_hour' => 5,   // same as signup (DwSignup)
        'network_block' => false,                 // true = also refuse Tor/VPN/non-US (DwSignup::ipRisk) at login
        'timezone' => 'Pacific/Honolulu',
        'mail' => 'smtp',                         // smtp | sink
        'allow_to' => ['*'],                      // recipients that may get real mail; others go to the sink only
        'sink_dir' => '/var/lib/app/data/magiclink-outbox',
        'logo' => 'url',                          // url (LOGO_URL) | cid (embedded, for staging)
        'link_base' => [],
        'log_file' => '/var/lib/app/data/magiclink.log',                        // {"dwalliance.com": "http://dwalliance.localhost:8081"}; default https://<host>
    ];
    private static ?array $conf = null;

    private static function cfg(string $k)
    {
        if (self::$conf === null) {
            $j = json_decode((string)@file_get_contents(self::CONFIG), true);
            self::$conf = is_array($j) ? $j : [];
        }
        return array_key_exists($k, self::$conf) ? self::$conf[$k] : (self::DEFAULTS[$k] ?? null);
    }

    // ------------------------------------------------------------------ routing
    // $host is the canonical host (staging alias already applied), $rawHost the Host header as sent.
    public static function route(array $parts, string $host, string $rawHost): void
    {
        parse_str((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $q);
        $_GET = $q;
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $op = $parts[1] ?? '';
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        try {
            if (!self::cfg('enabled') || count($parts) !== 2) {
                self::page(404, 'Not found', '<p>Not found.</p>');
            }
            if ($op === 'login' && $m === 'POST') {
                self::request($host);
            }
            if ($op === 'login' && $m === 'GET') {
                if (isset($_GET['t'])) {
                    self::redeem($host);
                }
                self::form($host);
            }
            if ($op === 'logout' && $m === 'POST') {
                self::logout($host, $rawHost);
            }
            if ($op === 'logout' && $m === 'GET') {
                header('Referrer-Policy: same-origin', true);   // so the button's POST carries a real Origin, not "null"
                self::page(200, 'Sign out', '<h1>Sign out</h1><form method="post" action="/session/logout/"><button>Sign out of this site</button></form>');
            }
            self::page(404, 'Not found', '<p>Not found.</p>');
        } catch (Throwable $e) {
            error_log('dw_login: ' . get_class($e) . ' ' . $e->getMessage());
            self::page(503, 'Unavailable', '<p>Sign-in is unavailable right now. Please try again later.</p>');
        }
    }

    // ------------------------------------------------------------------ POST /session/login
    private static function request(string $host): void
    {
        $raw = (string)file_get_contents('php://input');
        $json = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false;
        $in = $json ? json_decode($raw, true) : $_POST;
        $in = is_array($in) ? $in : [];
        $email = trim((string)($in['email'] ?? ''));
        $site = $host;
        if (DwDomain::isAppHost($host)) {
            $site = strtolower(trim((string)($in['domain'] ?? '')));      // app host: which site to sign in to
            if (!DwDomain::validHost($site) || DwDomain::isAppHost($site)) {
                self::answer($json, 400, ['ok' => false, 'error' => 'domain (the site to sign in to) is required']);
            }
        }
        if ($email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::answer($json, 400, ['ok' => false, 'error' => 'a valid email is required']);
        }
        $brand = DwDomain::brandForHost($site, [Dw::class, 'pdo']);
        if ($brand === null) {
            self::answer($json, 404, ['ok' => false, 'error' => 'unknown site']);
        }
        $ip = DwSignup::clientIp();
        $norm = DwSignup::normalizeEmail($email);
        $db = Dw::pdo();
        if (self::cfg('network_block')) {
            $risk = DwSignup::ipRisk($ip);
            if ($risk['action'] !== 'allow') {
                self::log('network block ' . $risk['kind'] . ' ip=' . $ip);
                self::answer($json, 403, ['ok' => false, 'error' => DwBrand::t('Sign-in from your network or location is not accepted. If you think this is a mistake, email support@dwalliance.com.')]);
            }
        }
        $emailKey = DwEdit::keyedHash('ml-email|' . $norm);
        $ipKey = DwEdit::keyedHash('ml-ip|' . $ip);
        $limit = self::limited($db, $emailKey, $ipKey);
        if ($limit !== null) {
            self::log('rate limit ' . $limit[0] . ' ip=' . $ip . ' host=' . $site);
            header('Retry-After: ' . $limit[1]);
            self::answer($json, 429, ['ok' => false, 'error' => 'Too many sign-in requests, try again in a few minutes.', 'retry_after' => $limit[1]]);
        }
        $db->exec('DELETE FROM site_login_code WHERE expires < NOW() - INTERVAL 1 DAY');
        $person = self::loginPerson($db, $email, $brand, $site);
        $token = bin2hex(random_bytes(32));
        $ins = $db->prepare('INSERT INTO site_login_code (code_hash, session_id, domain, state_hash, expires) VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . self::TTL . ' SECOND)');
        // Token row (known person) or a counter row with no person (unknown email): same count either way.
        $ins->execute([hash('sha256', $token), $person ?? '', $site, $emailKey]);
        $ins->execute([hash('sha256', random_bytes(32)), '', $site, $ipKey]);
        self::log(($person !== null ? 'link requested' : 'no LOGIN account for that email (no mail)') . ' host=' . $site . ' ip=' . $ip);
        // Same answer, same timing, whether or not the account exists: the mail goes out after the response.
        $payload = ['ok' => true, 'message' => self::GENERIC];
        if ($json) {
            Dw::jsonOut(200, $payload);
        } else {
            self::page(200, 'Check your email', '<h1>Check your email</h1><p>' . self::h(self::GENERIC) . '</p>', false);
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if ($person !== null) {
            try {
                self::mail($db, $email, $brand, $site, $token, $ip);
            } catch (Throwable $e) {
                self::log('mail failed ' . get_class($e) . ' ' . $e->getMessage());
            }
        }
        exit;
    }

    // null when allowed, else [limit name, retry-after seconds]. Counted from site_login_code, so all nodes share it.
    private static function limited(PDO $db, string $emailKey, string $ipKey): ?array
    {
        $count = function (string $key, int $window) use ($db): int {
            $q = $db->prepare('SELECT COUNT(*) FROM site_login_code WHERE state_hash = ? AND expires > NOW() + INTERVAL ' . (self::TTL - $window) . ' SECOND');
            $q->execute([$key]);
            return (int)$q->fetchColumn();
        };
        $pm = (int)self::cfg('email_per_minute');
        if ($pm > 0 && $count($emailKey, 60) >= $pm) {
            return ['email_per_minute', 60];
        }
        $ph = (int)self::cfg('email_per_hour');
        if ($ph > 0 && $count($emailKey, 3600) >= $ph) {
            return ['email_per_hour', 3600];
        }
        $ih = (int)self::cfg('ip_per_hour');
        if ($ih > 0 && $count($ipKey, 3600) >= $ih) {
            return ['ip_per_hour', 3600];
        }
        return null;
    }

    // Person id when this email belongs to a person with LOGIN rights (any) or SUPER_ADMIN on the brand,
    // or a domain_editor grant for this host (per-domain EDITOR, staging 2026-11-04).
    private static function loginPerson(PDO $db, string $email, string $brand, string $host = ''): ?string
    {
        $q = $db->prepare("SELECT p.id FROM email e JOIN person p ON p.id = e.owner_id
            WHERE e.email = ? AND e.owner_type = 'PRSN' AND e.brand = ?
              AND EXISTS (SELECT 1 FROM data_access d WHERE d.person_id = p.id AND d.brand = e.brand
                          AND (d.module_name = 'LOGIN' OR d.right_name = 'SUPER_ADMIN'))
            ORDER BY e.created LIMIT 1");
        $q->execute([$email, $brand]);
        $id = $q->fetchColumn();
        if (is_string($id) && $id !== '') {
            return $id;
        }
        if ($host === '' || DwDomain::isAppHost($host)) {
            return null;
        }
        require_once '/var/www/html/lib/DwPermissions.php';
        return DwPermissions::domainEditorPerson($db, $email, $brand, $host);
    }

    // ------------------------------------------------------------------ GET /session/login?t=
    private static function redeem(string $host): void
    {
        $t = (string)($_GET['t'] ?? '');
        $bad = function (): void {
            usleep(300000);
            DwLogin::pageOut(400, 'Link expired', '<h1>This sign-in link has expired or was already used</h1><p><a href="/session/login/">Get a new link</a></p>');
        };
        $brand = DwDomain::isAppHost($host) ? null : DwDomain::brandForHost($host, [Dw::class, 'pdo']);
        if ($brand === null || !preg_match('/^[0-9a-f]{64}$/', $t)) {
            $bad();
        }
        $db = Dw::pdo();
        $h = hash('sha256', $t);
        $q = $db->prepare("SELECT session_id, state_hash FROM site_login_code WHERE code_hash = ? AND domain = ? AND session_id <> '' AND used_at IS NULL AND expires > NOW()");
        $q->execute([$h, $host]);
        $rec = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rec)) {
            self::log('bad or used token host=' . $host . ' ip=' . DwSignup::clientIp());
            $bad();
        }
        $person = (string)$rec['session_id'];
        // It must be a magic-link row for a person who can still sign in here (not an edit-link code).
        $e = $db->prepare("SELECT e.email FROM email e WHERE e.owner_id = ? AND e.owner_type = 'PRSN' AND e.brand = ?");
        $e->execute([$person, $brand]);
        $match = false;
        foreach ($e->fetchAll(PDO::FETCH_COLUMN) as $addr) {
            if (hash_equals((string)$rec['state_hash'], DwEdit::keyedHash('ml-email|' . DwSignup::normalizeEmail((string)$addr)))) {
                $match = true;
            }
        }
        $r = $db->prepare("SELECT 1 FROM data_access WHERE person_id = ? AND brand = ? AND (module_name = 'LOGIN' OR right_name = 'SUPER_ADMIN') LIMIT 1");
        $r->execute([$person, $brand]);
        $okRights = (bool)$r->fetchColumn();
        if (!$okRights) {
            require_once '/var/www/html/lib/DwPermissions.php';
            $okRights = DwPermissions::isDomainEditor($person, $brand, $host);
        }
        if (!$match || !$okRights) {
            $bad();
        }
        // Burn it: only one request on any node can flip used_at.
        $u = $db->prepare('UPDATE site_login_code SET used_at = NOW() WHERE code_hash = ? AND domain = ? AND used_at IS NULL AND expires > NOW()');
        $u->execute([$h, $host]);
        if ($u->rowCount() !== 1) {
            $bad();
        }
        // Fresh session, exactly like /session/ (rights snapshot + history), but the id is never shown.
        $sid = Dw::randId();
        $rights = Dw::rightsJson($db, $person, $brand);
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO session (id, person_id, rights, created, brand) VALUES (?, ?, ?, NOW(), ?)')->execute([$sid, $person, $rights, $brand]);
            $db->prepare("INSERT INTO session_history (history_id, id, person_id, rights, created, brand, action, archived_at) VALUES (?, ?, ?, ?, NOW(), ?, 'INSERT', NOW())")
                ->execute([Dw::randId(), $sid, $person, $rights, $brand]);
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            throw $ex;
        }
        DwEdit::issueSiteCookie($host, $brand, $sid);
        header('Location: /', true, 303);
        exit;
    }

    // ------------------------------------------------------------------ POST /session/logout
    private static function logout(string $host, string $rawHost): void
    {
        // Same-origin only: the browser's Sec-Fetch-Site when sent, else the Origin (or Referer) host.
        $sfs = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
        $src = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($src === '' || $src === 'null') {
            $src = (string)($_SERVER['HTTP_REFERER'] ?? '');
        }
        $oh = parse_url($src, PHP_URL_HOST);
        $ok = $sfs !== '' ? $sfs === 'same-origin' : (is_string($oh) && hash_equals($rawHost, strtolower($oh)));
        if (!$ok) {
            self::page(403, 'Refused', '<p>Cross-site request refused.</p>');
        }
        $c = DwEdit::currentSiteCookie($host);
        if ($c !== null) {
            $db = Dw::pdo();
            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO session_history (history_id, id, person_id, rights, created, brand, action, archived_at) SELECT ?, id, person_id, rights, created, brand, 'DELETE', NOW() FROM session WHERE id = ?")
                    ->execute([Dw::randId(), $c['sid']]);
                $db->prepare('DELETE FROM session WHERE id = ?')->execute([$c['sid']]);
                $db->prepare('UPDATE site_session SET revoked_at = NOW() WHERE session_id = ? AND revoked_at IS NULL')->execute([$c['sid']]);
                $db->commit();
            } catch (Throwable $ex) {
                $db->rollBack();
                throw $ex;
            }
        }
        DwEdit::clearSiteCookies();
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false) {
            Dw::jsonOut(200, ['ok' => true, 'signed_in' => false]);
            exit;
        }
        header('Location: /', true, 303);
        exit;
    }

    // ------------------------------------------------------------------ mail
    private static function mail(PDO $db, string $to, string $brand, string $site, string $token, string $ip): void
    {
        $base = (array)self::cfg('link_base');
        $root = isset($base[$site]) && is_string($base[$site]) ? rtrim($base[$site], '/') : 'https://' . $site;
        $loginUrl = $root . '/session/login/?t=' . $token;
        $n = $db->prepare('SELECT c.company_name FROM brand b JOIN company c ON c.id = b.dw_company_id WHERE b.code = ?');
        $n->execute([$brand]);
        $brandName = trim((string)$n->fetchColumn());
        $brandName = $brandName !== '' ? $brandName : $site;
        try {
            $tz = new DateTimeZone((string)self::cfg('timezone'));
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Pacific/Honolulu');
        }
        $vars = [
            'brand_name' => $brandName,
            'site_url' => 'https://' . $site,
            'login_url' => $loginUrl,
            'requested_at' => (new DateTimeImmutable('now', $tz))->format('M j, Y, g:i A T'),
            'request_ip' => $ip,
        ];
        $html = DwBrand::t((string)@file_get_contents(self::TEMPLATES . '/magic_link_login.html'));
        $text = DwBrand::t((string)@file_get_contents(self::TEMPLATES . '/magic_link_login.txt'));
        if ($html === '' || $text === '') {
            throw new RuntimeException('magic-link templates missing');
        }
        $fill = function (string $tpl, callable $esc) use ($vars): string {
            return (string)preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => array_key_exists($m[1], $vars) ? $esc((string)$vars[$m[1]]) : '', $tpl);
        };
        $html = $fill($html, fn ($v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $text = $fill($text, fn ($v) => (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v));
        $subject = 'Your sign-in link for ' . preg_replace('/[\x00-\x1F\x7F]+/', ' ', $brandName);
        $cid = (string)self::cfg('logo') === 'cid';
        $logo = $cid ? (string)@file_get_contents(self::TEMPLATES . '/dw-logo.png') : '';
        if ($cid && $logo !== '') {
            $html = str_replace(DwBrand::logoUrl(), 'cid:dw-logo@' . DwBrand::domain(), $html);
        }
        $b = fn (string $s): string => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
        $alt = 'alt_' . bin2hex(random_bytes(8));
        $rel = 'rel_' . bin2hex(random_bytes(8));
        $htmlPart = "Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b($html) . "\r\n";
        if ($cid && $logo !== '') {
            $htmlPart = "Content-Type: multipart/related; boundary=\"$rel\"\r\n\r\n--$rel\r\n" . $htmlPart
                . "--$rel\r\nContent-Type: image/png\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <dw-logo@" . DwBrand::domain() . ">\r\nContent-Disposition: inline; filename=\"dw-logo.png\"\r\n\r\n"
                . $b($logo) . "\r\n--$rel--\r\n";
        }
        $mime = "MIME-Version: 1.0\r\nAuto-Submitted: auto-generated\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n"
            . "--$alt\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b($text) . "\r\n"
            . "--$alt\r\n" . $htmlPart . "--$alt--\r\n";
        $real = (string)self::cfg('mail') === 'smtp' && self::allowedTo($to);
        if (!$real) {
            // Staging test hook: the message (with its link) goes to a root/www-data-only file, never to SMTP.
            $dir = (string)self::cfg('sink_dir');
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $f = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
            file_put_contents($f, "X-Sink-To: $to\r\nSubject: $subject\r\n" . $mime, LOCK_EX);
            @chmod($f, 0600);
            self::log('mail sunk (not sent) host=' . $site);
            return;
        }
        if (!Dw::sendMime($to, $subject, $mime)) {
            self::log('SMTP send failed host=' . $site);
            return;
        }
        self::log('link mailed host=' . $site);
    }

    private static function allowedTo(string $to): bool
    {
        $to = strtolower($to);
        foreach ((array)self::cfg('allow_to') as $p) {
            if (is_string($p) && fnmatch(strtolower($p), $to)) {
                return true;
            }
        }
        return false;
    }

    // Events after the response is sent (mail result) never reach nginx's error log, so they also go to a
    // small log file (no tokens, no session ids, no email addresses). Before-response events use error_log too.
    private static function log(string $msg): void
    {
        error_log('dw_login: ' . $msg);
        $f = (string)self::cfg('log_file');
        if ($f !== '') {
            @error_log(gmdate('Y-m-d\TH:i:s\Z') . ' dw_login: ' . $msg . "\n", 3, $f);
        }
    }

    // ------------------------------------------------------------------ output
    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function answer(bool $json, int $code, array $p): void
    {
        if ($json) {
            Dw::jsonOut($code, $p);
            exit;
        }
        self::page($code, 'Sign in', '<p>' . self::h((string)($p['error'] ?? $p['message'] ?? '')) . '</p><p><a href="/session/login/">Back</a></p>');
    }

    public static function pageOut(int $code, string $title, string $body): void
    {
        self::page($code, $title, $body);
    }

    private static function page(int $code, string $title, string $body, bool $exit = true): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>'
            . self::h($title) . '</title></head><body style="font-family:system-ui;max-width:520px;margin:10vh auto;padding:0 1rem">' . $body . '</body></html>';
        if ($exit) {
            exit;
        }
    }

    private static function form(string $host): void
    {
        $app = DwDomain::isAppHost($host);
        $signedIn = !$app && DwEdit::currentSiteCookie($host) !== null;
        self::page(200, 'Sign in', '<h1>Sign in</h1>'
            . ($signedIn ? '<p>You are signed in on this site. <a href="/session/logout/">Sign out</a></p>' : '')
            . '<form method="post" action="/session/login/">'
            . ($app ? '<label>Site<br><input name="domain" placeholder="example.com" required></label><br><br>' : '')
            . '<label>Email<br><input type="email" name="email" autocomplete="email" required size="40"></label> <button>Email me a sign-in link</button></form>');
    }
}
