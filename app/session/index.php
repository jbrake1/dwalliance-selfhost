<?php
declare(strict_types=1);
require_once '/var/www/html/lib/Dw.php';

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

function expected(): array
{
    return [
        'email' => 'required, must match email.email for owner_type PRSN and this brand',
        'brand' => 'required, brand code, varchar(10)',
    ];
}

function fail(array $sent, string $error, int $code = 400): void
{
    Dw::jsonOut($code, ['ok' => false, 'error' => $error, 'you_sent' => $sent, 'expected' => expected()]);
    exit;
}

function shown(array $sent): array
{
    if (array_key_exists('session', $sent)) {
        $sent['session'] = '(sent, not shown)';
    }
    return $sent;
}

function expectedPermissions(): array
{
    return [
        'action' => 'get_permissions',
        'session' => 'JSON field session, cookie session, or __Host-dw_s on a customer domain',
        'brand' => 'optional when omitted (the session brand is used); otherwise the brand code this session must hold rights for',
        'domain' => 'optional hostname, resolved to a brand the same way the platform already does. If both brand and domain are sent they must match',
    ];
}

function failPermissions(array $sent, string $error, int $code): void
{
    Dw::jsonOut($code, ['ok' => false, 'error' => $error, 'you_sent' => shown($sent), 'expected' => expectedPermissions()]);
    exit;
}

function groupRights(string $rightsJson): array
{
    $rows = json_decode($rightsJson, true);
    if (!is_array($rows)) {
        return [[], false];
    }
    $grouped = [];
    $super = false;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $mod = (string)($row['module_name'] ?? '');
        $right = (string)($row['right_name'] ?? '');
        if ($mod === '' || $right === '') {
            continue;
        }
        if ($right === 'SUPER_ADMIN') {
            $super = true;
        }
        if (!isset($grouped[$mod])) {
            $grouped[$mod] = [];
        }
        if (!in_array($right, $grouped[$mod], true)) {
            $grouped[$mod][] = $right;
        }
    }
    ksort($grouped);
    foreach ($grouped as &$list) {
        sort($list);
    }
    unset($list);
    return [$grouped, $super];
}

function handleGetPermissions(array $sent): void
{
    require_once '/var/www/html/lib/DwDomain.php';
    require_once '/var/www/html/lib/DwPermissions.php';

    // Same resolver as /permissions/: JSON session, cookie session, then __Host-dw_s on a customer domain.
    $session = DwPermissions::resolveSession($sent);
    if ($session === null) {
        failPermissions($sent, 'a valid session is required', 401);
    }

    $brandIn = trim((string)($sent['brand'] ?? ''));
    $domainIn = strtolower(rtrim(trim((string)($sent['domain'] ?? '')), '.'));
    $resolvedBrand = '';
    $resolvedDomain = '';

    if ($domainIn !== '') {
        if (!DwDomain::validHost($domainIn)) {
            failPermissions($sent, 'domain was not found', 404);
        }
        $fromHost = DwDomain::brandForHost($domainIn, static function () {
            return Dw::pdo();
        });
        if ($fromHost === null || $fromHost === '') {
            failPermissions($sent, 'domain was not found', 404);
        }
        $resolvedBrand = $fromHost;
        $resolvedDomain = $domainIn;
        if ($brandIn !== '' && !hash_equals($resolvedBrand, $brandIn)) {
            failPermissions($sent, 'brand and domain do not match', 400);
        }
    } elseif ($brandIn !== '') {
        if (strlen($brandIn) > 10 || !preg_match('/^[A-Za-z0-9]{1,10}$/', $brandIn)) {
            failPermissions($sent, 'brand is required as a brand code, or send a domain', 400);
        }
        $resolvedBrand = $brandIn;
    } else {
        $resolvedBrand = (string)$session['brand'];
    }

    // A session is bound to one brand. Rights come from the session.rights snapshot,
    // which is what Dw::allows() uses to gate /files, /crm, /dns and the other modules.
    // Official grant/revoke paths call Dw::refreshSessionRights, so the snapshot stays
    // in step with data_access. Reveal nothing about the session's own brand on a miss.
    if (!hash_equals((string)$session['brand'], $resolvedBrand)) {
        failPermissions($sent, 'this session does not have rights for that brand', 403);
    }
    [$rights, $super] = groupRights((string)($session['rights'] ?? ''));
    if ($rights === []) {
        failPermissions($sent, 'this session does not have rights for that brand', 403);
    }

    $out = [
        'ok' => true,
        'action' => 'get_permissions',
        'brand' => $resolvedBrand,
        'super_admin' => $super,
        'rights' => $rights,
    ];
    if ($resolvedDomain !== '') {
        $out['domain'] = $resolvedDomain;
    }
    Dw::jsonOut(200, $out);
    exit;
}

