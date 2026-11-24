<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// ChatGPT as the DW site editor (refs #67, staging 2026-11-21).
// OAuth 2.0 authorization code flow (RFC 6749) with PKCE S256 (RFC 7636) and token revocation (RFC 7009),
// so a ChatGPT custom GPT (Actions) or a ChatGPT app can call /files and /markup as the person who allowed it.
//
//   GET  https://app.dwalliance.com/oauth/authorize/?response_type=code&client_id=..&redirect_uri=..&state=..
//        [&scope=files.read files.write markup.read][&code_challenge=..&code_challenge_method=S256]
//        -> asks which site, then 302 to the same URL on that site's own domain.
//   GET  https://<site>/oauth/authorize/?...  -> not signed in: the existing magic-link sign-in (POST /session/login)
//        signed in: consent screen (person, brand domains, scopes, what their account allows).
//   POST https://<site>/oauth/authorize/      -> Allow / Cancel. Allow: 303 to redirect_uri?code=..&state=..
//   POST /oauth/token/   grant_type=authorization_code (code, redirect_uri, code_verifier) | refresh_token
//   POST /oauth/revoke/  token=..  (a refresh token revokes the whole grant)
//   GET/POST https://<site>/oauth/connections/ -> the signed-in person sees and disconnects their grants.
//
// Clients live in /var/lib/app/data/oauth.json (mode 600, www-data), never in the DB or the repo:
//   {"key": "<64 hex, HMAC key for consent CSRF>", "access_ttl": 3600, "refresh_ttl": 2592000, "code_ttl": 600,
//    "direct_writes": false,
//    "clients": {"<client_id>": {"name": "ChatGPT", "secret_sha256": "<sha256 hex of the secret>",
//        "pkce": "optional"|"required", "redirect_uris": ["https://..."], "redirect_patterns": ["^https://...$"],
//        "scopes": ["files.read", "files.write", "markup.read"]}}}
// Only sha256 of codes and tokens is stored (oauth_token). Rights are never copied into a token: every request
// reads the person's live data_access for the grant's brand, through Dw::allows, exactly like a session.
// Tokens, codes, secrets, session ids and brand codes never appear in any response other than the token
// response itself, and never in logs.
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';

final class DwOAuth
{
    const CONFIG = '/var/lib/app/data/oauth.json';
    const ALIAS = '/var/lib/app/data/host_alias.json';
    const SCOPES = [
        'files.read' => 'See your site files and their version history',
        'files.write' => 'Make draft changes with a preview link, and publish them when you say so',
        'markup.read' => 'Read "Suggest an edit" requests (notes, marked elements and screenshots)',
        'files.delete' => 'Delete files',
        // Affiliate program (refs #74)
        'affiliate:join' => 'Join the affiliate program for you, manage your channels, see your referrals and commissions, and email you your referral link',
        'affiliate:manage' => 'Manage the affiliate program (staff): see affiliates, change commission rates for new referrals, file transfer requests',
        'affiliate:transfer' => 'Approve or reject affiliate transfer requests (staff)',
    ];
    const AFFILIATE_SCOPES = ['affiliate:join', 'affiliate:manage', 'affiliate:transfer'];
    const AFFILIATE_STAFF_SCOPES = ['affiliate:manage', 'affiliate:transfer'];
    // /files action -> scope a Bearer token needs.
    const FILES_SCOPE = [
        'read' => 'files.read', 'search' => 'files.read', 'versions' => 'files.read', 'preview' => 'files.read',
        'create' => 'files.write', 'update' => 'files.write', 'draft' => 'files.write', 'publish' => 'files.write',
        'restore' => 'files.write', 'discard' => 'files.write', 'delete' => 'files.delete',
    ];
    private static ?array $conf = null;
    private static ?array $current = null;

    // ------------------------------------------------------------------ config
    public static function conf(): array
    {
        if (self::$conf === null) {
            $j = json_decode((string)@file_get_contents(self::CONFIG), true);
            self::$conf = is_array($j) ? $j : [];
        }
        return self::$conf;
    }

    private static function ttl(string $k, int $def): int
    {
        $v = (int)(self::conf()[$k] ?? $def);
        return $v > 0 ? $v : $def;
    }

    public static function directWrites(): bool
    {
        return (self::conf()['direct_writes'] ?? false) === true;
    }

