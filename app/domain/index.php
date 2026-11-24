<?php
declare(strict_types=1);
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';
require_once '/var/www/html/lib/DwGuard.php';
require_once '/var/www/html/lib/DwZone.php';

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $data = is_array($json) ? $json : $_POST;
    if (!isset($data['action']) && isset($_GET['action']) && is_string($_GET['action'])) {
        $data['action'] = $_GET['action'];
    }
    return $data;
}

function shown(array $sent): array
{
    foreach (['session', 'brand'] as $k) {
        if (array_key_exists($k, $sent)) {
            $sent[$k] = '(sent, not shown)';
        }
    }
    return $sent;
}

function expected(): array
{
    return [
        'action' => 'create, read, or delete',
        'session' => 'JSON field session or cookie session',
        'brand' => 'required, must match the session brand',
        'rights' => 'module DOMAIN and the right for the action: CREATE, READ, or DELETE',
        'company_id' => 'required. The session person must have a relation to this company',
        'domain_name' => 'create and delete: required, for example example.com. read: optional filter',
        'include_www' => 'create and delete: optional, default true. Also claims or releases www.<domain_name>',
    ];
}

function fail(array $sent, string $error, int $code = 400): void
{
    Dw::jsonOut($code, ['ok' => false, 'error' => $error, 'you_sent' => shown($sent), 'expected' => expected()]);
    exit;
}

function help(): void
{
    $call = "const res = await fetch('/domain/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: '%s', session: 'SESSION_ID', brand: 'BRANDCODE', company_id: 'COMPANY_ID'%s }) }).then(r => r.json());";
    Dw::jsonOut(200, [
        'path' => '/domain',
        'session' => 'Required. Send session and brand. Brand must match the session. Module DOMAIN and the right for the action.',
        'what' => DwBrand::t('Claims a domain name for a company on this brand. A claimed name serves the brand PUBLIC /files at https://<name>/ once DNS and hosting point at DW Alliance, and lets /dns manage records in that zone.'),
        'actions' => [
            'create' => 'company_id and domain_name. Claims the name and www.<name>. A leading www. is removed first. Names already claimed by this company are returned as already_owned.',
            'read' => 'company_id. Optional domain_name. Lists the names this company has claimed on this brand.',
            'delete' => 'company_id and domain_name. Releases the name and www.<name>. Refused while /dns still has records in that zone on this brand.',
        ],
        'refused' => [
            'Names claimed by another company or brand, and names under or above a name another company or brand claimed.',
            DwDomain::appHost() . ', localhost, IP addresses, and .lan names.',
            'Protected platform names: ' . implode(', ', DwGuard::names()) . ', and every name under them.',
        ],
        'returns' => 'domain_id, domain_name, and status for each name. Never codes or session ids.',
        'examples' => [
            'create' => sprintf($call, 'create', ", domain_name: 'example.com'"),
            'read' => sprintf($call, 'read', ''),
            'delete' => sprintf($call, 'delete', ", domain_name: 'example.com'"),
        ],
    ]);
    exit;
}

function need(array $sent, string $key): string
{
    $v = trim((string)($sent[$key] ?? ''));
    if ($v === '') {
        fail($sent, $key . ' is required');
    }
    if (strlen($v) > 50) {
        fail($sent, $key . ' is longer than 50 characters');
    }
    return $v;
}

function present(array $sent, string $key): bool
{
    return array_key_exists($key, $sent) && $sent[$key] !== '' && $sent[$key] !== null;
}

function withWww(array $sent): bool
{
    if (!array_key_exists('include_www', $sent)) {
        return true;
    }
    $v = $sent['include_www'];
    if (is_bool($v)) {
        return $v;
    }
    $s = strtolower(trim((string)$v));
    if (in_array($s, ['1', 'true', 'yes'], true)) {
        return true;
    }
    if (in_array($s, ['0', 'false', 'no'], true)) {
        return false;
    }
    fail($sent, 'include_www must be true or false');
}

// Base name: lower case, no trailing dot, no leading www.
function rootName(array $sent): string
{
    $v = strtolower(rtrim(trim((string)($sent['domain_name'] ?? '')), '.'));
    if ($v === '') {
        fail($sent, 'domain_name is required');
    }
    if (filter_var(trim($v, '[]'), FILTER_VALIDATE_IP) !== false) {
        fail($sent, 'domain_name must be a domain name, not an IP address');
    }
    if (substr($v, 0, 4) === 'www.') {
        $v = substr($v, 4);
    }
    if (strlen($v) > 96 || !DwDomain::validHost($v)) {
        fail($sent, 'domain_name must be a domain name such as example.com, at most 96 characters');
    }
    return $v;
}

