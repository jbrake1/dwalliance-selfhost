<?php
declare(strict_types=1);
// Signup hardening for /account_setup (approved 2026-11-04: rate limits, wrong-code cap,
// block Tor, VPN/datacenter and non-US networks at signup only). Self-contained: no Turnstile,
// no human check. Used only by /account_setup; browsing, sessions and other pages never call it.
//  1. Rate limits on code emails, counted from existing account_setup rows (no new tables), so
//     they hold across all nodes: per normalized email 1 per 60 s and 3 per hour; per client IP
//     5 per hour. Gmail dots/+tags are normalized for this count only.
//  2. Wrong-code cap: every step-2 try spends 1/5 of the 30-minute life of the account_setup row
//     (created moves back 6 minutes, atomically in MySQL). A correct code gives its try back. So
//     after 5 wrong codes the id is expired everywhere and the user must start again.
//  3. Network block (HTTP 403): Tor exits, X4BNet VPN and datacenter ranges, and any IP outside
//     the allowed countries (DB-IP Lite country, CC-BY 4.0, https://db-ip.com). Lists live under
//     /var/lib/app/data/trust/iplists (outside the web root) and are refreshed by
//     tools/dw_iplists.php from cron. A missing or unreadable country DB fails open with a log line.
//     Country rule modes ("country" setting): "block" = only the "countries" list may sign up (the
//     hosted service: US only); "deny" = everyone except "blocked_countries" and "blocked_regions"
//     (self-host default: US OFAC comprehensive sanctions; see docs/signup-countries.md); "allow" = off.
// Optional overrides: "signup" object in /var/lib/app/data/trust/config.json. Defaults below.
require_once __DIR__ . '/DwBrand.php';

final class DwSignup
{
    private const DEFAULTS = [
        'enabled' => true,
        'email_per_minute' => 1,
        'email_per_hour' => 3,
        'ip_per_hour' => 5,
        'wrong_code_max' => 5,
        'tor' => 'block',              // block | allow  ("challenge" kept for the staging Turnstile code)
        'vpn' => 'block',              // VPN + datacenter/hosting ranges
        'country' => 'block',          // block | allow
        'countries' => ['US'],         // allowed countries ("block" mode)
        'blocked_countries' => [],     // "deny" mode: ISO 3166 codes that may not sign up
        'blocked_regions' => [],       // "deny" mode: "CC:Region" as named in DB-IP Lite city data, e.g. "UA:Crimea"
        'block_existing_account' => false,
        'list_max_age_hours' => 6,     // cron refresh interval for tor/vpn/hosting
        'country_max_age_days' => 25,  // DB-IP publishes monthly
        'exempt_ips' => [],
        'lists' => [
            'tor' => 'https://check.torproject.org/torbulkexitlist',
            'vpn' => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/vpn/ipv4.txt',
            'hosting' => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/datacenter/ipv4.txt',
            'country' => 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz',
            'region' => 'https://download.db-ip.com/free/dbip-city-lite-%s.csv.gz',
        ],
    ];
    public const DIR = '/var/lib/app/data/trust/iplists';
    private const CONFIG = '/var/lib/app/data/trust/config.json';
    // Only a reverse proxy in TRUSTED_PROXIES (DwConfig, comma separated) may tell us the client IP.
    private static function trustedProxies(): array
    {
        return DwConfig::list('TRUSTED_PROXIES');
    }

    public const BLOCK_MESSAGE = 'Signups from your network or location are not accepted. If you think this is a mistake, email support@dwalliance.com.';

    /** signup_failure reason for a country block: blocked_non_us (allow-list mode) or blocked_country (deny mode). */
    public static function countryReason(): string
    {
        return (string)self::cfg('country') === 'deny' ? 'blocked_country' : 'blocked_non_us';
    }

    /** BLOCK_MESSAGE with the configured support address. */
    public static function blockMessage(): string
    {
        return DwBrand::t(self::BLOCK_MESSAGE);
    }
    private static ?array $conf = null;

