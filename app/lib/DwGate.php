<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// Paid add-on gates (2026-11-15). A brand may use a gated admin feature when it has an active grant for it in
// brand_entitlement (paid subscription, or a staff grant). Gates are OFF unless store.json turns them on:
//   "entitlements": {"gates": {"store": {"enforce": true, "grace_days": 0, "grace_starts": null}, "watermark": {...}}}
// grace_days > 0 with grace_starts (ISO 8601 with offset, e.g. "2026-11-20T00:00:00-10:00") lets brands without the
// add-on keep using it, with a notice, until grace_starts + grace_days. Default: no grace. No email is ever sent here.
// The DW Alliance LLC seller brand is never gated. Only admin use is gated; shoppers and paid-order fulfilment are not.
require_once '/var/www/html/lib/DwDomain.php';

final class DwGate
{
    public const STORE_CONFIG = '/var/lib/app/data/store.json';
    // feature => [label, product slugs that grant it on the DW Alliance LLC store (live slug first)]
    public const FEATURES = [
        'store' => ['label' => 'Shopping cart', 'slugs' => ['shopping-cart', 'shopping-cart-test']],
        'watermark' => ['label' => 'Watermarks', 'slugs' => ['watermarks', 'watermarks-test']],
        'affiliate' => ['label' => 'Affiliate program', 'slugs' => ['affiliate-program', 'affiliate-program-test']],
    ];
    public const ENTITLEMENTS = ['no_banner', 'store', 'watermark', 'affiliate'];

    private static ?array $cfg = null;
    private static array $memo = [];

    public static function reset(): void
    {
        self::$cfg = null;
        self::$memo = [];
    }

    private static function ent(): array
    {
        if (self::$cfg === null) {
            $raw = @file_get_contents(self::STORE_CONFIG);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            self::$cfg = is_array($j['entitlements'] ?? null) ? $j['entitlements'] : [];
        }
        return self::$cfg;
    }

    public static function config(string $feature): array
    {
        // Affiliate is gated by DwAffiliate.php + /var/lib/app/data/affiliate-gate.json, not by DwGate.
        // Keep FEATURES['affiliate'] for catalog/offer consistency, but never enforce it here (no double-gate).
        if ($feature === 'affiliate') {
            return ['enforce' => false, 'grace_days' => 0, 'grace_starts' => null, 'free_count' => 0, 'price' => null];
        }
        $g = (array)((self::ent()['gates'] ?? [])[$feature] ?? []);
        $starts = null;
        if (is_string($g['grace_starts'] ?? null) && $g['grace_starts'] !== '') {
            try {
                $starts = new DateTimeImmutable($g['grace_starts']);
            } catch (Throwable $e) {
                $starts = null;
            }
        }
        $price = self::configPrice($g['price'] ?? null);
        $free = max(0, min(10000, (int)($g['free_count'] ?? 0)));
        return ['enforce' => ($g['enforce'] ?? false) === true, 'grace_days' => max(0, min(90, (int)($g['grace_days'] ?? 0))), 'grace_starts' => $starts,
            'free_count' => $free, 'price' => $price];
    }

