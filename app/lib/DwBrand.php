<?php
declare(strict_types=1);

require_once __DIR__ . '/DwConfig.php';

/**
 * Platform branding from configuration (refs #79): name, legal name, short mark, domains,
 * support address and logo. Values come from DwConfig (env DW_<KEY> or lib/dw_config.php).
 *
 * Code and page text are written with the hosted service's own wording ("DW Alliance",
 * "app.dwalliance.com", ...). DwBrand::t() maps that wording to the configured brand, so with
 * the production configuration it returns its input unchanged. Use t() for text shown to
 * people; use the accessors for logic (host checks, addresses, links).
 */
final class DwBrand
{
    // The hosted service's wording, as written in the code. Keys of t()'s map.
    private const BASE_NAME = 'DW Alliance';
    private const BASE_LEGAL = 'DW Alliance LLC';
    private const BASE_DOMAIN = 'dwalliance.com';
    private const BASE_APP = 'app.dwalliance.com';
    private const BASE_SUPPORT = 'support@dwalliance.com';
    private const BASE_LOGO = 'https://dwalliance.com/assets/email/dw-logo.png';

    private static ?array $map = null;

    // Values are inserted into HTML, JavaScript strings, mail headers and SQL LIKE patterns, so each
    // one is limited to characters that are safe in all of them; anything else falls back.
    private static function text(string $key, string $fallback): string
    {
        // Characters outside the safe set are dropped (so "Joe's" becomes "Joes"), never replaced by the default.
        $v = trim((string)preg_replace(['/[^\p{L}\p{N} .,&()-]+/u', '/\s+/u'], ['', ' '], DwConfig::get($key, $fallback)));
        $v = mb_substr($v, 0, 80);
        return $v !== '' ? $v : $fallback;
    }

    private static function host(string $key, string $fallback): string
    {
        $v = strtolower(trim(DwConfig::get($key, $fallback)));
        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $v) === 1 ? $v : $fallback;
    }

    public static function name(): string
    {
        return self::text('BRAND_NAME', self::BASE_NAME);
    }

    public static function legalName(): string
    {
        return self::text('BRAND_LEGAL_NAME', self::name());
    }

    /** Short mark shown in the promo banner badge (two or three letters). */
    public static function mark(): string
    {
        $v = trim(DwConfig::get('BRAND_MARK', 'DW'));
        return preg_match('/^[\p{L}\p{N}]{1,3}$/u', $v) === 1 ? $v : 'DW';
    }

    /** The platform's own marketing domain (bare, no www). */
    public static function domain(): string
    {
        return self::host('PLATFORM_DOMAIN', self::BASE_DOMAIN);
    }

    /** The app/API host. */
    public static function appHost(): string
    {
        return self::host('APP_HOST', 'app.' . self::domain());
    }

    public static function supportEmail(): string
    {
        $v = trim(DwConfig::get('SUPPORT_EMAIL', 'support@' . self::domain()));
        return filter_var($v, FILTER_VALIDATE_EMAIL) && preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$/', $v) === 1 ? $v : 'support@' . self::domain();
    }

    public static function logoUrl(): string
    {
        $v = trim(DwConfig::get('LOGO_URL', 'https://' . self::domain() . '/assets/email/dw-logo.png'));
        return preg_match('#^https://[A-Za-z0-9.-]+(/[A-Za-z0-9._~/-]*)?$#', $v) === 1 ? $v : 'https://' . self::domain() . '/assets/email/dw-logo.png';
    }

    /** True on the hosted service itself (platform domain dwalliance.com): pages may mention its own sites. */
    public static function isHostedService(): bool
    {
        return self::domain() === self::BASE_DOMAIN;
    }

    /** "Name <support@...>" for a From header. */
    public static function mailFrom(): string
    {
        return str_replace(["\r", "\n", '<', '>'], '', self::name()) . ' <' . self::supportEmail() . '>';
    }

    /** True for the platform domain, www.<domain> and any name under it (ns1., store., ...). */
    public static function isPlatformHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        $d = self::domain();
        return $host === $d || str_ends_with($host, '.' . $d);
    }

    /** Replace the hosted service's wording in $s with the configured brand. Identity in production. */
    public static function t(string $s): string
    {
        $map = self::map();
        return $map ? strtr($s, $map) : $s;
    }

    /** Same as t() but for a regex-quoted platform domain inside a pattern (dwalliance\.com). */
    public static function domainPattern(): string
    {
        return preg_quote(self::domain(), '/');
    }

    /** Start themed output for an HTML page: the buffer is passed through t() at the end. */
    public static function themeOutput(): void
    {
        if (self::map()) {
            ob_start([self::class, 't']);
        }
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$map = null;
    }

    private static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }
        $m = [
            self::BASE_LOGO => self::logoUrl(),
            self::BASE_SUPPORT => self::supportEmail(),
            self::BASE_APP => self::appHost(),
            self::BASE_LEGAL => self::legalName(),
            self::BASE_NAME => self::name(),
            self::BASE_DOMAIN => self::domain(),
            'dwalliance\\.com' => str_replace('.', '\\.', self::domain()),
        ];
        // strtr prefers the longest key, so "app.dwalliance.com" wins over "dwalliance.com".
        $m = array_filter($m, fn($v, $k) => $v !== $k, ARRAY_FILTER_USE_BOTH);
        return self::$map = $m;
    }
}
