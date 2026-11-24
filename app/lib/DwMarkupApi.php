<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// ChatGPT editor (refs #67, staging 2026-11-21): "Suggest an edit" (#suggest-edit) requests for an OAuth app.
// Loaded by markup/index.php. Uses its helpers mkJson, mkFail, mkSecret.
//   GET /markup/?a=api_list[&status=NEW|DONE|REJECTED|all][&limit=1..100]  Bearer, scope markup.read
//   GET /markup/?a=api_get&id=ID                                             Bearer, scope markup.read
//   GET /markup/?a=shot&id=ID&exp=UNIX&sig=HMAC                              signed screenshot link (1 hour)
// Who sees what (live rights): SUPER_ADMIN or FILE UPDATE on the brand -> every request on the brand;
// a domain editor -> requests for pages on the domains they edit. Never another brand's requests.
require_once '/var/www/html/lib/DwOAuth.php';

final class DwMarkupApi
{
    const SHOT_TTL = 3600;

    private static function hosts(array $s): ?array
    {
        if (Dw::allows($s, 'FILE', ['UPDATE'])) {
            return null;   // all of the brand
        }
        $q = Dw::pdo()->prepare('SELECT d.domain_name FROM domain_editor de JOIN domain d ON d.id = de.domain_id AND d.code = de.brand WHERE de.person_id = ? AND de.brand = ?');
        $q->execute([$s['person_id'], $s['brand']]);
        return array_map('strtolower', array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)));
    }

    private static function visible(?array $hosts, string $pageUrl): bool
    {
        if ($hosts === null) {
            return true;
        }
        $h = strtolower((string)parse_url($pageUrl, PHP_URL_HOST));
        return $h !== '' && in_array($h, $hosts, true);
    }

    private static function shotUrl(array $s, string $id): string
    {
        $exp = time() + self::SHOT_TTL;
        $sig = hash_hmac('sha256', 'shot|' . $id . '|' . $exp, mkSecret());
        $host = (string)($s['oauth_domain'] ?? '') !== '' ? (string)$s['oauth_domain'] : DwBrand::appHost();
        return 'https://' . $host . '/markup/?a=shot&id=' . rawurlencode($id) . '&exp=' . $exp . '&sig=' . $sig;
    }

    private static function shape(array $s, array $m): array
    {
        $text = trim((string)preg_replace('/^.*?\n\n/s', '', (string)$m['note']));
        return [
            'id' => (string)$m['id'],
            'status' => (string)$m['status'],
            'created' => (string)$m['created'],
            'page_url' => (string)$m['page_url'],
            'note' => $text,
            'marked_elements' => json_decode((string)($m['selectors'] ?? '[]'), true) ?: [],
            'viewport' => $m['viewport'],
            'from' => $m['email'] ?? null,
            'screenshot_url' => self::shotUrl($s, (string)$m['id']),
            'screenshot_file_id' => (string)$m['file_id'],
        ];
    }

    public static function route(string $a): void
    {
        if ($a === 'shot') {
            $id = (string)($_GET['id'] ?? '');
            $exp = (int)($_GET['exp'] ?? 0);
            $sig = (string)($_GET['sig'] ?? '');
            if ($exp < time() || $exp > time() + self::SHOT_TTL + 60 || !hash_equals(hash_hmac('sha256', 'shot|' . $id . '|' . $exp, mkSecret()), $sig)) {
                http_response_code(404);
                exit;
            }
            $q = Dw::pdo()->prepare('SELECT f.file_data, f.file_type FROM markup_request m JOIN files f ON f.id = m.file_id AND f.brand = m.brand WHERE m.id = ?');
            $q->execute([$id]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                exit;
            }
            header('Content-Type: ' . ($row['file_type'] === 'PNG' ? 'image/png' : 'image/jpeg'));
            header("Content-Security-Policy: default-src 'none'");
            header('Cache-Control: private, max-age=600');
            echo $row['file_data'];
            exit;
        }
        $s = DwOAuth::session('markup.read');
        if ($s === null) {
            mkFail(401, 'send Authorization: Bearer <access token> (scope markup.read)');
        }
        $hosts = self::hosts($s);
        if ($hosts === []) {
            mkFail(403, 'this account cannot see suggestion requests');
        }
        $db = Dw::pdo();
        $sel = 'SELECT m.id, m.status, m.created, m.page_url, m.viewport, m.selectors, m.file_id, n.note, (SELECT e.email FROM email e WHERE e.owner_id = m.person_id AND e.owner_type = \'PRSN\' AND e.brand = m.brand LIMIT 1) AS email FROM markup_request m JOIN notes n ON n.id = m.note_id WHERE m.brand = ?';
        if ($a === 'api_get') {
            $q = $db->prepare($sel . ' AND m.id = ?');
            $q->execute([$s['brand'], (string)($_GET['id'] ?? '')]);
            $m = $q->fetch(PDO::FETCH_ASSOC);
            if (!$m || !self::visible($hosts, (string)$m['page_url'])) {
                mkFail(404, 'request not found');
            }
            mkJson(200, ['ok' => true, 'request' => self::shape($s, $m)]);
        }
        $status = strtoupper((string)($_GET['status'] ?? 'NEW'));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
        $vals = [$s['brand']];
        if ($status !== 'ALL') {
            if (!in_array($status, ['NEW', 'DONE', 'REJECTED'], true)) {
                mkFail(400, 'status must be NEW, DONE, REJECTED or all');
            }
            $sel .= ' AND m.status = ?';
            $vals[] = $status;
        }
        $q = $db->prepare($sel . ' ORDER BY m.created DESC LIMIT 200');
        $q->execute($vals);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if (self::visible($hosts, (string)$m['page_url'])) {
                $out[] = self::shape($s, $m);
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        mkJson(200, ['ok' => true, 'note' => 'created is server time. screenshot_url works for one hour.', 'requests' => $out]);
    }
}
