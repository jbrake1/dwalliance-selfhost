<?php
declare(strict_types=1);
// Customer signup on a store domain (2026-11-11, issue #5). Replaces the staging draft (writes were disabled).
// 1. request_code (email) -> 6-character code emailed only; 2. verify -> person + email, customer signed in
// (cookie); 3. profile -> name, company, telephone, shipping, billing, only after the code checked out.
// Brand from the request Host only. No Turnstile. Signup rate limits, network blocks and signup_failure logging
// as /account_setup. Unverified rows are purged after 90 days. Every write goes through Dw::history.
require_once '/var/www/html/lib/DwCustomer.php';

const EUS_MODULE = 'end_user_account_setup';
const EUS_TTL_MIN = 30;

function eusHelp(): void
{
    DwCustomer::out(200, [
        'ok' => true,
        'path' => '/end_user_account_setup/',
        'what' => 'Customer signup for a store. Call it on the store\'s own domain (a domain claimed with /domain/); the store is found from the web address. No brand code is needed or shown.',
        'steps' => [
            '1' => 'POST {"action":"request_code","email":"you@example.com"}. Returns id. A 6-character code is emailed (never in the response) and expires in ' . EUS_TTL_MIN . ' minutes.',
            '2' => 'POST {"action":"verify","id":"ID","confirmation_id":"CODE"}. Creates the customer and signs them in on this domain for 30 days (HttpOnly cookie; the session id is never in the response). 5 wrong codes cancel the id.',
            '3' => 'POST {"action":"profile", ...} while signed in. Optional first_name, middle_name, last_name, company_name, telephone {phone_number, line_type}, shipping {line_1, line_2, line_3, city, state, zipcode, country}, billing {same fields}.',
            'status' => 'POST {"action":"status"} while signed in: what the profile has so far.',
            'delete_account' => 'POST {"action":"delete_account","confirm_email":"you@example.com"} while signed in (your own session only). Deletes your sign-in email, phone numbers, saved addresses and open carts (history rows kept), ends every session you have on this store, and signs you out. Orders and invoices are kept for the store\'s records. Refused (409) while you have an order waiting for payment: ask the store to cancel it first.',
        ],
        'already_a_customer' => 'Sign in with /customer_session/ on the same domain instead.',
        'rules' => ['US networks only; Tor, VPN and datacenter addresses are refused.', 'Rate limits: 1 code per email per minute, 3 per hour, 5 signups per network per hour.', 'Incomplete signups are deleted after 90 days.'],
    ]);
}

function eusFail(PDO $db, int $step, string $reason, int $status, string $error, ?string $email = null): void
{
    DwCustomer::logFailure($db, EUS_MODULE, $step, $reason, $status, $email, $error);
    DwCustomer::out($status, ['ok' => false, 'error' => $error]);
}

// Incomplete signups (never verified) older than 90 days, with DELETE history.
function eusPurge(PDO $db): int
{
    $q = $db->query('SELECT id FROM end_user_account_setup WHERE verified_at IS NULL AND created < NOW() - INTERVAL ' . DwCustomer::PURGE_DAYS . ' DAY LIMIT 200');
    $n = 0;
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $db->beginTransaction();
        Dw::history($db, 'end_user_account_setup', (string)$id, 'DELETE');
        $db->prepare('DELETE FROM end_user_account_setup WHERE id = ? AND verified_at IS NULL')->execute([$id]);
        $db->commit();
        $n++;
    }
    return $n;
}

function eusVerifiedSetup(PDO $db, string $personId, string $brand): ?array
{
    $q = $db->prepare('SELECT id, company_id FROM end_user_account_setup WHERE person_id = ? AND brand = ? AND verified_at IS NOT NULL LIMIT 1');
    $q->execute([$personId, $brand]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function eusUpsertAddress(PDO $db, string $personId, string $brand, string $type, array $values): string
{
    $q = $db->prepare("SELECT id FROM address WHERE owner_id = ? AND owner_type = 'PRSN' AND brand = ? AND address_type = ? ORDER BY created DESC LIMIT 1 FOR UPDATE");
    $q->execute([$personId, $brand, $type]);
    $id = $q->fetchColumn();
    if (is_string($id) && $id !== '') {
        Dw::history($db, 'address', $id, 'UPDATE');
        $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($values)));
        $db->prepare("UPDATE address SET $sets WHERE id = ? AND brand = ?")->execute([...array_values($values), $id, $brand]);
        return $id;
    }
    if (($values['line_1'] ?? null) === null || ($values['city'] ?? null) === null || ($values['state'] ?? null) === null || ($values['zipcode'] ?? null) === null) {
        DwCustomer::out(400, ['ok' => false, 'error' => strtolower($type) . ' needs line_1, city, state and zipcode']);
    }
    $values['country'] = $values['country'] ?? 'US';
    $id = Dw::randId();
    $cols = array_keys($values);
    $db->prepare('INSERT INTO address (id, ' . implode(', ', $cols) . ", address_type, owner_id, owner_type, created, created_by, brand) VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?, ?, 'PRSN', NOW(), ?, ?)")
        ->execute([$id, ...array_values($values), $type, $personId, $personId, $brand]);
    Dw::history($db, 'address', $id, 'INSERT');
    return $id;
}

