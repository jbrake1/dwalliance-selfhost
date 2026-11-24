<?php
declare(strict_types=1);
// Addresses (2026-11-11, issue #7): create, update, delete, read and list rows in address, with history.
// Two ways in:
//  - Customer: signed in on the store domain (/end_user_account_setup or /customer_session). Only their own
//    (PRSN) addresses on that store's brand.
//  - Staff: JSON session + brand with CRM rights. Addresses of a person or a company on that brand.
// Orders keep their own copy in invoice_address (one SHIPPING and one BILLING per invoice), so editing or
// deleting an address never changes a past order.
require_once '/var/www/html/lib/DwCustomer.php';

const ADDR_COLS = 'id, line_1, line_2, line_3, city, state, zipcode, country, address_type, owner_id, owner_type, created';

function addrHelp(): void
{
    DwCustomer::out(200, [
        'ok' => true,
        'path' => '/address/',
        'who' => [
            'customer' => 'Signed in on the store domain. No session field; only your own addresses.',
            'staff' => 'Send session and brand (from /session). Needs CRM rights. Send owner_type PRSN or CMPNY and owner_id (a person or company on your brand).',
        ],
        'actions' => [
            'create' => 'line_1, city, state, zipcode required; line_2, line_3, country (default US) optional; address_type Billing, Shipping or Other (default Shipping). Returns address_id.',
            'update' => 'address_id plus any fields to change (line_1, line_2, line_3, city, state, zipcode, country, address_type). Send an empty string to clear an optional line.',
            'delete' => 'address_id. Past orders keep their own copy of the address.',
            'read' => 'address_id.',
            'list' => 'Your addresses (customer), or owner_type + owner_id (staff). Optional address_type.',
        ],
        'history' => 'Every create, update and delete writes address_history in the same transaction (UPDATE and DELETE keep the old row).',
    ]);
}

function addrOut(array $r): array
{
    return array_intersect_key($r, array_flip(['id', 'line_1', 'line_2', 'line_3', 'city', 'state', 'zipcode', 'country', 'address_type', 'owner_id', 'owner_type', 'created'])) + ['address_id' => $r['id']];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    addrHelp();
}
$in = DwCustomer::body();
$action = strtolower(trim((string)($in['action'] ?? '')));
if ($action === '') {
    addrHelp();
}
if (!in_array($action, ['create', 'update', 'delete', 'read', 'list'], true)) {
    DwCustomer::out(400, ['ok' => false, 'error' => 'action must be create, update, delete, read or list']);
}

