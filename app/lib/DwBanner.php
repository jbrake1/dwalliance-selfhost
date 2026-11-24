<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// Server-injected DW promo banner on free sites (2026-11-14). Design: docs/banner/PROPOSAL.md.
// 404.php calls DwBanner::applies() for every HTML file it serves and, when true, DwBanner::insert()
// adds the banner before the last </body> (else before the last </html>, else at the end). The banner
// is never stored in the owner's files, so /files edits cannot remove it.
//
// Tenant on/off and the default style live in the database (refs #45), edited by DW staff through
// /store/banner/ (not by direct SQL):
//   - brand.banner_mode  ENUM('default','on','off') — per-brand show/hide
//   - brand.banner_style ENUM('default','A','B','C') — staff style when the owner has not picked one
//   - app_setting banner_default (on|off) and banner_style (A|B|C) — global defaults
// Precedence for show/hide: DW's own sites and checkout pages are always skipped; an active
// no_banner entitlement always hides it; then the brand's mode; then the global default.
// Style: owner domain_setting.banner_style, else brand.banner_style, else global, else A.
// All three styles are position:fixed at the bottom of the viewport (refs #47), with no
// dismiss control. --dw-credit-pad + a ResizeObserver reserve body padding so tenant
// footers are never covered.
//
// banner.json is kept for the transition and as infrastructure:
//   - explicit "enabled": false is an emergency kill switch (everything off, old serving path)
//   - exempt_hosts, skip_path_regex, seller_domain still come from the file (with the same defaults)
//   - enabled / only_hosts are the tenant fallback ONLY until staff have written app_setting
//     banner_default through the app. After that, the DB is authoritative.
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';

final class DwBanner
{
    public const CONFIG = '/var/lib/app/data/banner.json';
    public const MARKER = '<!-- dw-credit:server v2 -->';
    public const LINK = 'https://dwalliance.com/'; // the platform site; DwBrand::t() maps it to the configured domain
    public const STYLES = ['A', 'B', 'C'];
    public const SETTING_STYLE = 'banner_style';
    public const APP_DEFAULT = 'banner_default';
    public const APP_STYLE = 'banner_style';
    private const DEFAULTS = [
        'enabled' => false,
        'version' => 1,
        'exempt_hosts' => ['dwalliance.com', 'www.dwalliance.com'],
        // Fallback only, when no app_setting banner_default row exists yet.
        'only_hosts' => [],
        'skip_path_regex' => '~(^|/)checkout(\.html?|/|$)~i',
        'seller_domain' => 'dwalliance.com',
    ];
    private static ?array $cfg = null;
    /** @var array<string,mixed>|null CLI test hook; ignored unless PHP_SAPI is cli. */
    public static ?array $testDb = null;
    public static ?bool $testUseDbConfig = null;

    public static function resetCache(): void
    {
        self::$cfg = null;
        self::$testDb = null;
        self::$testUseDbConfig = null;
    }

    public static function cfg(): array
    {
        if (self::$cfg === null) {
            $raw = @file_get_contents(self::CONFIG);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            $defaults = self::DEFAULTS;
            $defaults['exempt_hosts'] = array_map([DwBrand::class, 't'], $defaults['exempt_hosts']);
            $defaults['seller_domain'] = DwBrand::t($defaults['seller_domain']);
            self::$cfg = is_array($j) ? array_replace($defaults, $j) : $defaults;
            self::$cfg['_present'] = is_array($j);
            if (!is_array($j)) {
                self::$cfg['enabled'] = false;
            }
        }
        return self::$cfg;
    }

    /** True when banner.json exists and sets "enabled": false. Emergency kill switch. */
    public static function killed(): bool
    {
        $c = self::cfg();
        return !empty($c['_present']) && $c['enabled'] === false;
    }

    /**
     * The banner system is active: not killed, and either the DB config has been written
     * or banner.json enabled is true (transition fallback).
     */
    public static function enabled(): bool
    {
        if (self::killed()) {
            return false;
        }
        if (self::usesDbConfig()) {
            return true;
        }
        return self::cfg()['enabled'] === true;
    }

