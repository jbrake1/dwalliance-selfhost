<?php
declare(strict_types=1);
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwGuard.php';

const DNS_TYPES = ['+', '=', 'C', '@', '.', '&', '^', 'Z', "'"];

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
    if (array_key_exists('session', $sent)) {
        $sent['session'] = '(sent, not shown)';
    }
    if (array_key_exists('brand', $sent)) {
        $sent['brand'] = '(sent, not shown)';
    }
    return $sent;
}

function expected(): array
{
    return [
        'action' => 'create, update, delete, or read',
        'session' => 'JSON field session or cookie session',
        'brand' => 'required, must match the session brand',
        'rights' => 'module DNS and the right for the action: CREATE, READ, UPDATE, or DELETE',
        'company_id' => 'required. The session person must have a relation to this company',
        'domain' => 'create: required zone name, already claimed by this company through /domain. read: optional filter',
        'rr_id' => 'update and delete. read: optional',
        'rr_type' => 'one of + = C @ . & ^ Z or \' (TXT)',
        'rr_fqdn' => 'hostname in the zone, up to 100 characters. A leading *. is allowed',
        'rr_ip' => 'IPv4. Required for + and =. Optional for @ and .',
        'rr_target' => 'required for C, @, ., &, ^, and \'. Up to 255 characters',
        'rr_mx_dist' => 'required for @, 0 to 255',
        'rr_ttl' => 'optional, 0 to 16777215. Default 600',
        'soa' => 'Z only: rr_email, rr_timestamp, rr_serial, rr_refresh, rr_retry, rr_expire, rr_minimum',
    ];
}

function fail(array $sent, string $error, int $code = 400): void
{
    Dw::jsonOut($code, ['ok' => false, 'error' => $error, 'you_sent' => shown($sent), 'expected' => expected()]);
    exit;
}