try {
    $db = Dw::pdo();
    // ---- who is asking ----
    if (isset($in['session']) && $in['session'] !== '') {
        $s = Dw::session($in);
        if ($s === null) {
            DwCustomer::out(401, ['ok' => false, 'error' => 'a valid session is required']);
        }
        $brand = trim((string)($in['brand'] ?? ''));
        if ($brand === '' || !hash_equals((string)$s['brand'], $brand)) {
            DwCustomer::out(403, ['ok' => false, 'error' => 'brand does not match this session']);
        }
        $right = ['create' => 'CREATE', 'update' => 'UPDATE', 'delete' => 'DELETE', 'read' => 'READ', 'list' => 'READ'][$action];
        if (!Dw::allows($s, 'CRM', [$right])) {
            DwCustomer::out(403, ['ok' => false, 'error' => 'this session needs module CRM right ' . $right]);
        }
        $actor = (string)$s['person_id'];
        $ownerScope = null; // staff: any person/company on the brand
    } else {
        $me = DwCustomer::current($db);
        if ($me === null) {
            DwCustomer::out(401, ['ok' => false, 'error' => 'sign in on the store domain first (/customer_session/), or send a staff session and brand']);
        }
        $brand = $me['brand'];
        $actor = $me['person_id'];
        $ownerScope = ['PRSN', $me['person_id']];
    }

    $ownerCheck = function (string $type, string $id) use ($db, $brand): bool {
        $t = $type === 'PRSN' ? 'person' : 'company';
        $q = $db->prepare("SELECT 1 FROM $t WHERE id = ? AND brand = ?");
        $q->execute([$id, $brand]);
        return (bool)$q->fetchColumn();
    };
    $owner = function () use ($in, $ownerScope, $ownerCheck): array {
        if ($ownerScope !== null) {
            return $ownerScope;
        }
        $type = strtoupper(trim((string)($in['owner_type'] ?? '')));
        $id = trim((string)($in['owner_id'] ?? ''));
        if (!in_array($type, ['PRSN', 'CMPNY'], true) || $id === '' || strlen($id) > 50) {
            DwCustomer::out(400, ['ok' => false, 'error' => 'owner_type (PRSN or CMPNY) and owner_id are required']);
        }
        if (!$ownerCheck($type, $id)) {
            DwCustomer::out(404, ['ok' => false, 'error' => ($type === 'PRSN' ? 'person' : 'company') . ' not found on this brand']);
        }
        return [$type, $id];
    };
    // Load one address the caller may touch (FOR UPDATE inside a transaction).
    $load = function (bool $lock) use ($db, $in, $brand, $ownerScope): array {
        $id = trim((string)($in['address_id'] ?? $in['id'] ?? ''));
        if ($id === '' || strlen($id) > 50) {
            DwCustomer::out(400, ['ok' => false, 'error' => 'address_id is required']);
        }
        $sql = 'SELECT ' . ADDR_COLS . ' FROM address WHERE id = ? AND brand = ?';
        $args = [$id, $brand];
        if ($ownerScope !== null) {
            $sql .= ' AND owner_type = ? AND owner_id = ?';
            array_push($args, ...$ownerScope);
        }
        $q = $db->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
        $q->execute($args);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            DwCustomer::out(404, ['ok' => false, 'error' => 'address not found']);
        }
        return $r;
    };

    if ($action === 'read') {
        DwCustomer::out(200, ['ok' => true, 'address' => addrOut($load(false))]);
    }

    if ($action === 'list') {
        [$ot, $oid] = $owner();
        $sql = 'SELECT ' . ADDR_COLS . ' FROM address WHERE brand = ? AND owner_type = ? AND owner_id = ?';
        $args = [$brand, $ot, $oid];
        if (isset($in['address_type']) && $in['address_type'] !== '') {
            $t = DwCustomer::addressType($in['address_type']);
            if ($t === null) {
                DwCustomer::out(400, ['ok' => false, 'error' => 'address_type must be Billing, Shipping or Other']);
            }
            $sql .= ' AND address_type = ?';
            $args[] = $t;
        }
        $q = $db->prepare($sql . ' ORDER BY created DESC LIMIT 200');
        $q->execute($args);
        DwCustomer::out(200, ['ok' => true, 'addresses' => array_map('addrOut', $q->fetchAll(PDO::FETCH_ASSOC))]);
    }

    if ($action === 'create') {
        [$ot, $oid] = $owner();
        $vals = DwCustomer::addressValues($in);
        foreach (['line_1', 'city', 'state', 'zipcode'] as $req) {
            if (($vals[$req] ?? null) === null) {
                DwCustomer::out(400, ['ok' => false, 'error' => 'line_1, city, state and zipcode are required']);
            }
        }
        $vals['country'] = $vals['country'] ?? 'US';
        $type = isset($in['address_type']) && $in['address_type'] !== '' ? DwCustomer::addressType($in['address_type']) : 'Shipping';
        if ($type === null) {
            DwCustomer::out(400, ['ok' => false, 'error' => 'address_type must be Billing, Shipping or Other']);
        }
        $id = Dw::randId();
        $cols = array_keys($vals);
        $db->beginTransaction();
        $db->prepare('INSERT INTO address (id, ' . implode(', ', $cols) . ', address_type, owner_id, owner_type, created, created_by, brand) VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?, ?, NOW(), ?, ?)')
            ->execute([$id, ...array_values($vals), $type, $oid, $ot, $actor, $brand]);
        Dw::history($db, 'address', $id, 'INSERT');
        $db->commit();
        DwCustomer::out(200, ['ok' => true, 'address_id' => $id]);
    }

    if ($action === 'update') {
        $vals = DwCustomer::addressValues($in);
        foreach (['line_1', 'city', 'state', 'zipcode'] as $req) {
            if (array_key_exists($req, $vals) && $vals[$req] === null) {
                DwCustomer::out(400, ['ok' => false, 'error' => $req . ' cannot be cleared']);
            }
        }
        if (isset($in['address_type']) && $in['address_type'] !== '') {
            $t = DwCustomer::addressType($in['address_type']);
            if ($t === null) {
                DwCustomer::out(400, ['ok' => false, 'error' => 'address_type must be Billing, Shipping or Other']);
            }
            $vals['address_type'] = $t;
        }
        if ($vals === []) {
            DwCustomer::out(400, ['ok' => false, 'error' => 'send at least one field to change']);
        }
        $db->beginTransaction();
        $row = $load(true);
        Dw::history($db, 'address', $row['id'], 'UPDATE');
        $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($vals)));
        $db->prepare("UPDATE address SET $sets WHERE id = ? AND brand = ?")->execute([...array_values($vals), $row['id'], $brand]);
        $db->commit();
        DwCustomer::out(200, ['ok' => true, 'address_id' => $row['id']]);
    }

    // delete
    $db->beginTransaction();
    $row = $load(true);
    Dw::history($db, 'address', $row['id'], 'DELETE');
    $db->prepare('DELETE FROM address WHERE id = ? AND brand = ?')->execute([$row['id'], $brand]);
    $db->commit();
    DwCustomer::out(200, ['ok' => true, 'address_id' => $row['id'], 'deleted' => true]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('address: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
    DwCustomer::out(500, ['ok' => false, 'error' => 'could not complete ' . $action]);
}