    public static function cfg(string $k)
    {
        if (self::$conf === null) {
            $raw = @file_get_contents(self::CONFIG);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            self::$conf = is_array($j['signup'] ?? null) ? $j['signup'] : [];
        }
        return array_key_exists($k, self::$conf) ? self::$conf[$k] : (self::DEFAULTS[$k] ?? null);
    }

    // Real client address: X-Real-IP only from a trusted proxy; else that proxy's right-most X-Forwarded-For
    // entry (entries to its left are client-supplied); else REMOTE_ADDR.
    public static function clientIp(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!in_array($remote, self::trustedProxies(), true)) {
            return $remote;
        }
        $real = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) {
            return $real;
        }
        $xff = array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        $last = (string)end($xff);
        return ($last !== '' && filter_var($last, FILTER_VALIDATE_IP)) ? $last : $remote;
    }

    // ---- Gmail normalization (rate-limit key only) ----
    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $plus = strpos($local, '+');
            if ($plus !== false) {
                $local = substr($local, 0, $plus);
            }
            return str_replace('.', '', $local) . '@gmail.com';
        }
        return $email;
    }

    private static function emailWhere(string $norm, string $col): array
    {
        if (substr($norm, -10) !== '@gmail.com') {
            return ["LOWER($col) = ?", [$norm], false];
        }
        $first = substr($norm, 0, 1);
        return ["(LOWER($col) LIKE ? OR LOWER($col) LIKE ?) AND LOWER($col) LIKE ?", ['%@gmail.com', '%@googlemail.com', $first . '%'], true];
    }

    private static function countEmail(PDO $db, string $norm, int $seconds): int
    {
        [$w, $args, $post] = self::emailWhere($norm, 'email');
        $q = $db->prepare("SELECT email FROM account_setup WHERE created >= NOW() - INTERVAL $seconds SECOND AND $w");
        $q->execute($args);
        $n = 0;
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $e) {
            if (!$post || self::normalizeEmail((string)$e) === $norm) {
                $n++;
            }
        }
        return $n;
    }

    public static function existingAccount(PDO $db, string $norm): bool
    {
        [$w, $args, $post] = self::emailWhere($norm, 'email');
        $q = $db->prepare("SELECT email FROM email WHERE owner_type = 'PRSN' AND $w");
        $q->execute($args);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $e) {
            if (!$post || self::normalizeEmail((string)$e) === $norm) {
                return true;
            }
        }
        return false;
    }

    // ---- 1. Rate limits: null when allowed, else ['error', 'retry_after', 'limit'] ----
    public static function rateLimit(PDO $db, string $email, string $ip): ?array
    {
        if (!self::cfg('enabled') || in_array($ip, (array)self::cfg('exempt_ips'), true)) {
            return null;
        }
        $norm = self::normalizeEmail($email);
        $pm = (int)self::cfg('email_per_minute');
        if ($pm > 0 && self::countEmail($db, $norm, 60) >= $pm) {
            return ['error' => 'a code was sent to this email less than a minute ago. Wait a minute and try again', 'retry_after' => 60, 'limit' => 'email_per_minute'];
        }
        $ph = (int)self::cfg('email_per_hour');
        if ($ph > 0 && self::countEmail($db, $norm, 3600) >= $ph) {
            return ['error' => 'too many codes for this email in the last hour. Try again later', 'retry_after' => 3600, 'limit' => 'email_per_hour'];
        }
        $ih = (int)self::cfg('ip_per_hour');
        if ($ih > 0 && $ip !== '') {
            $q = $db->prepare('SELECT COUNT(*) FROM account_setup WHERE created >= NOW() - INTERVAL 1 HOUR AND remote_ip = ?');
            $q->execute([substr($ip, 0, 50)]);
            if ((int)$q->fetchColumn() >= $ih) {
                return ['error' => 'too many signups from this network in the last hour. Try again later', 'retry_after' => 3600, 'limit' => 'ip_per_hour'];
            }
        }
        return null;
    }

    // ---- 2. Wrong-code cap ----
    private static function penalty(): int
    {
        $max = max(1, (int)self::cfg('wrong_code_max'));
        return intdiv(1800, $max);
    }

    // Spend one try. False when the id is unknown, expired or out of tries.
    public static function spendTry(PDO $db, string $id): bool
    {
        $q = $db->prepare('UPDATE account_setup SET created = created - INTERVAL ' . self::penalty() . ' SECOND WHERE id = ? AND created > NOW() - INTERVAL 30 MINUTE');
        $q->execute([$id]);
        return $q->rowCount() === 1;
    }

    // Correct code: give the try back.
    public static function refundTry(PDO $db, string $id): void
    {
        $db->prepare('UPDATE account_setup SET created = created + INTERVAL ' . self::penalty() . ' SECOND WHERE id = ?')->execute([$id]);
    }

    // ---- 3. Network block ----
    // Internal networks skip the network checks: INTERNAL_NETS (DwConfig, comma separated CIDRs) plus loopback.
    private static function allowNets(): array
    {
        $nets = [];
        foreach (DwConfig::list('INTERNAL_NETS') as $cidr) {
            if (preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d{1,2})$#', $cidr, $m) === 1 && (int)$m[2] <= 32) {
                $nets[] = [$m[1], (int)$m[2]];
            }
        }
        $nets[] = ['127.0.0.0', 8];
        return $nets;
    }

    public static function internal(string $ip): bool
    {
        if ($ip === '::1') {
            return true;
        }
        $n = ip2long($ip);
        if ($n === false) {
            return false;
        }
        foreach (self::allowNets() as [$net, $bits]) {
            $mask = (~((1 << (32 - $bits)) - 1)) & 0xFFFFFFFF;
            if (($n & $mask) === (ip2long($net) & $mask)) {
                return true;
            }
        }
        return false;
    }

    // Returns ['kind' => 'tor'|'vpn'|'hosting'|'country'|null, 'action' => 'allow'|'challenge'|'block'].
    public static function ipRisk(string $ip): array
    {
        $ok = ['kind' => null, 'action' => 'allow'];
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }
        if (!self::cfg('enabled') || $ip === '' || self::internal($ip) || in_array($ip, (array)self::cfg('exempt_ips'), true)
            || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ok;
        }
        $checks = [['tor', 'tor'], ['vpn', 'vpn'], ['hosting', 'vpn']];
        foreach ($checks as [$list, $setting]) {
            $action = (string)self::cfg($setting);
            if ($action !== 'allow' && self::inList($list, $ip)) {
                return ['kind' => $list, 'action' => in_array($action, ['challenge', 'block'], true) ? $action : 'block'];
            }
        }
        $action = (string)self::cfg('country');
        if ($action !== 'allow' && self::inCountry($ip) === false) {
            return ['kind' => 'country', 'action' => in_array($action, ['challenge', 'block'], true) ? $action : 'block'];
        }
        return $ok;
    }

    // Tor/VPN/hosting lists are compiled to <name>.php: ['exact' => [ip => 1], 'ranges' => [[start, end], ...]].
    private static array $lists = [];

    private static function inList(string $name, string $ip): bool
    {
        $l = self::$lists[$name] ?? (self::$lists[$name] = self::load($name));
        if (isset($l['exact'][$ip])) {
            return true;
        }
        $n = ip2long($ip);
        if ($n === false || !$l['ranges']) {
            return false;
        }
        $r = $l['ranges'];
        $lo = 0;
        $hi = count($r) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($n < $r[$mid][0]) {
                $hi = $mid - 1;
            } elseif ($n > $r[$mid][1]) {
                $lo = $mid + 1;
            } else {
                return true;
            }
        }
        return false;
    }

    private static function load(string $name): array
    {
        $file = self::DIR . '/' . $name . '.php';
        $age = @filemtime($file);
        // Cron refreshes every list_max_age_hours. Only if cron has missed twice does a request
        // try a quick (3 s) refresh, keeping the old copy on failure.
        if ($age === false || time() - $age > 2 * 3600 * (int)self::cfg('list_max_age_hours')) {
            error_log("DwSignup: list $name is " . ($age === false ? 'missing' : 'stale') . ', trying a quick refresh');
            self::refresh($name, 3);
        }
        $data = @include $file;
        if (!is_array($data)) {
            error_log("DwSignup: list $name unreadable, not used (fail open)");
            return ['exact' => [], 'ranges' => []];
        }
        return $data;
    }

    // Country: true = allowed country, false = other country, null = unknown (DB missing: fail open).
    // "deny" mode reads the blocked ranges (country-deny*.bin) instead: inside one = false.
    public static function inCountry(string $ip): ?bool
    {
        $deny = (string)self::cfg('country') === 'deny';
        if ($deny && !self::cfg('blocked_countries') && !self::cfg('blocked_regions')) {
            return true; // deny mode with empty lists: nothing is blocked
        }
        $v6 = strpos($ip, ':') !== false;
        $file = self::DIR . '/country-' . ($deny ? 'deny' : 'allow') . ($v6 ? '6' : '4') . '.bin';
        $size = $v6 ? 32 : 8;
        $fh = @fopen($file, 'rb');
        $len = $fh ? (int)@filesize($file) : 0;
        if (!$fh || $len < $size * ($deny ? 1 : 10) || $len % $size !== 0) {
            error_log('DwSignup: country DB ' . basename($file) . ' missing or unreadable, country check skipped (fail open)');
            if ($fh) {
                fclose($fh);
            }
            return null;
        }
        $key = @inet_pton($ip);
        if ($key === false || strlen($key) !== ($v6 ? 16 : 4)) {
            fclose($fh);
            return null;
        }
        $half = $size / 2;
        $lo = 0;
        $hi = intdiv($len, $size) - 1;
        $found = false;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            fseek($fh, $mid * $size);
            $rec = fread($fh, $size);
            if (!is_string($rec) || strlen($rec) !== $size) {
                break;
            }
            $start = substr($rec, 0, $half);
            $end = substr($rec, $half);
            if (strcmp($key, $start) < 0) {
                $hi = $mid - 1;
            } elseif (strcmp($key, $end) > 0) {
                $lo = $mid + 1;
            } else {
                $found = true;
                break;
            }
        }
        fclose($fh);
        return $deny ? !$found : $found;
    }

    // ---- refresh (cron via tools/dw_iplists.php; tor/vpn/hosting also from a request if stale) ----
    private static function lock(string $name)
    {
        @mkdir(self::DIR, 02750, true);
        $lock = @fopen(self::DIR . '/' . $name . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }
        return $lock;
    }

    private static function fetch(string $url, int $timeout): ?string
    {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2, CURLOPT_USERAGENT => 'dw-iplists/1']);
        $raw = curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        if (!is_string($raw) || $code !== 200) {
            error_log("DwSignup: download failed (HTTP $code) " . preg_replace('#^https?://([^/]+).*#', '$1', $url));
            return null;
        }
        return $raw;
    }

    public static function refresh(string $name, int $timeout = 20): bool
    {
        if ($name === 'country') {
            return self::refreshCountry(false);
        }
        $url = (string)(self::cfg('lists')[$name] ?? '');
        if ($url === '' || !($lock = self::lock($name))) {
            return false;
        }
        try {
            $raw = self::fetch($url, $timeout);
            if ($raw === null) {
                @touch(self::DIR . '/' . $name . '.php'); // back off; retry after the next interval
                return false;
            }
            $exact = [];
            $ranges = [];
            foreach (preg_split('/\r?\n/', $raw) as $line) {
                $line = trim(preg_replace('/#.*/', '', $line));
                if ($line === '') {
                    continue;
                }
                if (strpos($line, '/') !== false) {
                    [$net, $bits] = explode('/', $line, 2);
                    $n = ip2long($net);
                    $b = (int)$bits;
                    if ($n === false || $b < 8 || $b > 32) {
                        continue;
                    }
                    $mask = $b === 32 ? 0xFFFFFFFF : (~((1 << (32 - $b)) - 1) & 0xFFFFFFFF);
                    $start = $n & $mask;
                    $ranges[] = [$start, $start | (~$mask & 0xFFFFFFFF)];
                } elseif (filter_var($line, FILTER_VALIDATE_IP)) {
                    $exact[$line] = 1;
                }
            }
            if (count($exact) + count($ranges) < 10) {
                error_log("DwSignup: list $name looked empty, kept the old copy");
                return false;
            }
            usort($ranges, fn($a, $b) => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($ranges as $r) {
                $k = count($merged) - 1;
                if ($k >= 0 && $r[0] <= $merged[$k][1] + 1) {
                    $merged[$k][1] = max($merged[$k][1], $r[1]);
                } else {
                    $merged[] = $r;
                }
            }
            $tmp = self::DIR . '/' . $name . '.php.tmp';
            file_put_contents($tmp, '<?php return ' . var_export(['exact' => $exact, 'ranges' => $merged, 'fetched' => gmdate('c')], true) . ';');
            rename($tmp, self::DIR . '/' . $name . '.php');
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate(self::DIR . '/' . $name . '.php', true);
            }
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // DB-IP Lite country CSV (start,end,cc) -> sorted binary ranges of the allowed countries only:
    // country-allow4.bin (4+4 bytes) and country-allow6.bin (16+16 bytes), big-endian.
    public static function refreshCountry(bool $force): bool
    {
        if ((string)self::cfg('country') === 'deny') {
            return self::refreshCountryDeny($force);
        }
        $meta = self::DIR . '/country.json';
        $age = @filemtime(self::DIR . '/country-allow4.bin');
        if (!$force && $age !== false && time() - $age < 86400 * (int)self::cfg('country_max_age_days')) {
            return true; // fresh enough
        }
        if (!($lock = self::lock('country'))) {
            return false;
        }
        try {
            $tpl = (string)(self::cfg('lists')['country'] ?? '');
            $gz = null;
            $month = '';
            foreach ([gmdate('Y-m'), gmdate('Y-m', strtotime('first day of last month'))] as $m) {
                $gz = self::fetch(sprintf($tpl, $m), 60);
                if ($gz !== null) {
                    $month = $m;
                    break;
                }
            }
            $csv = $gz !== null ? @gzdecode($gz) : false;
            unset($gz);
            if (!is_string($csv)) {
                error_log('DwSignup: country DB download failed, kept the old copy');
                return false;
            }
            $allow = array_flip(array_map('strtoupper', (array)self::cfg('countries')));
            $v4 = [];
            $v6 = '';
            $n6 = 0;
            $rows = 0;
            $tok = strtok($csv, "\n");
            while ($tok !== false) {
                $p = explode(',', trim($tok));
                $tok = strtok("\n");
                if (count($p) < 3) {
                    continue;
                }
                $rows++;
                if (!isset($allow[strtoupper(trim($p[2], '"'))])) {
                    continue;
                }
                $a = @inet_pton(trim($p[0], '"'));
                $b = @inet_pton(trim($p[1], '"'));
                if ($a === false || $b === false || strlen($a) !== strlen($b)) {
                    continue;
                }
                if (strlen($a) === 4) {
                    $s = unpack('N', $a)[1];
                    $e = unpack('N', $b)[1];
                    $k = count($v4) - 1;
                    if ($k >= 0 && $s <= $v4[$k][1] + 1) {
                        $v4[$k][1] = max($v4[$k][1], $e);
                    } else {
                        $v4[] = [$s, $e];
                    }
                } else {
                    $v6 .= $a . $b;
                    $n6++;
                }
            }
            unset($csv);
            if ($rows < 100000 || count($v4) < 1000 || $n6 < 1000) {
                error_log("DwSignup: country DB looked wrong (rows=$rows v4=" . count($v4) . " v6=$n6), kept the old copy");
                return false;
            }
            $bin4 = '';
            foreach ($v4 as [$s, $e]) {
                $bin4 .= pack('NN', $s, $e);
            }
            foreach (['4' => $bin4, '6' => $v6] as $k => $bin) {
                $f = self::DIR . '/country-allow' . $k . '.bin';
                file_put_contents($f . '.tmp', $bin);
                rename($f . '.tmp', $f);
            }
            file_put_contents($meta, json_encode(['source' => 'DB-IP Lite country ' . $month . ' (CC-BY 4.0, https://db-ip.com)', 'allowed' => array_keys($allow), 'rows' => $rows, 'ranges4' => count($v4), 'ranges6' => $n6, 'fetched' => gmdate('c')]));
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    // ---- "deny" mode: everyone may sign up except blocked countries and regions ----
    // Builds country-deny4.bin / country-deny6.bin (same record format as country-allow*.bin) from
    // DB-IP Lite: the country file for blocked_countries, the city file (streamed from disk, it is
    // large) for blocked_regions. Rebuilt when older than country_max_age_days or when the lists change.
    // Geolocation is approximate; see docs/signup-countries.md. Not legal advice.
    public static function refreshCountryDeny(bool $force): bool
    {
        $countries = array_values(array_unique(array_filter(array_map(fn($c) => strtoupper(trim((string)$c)), (array)self::cfg('blocked_countries')))));
        $regions = [];
        foreach ((array)self::cfg('blocked_regions') as $r) {
            $p = explode(':', (string)$r, 2);
            if (count($p) === 2 && trim($p[0]) !== '' && trim($p[1]) !== '') {
                $regions[strtoupper(trim($p[0])) . ':' . mb_strtolower(trim($p[1]))] = trim((string)$r);
            }
        }
        $hash = sha1(json_encode([$countries, array_keys($regions)]));
        $meta = self::DIR . '/country.json';
        $old = json_decode((string)@file_get_contents($meta), true);
        $age = @filemtime(self::DIR . '/country-deny4.bin');
        if (!$force && $age !== false && ($old['list_hash'] ?? '') === $hash
            && time() - $age < 86400 * (int)self::cfg('country_max_age_days')) {
            return true;
        }
        if (!($lock = self::lock('country'))) {
            return false;
        }
        $tmpCity = null;
        try {
            $v4 = [];
            $v6 = [];
            $add = function (string $a, string $b) use (&$v4, &$v6): void {
                $a = @inet_pton($a);
                $b = @inet_pton($b);
                if ($a === false || $b === false || strlen($a) !== strlen($b)) {
                    return;
                }
                if (strlen($a) === 4) {
                    $v4[] = [unpack('N', $a)[1], unpack('N', $b)[1]];
                } else {
                    $v6[] = [$a, $b];
                }
            };
            $months = [gmdate('Y-m'), gmdate('Y-m', strtotime('first day of last month'))];
            $month = '';
            if ($countries) {
                $want = array_flip($countries);
                $csv = false;
                foreach ($months as $m) {
                    $gz = self::fetch(sprintf((string)(self::cfg('lists')['country'] ?? ''), $m), 60);
                    if ($gz !== null) {
                        $csv = @gzdecode($gz);
                        $month = $m;
                        break;
                    }
                }
                if (!is_string($csv)) {
                    error_log('DwSignup: country DB download failed, kept the old copy');
                    return false;
                }
                $rows = 0;
                $tok = strtok($csv, "\n");
                while ($tok !== false) {
                    $p = explode(',', trim($tok));
                    $tok = strtok("\n");
                    if (count($p) < 3) {
                        continue;
                    }
                    $rows++;
                    if (isset($want[strtoupper(trim($p[2], '"'))])) {
                        $add(trim($p[0], '"'), trim($p[1], '"'));
                    }
                }
                unset($csv);
                if ($rows < 100000) {
                    error_log("DwSignup: country DB looked wrong (rows=$rows), kept the old copy");
                    return false;
                }
            }
            $found = [];
            if ($regions) {
                $byCc = [];
                foreach (array_keys($regions) as $k) {
                    $byCc[substr($k, 0, strpos($k, ':'))] = true;
                }
                $tmpCity = self::DIR . '/region-download.tmp.gz';
                $ok = false;
                foreach ($months as $m) {
                    if (self::fetchToFile(sprintf((string)(self::cfg('lists')['region'] ?? ''), $m), $tmpCity, 600)) {
                        $ok = true;
                        $month = $month !== '' ? $month : $m;
                        break;
                    }
                }
                $gzh = $ok ? @gzopen($tmpCity, 'rb') : false;
                if (!$gzh) {
                    error_log('DwSignup: region DB download failed, kept the old copy');
                    return false;
                }
                $rows = 0;
                while (($line = gzgets($gzh, 4096)) !== false) {
                    $rows++;
                    // start,end,continent,country,stateprov,city,lat,lon (quoted when needed)
                    $c1 = strpos($line, ',');
                    $c2 = $c1 === false ? false : strpos($line, ',', $c1 + 1);
                    $c3 = $c2 === false ? false : strpos($line, ',', $c2 + 1);
                    $c4 = $c3 === false ? false : strpos($line, ',', $c3 + 1);
                    if ($c4 === false || !isset($byCc[substr($line, $c3 + 1, $c4 - $c3 - 1)])) {
                        continue;
                    }
                    $p = str_getcsv(rtrim($line, "\r\n"), ',', '"', '\\');
                    if (count($p) < 5) {
                        continue;
                    }
                    $key = strtoupper($p[3]) . ':' . mb_strtolower(trim($p[4]));
                    if (isset($regions[$key])) {
                        $found[$key] = ($found[$key] ?? 0) + 1;
                        $add($p[0], $p[1]);
                    }
                }
                gzclose($gzh);
                if ($rows < 100000) {
                    error_log("DwSignup: region DB looked wrong (rows=$rows), kept the old copy");
                    return false;
                }
            }
            usort($v4, fn($a, $b) => $a[0] <=> $b[0]);
            $bin4 = '';
            $merged = [];
            foreach ($v4 as $r) {
                $k = count($merged) - 1;
                if ($k >= 0 && $r[0] <= $merged[$k][1] + 1) {
                    $merged[$k][1] = max($merged[$k][1], $r[1]);
                } else {
                    $merged[] = $r;
                }
            }
            foreach ($merged as [$s, $e]) {
                $bin4 .= pack('NN', $s, $e);
            }
            usort($v6, fn($a, $b) => strcmp($a[0], $b[0]));
            $m6 = [];
            foreach ($v6 as $r) {
                $k = count($m6) - 1;
                if ($k >= 0 && strcmp($r[0], $m6[$k][1]) <= 0) {
                    if (strcmp($r[1], $m6[$k][1]) > 0) {
                        $m6[$k][1] = $r[1];
                    }
                } else {
                    $m6[] = $r;
                }
            }
            $v6 = $m6;
            $bin6 = '';
            foreach ($v6 as [$s, $e]) {
                $bin6 .= $s . $e;
            }
            foreach (['4' => $bin4, '6' => $bin6] as $k => $bin) {
                $f = self::DIR . '/country-deny' . $k . '.bin';
                file_put_contents($f . '.tmp', $bin);
                rename($f . '.tmp', $f);
            }
            $unmatched = array_values(array_diff_key($regions, $found));
            if ($unmatched) {
                error_log('DwSignup: blocked_regions with no match in DB-IP: ' . implode(', ', $unmatched));
            }
            file_put_contents($meta, json_encode(['mode' => 'deny', 'source' => 'DB-IP Lite ' . $month . ' (CC-BY 4.0, https://db-ip.com)',
                'blocked_countries' => $countries, 'blocked_regions' => array_values($regions), 'unmatched_regions' => $unmatched,
                'ranges4' => count($merged), 'ranges6' => count($v6), 'list_hash' => $hash, 'fetched' => gmdate('c')]));
            return true;
        } finally {
            if ($tmpCity !== null) {
                @unlink($tmpCity);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function fetchToFile(string $url, string $path, int $timeout): bool
    {
        $fh = @fopen($path, 'wb');
        if (!$fh) {
            return false;
        }
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2, CURLOPT_USERAGENT => 'dw-iplists/1']);
        $ok = curl_exec($c) !== false;
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        fclose($fh);
        if (!$ok || $code !== 200) {
            error_log("DwSignup: download failed (HTTP $code) " . preg_replace('#^https?://([^/]+).*#', '$1', $url));
            @unlink($path);
            return false;
        }
        return true;
    }
}