// Delete the signed-in customer's account on this brand. Every row change goes through Dw::history. Kept: person
// (orders point at it), invoices, invoice lines and their address copies, end_user_account_setup rows (audit).
function eusDeleteAccount(PDO $db, string $pid, string $brand, array $in): void
{
    $q = $db->prepare("SELECT id, email FROM email WHERE owner_id = ? AND owner_type = 'PRSN' AND brand = ?");
    $q->execute([$pid, $brand]);
    $emails = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $confirm = DwCustomer::email($in['confirm_email'] ?? '');
    if ($confirm === null || !in_array(strtolower($confirm), array_map('strtolower', array_values($emails)), true)) {
        DwCustomer::out(400, ['ok' => false, 'error' => 'confirm_email must be the email this account signs in with']);
    }
    $q = $db->prepare("SELECT COUNT(*) FROM invoice WHERE person_id = ? AND brand = ? AND status = 'PENDING'");
    $q->execute([$pid, $brand]);
    $open = (int)$q->fetchColumn();
    if ($open > 0) {
        DwCustomer::out(409, ['ok' => false, 'error' => 'you have ' . $open . ' order(s) waiting for payment. Ask the store to cancel them first, then delete your account', 'open_orders' => $open]);
    }
    $col = function (string $sql, array $a) use ($db): array {
        $s = $db->prepare($sql);
        $s->execute($a);
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    };
    $del = function (string $table, string $id) use ($db): void {
        Dw::history($db, $table, $id, 'DELETE');
        $db->prepare('DELETE FROM `' . $table . '` WHERE id = ?')->execute([$id]);
    };
    $n = ['emails' => 0, 'telephones' => 0, 'addresses' => 0, 'carts' => 0, 'sessions' => 0, 'relations' => 0];
    $db->beginTransaction();
    try {
        $sessions = $col('SELECT id FROM session WHERE person_id = ? AND brand = ? FOR UPDATE', [$pid, $brand]);
        if ($sessions) {
            $in_ = implode(',', array_fill(0, count($sessions), '?'));
            // Open carts (never checked out) of this customer's sessions.
            foreach ($col("SELECT c.id FROM cart c LEFT JOIN invoice i ON i.cart_id = c.id WHERE c.brand = ? AND i.id IS NULL AND c.session_id IN ($in_)", [$brand, ...$sessions]) as $cid) {
                foreach ($col('SELECT id FROM cart_item WHERE cart_id = ?', [$cid]) as $x) {
                    $del('cart_item', $x);
                }
                $del('cart', $cid);
                $n['carts']++;
            }
            // End every session: revoke each store-domain sign-in, then remove the session rows.
            $db->prepare("UPDATE site_session SET revoked_at = NOW() WHERE revoked_at IS NULL AND session_id IN ($in_)")->execute($sessions);
            foreach ($sessions as $sid) {
                $del('session', $sid);
                $n['sessions']++;
            }
        }
        foreach (array_keys($emails) as $x) {
            $del('email', (string)$x);
            $n['emails']++;
        }
        foreach ($col("SELECT id FROM telephone WHERE owner_id = ? AND owner_type = 'PRSN' AND brand = ?", [$pid, $brand]) as $x) {
            $del('telephone', $x);
            $n['telephones']++;
        }
        foreach ($col("SELECT id FROM address WHERE owner_id = ? AND owner_type = 'PRSN' AND brand = ?", [$pid, $brand]) as $x) {
            $del('address', $x);
            $n['addresses']++;
        }
        foreach ($col('SELECT id FROM relation WHERE person_id = ? AND brand = ?', [$pid, $brand]) as $x) {
            $del('relation', $x);
            $n['relations']++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    $q = $db->prepare('SELECT COUNT(*) FROM invoice WHERE person_id = ? AND brand = ?');
    $q->execute([$pid, $brand]);
    $kept = (int)$q->fetchColumn();
    error_log('end_user_account_setup: delete_account done, removed ' . json_encode($n) . ', orders kept ' . $kept);
    setcookie(DwCustomer::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    DwCustomer::out(200, ['ok' => true, 'deleted' => true, 'signed_in' => false, 'removed' => $n, 'orders_kept' => $kept]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    eusHelp();
}
$in = DwCustomer::body();
$action = strtolower(trim((string)($in['action'] ?? '')));
if ($action === '') {
    eusHelp();
}
if (!in_array($action, ['request_code', 'verify', 'profile', 'status', 'delete_account'], true)) {
    DwCustomer::out(400, ['ok' => false, 'error' => 'action must be request_code, verify, profile, status or delete_account']);
}
[$brand, $host] = DwCustomer::requireStoreHost();

try {
    $db = Dw::pdo();
try {
    require_once '/var/www/html/lib/DwAffiliate.php';
    DwAffiliate::captureReferralFromRequest($db);
} catch (Throwable $e) {
}

    if ($action === 'request_code') {
        $email = DwCustomer::email($in['email'] ?? '');
        if ($email === null) {
            eusFail($db, 1, 'bad_email', 400, 'a valid email is required (max 100 characters)');
        }
        DwCustomer::networkBlock($db, EUS_MODULE, 1, $email);
        $norm = DwSignup::normalizeEmail($email);
        $ip = DwSignup::clientIp();
        $limit = DwCustomer::signupLimit($db, $norm, $ip);
        if ($limit !== null) {
            header('Retry-After: ' . $limit[1]);
            eusFail($db, 1, $limit[2] === 'ip_per_hour' ? 'rate_limit_ip' : 'rate_limit_email', 429, $limit[0], $email);
        }
        eusPurge($db);
        $existing = DwCustomer::personByEmail($db, $email, $brand);
        $id = Dw::randId();
        $code = DwCustomer::code();
        $db->beginTransaction();
        $db->prepare('INSERT INTO end_user_account_setup (id, brand, email, email_normalized, remote_ip, code_hash, created, expires_at, attempts) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ' . EUS_TTL_MIN . ' MINUTE, 0)')
            ->execute([$id, $brand, $email, $norm, substr($ip, 0, 45), $existing === null ? DwCustomer::hash('eus', $id, $code) : null]);
        Dw::history($db, 'end_user_account_setup', $id, 'INSERT');
        $db->commit();
        // Same response either way, so the endpoint does not reveal who is already a customer.
        $mailed = $existing === null
            ? DwCustomer::mail($email, 'Your confirmation code for ' . $host, "Your confirmation code is " . $code . "\nIt expires in " . EUS_TTL_MIN . " minutes.\nIf you did not ask for it, ignore this email.\n")
            : DwCustomer::mail($email, 'You already have an account on ' . $host, "Someone asked to sign up on " . $host . " with this email, but you already have an account.\nSign in on " . $host . " with your email instead.\nIf it was not you, ignore this email.\n");
        DwCustomer::out(200, ['ok' => true, 'id' => $id, 'emailed' => $mailed, 'expires_in_minutes' => EUS_TTL_MIN, 'next' => 'verify with id and the emailed confirmation_id']);
    }

    if ($action === 'verify') {
        $id = strtolower(trim((string)($in['id'] ?? '')));
        $code = strtoupper(trim((string)($in['confirmation_id'] ?? $in['code'] ?? '')));
        if (!preg_match('/^[0-9a-f]{50}$/', $id) || !preg_match('/^[A-Z0-9]{6}$/', $code)) {
            eusFail($db, 2, 'bad_input', 400, 'id (from step 1) and the 6-character confirmation_id are required');
        }
        $max = max(1, (int)DwSignup::cfg('wrong_code_max'));
        $db->beginTransaction();
        $q = $db->prepare('SELECT id, email, code_hash, attempts, verified_at, expires_at > NOW() AS live FROM end_user_account_setup WHERE id = ? AND brand = ? FOR UPDATE');
        $q->execute([$id, $brand]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        $dead = 'confirmation_id expired, unknown id, or too many wrong codes. Start again with step 1';
        if (!$row || $row['verified_at'] !== null || !(int)$row['live'] || $row['code_hash'] === null || (int)$row['attempts'] >= $max) {
            $db->rollBack();
            eusFail($db, 2, 'dead_id', 400, $dead, $row ? (string)$row['email'] : null);
        }
        $email = (string)$row['email'];
        if (!hash_equals((string)$row['code_hash'], DwCustomer::hash('eus', $id, $code))) {
            Dw::history($db, 'end_user_account_setup', $id, 'UPDATE');
            $db->prepare('UPDATE end_user_account_setup SET attempts = attempts + 1 WHERE id = ?')->execute([$id]);
            $db->commit();
            eusFail($db, 2, 'wrong_code', 400, 'confirmation_id does not match. After ' . $max . ' wrong codes the id is cancelled', $email);
        }
        if (DwCustomer::personByEmail($db, $email, $brand) !== null) {
            $db->rollBack();
            eusFail($db, 2, 'existing_account', 409, 'this email already has an account here; sign in with /customer_session/', $email);
        }
        $personId = Dw::randId();
        $emailId = Dw::randId();
        $db->prepare('INSERT INTO person (id, created, brand) VALUES (?, NOW(), ?)')->execute([$personId, $brand]);
        Dw::history($db, 'person', $personId, 'INSERT');
        $db->prepare("INSERT INTO email (id, email, owner_id, owner_type, created, created_by, brand) VALUES (?, ?, ?, 'PRSN', NOW(), ?, ?)")->execute([$emailId, $email, $personId, $personId, $brand]);
        Dw::history($db, 'email', $emailId, 'INSERT');
        Dw::history($db, 'end_user_account_setup', $id, 'UPDATE');
        $db->prepare('UPDATE end_user_account_setup SET verified_at = NOW(), person_id = ?, code_hash = NULL WHERE id = ?')->execute([$personId, $id]);
        $db->commit();
        try {
            require_once '/var/www/html/lib/DwAffiliate.php';
            DwAffiliate::attributeConversionFromRequest($db, [
                'person_id' => $personId,
                'company_id' => null,
                'reference_type' => 'signup',
                'reference_id' => $personId,
            ]);
        } catch (Throwable $e) {
        }
        DwCustomer::signIn($db, $brand, $host, $personId);
        DwCustomer::out(200, ['ok' => true, 'verified' => true, 'signed_in' => true, 'session_days' => DwCustomer::SESSION_DAYS, 'next' => 'profile']);
    }

    $me = DwCustomer::current($db);
    if ($me === null) {
        DwCustomer::out(401, ['ok' => false, 'error' => 'sign in first: finish step 2 here, or use /customer_session/ on this domain']);
    }
    $pid = $me['person_id'];

    // Delete / cancel account (2026-11-13). The customer's own session only (cookie on this store domain).
    if ($action === 'delete_account') {
        eusDeleteAccount($db, $pid, $brand, $in);
    }

    $setup = eusVerifiedSetup($db, $pid, $brand);
    if ($setup === null) {
        DwCustomer::out(403, ['ok' => false, 'error' => 'the profile can only be filled in after this customer verified their email with step 2']);
    }

    if ($action === 'status') {
        $p = $db->prepare('SELECT first_name, middle_name, last_name FROM person WHERE id = ? AND brand = ?');
        $p->execute([$pid, $brand]);
        $person = $p->fetch(PDO::FETCH_ASSOC) ?: [];
        $types = $db->prepare("SELECT address_type, COUNT(*) FROM address WHERE owner_id = ? AND owner_type = 'PRSN' AND brand = ? GROUP BY address_type");
        $types->execute([$pid, $brand]);
        $addr = $types->fetchAll(PDO::FETCH_KEY_PAIR);
        $tel = $db->prepare("SELECT COUNT(*) FROM telephone WHERE owner_id = ? AND owner_type = 'PRSN' AND brand = ?");
        $tel->execute([$pid, $brand]);
        DwCustomer::out(200, ['ok' => true, 'verified' => true, 'person' => $person, 'has_company' => $setup['company_id'] !== null,
            'has_shipping' => (int)($addr['Shipping'] ?? 0) > 0, 'has_billing' => (int)($addr['Billing'] ?? 0) > 0, 'has_telephone' => (int)$tel->fetchColumn() > 0]);
    }

    // profile
    $names = [];
    foreach (['first_name', 'middle_name', 'last_name'] as $k) {
        if (array_key_exists($k, $in)) {
            $names[$k] = DwCustomer::text($in, $k, 100);
        }
    }
    $companyName = DwCustomer::text($in, 'company_name', 100);
    $tel = null;
    if (isset($in['telephone'])) {
        if (!is_array($in['telephone'])) {
            DwCustomer::out(400, ['ok' => false, 'error' => 'telephone must be an object {phone_number, line_type}']);
        }
        $phone = DwCustomer::text($in['telephone'], 'phone_number', 20);
        $lt = strtoupper(trim((string)($in['telephone']['line_type'] ?? 'VOICE_TEXT')));
        if ($phone === null || !preg_match('/^[0-9+().\- ]{7,20}$/', $phone) || !in_array($lt, ['VOICE_TEXT', 'VOICE', 'TEXT', 'FAX', 'OTHER'], true)) {
            DwCustomer::out(400, ['ok' => false, 'error' => 'telephone needs phone_number (digits, 7-20 characters) and line_type VOICE_TEXT, VOICE, TEXT, FAX or OTHER']);
        }
        $tel = [$phone, $lt];
    }
    $addresses = [];
    foreach (['shipping' => 'Shipping', 'billing' => 'Billing'] as $k => $type) {
        if (isset($in[$k])) {
            if (!is_array($in[$k])) {
                DwCustomer::out(400, ['ok' => false, 'error' => $k . ' must be an object']);
            }
            $vals = array_filter(DwCustomer::addressValues($in[$k]), fn($v) => $v !== null);
            if ($vals === []) {
                DwCustomer::out(400, ['ok' => false, 'error' => $k . ' needs address fields']);
            }
            $addresses[$type] = $vals;
        }
    }
    if ($names === [] && $companyName === null && $tel === null && $addresses === []) {
        DwCustomer::out(400, ['ok' => false, 'error' => 'send at least one of first_name, middle_name, last_name, company_name, telephone, shipping, billing']);
    }
    $out = ['ok' => true, 'profile_updated' => true];
    $db->beginTransaction();
    if ($names !== []) {
        Dw::history($db, 'person', $pid, 'UPDATE');
        $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($names)));
        $db->prepare("UPDATE person SET $sets WHERE id = ? AND brand = ?")->execute([...array_values($names), $pid, $brand]);
    }
    if ($companyName !== null) {
        $cid = $setup['company_id'];
        if ($cid !== null) {
            Dw::history($db, 'company', $cid, 'UPDATE');
            $db->prepare('UPDATE company SET company_name = ? WHERE id = ? AND brand = ?')->execute([$companyName, $cid, $brand]);
        } else {
            $cid = Dw::randId();
            $rel = Dw::randId();
            $db->prepare('INSERT INTO company (id, company_name, brand) VALUES (?, ?, ?)')->execute([$cid, $companyName, $brand]);
            Dw::history($db, 'company', $cid, 'INSERT');
            $db->prepare('INSERT INTO relation (id, person_id, company, created_on, created_by, brand) VALUES (?, ?, ?, NOW(), ?, ?)')->execute([$rel, $pid, $cid, $pid, $brand]);
            Dw::history($db, 'relation', $rel, 'INSERT');
            Dw::history($db, 'end_user_account_setup', $setup['id'], 'UPDATE');
            $db->prepare('UPDATE end_user_account_setup SET company_id = ? WHERE id = ?')->execute([$cid, $setup['id']]);
        }
        $out['company_id'] = $cid;
    }
    if ($tel !== null) {
        $q = $db->prepare("SELECT id FROM telephone WHERE owner_id = ? AND owner_type = 'PRSN' AND brand = ? ORDER BY created DESC LIMIT 1 FOR UPDATE");
        $q->execute([$pid, $brand]);
        $tid = $q->fetchColumn();
        if (is_string($tid) && $tid !== '') {
            Dw::history($db, 'telephone', $tid, 'UPDATE');
            $db->prepare('UPDATE telephone SET phone_number = ?, line_type = ? WHERE id = ?')->execute([$tel[0], $tel[1], $tid]);
        } else {
            $tid = Dw::randId();
            $db->prepare("INSERT INTO telephone (id, phone_number, line_type, owner_id, owner_type, created, created_by, brand) VALUES (?, ?, ?, ?, 'PRSN', NOW(), ?, ?)")->execute([$tid, $tel[0], $tel[1], $pid, $pid, $brand]);
            Dw::history($db, 'telephone', $tid, 'INSERT');
        }
    }
    foreach ($addresses as $type => $vals) {
        $out[strtolower($type) . '_address_id'] = eusUpsertAddress($db, $pid, $brand, $type, $vals);
    }
    $db->commit();
    DwCustomer::out(200, $out);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('end_user_account_setup: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
    DwCustomer::out(500, ['ok' => false, 'error' => 'could not complete ' . $action]);
}
