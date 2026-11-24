<?php
declare(strict_types=1);
// Per-domain editor switch and EDITOR grants (staging 2026-11-04). See /workspace/dw/permissions/DESIGN.md.
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';

final class DwPermissions
{
    public const SETTING_EDITOR = 'editor_enabled';

    /** Resolve API session: JSON/cookie session id, or __Host-dw_s on a customer domain. */
    public static function resolveSession(array $sent): ?array
    {
        $s = Dw::session($sent);
        if ($s !== null) {
            return $s;
        }
        $host = DwDomain::requestHost();
        if ($host === '' || DwDomain::isAppHost($host)) {
            return null;
        }
        require_once '/var/www/html/lib/DwEdit.php';
        return DwEdit::siteSession($host);
    }

    public static function isSuperAdmin(string $personId, string $brand): bool
    {
        $q = Dw::pdo()->prepare("SELECT 1 FROM data_access WHERE person_id = ? AND brand = ? AND right_name = 'SUPER_ADMIN' LIMIT 1");
        $q->execute([$personId, $brand]);
        return (bool)$q->fetchColumn();
    }

    /** domain row for host on this brand, or null. */
    public static function domainRow(string $host, string $brand): ?array
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || !DwDomain::validHost($host)) {
            return null;
        }
        $q = Dw::pdo()->prepare('SELECT id, domain_name, code, company_id FROM domain WHERE domain_name = ? AND code = ? LIMIT 1');
        $q->execute([$host, $brand]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** True when the editor loader may inject for this brand+host. DB switch first, then markup.json fallback. */
    public static function editorEnabled(string $brand, string $host): bool
    {
        $row = self::domainRow($host, $brand);
        if ($row !== null) {
            $q = Dw::pdo()->prepare('SELECT setting_value FROM domain_setting WHERE domain_id = ? AND setting_name = ? LIMIT 1');
            $q->execute([$row['id'], self::SETTING_EDITOR]);
            $v = $q->fetchColumn();
            if (is_string($v)) {
                return $v === '1' || strtolower($v) === 'true' || $v === 'on';
            }
        }
        return self::markupJsonFallback($brand);
    }

    public static function markupJsonFallback(string $brand): bool
    {
        $c = json_decode((string)@file_get_contents('/var/lib/app/data/markup.json'), true);
        if (!is_array($c)) {
            return false;
        }
        return in_array($brand, (array)($c['brands'] ?? []), true);
    }

    public static function markupAlertFallback(): string
    {
        $c = json_decode((string)@file_get_contents('/var/lib/app/data/markup.json'), true);
        if (!is_array($c)) {
            return '';
        }
        $to = (string)($c['alert_to'] ?? '');
        return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
    }

    /** Emails of SUPER_ADMINs on this brand (unique, valid). */
    public static function superAdminEmails(string $brand): array
    {
        $q = Dw::pdo()->prepare(
            "SELECT DISTINCT e.email FROM data_access d
             JOIN email e ON e.owner_id = d.person_id AND e.owner_type = 'PRSN' AND e.brand = d.brand
             WHERE d.brand = ? AND d.right_name = 'SUPER_ADMIN'"
        );
        $q->execute([$brand]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $email = strtolower(trim((string)$email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[$email] = true;
            }
        }
        return array_keys($out);
    }

    /** Recipients for a markup suggestion on this brand. */
    public static function suggestionRecipients(string $brand): array
    {
        $emails = self::superAdminEmails($brand);
        if ($emails !== []) {
            return $emails;
        }
        $fb = self::markupAlertFallback();
        return $fb !== '' ? [$fb] : [];
    }

    /** Live editor check for a person on a host (domain_editor row). SUPER_ADMIN handled by DwEdit. */
    public static function isDomainEditor(string $personId, string $brand, string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        $q = Dw::pdo()->prepare(
            'SELECT 1 FROM domain_editor de
             JOIN domain d ON d.id = de.domain_id
             WHERE de.person_id = ? AND de.brand = ? AND d.domain_name = ? AND d.code = ? LIMIT 1'
        );
        $q->execute([$personId, $brand, $host, $brand]);
        return (bool)$q->fetchColumn();
    }

    /** Person id if this email can magic-link on host via domain_editor (no LOGIN required). */
    public static function domainEditorPerson(PDO $db, string $email, string $brand, string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));
        $q = $db->prepare(
            "SELECT p.id FROM email e
             JOIN person p ON p.id = e.owner_id
             JOIN domain_editor de ON de.person_id = p.id AND de.brand = e.brand
             JOIN domain d ON d.id = de.domain_id AND d.domain_name = ? AND d.code = e.brand
             WHERE e.email = ? AND e.owner_type = 'PRSN' AND e.brand = ?
             ORDER BY e.created LIMIT 1"
        );
        $q->execute([$host, $email, $brand]);
        $id = $q->fetchColumn();
        return is_string($id) && $id !== '' ? $id : null;
    }

    public static function personByEmail(string $email, string $brand): ?array
    {
        $q = Dw::pdo()->prepare(
            "SELECT p.id, p.first_name, p.last_name, e.email FROM email e
             JOIN person p ON p.id = e.owner_id
             WHERE e.email = ? AND e.owner_type = 'PRSN' AND e.brand = ?
             ORDER BY e.created LIMIT 1"
        );
        $q->execute([$email, $brand]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public static function archiveSetting(PDO $db, string $id, string $action): void
    {
        $db->prepare(
            'INSERT INTO domain_setting_history
             (history_id, id, domain_id, brand, setting_name, setting_value, created, created_by_person_id, updated, updated_by_person_id, action)
             SELECT ?, id, domain_id, brand, setting_name, setting_value, created, created_by_person_id, updated, updated_by_person_id, ?
             FROM domain_setting WHERE id = ?'
        )->execute([Dw::randId(), $action, $id]);
    }

    public static function archiveEditor(PDO $db, string $id, string $action): void
    {
        $db->prepare(
            'INSERT INTO domain_editor_history
             (history_id, id, domain_id, brand, person_id, created, created_by_person_id, action)
             SELECT ?, id, domain_id, brand, person_id, created, created_by_person_id, ?
             FROM domain_editor WHERE id = ?'
        )->execute([Dw::randId(), $action, $id]);
    }

    public static function countSuperAdmins(string $brand): int
    {
        $q = Dw::pdo()->prepare("SELECT COUNT(DISTINCT person_id) FROM data_access WHERE brand = ? AND right_name = 'SUPER_ADMIN'");
        $q->execute([$brand]);
        return (int)$q->fetchColumn();
    }
}