function refuseName(array $sent, string $name): void
{
    if (DwDomain::isAppHost($name) || $name === 'localhost' || substr($name, -10) === '.localhost' || substr($name, -4) === '.lan') {
        fail($sent, $name . ' cannot be claimed', 403);
    }
    $why = DwGuard::claimRefusal($name);
    if ($why !== null) {
        fail($sent, $why, 403);
    }
}

// Parents of a name down to two labels: a.b.example.com -> b.example.com, example.com
function parents(string $name): array
{
    $parts = explode('.', $name);
    $out = [];
    for ($i = 1; $i < count($parts) - 1; $i++) {
        $out[] = implode('.', array_slice($parts, $i));
    }
    return $out;
}

function names(string $base, bool $www): array
{
    return $www ? [$base, 'www.' . $base] : [$base];
}

$sent = body();
if ($sent === []) {
    help();
}
$action = strtolower(trim((string)($sent['action'] ?? '')));
if ($action === 'list') {
    $action = 'read';
}
if ($action === 'release') {
    $action = 'delete';
}
$rights = ['create' => 'CREATE', 'read' => 'READ', 'delete' => 'DELETE'];
if (!isset($rights[$action])) {
    fail($sent, 'action must be create, read, or delete');
}

try {
    $session = Dw::session($sent);
} catch (Throwable $e) {
    fail($sent, 'database unavailable', 500);
}
if ($session === null) {
    fail($sent, 'a valid session is required', 401);
}
$brand = need($sent, 'brand');
if ($brand !== (string)$session['brand']) {
    fail($sent, 'brand does not match this session', 403);
}
if (!Dw::allows($session, 'DOMAIN', [$rights[$action]])) {
    fail($sent, 'this session needs module DOMAIN and ' . $rights[$action], 403);
}

