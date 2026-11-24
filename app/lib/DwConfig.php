<?php
declare(strict_types=1);

/**
 * Site configuration (refs #79). One place for values that differ between installs:
 * database and mail hosts, trusted proxies, branding, alert addresses.
 *
 * Lookup order for a key such as DB_HOST:
 *   1. the environment variable DW_DB_HOST (php-fpm env[] or the shell), when set and not empty;
 *   2. lib/dw_config.php, a PHP file that returns [KEY => value];
 *   3. the fallback passed by the caller.
 *
 * The hosted service keeps its production values in lib/dw_config.php. A self-host release
 * ships a different lib/dw_config.php (selfhost/config/dw_config.php); every other file is the same.
 */
final class DwConfig
{
    /** @var ?array<string,mixed> */
    private static ?array $file = null;

    /** Test hook: replace the file values (null = read lib/dw_config.php again). */
    public static function setForTest(?array $values): void
    {
        self::$file = $values;
    }

    public static function get(string $key, string $fallback = ''): string
    {
        $env = getenv('DW_' . $key);
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $v = self::fileValues()[$key] ?? null;
        if (is_array($v)) {
            return implode(',', array_map('strval', $v));
        }
        return $v === null ? $fallback : (string)$v;
    }

    /** Comma separated value as a list of trimmed, non-empty strings. */
    public static function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', self::get($key))), fn($s) => $s !== ''));
    }

    /** @return array<string,mixed> */
    private static function fileValues(): array
    {
        if (self::$file === null) {
            $f = __DIR__ . '/dw_config.php';
            $v = is_file($f) ? (include $f) : [];
            self::$file = is_array($v) ? $v : [];
        }
        return self::$file;
    }
}
