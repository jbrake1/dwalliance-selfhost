<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';

// Names the platform runs on. /dns refuses to create, update, or delete them
// and /domain refuses to claim them, whatever brand or company asks.
final class DwGuard
{
    // zone => protected host labels (each one covers the name and every name under it)
    // and record types that are protected anywhere in the zone.
    // The zone is the platform domain (DwBrand::domain(), dwalliance.com on the hosted service).
    public const PROTECTED_RULE = [
        'names' => ['app', 'ns1', 'ns2', 'ns3', 'mail', 'store'],
        'types' => ['@' => 'MX', '.' => 'NS', '&' => 'NS', 'Z' => 'SOA'],
    ];

    /** @return array<string,array{names:list<string>,types:array<string,string>}> */
    public static function protectedZones(): array
    {
        return [DwBrand::domain() => self::PROTECTED_RULE];
    }

    private static function under(string $name, string $parent): bool
    {
        return $name === $parent || substr($name, -strlen('.' . $parent)) === '.' . $parent;
    }

    // Reason a record may not be changed, or null when it may.
    public static function dnsRefusal(string $fqdn, string $type, string $zone): ?string
    {
        $fqdn = strtolower(rtrim(trim($fqdn), '.'));
        $zone = strtolower(rtrim(trim($zone), '.'));
        if (substr($fqdn, 0, 2) === '*.') {
            $fqdn = substr($fqdn, 2);
        }
        foreach (self::protectedZones() as $pz => $rule) {
            if (!self::under($fqdn, $pz) && !self::under($zone, $pz)) {
                continue;
            }
            foreach ($rule['names'] as $label) {
                $host = $label . '.' . $pz;
                if (self::under($fqdn, $host)) {
                    return $host . ' is a protected platform name and cannot be changed through /dns';
                }
            }
            if (isset($rule['types'][$type])) {
                return $rule['types'][$type] . ' records on ' . $pz . ' are protected and cannot be changed through /dns';
            }
        }
        return null;
    }

    // Reason a name may not be claimed through /domain, or null when it may.
    public static function claimRefusal(string $name): ?string
    {
        $name = strtolower($name);
        foreach (self::protectedZones() as $pz => $rule) {
            foreach ($rule['names'] as $label) {
                $host = $label . '.' . $pz;
                if (self::under($name, $host)) {
                    return $host . ' is a protected platform name';
                }
            }
        }
        return null;
    }

    // Every protected host, for help text.
    public static function names(): array
    {
        $out = [];
        foreach (self::protectedZones() as $pz => $rule) {
            foreach ($rule['names'] as $label) {
                $out[] = $label . '.' . $pz;
            }
        }
        return $out;
    }
}
