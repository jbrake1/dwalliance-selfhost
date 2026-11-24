<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwGuard.php';

// Platform NS rows for a claimed zone (2026-11-04, staging).
// tinydns only answers for a zone that has '.' rows, so a claimed apex domain gets
// the platform name servers (ns1/ns2/ns3 of the platform domain), the same way existing customer zones have them.
// Rows go to crm.dns with a dns_history INSERT, the same columns /dns writes.
final class DwZone
{
    /** Platform name servers: ns1/ns2/ns3 of the platform domain. */
    public static function ns(): array
    {
        $d = DwBrand::domain();
        return ['ns1.' . $d, 'ns2.' . $d, 'ns3.' . $d];
    }
    public const TTL = 600;

    // Two-label public suffixes where the apex has three labels (example.co.uk).
    private const SECOND_LEVEL = [
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'ac.uk', 'gov.uk',
        'com.au', 'net.au', 'org.au', 'co.nz', 'org.nz', 'net.nz', 'co.jp', 'co.za', 'com.br', 'com.mx', 'co.in',
    ];

    // True when $name is a registrable apex (example.com, example.co.uk), not a subdomain.
    public static function isApex(string $name): bool
    {
        $labels = explode('.', strtolower($name));
        $n = count($labels);
        if ($n === 2) {
            return !in_array($name, self::SECOND_LEVEL, true);
        }
        return $n === 3 && in_array($labels[1] . '.' . $labels[2], self::SECOND_LEVEL, true);
    }

    // Zones the platform runs itself (dwalliance.com and anything under it) never get rows here.
    public static function isPlatform(string $name): bool
    {
        foreach (array_keys(DwGuard::protectedZones()) as $pz) {
            if ($name === $pz || substr($name, -strlen('.' . $pz)) === '.' . $pz) {
                return true;
            }
        }
        return false;
    }

    // Adds the three NS rows when the zone has no '.' rows at all (any brand).
    // Returns: added | present | skipped_subdomain | skipped_platform | busy
    public static function ensureNs(PDO $db, PDO $dns, string $zone, string $companyId, string $personId, string $brand): string
    {
        $zone = strtolower(rtrim(trim($zone), '.'));
        if (self::isPlatform($zone)) {
            return 'skipped_platform';
        }
        if (!self::isApex($zone)) {
            return 'skipped_subdomain';
        }
        $lock = 'dw_zone_ns_' . substr(sha1($zone), 0, 40);
        $got = $dns->prepare('SELECT GET_LOCK(?, 5)');
        $got->execute([$lock]);
        if ((int)$got->fetchColumn() !== 1) {
            return 'busy';
        }
        try {
            $has = $dns->prepare("SELECT COUNT(*) FROM dns WHERE LOWER(domain) = ? AND rr_type = '.'");
            $has->execute([$zone]);
            if ((int)$has->fetchColumn() > 0) {
                return 'present';
            }
            foreach (self::ns() as $ns) {
                if (DwGuard::dnsRefusal($zone, '.', $zone) !== null) {
                    return 'skipped_platform';
                }
            }
            $add = $dns->prepare('INSERT INTO dns (rr_type, rr_fqdn, rr_ip, rr_target, rr_mx_dist, rr_ttl, rr_timestamp, rr_email, rr_serial, rr_refresh, rr_retry, rr_expire, rr_minimum, company_id, domain, brand) VALUES (?, ?, ?, ?, 0, ?, ?, ?, 0, 0, 0, 0, 0, ?, ?, ?)');
            $added = [];
            $own = !$db->inTransaction();
            try {
                if ($own) {
                    $db->beginTransaction();
                }
                foreach (self::ns() as $ns) {
                    $row = self::row('.', $zone, $ns, $brand);
                    $add->execute(['.', $zone, '', $ns, self::TTL, $row['rr_timestamp'], '', $companyId, $zone, $brand]);
                    $row['rr_id'] = (int)$dns->lastInsertId();
                    $added[] = $row['rr_id'];
                    self::history($db, $row, $companyId, $personId, 'INSERT');
                }
                if ($own) {
                    $db->commit();
                }
            } catch (Throwable $e) {
                // Same rule as /dns create: no crm.dns row without its history row.
                if ($own && $db->inTransaction()) {
                    $db->rollBack();
                }
                $undo = $dns->prepare('DELETE FROM dns WHERE rr_id = ? AND brand = ?');
                foreach ($added as $id) {
                    $undo->execute([$id, $brand]);
                }
                throw $e;
            }
            return 'added';
        } finally {
            $dns->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }

    // On release: when the only rows left in the zone on this brand are the platform NS rows,
    // remove them (DELETE history first, as /dns delete does) so the claim can be released.
    // Any other row stays and the release is refused as before. Returns rows removed.
    public static function dropPlatformNs(PDO $db, PDO $dns, string $zone, string $companyId, string $personId, string $brand): int
    {
        $zone = strtolower($zone);
        $q = $dns->prepare('SELECT rr_id, rr_type, rr_fqdn, rr_ip, rr_target, rr_mx_dist, rr_ttl, rr_timestamp, rr_email, rr_serial, rr_refresh, rr_retry, rr_expire, rr_minimum, domain, brand FROM dns WHERE brand = ? AND LOWER(domain) = ?');
        $q->execute([$brand, $zone]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return 0;
        }
        foreach ($rows as $r) {
            if ((string)$r['rr_type'] !== '.' || strtolower((string)$r['rr_fqdn']) !== $zone || !in_array(strtolower((string)$r['rr_target']), self::ns(), true)) {
                return 0;
            }
        }
        $gone = $dns->prepare('DELETE FROM dns WHERE rr_id = ? AND brand = ?');
        $n = 0;
        $deleted = [];
        $own = !$db->inTransaction();
        try {
            if ($own) {
                $db->beginTransaction();
            }
            foreach ($rows as $r) {
                self::history($db, $r, $companyId, $personId, 'DELETE');
                $gone->execute([(int)$r['rr_id'], $brand]);
                if ($gone->rowCount() > 0) {
                    $deleted[] = $r;
                    $n++;
                }
            }
            if ($own) {
                $db->commit();
            }
        } catch (Throwable $e) {
            // History did not commit: put the deleted NS rows back so crm.dns and dns_history agree.
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            foreach ($deleted as $r) {
                Dw::dnsRestore($dns, $r, $companyId);
            }
            throw $e;
        }
        return $n;
    }

    private static function row(string $type, string $zone, string $target, string $brand): array
    {
        return [
            'rr_type' => $type, 'rr_fqdn' => $zone, 'rr_ip' => '', 'rr_target' => $target, 'rr_mx_dist' => 0,
            'rr_ttl' => self::TTL, 'rr_timestamp' => '0000-00-00 00:00:00', 'rr_email' => '', 'rr_serial' => 0,
            'rr_refresh' => 0, 'rr_retry' => 0, 'rr_expire' => 0, 'rr_minimum' => 0, 'domain' => $zone, 'brand' => $brand,
        ];
    }

    // Same insert as /dns: the shared Dw::dnsHistory() (issue #3).
    private static function history(PDO $db, array $row, string $companyId, string $personId, string $action): void
    {
        Dw::dnsHistory($db, $row, $companyId, $personId, $action);
    }
}
