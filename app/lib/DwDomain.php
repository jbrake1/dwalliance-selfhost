<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';

// Host -> brand code lookup for customer domains (domain table, domain_name -> code).
// Cache: APCu when loaded, else a small file cache. Misses are cached too.
final class DwDomain
{
    /** The app/API host (DwBrand::appHost(), app.dwalliance.com on the hosted service). */
    public static function appHost(): string
    {
        return DwBrand::appHost();
    }
    private const TTL = 60;
    private static array $memo = [];

    // Host header only. The edge sets it; clients cannot override it on app.dwalliance.com.
    public static function requestHost(): string
    {
        $h = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $h = (string)preg_replace('/:\d+$/', '', $h);
        return rtrim($h, '.');
    }

    // True for hosts that keep the /BRAND/... routing: the app host, empty, IPs, and internal names.
    public static function isAppHost(string $host): bool
    {
        if ($host === '' || $host === self::appHost() || $host === 'localhost') {
            return true;
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return substr($host, -4) === '.lan';
    }

    public static function validHost(string $host): bool
    {
        return strlen($host) <= 253
            && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
    }

    // Returns the brand code for a host, or null when the host is not a claimed domain.
    public static function brandForHost(string $host, callable $pdo): ?string
    {
        if (!self::validHost($host)) {
            return null;
        }
        if (array_key_exists($host, self::$memo)) {
            return self::$memo[$host];
        }
        $key = 'dwdomain:' . $host;
        $hit = self::cacheGet($key);
        if ($hit !== null) {
            return self::$memo[$host] = ($hit === '' ? null : $hit);
        }
        $q = $pdo()->prepare('SELECT d.code FROM domain d JOIN brand b ON b.code = d.code WHERE d.domain_name = ? AND d.code IS NOT NULL LIMIT 1');
        $q->execute([$host]);
        $code = $q->fetchColumn();
        $code = is_string($code) && preg_match('/^[A-Za-z0-9]{1,10}$/', $code) ? $code : null;
        self::cacheSet($key, $code ?? '');
        return self::$memo[$host] = $code;
    }

    private static function cacheFile(string $key): string
    {
        return sys_get_temp_dir() . '/dw-domain-cache/' . sha1($key);
    }

    private static function cacheGet(string $key): ?string
    {
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $ok = false;
            $v = apcu_fetch($key, $ok);
            return $ok && is_string($v) ? $v : null;
        }
        $f = self::cacheFile($key);
        $t = @filemtime($f);
        if ($t === false || $t < time() - self::TTL) {
            return null;
        }
        $v = @file_get_contents($f);
        return is_string($v) ? $v : null;
    }

    private static function cacheSet(string $key, string $value): void
    {
        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            apcu_store($key, $value, self::TTL);
            return;
        }
        $f = self::cacheFile($key);
        $d = dirname($f);
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);
        }
        $tmp = $f . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $value) !== false) {
            @rename($tmp, $f);
        } else {
            @unlink($tmp);
        }
    }
}