    // Optional per-gate list price in store.json (e.g. "10.00"). Additive; existing callers ignore unknown keys.
    private static function configPrice(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
            return number_format((float)$v, 2, '.', '');
        }
        return null;
    }

    public static function sellerDomain(): string
    {
        return (string)(self::ent()['seller_domain'] ?? DwBrand::domain());
    }

    public static function isSeller(PDO $db, string $brand): bool
    {
        $s = DwDomain::brandForHost(self::sellerDomain(), fn() => $db);
        return $s !== null && $s !== '' && hash_equals($s, $brand);
    }

    // Active grant (paid, in its grace days, cancelled but paid through, or a staff grant) for this brand.
    public static function grant(PDO $db, string $brand, string $ent): ?array
    {
        $q = $db->prepare("SELECT id, status, paid_through, grace_until, provider_status FROM brand_entitlement
            WHERE brand = ? AND entitlement = ? AND status IN ('ACTIVE','CANCELLED') AND starts_at <= NOW() AND (grace_until IS NULL OR grace_until > NOW())
            ORDER BY grace_until IS NULL DESC, grace_until DESC LIMIT 1");
        $q->execute([$brand, $ent]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public static function has(PDO $db, string $brand, string $ent): bool
    {
        return self::isSeller($db, $brand) || self::grant($db, $brand, $ent) !== null;
    }

    // Price and a prefilled link into the self-serve subscribe flow for this brand's site.
    public static function offer(PDO $db, string $brand, string $feature): array
    {
        $f = self::FEATURES[$feature];
        $seller = DwDomain::brandForHost(self::sellerDomain(), fn() => $db) ?? '';
        $in = implode(',', array_fill(0, count($f['slugs']), '?'));
        $q = $db->prepare("SELECT p.slug, p.name, COALESCE(v.price, p.price) AS price FROM product p JOIN product_variant v ON v.product_id = p.id
            WHERE p.brand = ? AND p.active = 1 AND p.slug IN ($in) AND FIND_IN_SET(?, p.entitlements) AND v.billing_interval = 'MONTH' AND v.paypal_plan_id LIKE 'P-%'
            ORDER BY FIELD(p.slug, $in), v.position LIMIT 1");
        $q->execute(array_merge([$seller], $f['slugs'], [$feature], $f['slugs']));
        $p = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        $d = $db->prepare(DwBrand::t("SELECT domain_name FROM domain WHERE code = ? ORDER BY domain_name LIKE 'www.%', domain_name LIKE '%.dwalliance.com', domain_name LIMIT 1"));
        $d->execute([$brand]);
        $site = (string)($d->fetchColumn() ?: '');
        $site = strncmp($site, 'www.', 4) === 0 ? substr($site, 4) : $site;
        $slug = $p ? preg_replace('/-test$/', '', (string)$p['slug']) : $f['slugs'][0];
        $url = 'https://' . self::sellerDomain() . '/store/subscribe/?plan=' . rawurlencode($slug) . '&billing=monthly' . ($site !== '' ? '&site=' . rawurlencode($site) : '');
        $cfg = self::config($feature);
        $price = $cfg['price'] ?? ($p['price'] ?? null);
        $free = (int)$cfg['free_count'];
        $message = $free > 0 && $price !== null
            ? ('First ' . $free . ' watermarked files free; then $' . $price . '/month')
            : null;
        return ['feature' => $feature, 'name' => $p['name'] ?? $f['label'], 'price' => $price, 'interval' => 'month', 'currency' => 'USD',
            'site' => $site !== '' ? $site : null, 'subscribe_url' => $url, 'free_count' => $free, 'message' => $message];
    }

    // Gate decision for a brand. state: seller | subscribed | not_enforced | grace | required.
    public static function check(PDO $db, string $brand, string $feature): array
    {
        $k = $brand . '|' . $feature;
        if (isset(self::$memo[$k])) {
            return self::$memo[$k];
        }
        if (!isset(self::FEATURES[$feature])) {
            throw new InvalidArgumentException('unknown gated feature');
        }
        $cfg = self::config($feature);
        $out = ['feature' => $feature, 'allowed' => true, 'state' => 'subscribed', 'enforced' => $cfg['enforce'], 'grant_until' => null, 'grace_ends' => null, 'notice' => null, 'offer' => null];
        if (self::isSeller($db, $brand)) {
            return self::$memo[$k] = ['state' => 'seller'] + $out;
        }
        $g = self::grant($db, $brand, $feature);
        if ($g !== null) {
            return self::$memo[$k] = ['grant_until' => $g['grace_until']] + $out;
        }
        $offer = self::offer($db, $brand, $feature);
        $price = $offer['price'] !== null ? '$' . $offer['price'] . '/month' : 'a monthly add-on';
        $base = ($offer['message'] ?? ($offer['name'] . ' is a paid add-on (' . $price . ')')) . '. Subscribe at ' . $offer['subscribe_url'];
        if (!$cfg['enforce']) {
            return self::$memo[$k] = ['state' => 'not_enforced', 'offer' => $offer] + $out;
        }
        if ($cfg['grace_days'] > 0 && $cfg['grace_starts'] !== null) {
            $ends = $cfg['grace_starts']->modify('+' . $cfg['grace_days'] . ' days');
            if (new DateTimeImmutable('now') < $ends) {
                return self::$memo[$k] = ['state' => 'grace', 'grace_ends' => $ends->format(DATE_ATOM), 'offer' => $offer,
                    'notice' => $base . ' to keep using it after ' . $ends->format('F j, Y') . '.'] + $out;
            }
        }
        return self::$memo[$k] = ['allowed' => false, 'state' => 'required', 'offer' => $offer, 'notice' => $base . '.'] + $out;
    }

    // JSON body for a refused admin call (HTTP 402).
    public static function denyBody(array $c): array
    {
        return ['ok' => false, 'error' => $c['notice'] ?? 'this needs a paid add-on', 'gate' => $c];
    }
}