    private static function client(string $id): ?array
    {
        $c = self::conf()['clients'][$id] ?? null;
        return is_array($c) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $id) ? $c : null;
    }

    private static function key(): string
    {
        $k = (string)(self::conf()['key'] ?? '');
        if (strlen($k) < 32) {
            throw new RuntimeException('oauth not configured');
        }
        return $k;
    }

    // Canonical host: the Host header, with the optional *.localhost staging aliases (same as 404.php).
    public static function host(): string
    {
        $h = DwDomain::requestHost();
        if (substr($h, -10) === '.localhost') {
            $a = json_decode((string)@file_get_contents(self::ALIAS), true);
            if (is_array($a) && isset($a[$h]) && is_string($a[$h]) && DwDomain::validHost($a[$h])) {
                $h = strtolower($a[$h]);
            }
        }
        return $h;
    }

    // ------------------------------------------------------------------ Bearer (used by /files and /markup)
    public static function bearer(): ?string
    {
        $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($h === '') {
            return null;
        }
        return preg_match('/^Bearer\s+([A-Za-z0-9._~+\/-]+=*)\s*$/i', trim($h), $m) ? $m[1] : '';
    }

    /**
     * Session-shaped array for a valid access token with $scope, or null when no Authorization header was sent.
     * A bad, expired or revoked token, or a missing scope, answers 401/403 JSON here and exits.
     * Shape: id ('' : no session row), person_id, brand, rights (live data_access JSON), plus oauth_* keys.
     */
    public static function session(string $scope): ?array
    {
        $tok = self::bearer();
        if ($tok === null) {
            return null;
        }
        $deny = function (int $code, string $err, string $desc): void {
            header('WWW-Authenticate: Bearer realm="dwalliance", error="' . $err . '"');
            Dw::jsonOut($code, ['ok' => false, 'error' => $desc, 'oauth_error' => $err]);
            exit;
        };
        if ($tok === '' || !preg_match('/^dwa_[0-9a-f]{64}$/', $tok)) {
            $deny(401, 'invalid_token', 'the access token is not valid');
        }
        $db = Dw::pdo();
        $q = $db->prepare("SELECT g.id, g.client_id, g.person_id, g.brand, g.domain, g.scope, g.last_used FROM oauth_token t JOIN oauth_grant g ON g.id = t.grant_id
            WHERE t.token_hash = ? AND t.kind = 'access' AND t.revoked_at IS NULL AND t.expires > NOW() AND g.revoked_at IS NULL");
        $q->execute([hash('sha256', $tok)]);
        $g = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($g) || self::client((string)$g['client_id']) === null) {
            $deny(401, 'invalid_token', 'the access token is expired or was revoked; sign in again');
        }
        $scopes = preg_split('/\s+/', trim((string)$g['scope'])) ?: [];
        if (!in_array($scope, $scopes, true)) {
            $deny(403, 'insufficient_scope', 'this connection does not allow ' . $scope);
        }
        // Heartbeat for /oauth/connections (at most every 5 minutes; not archived).
        $db->prepare('UPDATE oauth_grant SET last_used = NOW() WHERE id = ? AND (last_used IS NULL OR last_used < NOW() - INTERVAL 5 MINUTE)')->execute([$g['id']]);
        return self::$current = [
            'id' => '',
            'person_id' => (string)$g['person_id'],
            'brand' => (string)$g['brand'],
            'rights' => Dw::rightsJson($db, (string)$g['person_id'], (string)$g['brand']),
            'oauth_grant' => (string)$g['id'],
            'oauth_client' => (string)$g['client_id'],
            'oauth_domain' => (string)$g['domain'],
            'oauth_scope' => (string)$g['scope'],
        ];
    }

    /** /files hook: Bearer session for this action (null = no Bearer header, so the old rules apply). */
    public static function filesSession(string $action, array $sent): ?array
    {
        $scope = self::FILES_SCOPE[$action] ?? 'files.write';
        $s = self::session($scope);
        if ($s !== null && in_array($action, ['create', 'update'], true) && !self::directWrites()) {
            Dw::jsonOut(403, ['ok' => false, 'error' => 'ChatGPT connections change files through drafts: use action draft (returns a preview_url), then action publish when the person says so']);
            exit;
        }
        return $s;
    }

    public static function current(): ?array
    {
        return self::$current;
    }

    // ------------------------------------------------------------------ pages
    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function page(int $code, string $title, string $body, string $formTo = '', string $script = ''): void
    {
        $nonce = bin2hex(random_bytes(12));
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data: https://" . DwBrand::domain() . "; style-src 'unsafe-inline'; script-src 'nonce-$nonce'; connect-src 'self'; form-action 'self'" . ($formTo !== '' ? ' ' . $formTo : '') . "; base-uri 'none'; frame-ancestors 'none'");
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . self::h($title) . ' | ' . self::h(DwBrand::name()) . '</title><style>'
            . 'body{margin:0;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f4f6fb;color:#14213d}'
            . 'header{background:#0b1f3a;color:#fff;padding:14px 22px;font-weight:700;letter-spacing:.3px}header span{color:#f2b134}'
            . 'main{max-width:560px;margin:32px auto;padding:0 16px}.card{background:#fff;border-radius:14px;box-shadow:0 2px 14px rgba(11,31,58,.08);padding:26px 26px 22px}'
            . 'h1{font-size:22px;margin:0 0 10px}ul{padding-left:20px}li{margin:4px 0}.muted{color:#5b6b84;font-size:14px}'
            . 'input[type=email],input[type=text]{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #c8d1e0;border-radius:9px;font-size:16px;margin:6px 0 12px}'
            . 'button,.btn{display:inline-block;border:0;border-radius:9px;padding:11px 20px;font-size:16px;font-weight:600;cursor:pointer;text-decoration:none}'
            . '.go{background:#1d4ed8;color:#fff}.no{background:#e7ebf3;color:#14213d;margin-left:8px}.row{margin-top:18px}.pill{display:inline-block;background:#eef2ff;color:#1d4ed8;border-radius:99px;padding:1px 10px;font-size:13px;margin:2px 4px 2px 0}'
            . 'table{width:100%;border-collapse:collapse;font-size:14px}td,th{text-align:left;padding:8px 6px;border-bottom:1px solid #e7ebf3}'
            . '</style></head><body><header>' . (DwBrand::name() === 'DW Alliance' ? 'DW <span>Alliance</span>' : self::h(DwBrand::name())) . '</header><main><div class="card">' . $body . '</div></main>'
            . ($script !== '' ? '<script nonce="' . $nonce . '">' . $script . '</script>' : '') . '</body></html>';
        exit;
    }

    private static function errorPage(string $msg): void
    {
        self::page(400, 'Cannot connect', '<h1>This connection request cannot continue</h1><p>' . self::h($msg) . '</p><p class="muted">Go back to ChatGPT and try connecting again.</p>');
    }

    // ------------------------------------------------------------------ authorize
    private static function validRedirect(array $c, string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 500 || !preg_match('#^https://[^\s\#]+$#', $uri)) {
            return false;
        }
        foreach ((array)($c['redirect_uris'] ?? []) as $u) {
            if (is_string($u) && hash_equals($u, $uri)) {
                return true;
            }
        }
        foreach ((array)($c['redirect_patterns'] ?? []) as $p) {
            if (is_string($p) && @preg_match('#' . str_replace('#', '\#', $p) . '#', $uri) === 1) {
                return true;
            }
        }
        return false;
    }

    // Checked authorize parameters. Problems before redirect_uri is trusted show a page; after, go back with error.
    private static function authParams(array $in): array
    {
        $cid = (string)($in['client_id'] ?? '');
        $c = self::client($cid);
        if ($c === null) {
            self::errorPage('Unknown app (client_id).');
        }
        $ru = (string)($in['redirect_uri'] ?? '');
        if (!self::validRedirect($c, $ru)) {
            self::errorPage('The return address (redirect_uri) is not registered for this app.');
        }
        $state = (string)($in['state'] ?? '');
        $back = function (string $err, string $desc) use ($ru, $state): void {
            self::redirectBack($ru, ['error' => $err, 'error_description' => $desc, 'state' => $state]);
        };
        if (strlen($state) > 1000) {
            self::errorPage('state is too long.');
        }
        if ((string)($in['response_type'] ?? '') !== 'code') {
            $back('unsupported_response_type', 'response_type must be code');
        }
        $allowed = array_values(array_filter((array)($c['scopes'] ?? []), fn($s) => is_string($s) && isset(self::SCOPES[$s])));
        $want = trim((string)($in['scope'] ?? ''));
        $scopes = $want === '' ? $allowed : array_values(array_unique(preg_split('/[\s,]+/', $want) ?: []));
        foreach ($scopes as $s) {
            if (!in_array($s, $allowed, true)) {
                $back('invalid_scope', 'scope ' . $s . ' is not available to this app');
            }
        }
        if ($scopes === []) {
            $back('invalid_scope', 'no scope');
        }
        $cc = (string)($in['code_challenge'] ?? '');
        $ccm = (string)($in['code_challenge_method'] ?? ($cc !== '' ? 'plain' : ''));
        if ($cc !== '' && ($ccm !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $cc))) {
            $back('invalid_request', 'code_challenge must be S256 (base64url, 43-128 characters)');
        }
        $needPkce = ($c['pkce'] ?? 'required') === 'required' || ($c['secret_sha256'] ?? '') === '';
        if ($cc === '' && $needPkce) {
            $back('invalid_request', 'PKCE (code_challenge with S256) is required');
        }
        return ['response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => $ru, 'state' => $state,
            'scope' => implode(' ', $scopes), 'code_challenge' => $cc, 'code_challenge_method' => $cc !== '' ? 'S256' : '',
            '_client' => $c];
    }

    private static function query(array $p): string
    {
        unset($p['_client']);
        return http_build_query(array_filter($p, fn($v) => $v !== ''), '', '&', PHP_QUERY_RFC3986);
    }

    private static function redirectBack(string $uri, array $p): void
    {
        $p = array_filter($p, fn($v) => $v !== '');
        header('Cache-Control: no-store');
        header('Location: ' . $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($p, '', '&', PHP_QUERY_RFC3986), true, 303);
        exit;
    }

    private static function sameOrigin(string $host): bool
    {
        $sfs = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
        if ($sfs !== '') {
            return $sfs === 'same-origin';
        }
        $src = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($src === '' || $src === 'null') {
            $src = (string)($_SERVER['HTTP_REFERER'] ?? '');
        }
        $oh = parse_url($src, PHP_URL_HOST);
        return is_string($oh) && hash_equals(DwDomain::requestHost(), strtolower($oh));
    }

    /** What this person may do on this brand/host, or null when they cannot edit here at all. */
    public static function abilities(string $personId, string $brand, string $host): ?array
    {
        $q = Dw::pdo()->prepare('SELECT module_name, right_name FROM data_access WHERE person_id = ? AND brand = ?');
        $q->execute([$personId, $brand]);
        $super = false;
        $file = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['right_name'] === 'SUPER_ADMIN') {
                $super = true;
            } elseif ($r['module_name'] === 'FILE') {
                $file[] = (string)$r['right_name'];
            }
        }
        require_once '/var/www/html/lib/DwPermissions.php';
        $editor = $host !== '' && DwPermissions::isDomainEditor($personId, $brand, $host);
        if (!$super && $file === [] && !$editor) {
            return null;
        }
        $lines = [];
        if ($super) {
            $lines[] = 'Super admin: every file on this account';
        } else {
            if ($file !== []) {
                sort($file);
                $lines[] = 'Files: ' . strtolower(implode(', ', array_unique($file)));
            }
            if ($editor) {
                $lines[] = 'Site editor of ' . $host . ' (suggestion requests)';
            }
        }
        return ['super' => $super, 'file' => $file, 'editor' => $editor, 'lines' => $lines];
    }

    private static function csrf(string $sid, array $p): string
    {
        return hash_hmac('sha256', 'consent|' . $sid . '|' . self::query($p), self::key());
    }

    public static function authorizeRoute(): void
    {
        try {
            $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $in = $m === 'POST' ? $_POST : $_GET;
            $p = self::authParams($in);
            $host = self::host();
            $brand = DwDomain::isAppHost($host) ? null : DwDomain::brandForHost($host, [Dw::class, 'pdo']);

            if ($brand === null) {
                // Step 1 (app.dwalliance.com or an unknown host): which site? Then continue on that site's domain.
                $site = strtolower(trim((string)($_GET['site'] ?? '')));
                $site = (string)preg_replace('#^https?://#', '', rtrim($site, '/'));
                $err = '';
                if ($site !== '') {
                    $sb = DwDomain::validHost($site) && !DwDomain::isAppHost($site) ? DwDomain::brandForHost($site, [Dw::class, 'pdo']) : null;
                    if ($sb !== null) {
                        header('Cache-Control: no-store');
                        header('Location: https://' . $site . '/oauth/authorize/?' . self::query($p), true, 302);
                        exit;
                    }
                    $err = DwBrand::t('<p style="color:#b42318">That is not a DW Alliance website. Check the spelling (for example mysite.com).</p>');
                }
                $hidden = '';
                foreach ($p as $k => $v) {
                    if ($k !== '_client' && $v !== '') {
                        $hidden .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h((string)$v) . '">';
                    }
                }
                self::page(200, 'Connect ' . (string)($p['_client']['name'] ?? 'app'), '<h1>Connect ' . self::h((string)($p['_client']['name'] ?? 'this app')) . ' to your website</h1>'
                    . DwBrand::t('<p>Which DW Alliance website should it edit?</p>') . $err
                    . '<form method="get" action="/oauth/authorize/">' . $hidden . '<input type="text" name="site" placeholder="mysite.com" autocomplete="url" required value="' . self::h($site) . '">'
                    . '<button class="go">Continue</button></form><p class="muted">Next you sign in on that website with a one-time email link.</p>', 'https:');
            }

            // Step 2 on the site's domain: signed in?
            require_once '/var/www/html/lib/DwEdit.php';
            $c = DwEdit::currentSiteCookie($host);
            $s = DwEdit::siteSession($host);
            if (($_GET['poll'] ?? '') === '1') {
                Dw::jsonOut(200, ['signed_in' => $s !== null && $c !== null && hash_equals((string)$s['brand'], $brand)]);
                exit;
            }
            $appName = (string)($p['_client']['name'] ?? 'This app');
            if ($s === null || $c === null || !hash_equals((string)$s['brand'], $brand)) {
                $here = '/oauth/authorize/?' . self::query($p);
                $js = "(function(){var f=document.getElementById('f'),m=document.getElementById('m');"
                    . "f.addEventListener('submit',function(e){e.preventDefault();m.textContent='Sending...';"
                    . "fetch('/session/login',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({email:f.email.value})})"
                    . ".then(function(r){return r.json().catch(function(){return {};});}).then(function(j){m.textContent=(j&&j.error)?j.error:'Check your email and open the sign-in link in this browser. This page continues by itself.';poll();})"
                    . ".catch(function(){m.textContent='Could not send. Try again.';});});"
                    . "var t=null;function poll(){if(t)return;t=setInterval(function(){fetch(" . json_encode($here . '&poll=1') . ",{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){if(j.signed_in){clearInterval(t);location.reload();}}).catch(function(){});},3000);}})();";
                self::page(200, 'Sign in', '<h1>Sign in to ' . self::h($host) . '</h1><p>' . self::h($appName) . ' wants to work on this website for you. First, sign in.</p>'
                    . '<form id="f"><input type="email" name="email" placeholder="you@example.com" autocomplete="email" required><button class="go">Email me a sign-in link</button></form>'
                    . '<p id="m" class="muted" role="status"></p><p class="muted">Opened the link already? <a href="' . self::h($here) . '">Continue</a></p>', '', $js);
            }

            $person = (string)$s['person_id'];
            $want = explode(' ', $p['scope']);
            $editScopes = array_values(array_diff($want, self::AFFILIATE_SCOPES));
            $ab = self::abilities($person, $brand, $host);
            if ($editScopes !== [] && $ab === null) {
                self::page(403, 'No editing rights', '<h1>Your account cannot edit ' . self::h($host) . '</h1><p>Ask the site owner to give you editing rights, then try again.</p>');
            }
            if (array_intersect($want, self::AFFILIATE_STAFF_SCOPES) !== []) {
                require_once '/var/www/html/lib/DwAffiliate.php';
                if (!DwAffiliate::canManage(Dw::pdo(), $person, $brand)) {
                    self::page(403, 'Not affiliate staff', '<h1>Your account cannot manage the affiliate program on ' . self::h($host) . '</h1><p>Only super admins and people with the affiliate permission can allow this. Connect again without the staff permissions, or ask the site owner.</p>');
                }
            }
            $abLines = $ab['lines'] ?? [];
            if (in_array('affiliate:join', $want, true)) {
                $abLines[] = 'Affiliate program: your own affiliate account';
            }
            if (array_intersect($want, self::AFFILIATE_STAFF_SCOPES) !== []) {
                $abLines[] = 'Affiliate program: staff';
            }
            $onlyAffiliate = $editScopes === [];
            if ($m === 'POST') {
                if (!self::sameOrigin(DwDomain::requestHost()) || !hash_equals(self::csrf((string)$s['id'], $p), (string)($_POST['csrf'] ?? ''))) {
                    self::page(403, 'Refused', '<h1>Request refused</h1><p>Reload the page and try again.</p>');
                }
                if (($_POST['decision'] ?? '') !== 'allow') {
                    self::redirectBack($p['redirect_uri'], ['error' => 'access_denied', 'state' => $p['state']]);
                }
                $db = Dw::pdo();
                $gid = Dw::randId();
                $code = bin2hex(random_bytes(32));
                $db->beginTransaction();
                try {
                    $db->prepare('INSERT INTO oauth_grant (id, client_id, person_id, brand, domain, scope, created) VALUES (?, ?, ?, ?, ?, ?, NOW())')
                        ->execute([$gid, $p['client_id'], $person, $brand, $host, $p['scope']]);
                    Dw::history($db, 'oauth_grant', $gid, 'INSERT');
                    $db->prepare("INSERT INTO oauth_token (token_hash, kind, grant_id, redirect_uri, code_challenge, created, expires) VALUES (?, 'code', ?, ?, ?, NOW(), NOW() + INTERVAL ? SECOND)")
                        ->execute([hash('sha256', $code), $gid, $p['redirect_uri'], $p['code_challenge'] !== '' ? $p['code_challenge'] : null, self::ttl('code_ttl', 600)]);
                    $db->commit();
                } catch (Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }
                self::redirectBack($p['redirect_uri'], ['code' => $code, 'state' => $p['state']]);
            }

            // Consent screen.
            $db = Dw::pdo();
            $q = $db->prepare('SELECT domain_name FROM domain WHERE code = ? ORDER BY domain_name');
            $q->execute([$brand]);
            $domains = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
            $e = $db->prepare("SELECT email FROM email WHERE owner_id = ? AND owner_type = 'PRSN' AND brand = ? ORDER BY created LIMIT 1");
            $e->execute([$person, $brand]);
            $email = (string)($e->fetchColumn() ?: '');
            $scopes = '';
            foreach (explode(' ', $p['scope']) as $sc) {
                $scopes .= '<li>' . self::h(self::SCOPES[$sc] ?? $sc) . '</li>';
            }
            $doms = '';
            foreach ($domains as $d) {
                $doms .= '<span class="pill">' . self::h($d) . '</span>';
            }
            $hidden = '<input type="hidden" name="csrf" value="' . self::h(self::csrf((string)$s['id'], $p)) . '">';
            foreach ($p as $k => $v) {
                if ($k !== '_client' && $v !== '') {
                    $hidden .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h((string)$v) . '">';
                }
            }
            $to = parse_url($p['redirect_uri'], PHP_URL_SCHEME) . '://' . parse_url($p['redirect_uri'], PHP_URL_HOST);
            self::page(200, 'Allow ' . $appName, '<h1>Allow ' . self::h($appName) . ($onlyAffiliate ? ' to use the affiliate program for you?' : ' to edit your website?') . '</h1>'
                . '<p class="muted">Signed in as ' . self::h($email) . '</p>'
                . '<p><b>Websites</b><br>' . $doms . '</p>'
                . '<p><b>' . self::h($appName) . ' will be able to</b></p><ul>' . $scopes . '</ul>'
                . '<p><b>Your account allows</b></p><ul>' . implode('', array_map(fn($l) => '<li>' . self::h($l) . '</li>', $abLines)) . '</ul>'
                . ($onlyAffiliate
                    ? '<p class="muted">Referral codes and links are sent only to your email. Ownership transfers always need staff approval, and prices cannot be changed this way. You can disconnect any time at <a href="/oauth/connections/">' . self::h($host) . '/oauth/connections/</a>.</p>'
                    : '<p class="muted">Changes start as private drafts with a preview link. Nothing goes live until you tell it to publish, and every change keeps version history. You can disconnect any time at <a href="/oauth/connections/">' . self::h($host) . '/oauth/connections/</a>.</p>')
                . '<form method="post" action="/oauth/authorize/">' . $hidden . '<div class="row"><button class="go" name="decision" value="allow">Allow</button><button class="btn no" name="decision" value="deny">Cancel</button></div></form>', self::h($to));
        } catch (Throwable $e) {
            error_log('dw_oauth authorize: ' . get_class($e) . ' ' . $e->getMessage());
            self::page(503, 'Unavailable', '<h1>Unavailable</h1><p>Please try again in a minute.</p>');
        }
    }

    // ------------------------------------------------------------------ token + revoke
    private static function tokenOut(int $code, array $p): void
    {
        header('Pragma: no-cache');
        if ($code === 401) {
            header('WWW-Authenticate: Basic realm="dwalliance"');
        }
        Dw::jsonOut($code, $p);
        exit;
    }

    private static function input(): array
    {
        $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($ct, 'json') !== false) {
            $j = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
            return is_array($j) ? $j : [];
        }
        return $_POST;
    }

    /** Authenticated client [id, config] from Basic auth or client_id/client_secret in the body. */
    private static function authClient(array $in): array
    {
        $id = '';
        $secret = null;
        $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', trim($h), $m)) {
            $dec = (string)base64_decode($m[1], true);
            $i = strpos($dec, ':');
            if ($i !== false) {
                $id = rawurldecode(substr($dec, 0, $i));
                $secret = rawurldecode(substr($dec, $i + 1));
            }
        }
        if ($id === '') {
            $id = (string)($in['client_id'] ?? '');
            $secret = isset($in['client_secret']) ? (string)$in['client_secret'] : null;
        }
        $c = self::client($id);
        if ($c === null) {
            usleep(200000);
            self::tokenOut(401, ['error' => 'invalid_client']);
        }
        $want = (string)($c['secret_sha256'] ?? '');
        if ($want !== '') {
            if ($secret === null || !hash_equals($want, hash('sha256', $secret))) {
                usleep(200000);
                self::tokenOut(401, ['error' => 'invalid_client']);
            }
        }
        return [$id, $c];
    }

    private static function stillAllowed(PDO $db, array $g): bool
    {
        // Affiliate scopes (refs #74): each request re-checks its own scope; the grant stays usable while ANY of its parts is.
        $sc = preg_split('/\s+/', trim((string)($g['scope'] ?? ''))) ?: [];
        if (array_intersect($sc, self::AFFILIATE_STAFF_SCOPES) !== []) {
            require_once '/var/www/html/lib/DwAffiliate.php';
            if (DwAffiliate::canManage($db, (string)$g['person_id'], (string)$g['brand'])) {
                return true;
            }
        }
        if (in_array('affiliate:join', $sc, true)) {
            $q = $db->prepare("SELECT 1 FROM data_access WHERE person_id = ? AND brand = ? AND (module_name = 'LOGIN' OR right_name = 'SUPER_ADMIN') LIMIT 1");
            $q->execute([$g['person_id'], $g['brand']]);
            if ($q->fetchColumn()) {
                return true;
            }
            require_once '/var/www/html/lib/DwPermissions.php';
            if (DwPermissions::isDomainEditor((string)$g['person_id'], (string)$g['brand'], (string)$g['domain'])) {
                return true;
            }
        }
        if (array_diff($sc, self::AFFILIATE_SCOPES) === []) {
            return false;
        }
        $q = $db->prepare("SELECT 1 FROM data_access WHERE person_id = ? AND brand = ? AND (right_name = 'SUPER_ADMIN' OR module_name = 'FILE') LIMIT 1");
        $q->execute([$g['person_id'], $g['brand']]);
        if ($q->fetchColumn()) {
            return true;
        }
        require_once '/var/www/html/lib/DwPermissions.php';
        return DwPermissions::isDomainEditor((string)$g['person_id'], (string)$g['brand'], (string)$g['domain']);
    }

    private static function issue(PDO $db, array $g): array
    {
        $access = 'dwa_' . bin2hex(random_bytes(32));
        $refresh = 'dwr_' . bin2hex(random_bytes(32));
        $ins = $db->prepare('INSERT INTO oauth_token (token_hash, kind, grant_id, created, expires) VALUES (?, ?, ?, NOW(), NOW() + INTERVAL ? SECOND)');
        $ins->execute([hash('sha256', $access), 'access', $g['id'], self::ttl('access_ttl', 3600)]);
        $ins->execute([hash('sha256', $refresh), 'refresh', $g['id'], self::ttl('refresh_ttl', 2592000)]);
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ttl('access_ttl', 3600),
            'refresh_token' => $refresh, 'scope' => (string)$g['scope']];
    }

    private static function revokeGrant(PDO $db, string $gid): void
    {
        $db->prepare('UPDATE oauth_token SET revoked_at = NOW() WHERE grant_id = ? AND revoked_at IS NULL')->execute([$gid]);
        $u = $db->prepare('UPDATE oauth_grant SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL');
        $u->execute([$gid]);
        if ($u->rowCount() === 1) {
            Dw::history($db, 'oauth_grant', $gid, 'UPDATE');
        }
    }

    public static function tokenRoute(): void
    {
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::tokenOut(405, ['error' => 'invalid_request', 'error_description' => 'POST only']);
        }
        try {
            $in = self::input();
            [$cid] = self::authClient($in);
            $gt = (string)($in['grant_type'] ?? '');
            $db = Dw::pdo();
            if ($gt === 'authorization_code') {
                $code = (string)($in['code'] ?? '');
                if (!preg_match('/^[0-9a-f]{64}$/', $code)) {
                    self::tokenOut(400, ['error' => 'invalid_grant']);
                }
                $db->beginTransaction();
                $q = $db->prepare("SELECT t.token_hash, t.redirect_uri, t.code_challenge, t.used_at, t.revoked_at, t.expires > NOW() AS live, g.* FROM oauth_token t JOIN oauth_grant g ON g.id = t.grant_id WHERE t.token_hash = ? AND t.kind = 'code' FOR UPDATE");
                $q->execute([hash('sha256', $code)]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if (!is_array($r) || !hash_equals((string)$r['client_id'], $cid)) {
                    $db->rollBack();
                    self::tokenOut(400, ['error' => 'invalid_grant']);
                }
                if ($r['used_at'] !== null) {
                    // Code replay: revoke everything issued from it (RFC 6749 4.1.2).
                    self::revokeGrant($db, (string)$r['id']);
                    $db->commit();
                    self::tokenOut(400, ['error' => 'invalid_grant', 'error_description' => 'code already used']);
                }
                $ru = (string)($in['redirect_uri'] ?? '');
                $bad = !(int)$r['live'] || $r['revoked_at'] !== null
                    || ($r['redirect_uri'] !== null && !hash_equals((string)$r['redirect_uri'], $ru));
                if (!$bad && $r['code_challenge'] !== null) {
                    $v = (string)($in['code_verifier'] ?? '');
                    $calc = rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '=');
                    $bad = !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $v) || !hash_equals((string)$r['code_challenge'], $calc);
                }
                if ($bad || $r['revoked_at'] !== null) {
                    $db->rollBack();
                    self::tokenOut(400, ['error' => 'invalid_grant']);
                }
                $db->prepare('UPDATE oauth_token SET used_at = NOW() WHERE token_hash = ?')->execute([$r['token_hash']]);
                $g = $db->prepare('SELECT * FROM oauth_grant WHERE id = ? AND revoked_at IS NULL');
                $g->execute([$r['id']]);
                $grant = $g->fetch(PDO::FETCH_ASSOC);
                if (!is_array($grant) || !self::stillAllowed($db, $grant)) {
                    $db->commit();
                    self::tokenOut(400, ['error' => 'invalid_grant']);
                }
                $out = self::issue($db, $grant);
                $db->commit();
                self::tokenOut(200, $out);
            }
            if ($gt === 'refresh_token') {
                $rt = (string)($in['refresh_token'] ?? '');
                if (!preg_match('/^dwr_[0-9a-f]{64}$/', $rt)) {
                    self::tokenOut(400, ['error' => 'invalid_grant']);
                }
                $db->beginTransaction();
                $q = $db->prepare("SELECT t.token_hash, g.* FROM oauth_token t JOIN oauth_grant g ON g.id = t.grant_id
                    WHERE t.token_hash = ? AND t.kind = 'refresh' AND t.revoked_at IS NULL AND t.used_at IS NULL AND t.expires > NOW() AND g.revoked_at IS NULL FOR UPDATE");
                $q->execute([hash('sha256', $rt)]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if (!is_array($r) || !hash_equals((string)$r['client_id'], $cid) || !self::stillAllowed($db, $r)) {
                    $db->rollBack();
                    self::tokenOut(400, ['error' => 'invalid_grant']);
                }
                // Rotate: the old refresh token is spent.
                $db->prepare('UPDATE oauth_token SET used_at = NOW(), revoked_at = NOW() WHERE token_hash = ?')->execute([$r['token_hash']]);
                unset($r['token_hash']);
                $out = self::issue($db, $r);
                $db->commit();
                self::tokenOut(200, $out);
            }
            self::tokenOut(400, ['error' => 'unsupported_grant_type']);
        } catch (Throwable $e) {
            if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('dw_oauth token: ' . get_class($e) . ' ' . $e->getMessage());
            self::tokenOut(503, ['error' => 'temporarily_unavailable']);
        }
    }

    public static function revokeRoute(): void
    {
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::tokenOut(405, ['error' => 'invalid_request', 'error_description' => 'POST only']);
        }
        try {
            $in = self::input();
            [$cid] = self::authClient($in);
            $t = (string)($in['token'] ?? '');
            if (preg_match('/^dw[ar]_[0-9a-f]{64}$/', $t)) {
                $db = Dw::pdo();
                $db->beginTransaction();
                $q = $db->prepare('SELECT t.kind, g.id, g.client_id FROM oauth_token t JOIN oauth_grant g ON g.id = t.grant_id WHERE t.token_hash = ? FOR UPDATE');
                $q->execute([hash('sha256', $t)]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if (is_array($r) && hash_equals((string)$r['client_id'], $cid)) {
                    if ($r['kind'] === 'refresh') {
                        self::revokeGrant($db, (string)$r['id']);
                    } else {
                        $db->prepare('UPDATE oauth_token SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL')->execute([hash('sha256', $t)]);
                    }
                }
                $db->commit();
            }
            self::tokenOut(200, ['ok' => true]);   // RFC 7009: same answer for unknown tokens
        } catch (Throwable $e) {
            if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('dw_oauth revoke: ' . get_class($e) . ' ' . $e->getMessage());
            self::tokenOut(503, ['error' => 'temporarily_unavailable']);
        }
    }

    // ------------------------------------------------------------------ connections (signed-in person)
    public static function connectionsRoute(): void
    {
        try {
            $host = self::host();
            $brand = DwDomain::isAppHost($host) ? null : DwDomain::brandForHost($host, [Dw::class, 'pdo']);
            if ($brand === null) {
                self::page(404, 'Not found', '<h1>Open this page on your own website</h1><p class="muted">For example https://mysite.com/oauth/connections/</p>');
            }
            require_once '/var/www/html/lib/DwEdit.php';
            $s = DwEdit::siteSession($host);
            if ($s === null || DwEdit::currentSiteCookie($host) === null || !hash_equals((string)$s['brand'], $brand)) {
                self::page(401, 'Sign in', '<h1>Sign in first</h1><p><a href="/session/login/">Sign in to ' . self::h($host) . '</a>, then come back to this page.</p>');
            }
            $db = Dw::pdo();
            $person = (string)$s['person_id'];
            $tok = hash_hmac('sha256', 'connections|' . $s['id'], self::key());
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                if (!self::sameOrigin(DwDomain::requestHost()) || !hash_equals($tok, (string)($_POST['csrf'] ?? ''))) {
                    self::page(403, 'Refused', '<h1>Request refused</h1><p>Reload the page and try again.</p>');
                }
                $gid = (string)($_POST['grant'] ?? '');
                $db->beginTransaction();
                $q = $db->prepare('SELECT id FROM oauth_grant WHERE id = ? AND person_id = ? AND brand = ? FOR UPDATE');
                $q->execute([$gid, $person, $brand]);
                if ($q->fetchColumn()) {
                    self::revokeGrant($db, $gid);
                }
                $db->commit();
                header('Location: /oauth/connections/', true, 303);
                exit;
            }
            $q = $db->prepare('SELECT id, client_id, domain, scope, created, last_used FROM oauth_grant WHERE person_id = ? AND brand = ? AND revoked_at IS NULL ORDER BY created DESC LIMIT 50');
            $q->execute([$person, $brand]);
            $rows = '';
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $name = (string)(self::client((string)$g['client_id'])['name'] ?? 'App');
                $rows .= '<tr><td>' . self::h($name) . '<div class="muted">' . self::h((string)$g['domain']) . '</div></td><td class="muted">' . self::h((string)$g['created']) . '</td><td class="muted">' . self::h((string)($g['last_used'] ?? '')) . '</td>'
                    . '<td><form method="post" action="/oauth/connections/"><input type="hidden" name="csrf" value="' . self::h($tok) . '"><input type="hidden" name="grant" value="' . self::h((string)$g['id']) . '"><button class="btn no">Disconnect</button></form></td></tr>';
            }
            self::page(200, 'Connected apps', '<h1>Connected apps</h1>' . ($rows === '' ? '<p>No apps are connected to your account.</p>'
                : '<table><tr><th>App</th><th>Connected</th><th>Last used</th><th></th></tr>' . $rows . '</table><p class="muted">Times are server time. Disconnecting stops the app at once.</p>'));
        } catch (Throwable $e) {
            error_log('dw_oauth connections: ' . get_class($e) . ' ' . $e->getMessage());
            self::page(503, 'Unavailable', '<h1>Unavailable</h1><p>Please try again in a minute.</p>');
        }
    }
}