function help(): void
{
    Dw::jsonOut(200, [
        'path' => '/dns',
        'session' => 'Required. Send session and brand. Brand must match the session. Module DNS and the right for the action.',
        'store' => 'Rows are written to the DNS table (crm.dns). dns_history is written to the app database. Claim the zone first with /domain. /dns no longer claims names.',
        'protected' => 'Refused for every brand: ' . implode(', ', DwGuard::names()) . ' and every name under them, plus MX, NS, and SOA records on ' . DwBrand::domain() . '.',
        'actions' => [
            'create' => 'company_id, domain, rr_type, rr_fqdn, and the fields that type uses. The zone must already belong to this company. Claim it with /domain action create.',
            'update' => 'company_id, rr_id, and any fields to replace. The zone must already belong to this company.',
            'delete' => 'company_id and rr_id.',
            'read' => 'company_id. Optional rr_id or domain. Returns records for zones this company owns.',
        ],
        'rr_type' => [
            '+' => 'A. rr_fqdn and rr_ip',
            '=' => 'A and PTR. rr_fqdn and rr_ip',
            'C' => 'CNAME. rr_fqdn and rr_target',
            '@' => 'MX. rr_fqdn, rr_target, rr_mx_dist. rr_ip is optional',
            '.' => 'NS. rr_fqdn and rr_target. rr_ip is optional',
            '&' => 'NS delegation. rr_fqdn and rr_target',
            '^' => 'PTR. rr_fqdn and rr_target',
            'Z' => 'SOA. rr_fqdn plus the SOA columns when sent',
            "'" => 'TXT. rr_fqdn and rr_target',
        ],
        'examples' => [
            'create' => "const res = await fetch('/dns/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create', session: 'SESSION_ID', brand: 'BRANDCODE', company_id: 'COMPANY_ID', domain: 'example.com', rr_type: '+', rr_fqdn: 'www.example.com', rr_ip: '192.0.2.10' }) }).then(r => r.json());",
            'read' => "const res = await fetch('/dns/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'read', session: 'SESSION_ID', brand: 'BRANDCODE', company_id: 'COMPANY_ID', domain: 'example.com' }) }).then(r => r.json());",
            'update' => "const res = await fetch('/dns/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'update', session: 'SESSION_ID', brand: 'BRANDCODE', company_id: 'COMPANY_ID', rr_id: 1, rr_ip: '192.0.2.11' }) }).then(r => r.json());",
            'delete' => "const res = await fetch('/dns/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', session: 'SESSION_ID', brand: 'BRANDCODE', company_id: 'COMPANY_ID', rr_id: 1 }) }).then(r => r.json());",
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

function hostname(array $sent, string $key, string $value, int $max, bool $wild): string
{
    $v = strtolower(trim($value));
    if ($v === '' || strlen($v) > $max || strpos($v, '..') !== false) {
        fail($sent, $key . ' must be a hostname of at most ' . $max . ' characters');
    }
    $body = $v;
    if ($wild && substr($v, 0, 2) === '*.') {
        $body = substr($v, 2);
    }
    if (!preg_match('/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)+$/', $body)) {
        fail($sent, $key . ' must be a hostname of at most ' . $max . ' characters');
    }
    return $v;
}

function inZone(string $fqdn, string $domain): bool
{
    if ($fqdn === $domain) {
        return true;
    }
    $suffix = '.' . $domain;
    return strlen($fqdn) > strlen($suffix) && substr($fqdn, -strlen($suffix)) === $suffix;
}

function ipv4(array $sent, string $value): string
{
    $v = trim($value);
    if (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        fail($sent, 'rr_ip must be an IPv4 address');
    }
    return $v;
}

function whole(array $sent, string $key, $value, int $min, int $max): int
{
    if (is_int($value)) {
        $n = $value;
    } elseif (is_float($value) && floor($value) === $value) {
        $n = (int)$value;
    } elseif (is_string($value) && preg_match('/^\d{1,10}$/', trim($value))) {
        $n = (int)trim($value);
    } else {
        fail($sent, $key . ' must be a whole number from ' . $min . ' to ' . $max);
    }
    if ($n < $min || $n > $max) {
        fail($sent, $key . ' must be a whole number from ' . $min . ' to ' . $max);
    }
    return $n;
}

function present(array $sent, string $key): bool
{
    return array_key_exists($key, $sent) && $sent[$key] !== '' && $sent[$key] !== null;
}

function recordFrom(array $sent, ?array $existing): array
{
    $type = (string)($existing['rr_type'] ?? '');
    if (present($sent, 'rr_type') || $existing === null) {
        $type = trim((string)($sent['rr_type'] ?? ''));
        if (!in_array($type, DNS_TYPES, true)) {
            fail($sent, 'rr_type must be one of + = C @ . & ^ Z \'');
        }
    }
    $domain = strtolower((string)($existing['domain'] ?? ''));
    if (present($sent, 'domain') || $existing === null) {
        $domain = hostname($sent, 'domain', (string)($sent['domain'] ?? ''), 255, false);
    }
    $fqdn = strtolower((string)($existing['rr_fqdn'] ?? ''));
    if (present($sent, 'rr_fqdn') || $existing === null) {
        $fqdn = hostname($sent, 'rr_fqdn', (string)($sent['rr_fqdn'] ?? ''), 100, true);
    }
    if (!inZone($fqdn, $domain)) {
        fail($sent, 'rr_fqdn must be the domain or a name under it');
    }

    $ip = (string)($existing['rr_ip'] ?? '');
    $target = (string)($existing['rr_target'] ?? '');
    $dist = (int)($existing['rr_mx_dist'] ?? 0);
    $ttl = $existing === null ? 600 : (int)$existing['rr_ttl'];
    if (present($sent, 'rr_ttl')) {
        $ttl = whole($sent, 'rr_ttl', $sent['rr_ttl'], 0, 16777215);
    }
    $stamp = (string)($existing['rr_timestamp'] ?? '0000-00-00 00:00:00');
    $email = (string)($existing['rr_email'] ?? '');
    $serial = (int)($existing['rr_serial'] ?? 0);
    $refresh = (int)($existing['rr_refresh'] ?? 0);
    $retry = (int)($existing['rr_retry'] ?? 0);
    $expire = (int)($existing['rr_expire'] ?? 0);
    $minimum = (int)($existing['rr_minimum'] ?? 0);

    $needsIp = $type === '+' || $type === '=';
    $optionalIp = $type === '@' || $type === '.';
    $needsTarget = in_array($type, ['C', '@', '.', '&', '^', "'"], true);

    if ($needsIp) {
        if (present($sent, 'rr_ip')) {
            $ip = ipv4($sent, (string)$sent['rr_ip']);
        } elseif ($ip === '') {
            fail($sent, 'rr_ip is required');
        } else {
            $ip = ipv4($sent, $ip);
        }
    } elseif ($optionalIp) {
        if (present($sent, 'rr_ip')) {
            $ip = ipv4($sent, (string)$sent['rr_ip']);
        }
    } else {
        $ip = '';
    }

    if ($needsTarget) {
        if (present($sent, 'rr_target') || $existing === null || $target === '') {
            $target = trim((string)($sent['rr_target'] ?? $target));
        }
        if ($target === '' || strlen($target) > 255 || strpbrk($target, "\r\n") !== false) {
            fail($sent, 'rr_target is required and must be at most 255 characters');
        }
    } else {
        $target = '';
    }

    if ($type === '@') {
        if (present($sent, 'rr_mx_dist') || $existing === null) {
            $dist = whole($sent, 'rr_mx_dist', $sent['rr_mx_dist'] ?? '', 0, 255);
        }
    } else {
        $dist = 0;
    }

    if ($type === 'Z') {
        if (present($sent, 'rr_email')) {
            $email = trim((string)$sent['rr_email']);
            if (strlen($email) > 255 || strpbrk($email, "\r\n") !== false) {
                fail($sent, 'rr_email must be at most 255 characters');
            }
        } elseif ($existing === null) {
            $email = '';
        }
        if (present($sent, 'rr_timestamp')) {
            $stampIn = trim((string)$sent['rr_timestamp']);
            $when = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stampIn);
            $errors = DateTimeImmutable::getLastErrors();
            $bad = !$when instanceof DateTimeImmutable
                || $when->format('Y-m-d H:i:s') !== $stampIn
                || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0));
            if ($bad) {
                fail($sent, 'rr_timestamp must be YYYY-MM-DD HH:MM:SS');
            }
            $stamp = $stampIn;
        } elseif ($existing === null) {
            $stamp = '0000-00-00 00:00:00';
        }
        $soa = ['rr_serial' => $serial, 'rr_refresh' => $refresh, 'rr_retry' => $retry, 'rr_expire' => $expire, 'rr_minimum' => $minimum];
        foreach ($soa as $key => $value) {
            if (present($sent, $key)) {
                $soa[$key] = whole($sent, $key, $sent[$key], 0, 2147483647);
            }
        }
        $serial = $soa['rr_serial'];
        $refresh = $soa['rr_refresh'];
        $retry = $soa['rr_retry'];
        $expire = $soa['rr_expire'];
        $minimum = $soa['rr_minimum'];
    } else {
        $stamp = '0000-00-00 00:00:00';
        $email = '';
        $serial = 0;
        $refresh = 0;
        $retry = 0;
        $expire = 0;
        $minimum = 0;
    }

    return [
        'rr_type' => $type,
        'rr_fqdn' => $fqdn,
        'rr_ip' => $ip,
        'rr_target' => $target,
        'rr_mx_dist' => $dist,
        'rr_ttl' => $ttl,
        'rr_timestamp' => $stamp,
        'rr_email' => $email,
        'rr_serial' => $serial,
        'rr_refresh' => $refresh,
        'rr_retry' => $retry,
        'rr_expire' => $expire,
        'rr_minimum' => $minimum,
        'domain' => $domain,
    ];
}

function rowOut(array $row, string $companyId): array
{
    return [
        'rr_id' => (int)$row['rr_id'],
        'company_id' => $companyId,
        'domain' => (string)$row['domain'],
        'rr_type' => (string)$row['rr_type'],
        'rr_fqdn' => (string)$row['rr_fqdn'],
        'rr_ip' => (string)$row['rr_ip'],
        'rr_target' => (string)$row['rr_target'],
        'rr_mx_dist' => (int)$row['rr_mx_dist'],
        'rr_ttl' => (int)$row['rr_ttl'],
        'rr_timestamp' => (string)$row['rr_timestamp'],
        'rr_email' => (string)$row['rr_email'],
        'rr_serial' => (int)$row['rr_serial'],
        'rr_refresh' => (int)$row['rr_refresh'],
        'rr_retry' => (int)$row['rr_retry'],
        'rr_expire' => (int)$row['rr_expire'],
        'rr_minimum' => (int)$row['rr_minimum'],
    ];
}

function writeHistory(PDO $db, array $row, string $companyId, string $personId, string $action): void
{
    Dw::dnsHistory($db, $row, $companyId, $personId, $action);
}

function requireOwned(PDO $db, array $sent, string $domain, string $companyId, string $brand): void
{
    $q = $db->prepare('SELECT company_id, code AS brand FROM domain WHERE LOWER(domain_name) = ? LIMIT 1');
    $q->execute([$domain]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fail($sent, 'domain is not claimed by this company. Claim it first with /domain action create', 404);
    }
    if ((string)$row['company_id'] !== $companyId || (string)$row['brand'] !== $brand) {
        fail($sent, 'that domain belongs to another company', 403);
    }
}

function guard(array $sent, array $row): void
{
    $why = DwGuard::dnsRefusal((string)$row['rr_fqdn'], (string)$row['rr_type'], (string)$row['domain']);
    if ($why !== null) {
        fail($sent, $why, 403);
    }
}

function loadRow(PDO $dns, array $sent, int $rrId, string $brand): array
{
    $q = $dns->prepare('SELECT rr_id, rr_type, rr_fqdn, rr_ip, rr_target, rr_mx_dist, rr_ttl, rr_timestamp, rr_email, rr_serial, rr_refresh, rr_retry, rr_expire, rr_minimum, company_id, domain, brand FROM dns WHERE rr_id = ? AND brand = ? LIMIT 1');
    $q->execute([$rrId, $brand]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fail($sent, 'rr_id was not found on this brand', 404);
    }
    return $row;
}

function rrId(array $sent): int
{
    if (!present($sent, 'rr_id')) {
        fail($sent, 'rr_id is required');
    }
    return whole($sent, 'rr_id', $sent['rr_id'], 1, 2147483647);
}

$sent = body();
if ($sent === []) {
    help();
}
$action = strtolower(trim((string)($sent['action'] ?? '')));
$rights = ['create' => 'CREATE', 'read' => 'READ', 'update' => 'UPDATE', 'delete' => 'DELETE'];
if (!isset($rights[$action])) {
    fail($sent, 'action must be create, update, delete, or read');
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
if (!Dw::allows($session, 'DNS', [$rights[$action]])) {
    fail($sent, 'this session needs module DNS and ' . $rights[$action], 403);
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
        $names = [];
        if (present($sent, 'domain')) {
            $domain = hostname($sent, 'domain', (string)$sent['domain'], 255, false);
            requireOwned($db, $sent, $domain, $companyId, $brand);
            $names = [$domain];
        } else {
            $owned = $db->prepare('SELECT domain_name FROM domain WHERE company_id = ? AND code = ?');
            $owned->execute([$companyId, $brand]);
            while ($name = $owned->fetchColumn()) {
                $names[] = (string)$name;
            }
        }
        if (present($sent, 'rr_id')) {
            $id = rrId($sent);
            $row = loadRow(Dw::pdoDns(), $sent, $id, $brand);
            requireOwned($db, $sent, strtolower((string)$row['domain']), $companyId, $brand);
            if ($names !== [] && !in_array(strtolower((string)$row['domain']), array_map('strtolower', $names), true)) {
                fail($sent, 'rr_id was not found on this domain', 404);
            }
            Dw::jsonOut(200, ['ok' => true, 'records' => [rowOut($row, $companyId)]]);
            exit;
        }
        if ($names === []) {
            Dw::jsonOut(200, ['ok' => true, 'records' => []]);
            exit;
        }
        $marks = implode(',', array_fill(0, count($names), '?'));
        $q = Dw::pdoDns()->prepare('SELECT rr_id, rr_type, rr_fqdn, rr_ip, rr_target, rr_mx_dist, rr_ttl, rr_timestamp, rr_email, rr_serial, rr_refresh, rr_retry, rr_expire, rr_minimum, domain, brand FROM dns WHERE brand = ? AND domain IN (' . $marks . ') ORDER BY domain, rr_type, rr_fqdn, rr_id');
        $q->execute(array_merge([$brand], $names));
        $records = [];
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $records[] = rowOut($row, $companyId);
        }
        Dw::jsonOut(200, ['ok' => true, 'records' => $records]);
        exit;
    }

    if ($action === 'create') {
        $row = recordFrom($sent, null);
        $row['brand'] = $brand;
        guard($sent, $row);
        requireOwned($db, $sent, $row['domain'], $companyId, $brand);
        $dns = Dw::pdoDns();
        $dns->prepare('INSERT INTO dns (rr_type, rr_fqdn, rr_ip, rr_target, rr_mx_dist, rr_ttl, rr_timestamp, rr_email, rr_serial, rr_refresh, rr_retry, rr_expire, rr_minimum, company_id, domain, brand) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $row['rr_type'], $row['rr_fqdn'], $row['rr_ip'], $row['rr_target'], $row['rr_mx_dist'], $row['rr_ttl'],
            $row['rr_timestamp'], $row['rr_email'], $row['rr_serial'], $row['rr_refresh'], $row['rr_retry'], $row['rr_expire'], $row['rr_minimum'],
            $companyId, $row['domain'], $brand,
        ]);
        $row['rr_id'] = (int)$dns->lastInsertId();
        if ($row['rr_id'] < 1) {
            fail($sent, 'could not complete create', 500);
        }
        try {
            $db->beginTransaction();
            writeHistory($db, $row, $companyId, $personId, 'INSERT');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $dns->prepare('DELETE FROM dns WHERE rr_id = ? AND brand = ?')->execute([$row['rr_id'], $brand]);
            throw $e;
        }
        Dw::jsonOut(200, ['ok' => true, 'rr_id' => $row['rr_id'], 'domain' => $row['domain'], 'company_id' => $companyId]);
        exit;
    }

    $id = rrId($sent);
    $dns = Dw::pdoDns();
    $current = loadRow($dns, $sent, $id, $brand);
    requireOwned($db, $sent, strtolower((string)$current['domain']), $companyId, $brand);
    guard($sent, $current);

    if ($action === 'delete') {
        $current['brand'] = $brand;
        $db->beginTransaction();
        writeHistory($db, $current, $companyId, $personId, 'DELETE');
        $gone = $dns->prepare('DELETE FROM dns WHERE rr_id = ? AND brand = ?');
        $gone->execute([$id, $brand]);
        if ($gone->rowCount() === 0) {
            $db->rollBack();
            fail($sent, 'could not complete delete', 500);
        }
        try {
            $db->commit();
        } catch (Throwable $e) {
            Dw::dnsRestore($dns, $current, $companyId);
            throw $e;
        }
        Dw::jsonOut(200, ['ok' => true, 'rr_id' => $id]);
        exit;
    }

    $next = recordFrom($sent, $current);
    $next['brand'] = $brand;
    $next['rr_id'] = $id;
    guard($sent, $next);
    if ($next['domain'] !== strtolower((string)$current['domain'])) {
        requireOwned($db, $sent, $next['domain'], $companyId, $brand);
    }
    $current['brand'] = $brand;
    $db->beginTransaction();
    writeHistory($db, $current, $companyId, $personId, 'UPDATE');
    $dns->prepare('UPDATE dns SET rr_type = ?, rr_fqdn = ?, rr_ip = ?, rr_target = ?, rr_mx_dist = ?, rr_ttl = ?, rr_timestamp = ?, rr_email = ?, rr_serial = ?, rr_refresh = ?, rr_retry = ?, rr_expire = ?, rr_minimum = ?, domain = ? WHERE rr_id = ? AND brand = ?')->execute([
        $next['rr_type'], $next['rr_fqdn'], $next['rr_ip'], $next['rr_target'], $next['rr_mx_dist'], $next['rr_ttl'],
        $next['rr_timestamp'], $next['rr_email'], $next['rr_serial'], $next['rr_refresh'], $next['rr_retry'], $next['rr_expire'], $next['rr_minimum'],
        $next['domain'], $id, $brand,
    ]);
    try {
        $db->commit();
    } catch (Throwable $e) {
        Dw::dnsRestore($dns, $current, $companyId);
        throw $e;
    }
    Dw::jsonOut(200, ['ok' => true, 'rr_id' => $id]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fail($sent, 'could not complete ' . $action, 500);
}