$sent = body();
if ($sent === []) {
    Dw::jsonOut(200, [
        'path' => '/session',
        'session' => false,
        'required' => ['email', 'brand'],
        'flow' => [
            'POST email and brand (the brand code, up to 10 characters).',
            'If that email belongs to a person on that brand, a session id is created and emailed.',
            'The response is ok and mailed only. The id is only in the email. Rights are stored, not returned.',
        ],
        'actions' => [
            'get_permissions' => 'POST {action:"get_permissions", session, brand or domain}. Session may also come from the session cookie, or from __Host-dw_s on a customer domain. The session must be valid and hold rights for that brand (a domain is resolved to its brand). Returns the brand, optional domain, super_admin, and rights grouped by module. The session id is never returned. An empty body or a missing action still starts a sign-in as before.',
        ],
        'javascript' => [
            'create' => "const session = await fetch('/session/', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'person@example.com', brand: 'DWALLC' }) }).then(r => r.json());",
            'get_permissions' => "const perms = await fetch('/session/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'get_permissions', session: 'SESSION_ID', brand: 'BRANDCODE' }) }).then(r => r.json());",
            'get_permissions_domain' => "const perms = await fetch('/session/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'get_permissions', session: 'SESSION_ID', domain: 'www.example.com' }) }).then(r => r.json());",
        ],
    ]);
    exit;
}
$action = isset($sent['action']) && is_string($sent['action']) ? trim($sent['action']) : '';
if ($action === 'get_permissions') {
    handleGetPermissions($sent);
    exit;
}
$email = trim((string)($sent['email'] ?? ''));
$brand = trim((string)($sent['brand'] ?? ''));
if ($email === '' || $brand === '' || strlen($brand) > 10 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail($sent, 'email and brand are required');
}
try {
    $db = Dw::pdo();
    $q = $db->prepare('SELECT p.id FROM email e INNER JOIN person p ON p.id = e.owner_id WHERE e.email = ? AND e.owner_type = ? AND e.brand = ? LIMIT 1');
    $q->execute([$email, 'PRSN', $brand]);
    $personId = $q->fetchColumn();
    if (!$personId) {
        fail($sent, 'email was not found for that brand');
    }
    $rightsQ = $db->prepare('SELECT module_name, right_name FROM data_access WHERE person_id = ? AND brand = ?');
    $rightsQ->execute([$personId, $brand]);
    $rights = $rightsQ->fetchAll(PDO::FETCH_ASSOC);
    $sessionId = Dw::randId();
    $historyId = Dw::randId();
    $rightsJson = json_encode($rights, JSON_UNESCAPED_SLASHES);
    $db->beginTransaction();
    $db->prepare('INSERT INTO session (id, person_id, rights, created, brand) VALUES (?, ?, ?, NOW(), ?)')->execute([$sessionId, $personId, $rightsJson, $brand]);
    $db->prepare('INSERT INTO session_history (history_id, id, person_id, rights, created, brand, action, archived_at) VALUES (?, ?, ?, ?, NOW(), ?, ?, NOW())')->execute([$historyId, $sessionId, $personId, $rightsJson, $brand, 'INSERT']);
    $db->commit();
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fail($sent, 'could not create the session', 500);
}
$mailed = Dw::sendMail($email, DwBrand::name() . ' session', $sessionId);
if (!$mailed) {
    fail($sent, 'session was created but the email was not sent', 500);
}
Dw::jsonOut(200, ['ok' => true, 'mailed' => true]);