try {
    $db = Dw::pdo();
    $companyId = need($sent, 'company_id');
    $personId = (string)$session['person_id'];
    $member = $db->prepare('SELECT id FROM relation WHERE person_id = ? AND company = ? AND brand = ? LIMIT 1');
    $member->execute([$personId, $companyId, $brand]);
    if (!$member->fetchColumn()) {
        fail($sent, 'this person is not in that company', 403);
    }

    if ($action === 'read') {
        $sql = 'SELECT id, domain_name, created FROM domain WHERE company_id = ? AND code = ?';
        $args = [$companyId, $brand];
        if (present($sent, 'domain_name')) {
            $sql .= ' AND (LOWER(domain_name) = ? OR LOWER(domain_name) = ?)';
            $b = rootName($sent);
            $args[] = $b;
            $args[] = 'www.' . $b;
        }
        $q = $db->prepare($sql . ' ORDER BY domain_name');
        $q->execute($args);
        $out = [];
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            $out[] = ['domain_id' => (string)$r['id'], 'domain_name' => (string)$r['domain_name'], 'created' => (string)$r['created']];
        }
        Dw::jsonOut(200, ['ok' => true, 'company_id' => $companyId, 'domains' => $out]);
        exit;
    }

    $base = rootName($sent);
    $list = names($base, withWww($sent));

    if ($action === 'create') {
        foreach ($list as $n) {
            refuseName($sent, $n);
        }
        $db->beginTransaction();
        // Another company or brand already holding the name, a parent of it, or a name under it.
        $check = [];
        foreach ($list as $n) {
            $check[] = $n;
            foreach (parents($n) as $p) {
                $check[] = $p;
            }
        }
        $check = array_values(array_unique($check));
        $marks = implode(',', array_fill(0, count($check), '?'));
        $q = $db->prepare('SELECT id, LOWER(domain_name) AS n, company_id, code FROM domain WHERE LOWER(domain_name) IN (' . $marks . ') OR LOWER(domain_name) LIKE ? FOR UPDATE');
        $q->execute(array_merge($check, ['%.' . $base]));
        $mine = [];
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            if ((string)$r['company_id'] !== $companyId || (string)$r['code'] !== $brand) {
                $db->rollBack();
                fail($sent, (string)$r['n'] . ' is already claimed by another company', 403);
            }
            $mine[(string)$r['n']] = (string)$r['id'];
        }
        $add = $db->prepare('INSERT INTO domain (id, domain_name, code, company_id, created_by, created) VALUES (?, ?, ?, ?, ?, NOW())');
        $hist = $db->prepare('INSERT INTO domain_history (history_id, id, domain_name, code, company_id, created_by, created, action, archived_at) SELECT ?, id, domain_name, code, company_id, created_by, created, ?, NOW() FROM domain WHERE id = ?');
        $out = [];
        foreach ($list as $n) {
            if (isset($mine[$n])) {
                $out[] = ['domain_id' => $mine[$n], 'domain_name' => $n, 'status' => 'already_owned'];
                continue;
            }
            $id = Dw::randId();
            $add->execute([$id, $n, $brand, $companyId, $personId]);
            $hist->execute([Dw::randId(), 'INSERT', $id]);
            $out[] = ['domain_id' => $id, 'domain_name' => $n, 'status' => 'created'];
        }
        $db->commit();
        // Platform NS rows (ns1/ns2/ns3.dwalliance.com) so our nameservers answer for the zone.
        // Only for an apex outside dwalliance.com with no '.' rows yet; never fails the claim.
        try {
            $nsStatus = DwZone::ensureNs($db, Dw::pdoDns(), $base, $companyId, $personId, $brand);
        } catch (Throwable $e) {
            $nsStatus = 'failed';
        }
        Dw::jsonOut(200, ['ok' => true, 'company_id' => $companyId, 'domains' => $out, 'dns_ns' => $nsStatus]);
        exit;
    }

    // delete
    // The NS rows added at claim time would block the release below; when they are the only
    // rows left in the zone on this brand (and www has none), remove them first, with history.
    $own = $db->prepare('SELECT COUNT(*) FROM domain WHERE company_id = ? AND code = ? AND LOWER(domain_name) = ?');
    $own->execute([$companyId, $brand, $base]);
    $baseOwned = (int)$own->fetchColumn() > 0;
    $own->execute([$companyId, $brand, 'www.' . $base]);
    $wwwStays = !in_array('www.' . $base, $list, true) && (int)$own->fetchColumn() > 0;
    if ($baseOwned && !$wwwStays) {
        $wwwLeft = Dw::pdoDns()->prepare('SELECT COUNT(*) FROM dns WHERE brand = ? AND LOWER(domain) = ?');
        $wwwLeft->execute([$brand, 'www.' . $base]);
        if ((int)$wwwLeft->fetchColumn() === 0) {
            DwZone::dropPlatformNs($db, Dw::pdoDns(), $base, $companyId, $personId, $brand);
        }
    }
    $dnsLeft = Dw::pdoDns()->prepare('SELECT COUNT(*) FROM dns WHERE brand = ? AND LOWER(domain) = ?');
    $db->beginTransaction();
    $marks = implode(',', array_fill(0, count($list), '?'));
    $q = $db->prepare('SELECT id, LOWER(domain_name) AS n FROM domain WHERE company_id = ? AND code = ? AND LOWER(domain_name) IN (' . $marks . ') FOR UPDATE');
    $q->execute(array_merge([$companyId, $brand], $list));
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        $db->rollBack();
        fail($sent, 'domain_name is not claimed by this company', 404);
    }
    foreach ($rows as $r) {
        $dnsLeft->execute([$brand, (string)$r['n']]);
        $left = (int)$dnsLeft->fetchColumn();
        if ($left > 0) {
            $db->rollBack();
            fail($sent, (string)$r['n'] . ' still has ' . $left . ' DNS records on this brand. Delete them with /dns first', 409);
        }
    }
    $hist = $db->prepare('INSERT INTO domain_history (history_id, id, domain_name, code, company_id, created_by, created, action, archived_at) SELECT ?, id, domain_name, code, company_id, created_by, created, ?, NOW() FROM domain WHERE id = ?');
    $gone = $db->prepare('DELETE FROM domain WHERE id = ? AND company_id = ? AND code = ?');
    $out = [];
    foreach ($rows as $r) {
        $hist->execute([Dw::randId(), 'DELETE', (string)$r['id']]);
        $gone->execute([(string)$r['id'], $companyId, $brand]);
        $out[] = ['domain_id' => (string)$r['id'], 'domain_name' => (string)$r['n'], 'status' => 'released'];
    }
    $db->commit();
    Dw::jsonOut(200, ['ok' => true, 'company_id' => $companyId, 'domains' => $out]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fail($sent, 'could not complete ' . $action, 500);
}