    public static function bare(string $host): string
    {
        $h = strtolower(rtrim($host, '.'));
        return strncmp($h, 'www.', 4) === 0 ? substr($h, 4) : $h;
    }

    /** Pure show/hide after skips. Entitlement always wins; else brand mode; else the global default. */
    public static function wanted(string $brandMode, string $globalDefault, bool $hasEntitlement): bool
    {
        if ($hasEntitlement) {
            return false;
        }
        $mode = in_array($brandMode, ['on', 'off'], true) ? $brandMode : 'default';
        $g = $globalDefault === 'on' ? 'on' : 'off';
        return ($mode === 'default' ? $g : $mode) === 'on';
    }

    /** Style: owner domain, then brand (if not default), then global, else A. */
    public static function pickStyle(?string $domainStyle, ?string $brandStyle, ?string $globalStyle): string
    {
        foreach ([$domainStyle, $brandStyle, $globalStyle] as $i => $s) {
            $v = strtoupper(trim((string)$s));
            if ($i === 1 && strtolower(trim((string)$s)) === 'default') {
                continue;
            }
            if (in_array($v, self::STYLES, true)) {
                return $v;
            }
        }
        return 'A';
    }

    /** Should this HTML response carry the banner? $path is the request path ("/store/checkout.html"). */
    public static function applies(string $host, string $brand, bool $appHost, string $path): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($m !== 'GET' && $m !== 'HEAD') {
            return false;
        }
        if (strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')) === 'empty') {
            return false; // fetch/XHR of an HTML fragment by the page's own script
        }
        $c = self::cfg();
        $re = (string)$c['skip_path_regex'];
        if ($re !== '' && @preg_match($re, $path) === 1) {
            return false;
        }
        if (!$appHost && in_array(self::bare($host), array_map([self::class, 'bare'], (array)$c['exempt_hosts']), true)) {
            return false;
        }
        $dbReady = self::usesDbConfig();
        if (!$dbReady) {
            // Transition fallback: only_hosts is checked before any DB lookup so a listed-out
            // site never fail-opens.
            $only = array_values(array_filter(array_map([self::class, 'bare'], (array)($c['only_hosts'] ?? [])), fn($h) => $h !== ''));
            if ($only !== [] && !in_array(self::bare($host), $only, true)) {
                return false;
            }
        }
        try {
            $seller = self::sellerBrand();
            if ($seller !== null && hash_equals($seller, $brand)) {
                return false; // DW Alliance LLC's own sites and previews
            }
            $db = self::pdo();
            $entitled = $db !== null ? self::hasNoBanner($db, $brand, $appHost ? null : self::bare($host)) : self::testEntitled($brand, $appHost ? null : self::bare($host));
            if ($dbReady) {
                return self::wanted(self::brandMode($brand), self::globalDefault(), $entitled);
            }
            return !$entitled;
        } catch (Throwable $e) {
            if ($dbReady) {
                // Default is off; a lookup error must not banner every tenant.
                error_log('DwBanner lookup failed, hiding banner: ' . get_class($e));
                return false;
            }
            error_log('DwBanner lookup failed, showing banner: ' . get_class($e));
            return true; // fallback fail-open: SHOW (previous behaviour when banner.json had enabled the site)
        }
    }

    /**
     * True when a no_banner entitlement is in force for this brand and site (bare host, www twin included).
     * $bareHost null (app.dwalliance.com preview path) = any in-force no_banner on the brand.
     */
    public static function hasNoBanner(PDO $db, string $brand, ?string $bareHost): bool
    {
        $sql = "SELECT 1 FROM brand_entitlement be
                WHERE be.brand = ? AND be.entitlement = 'no_banner' AND be.status IN ('ACTIVE','CANCELLED')
                  AND be.starts_at <= NOW() AND (be.grace_until IS NULL OR be.grace_until > NOW())";
        $args = [$brand];
        if ($bareHost !== null) {
            $sql .= ' AND (be.domain_id IS NULL OR be.domain_id IN (SELECT d.id FROM domain d WHERE d.code = ? AND d.domain_name IN (?, ?)))';
            array_push($args, $brand, $bareHost, 'www.' . $bareHost);
        }
        $q = $db->prepare($sql . ' LIMIT 1');
        $q->execute($args);
        return (bool)$q->fetchColumn();
    }

    /**
     * Effective style for this site. Owner domain_setting.banner_style if set, else the brand
     * column if not default, else app_setting banner_style, else A. Any error = A.
     */
    public static function styleFor(PDO $db, string $brand, ?string $bareHost): string
    {
        try {
            $domain = null;
            if ($bareHost !== null) {
                $q = $db->prepare("SELECT ds.setting_value FROM domain_setting ds JOIN domain d ON d.id = ds.domain_id
                    WHERE d.code = ? AND d.domain_name IN (?, ?) AND ds.setting_name = ? ORDER BY d.domain_name = ? DESC LIMIT 1");
                $q->execute([$brand, $bareHost, 'www.' . $bareHost, self::SETTING_STYLE, $bareHost]);
            } else {
                $q = $db->prepare("SELECT ds.setting_value FROM domain_setting ds JOIN domain d ON d.id = ds.domain_id
                    WHERE d.code = ? AND ds.setting_name = ? ORDER BY d.domain_name LIMIT 1");
                $q->execute([$brand, self::SETTING_STYLE]);
            }
            $domain = strtoupper(trim((string)$q->fetchColumn()));
            $brandStyle = self::brandStyle($db, $brand);
            $global = self::globalStyle($db);
            return self::pickStyle($domain !== '' ? $domain : null, $brandStyle, $global);
        } catch (Throwable $e) {
            return 'A';
        }
    }

    /** True when staff have written app_setting banner_default (DB is the tenant source of truth). */
    public static function usesDbConfig(): bool
    {
        if (self::$testUseDbConfig !== null && PHP_SAPI === 'cli') {
            return self::$testUseDbConfig;
        }
        if (PHP_SAPI === 'cli' && is_array(self::$testDb) && array_key_exists('global_default', self::$testDb)) {
            return true;
        }
        try {
            $db = Dw::pdo();
            $q = $db->query("SELECT 1 FROM app_setting WHERE setting_name = 'banner_default' LIMIT 1");
            return (bool)$q->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function hook(): ?array
    {
        return PHP_SAPI === 'cli' && is_array(self::$testDb) ? self::$testDb : null;
    }

    private static function pdo(): ?PDO
    {
        return self::hook() !== null ? null : Dw::pdo();
    }

    public static function sellerBrand(): ?string
    {
        $h = self::hook();
        if ($h !== null) {
            $s = $h['seller'] ?? null;
            return is_string($s) && $s !== '' ? $s : null;
        }
        $c = self::cfg();
        return DwDomain::brandForHost((string)$c['seller_domain'], [Dw::class, 'pdo']);
    }

    private static function testEntitled(string $brand, ?string $bareHost): bool
    {
        $h = self::hook();
        if ($h === null) {
            return false;
        }
        $ent = $h['entitled'] ?? [];
        return !empty($ent[$brand]) || ($bareHost !== null && !empty($ent[$bareHost]));
    }

    public static function brandMode(string $brand): string
    {
        $h = self::hook();
        if ($h !== null) {
            $m = strtolower((string)(($h['modes'][$brand] ?? 'default')));
            return in_array($m, ['default', 'on', 'off'], true) ? $m : 'default';
        }
        try {
            $q = Dw::pdo()->prepare('SELECT banner_mode FROM brand WHERE code = ? LIMIT 1');
            $q->execute([$brand]);
            $m = strtolower(trim((string)$q->fetchColumn()));
            return in_array($m, ['default', 'on', 'off'], true) ? $m : 'default';
        } catch (Throwable $e) {
            return 'default';
        }
    }

    public static function globalDefault(): string
    {
        $h = self::hook();
        if ($h !== null) {
            return (($h['global_default'] ?? 'off') === 'on') ? 'on' : 'off';
        }
        try {
            $q = Dw::pdo()->prepare("SELECT setting_value FROM app_setting WHERE setting_name = ? LIMIT 1");
            $q->execute([self::APP_DEFAULT]);
            $v = strtolower(trim((string)$q->fetchColumn()));
            return $v === 'on' ? 'on' : 'off';
        } catch (Throwable $e) {
            return 'off';
        }
    }

    private static function brandStyle(PDO $db, string $brand): ?string
    {
        $h = self::hook();
        if ($h !== null) {
            $s = $h['styles'][$brand] ?? null;
            return is_string($s) ? $s : null;
        }
        $q = $db->prepare('SELECT banner_style FROM brand WHERE code = ? LIMIT 1');
        $q->execute([$brand]);
        $v = trim((string)$q->fetchColumn());
        return $v !== '' ? $v : null;
    }

    private static function globalStyle(PDO $db): ?string
    {
        $h = self::hook();
        if ($h !== null) {
            $s = $h['global_style'] ?? null;
            return is_string($s) ? $s : null;
        }
        $q = $db->prepare('SELECT setting_value FROM app_setting WHERE setting_name = ? LIMIT 1');
        $q->execute([self::APP_STYLE]);
        $v = strtoupper(trim((string)$q->fetchColumn()));
        return $v !== '' ? $v : null;
    }

    /**
     * The banner markup. Closed declarative shadow DOM, random element name per response,
     * inline !important on the host, fixed marker comment.
     * All three styles are position:fixed at the bottom of the viewport (refs #47) and have no close
     * or dismiss control. They stay on screen while the visitor scrolls, on mobile and desktop,
     * and include env(safe-area-inset-bottom).
     *   A: full-width promo bar (default).
     *   B: slimmer full-width bar.
     *   C: corner badge (bottom-right on desktop, a compact pill bottom-left on phones).
     * On viewports under 600px, all three styles are a single compact row about 56–72px tall
     * (plus the safe-area inset): the DW mark, a short headline, and a tappable CTA (min 44px).
     * The subline is hidden. Desktop styles are unchanged.
     * Body padding: --dw-credit-pad on html, applied as padding-bottom on body. A short inline
     * script uses ResizeObserver on the host to keep that padding equal to the visible bar (from
     * the top of the banner to the bottom of the viewport, so style C's gap is included). If the
     * script does not run, a generous style-specific default still reserves space.
     */
    public static function html(string $style = 'A'): string
    {
        $style = in_array($style, self::STYLES, true) ? $style : 'A';
        $tag = 'dw-' . substr(str_shuffle('abcdefghijkmnpqrstuvwxyz'), 0, 1) . bin2hex(random_bytes(3));
        $link = DwBrand::t(self::LINK);
        // Light-DOM host styles resist tenant CSS. position:fixed overrides the all:initial reset.
        // The host itself is the fixed box (so ResizeObserver can measure it). Inner pieces stay
        // in-flow; otherwise the host would collapse to 0 height and body padding would be wrong.
        $base = 'all:initial!important;display:block!important;visibility:visible!important;opacity:1!important;'
            . 'transform:none!important;clip-path:none!important;filter:none!important;margin:0!important;'
            . 'position:fixed!important;z-index:2147483000!important;box-sizing:border-box!important;';
        $font = "font-family:system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
        $common = ':host{all:initial}*{box-sizing:border-box}a{color:inherit}'
            . '.m{display:inline-flex;align-items:center;justify-content:center;flex:none;border-radius:50%;background:#1a7f4b;color:#fff;font-weight:800;letter-spacing:.02em}'
            . '.cta{display:inline-flex;align-items:center;gap:.4em;flex:none;background:#f4c95d;color:#111;font-weight:700;text-decoration:none;border-radius:999px;white-space:nowrap}'
            . '.cta:hover,.cta:focus-visible{background:#ffd978}a:focus-visible{outline:3px solid #8fd3ff;outline-offset:2px}';
        if ($style === 'B') {
            $pad = 55;
            $padSm = 72;
            $host = $base . 'left:0!important;right:0!important;bottom:0!important;width:100%!important;height:auto!important;';
            $hostCss = '';
            $css = $common
                . '.bar{' . $font . 'position:relative;left:0;right:0;bottom:0;background:#14201a;color:#fff;border-top:3px solid #1a7f4b;box-shadow:0 -2px 10px rgba(0,0,0,.18);'
                . 'min-height:52px;padding:6px 16px calc(6px + env(safe-area-inset-bottom, 0px));display:flex;align-items:center;justify-content:center;gap:14px}'
                . '.m{width:30px;height:30px;font-size:13px}.h{font-size:15px;font-weight:650;line-height:1.3;margin:0}.h b{color:#f4c95d;font-weight:750}'
                . '.cta{font-size:14px;padding:8px 16px;min-height:36px}.short{display:none}'
                . '@media (max-width:600px){.bar{min-height:56px;padding:6px 12px calc(6px + env(safe-area-inset-bottom, 0px));gap:8px}'
                . '.long{display:none}.short{display:inline}.m{width:32px;height:32px;font-size:13px}'
                . '.h{font-size:13px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
                . '.cta{font-size:13px;padding:0 12px;min-height:44px;min-width:44px}}';
            $inner = '<div class="bar"><span class="m" aria-hidden="true">DW</span><p class="h"><span class="long">Free websites by <b>DW Alliance LLC</b> &mdash; for businesses and developers</span>'
                . '<span class="short">Free website by <b>DW Alliance LLC</b></span></p><a class="cta" href="' . $link . '">Get yours free</a></div>';
        } elseif ($style === 'C') {
            $pad = 88;
            $padSm = 80;
            $host = $base . 'width:auto!important;height:auto!important;max-width:calc(100vw - 32px)!important;';
            $hostCss = $tag . '{right:16px!important;left:auto!important;bottom:calc(16px + env(safe-area-inset-bottom, 0px))!important}'
                . '@media (max-width:600px){' . $tag . '{right:auto!important;left:10px!important;bottom:calc(10px + env(safe-area-inset-bottom, 0px))!important;max-width:calc(100vw - 20px)!important}}';
            $css = $common
                . ':host{width:auto}'
                . '.b{' . $font . 'position:relative;display:flex;align-items:center;gap:10px;background:#14201a;color:#fff;text-decoration:none;'
                . 'padding:8px 16px 8px 8px;border-radius:999px;box-shadow:0 4px 16px rgba(0,0,0,.25);border:2px solid #1a7f4b;font-size:14px;font-weight:650;line-height:1.2;max-width:calc(100vw - 32px)}'
                . '.m{width:34px;height:34px;font-size:14px}.b b{color:#f4c95d}.b small{display:block;font-size:12px;font-weight:500;color:#c4d0c7}.short{display:none}'
                . '@media (max-width:600px){.b{gap:8px;padding:8px 14px 8px 8px;font-size:13px;border-width:1px;min-height:56px;min-width:44px}'
                . '.m{width:32px;height:32px;font-size:13px}.b small,.long{display:none}.short{display:inline}}';
            $inner = '<a class="b" href="' . $link . '"><span class="m" aria-hidden="true">DW</span><span><span class="long">Free website by <b>DW Alliance LLC</b>'
                . '<small>For businesses and developers</small></span><span class="short">Free site by <b>DW Alliance LLC</b></span></span></a>';
        } else {
            $pad = 120;
            $padSm = 72;
            $host = $base . 'left:0!important;right:0!important;bottom:0!important;width:100%!important;height:auto!important;';
            $hostCss = '';
            $css = $common
                . '.m{width:48px;height:48px;font-size:20px}'
                . '.bar{' . $font . 'position:relative;left:0;right:0;bottom:0;background:#14201a;color:#e9efe9;padding:22px 24px calc(22px + env(safe-area-inset-bottom, 0px));border-top:4px solid #1a7f4b;box-shadow:0 -2px 12px rgba(0,0,0,.2)}'
                . '.in{max-width:1080px;margin:0 auto;display:flex;align-items:center;gap:18px}'
                . '.t{flex:1;min-width:0}.h{font-size:19px;font-weight:750;line-height:1.3;color:#fff;margin:0 0 4px}'
                . '.s{font-size:15px;line-height:1.45;color:#c4d0c7;margin:0}'
                . '.cta{font-size:15px;padding:12px 20px;min-height:44px}.short{display:none}'
                . '@media (max-width:600px){.bar{padding:6px 12px calc(6px + env(safe-area-inset-bottom, 0px));border-top-width:3px}'
                . '.in{flex-direction:row;text-align:left;gap:8px;align-items:center}'
                . '.m{width:32px;height:32px;font-size:13px}.s,.long{display:none}.short{display:inline}'
                . '.h{font-size:13px;font-weight:700;line-height:1.2;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
                . '.cta{width:auto;min-height:44px;min-width:44px;font-size:13px;padding:0 12px;justify-content:center}}';
            $inner = '<div class="bar"><div class="in"><span class="m" aria-hidden="true">DW</span><div class="t">'
                . '<p class="h"><span class="long">Free websites by DW Alliance LLC</span>'
                . '<span class="short">Free website by DW Alliance LLC</span></p>'
                . '<p class="s">For businesses and developers. Your own domain and HTTPS, built with your favorite AI agent.</p></div>'
                . '<a class="cta" href="' . $link . '"><span class="long">Get your free website <span aria-hidden="true">&rarr;</span></span>'
                . '<span class="short">Get yours</span></a></div></div>';
        }
        $fallback = DwBrand::t('Free websites by <a href="' . $link . '">DW Alliance LLC</a> &mdash; for businesses and developers');
        $inner = DwBrand::t($inner);
        if (DwBrand::mark() !== 'DW') {
            $inner = str_replace('<span class="m" aria-hidden="true">DW</span>', '<span class="m" aria-hidden="true">' . htmlspecialchars(DwBrand::mark(), ENT_QUOTES) . '</span>', $inner);
        }
        $padCss = $hostCss
            . 'html{--dw-credit-pad:calc(' . $pad . 'px + env(safe-area-inset-bottom, 0px))}'
            . '@media (max-width:600px){html{--dw-credit-pad:calc(' . $padSm . 'px + env(safe-area-inset-bottom, 0px))}}'
            . 'body{padding-bottom:var(--dw-credit-pad)!important;box-sizing:border-box}';
        // Measure the visible host (top of the bar/badge to the bottom of the viewport) so body
        // padding matches, including style C's gap above the safe area.
        $padJs = '(function(){var el=document.getElementsByTagName("' . $tag . '")[0];if(!el)return;'
            . 'function s(){var r=el.getBoundingClientRect();var h=Math.max(0,Math.ceil((window.innerHeight||0)-r.top));'
            . 'document.documentElement.style.setProperty("--dw-credit-pad",h+"px");}'
            . 's();if(window.ResizeObserver)new ResizeObserver(s).observe(el);'
            . 'window.addEventListener("resize",s,{passive:true});})();';
        return "\n" . self::MARKER . "\n<style id=\"dw-credit-pad\">" . $padCss . '</style>'
            . '<' . $tag . ' lang="en" style="' . $host . '"><template shadowrootmode="closed"><style>' . $css . '</style>'
            . $inner . '</template>' . $fallback . '</' . $tag . ">\n"
            . '<script>' . $padJs . '</script>' . "\n";
    }

        public static function insert(string $html, string $tags): string
    {
        foreach (['</body>', '</html>'] as $end) {
            $pos = strripos($html, $end);
            if ($pos !== false) {
                return substr_replace($html, $tags, $pos, 0);
            }
        }
        return $html . $tags;
    }
}
