<?php
declare(strict_types=1);
// Customer email-code login on a store domain (2026-11-11, issue #6). Staff /session is unchanged.
// Pending logins are site_login_code rows (no new table): code_hash = keyed hash of the public login id,
// state_hash = keyed hash of id + code, session_id = the customer's person id, domain = this exact host.
// DwLogin/DwEdit hash their own tokens differently, so they can never redeem these rows. A wrong code takes
// 3 minutes off the 15-minute life, so 5 wrong codes kill the login id.
require_once '/var/www/html/lib/DwCustomer.php';

const CS_TTL = 900;
const CS_WRONG_PENALTY = 180;
const CS_GENERIC = 'If that email has an account on this site, a sign-in code is on its way. It expires in 15 minutes.';

function csHelp(): void
{
    DwCustomer::out(200, [
        'ok' => true,
        'path' => '/customer_session/',
        'what' => 'Customer sign-in with an emailed code, on the store\'s own domain. The session is tied to that domain and lasts 30 days. Staff use /session/.',
        'actions' => [
            'request_code' => 'POST {"action":"request_code","email":"you@example.com"}. Always returns id and the same message; the 6-character code is emailed only if the email has an account on this site.',
            'verify' => 'POST {"action":"verify","id":"ID","code":"CODE"}. Signs in on this domain (HttpOnly cookie; the session id is never in the response).',
            'whoami' => 'POST {"action":"whoami"}. Who is signed in on this domain, or signed_in false.',
            'logout' => 'POST {"action":"logout"}. Ends the session on this domain.',
        ],
        'limits' => '1 code per account per minute, 5 per hour. 5 wrong codes cancel the id.',
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    csHelp();
}
$in = DwCustomer::body();
$action = strtolower(trim((string)($in['action'] ?? '')));
if ($action === '') {
    csHelp();
}
if (!in_array($action, ['request_code', 'verify', 'whoami', 'logout'], true)) {
    DwCustomer::out(400, ['ok' => false, 'error' => 'action must be request_code, verify, whoami or logout']);
}
[$brand, $host] = DwCustomer::requireStoreHost();

try {
    $db = Dw::pdo();

    if ($action === 'request_code') {
        $email = DwCustomer::email($in['email'] ?? '');
        if ($email === null) {
            DwCustomer::out(400, ['ok' => false, 'error' => 'a valid email is required']);
        }
        $id = Dw::randId();
        $pid = DwCustomer::personByEmail($db, $email, $brand);
        $mailed = false;
        if ($pid !== null) {
            $count = function (int $sec) use ($db, $pid, $host): int {
                $q = $db->prepare('SELECT COUNT(*) FROM site_login_code WHERE session_id = ? AND domain = ? AND expires > NOW() + INTERVAL ' . (CS_TTL - $sec) . ' SECOND');
                $q->execute([$pid, $host]);
                return (int)$q->fetchColumn();
            };
            if ($count(60) >= 1 || $count(3600) >= 5) {
                header('Retry-After: 60');
                DwCustomer::out(429, ['ok' => false, 'error' => 'a code was sent recently. Wait a minute and try again']);
            }
            $code = DwCustomer::code();
            $db->prepare('INSERT INTO site_login_code (code_hash, session_id, domain, state_hash, expires) VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . CS_TTL . ' SECOND)')
                ->execute([DwCustomer::hash('cul-id', $id), $pid, $host, DwCustomer::hash('cul-code', $id, $code)]);
            $mailed = DwCustomer::mail($email, 'Your sign-in code for ' . $host, "Your sign-in code is " . $code . "\nIt works once and expires in 15 minutes.\nIf you did not ask for it, ignore this email.\n");
        }
        DwCustomer::out(200, ['ok' => true, 'id' => $id, 'message' => CS_GENERIC]);
    }

    if ($action === 'verify') {
        $id = strtolower(trim((string)($in['id'] ?? '')));
        $code = strtoupper(trim((string)($in['code'] ?? '')));
        $bad = 'code is wrong or expired. Ask for a new one';
        if (!preg_match('/^[0-9a-f]{50}$/', $id) || !preg_match('/^[A-Z0-9]{6}$/', $code)) {
            DwCustomer::out(400, ['ok' => false, 'error' => 'id and the 6-character code are required']);
        }
        $key = DwCustomer::hash('cul-id', $id);
        $q = $db->prepare("SELECT session_id, state_hash FROM site_login_code WHERE code_hash = ? AND domain = ? AND session_id <> '' AND used_at IS NULL AND expires > NOW()");
        $q->execute([$key, $host]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            DwCustomer::out(400, ['ok' => false, 'error' => $bad]);
        }
        if (!hash_equals((string)$row['state_hash'], DwCustomer::hash('cul-code', $id, $code))) {
            $db->prepare('UPDATE site_login_code SET expires = expires - INTERVAL ' . CS_WRONG_PENALTY . ' SECOND WHERE code_hash = ?')->execute([$key]);
            DwCustomer::out(400, ['ok' => false, 'error' => $bad]);
        }
        $u = $db->prepare('UPDATE site_login_code SET used_at = NOW() WHERE code_hash = ? AND used_at IS NULL AND expires > NOW()');
        $u->execute([$key]);
        $pid = (string)$row['session_id'];
        $p = $db->prepare('SELECT 1 FROM person WHERE id = ? AND brand = ?');
        $p->execute([$pid, $brand]);
        if ($u->rowCount() !== 1 || !$p->fetchColumn()) {
            DwCustomer::out(400, ['ok' => false, 'error' => $bad]);
        }
        DwCustomer::signIn($db, $brand, $host, $pid);
        DwCustomer::out(200, ['ok' => true, 'signed_in' => true, 'session_days' => DwCustomer::SESSION_DAYS]);
    }

    if ($action === 'logout') {
        DwCustomer::out(200, ['ok' => true, 'signed_out' => DwCustomer::signOut($db)]);
    }

    $me = DwCustomer::current($db);
    if ($me === null) {
        DwCustomer::out(200, ['ok' => true, 'signed_in' => false]);
    }
    $q = $db->prepare("SELECT p.first_name, p.last_name, (SELECT e.email FROM email e WHERE e.owner_id = p.id AND e.owner_type = 'PRSN' AND e.brand = p.brand ORDER BY e.created LIMIT 1) AS email,
        (SELECT DATE_FORMAT(ss.created + INTERVAL " . DwCustomer::SESSION_DAYS . " DAY, '%Y-%m-%d') FROM site_session ss WHERE ss.jti = ?) AS expires
        FROM person p WHERE p.id = ? AND p.brand = ?");
    $q->execute([$me['jti'], $me['person_id'], $brand]);
    DwCustomer::out(200, ['ok' => true, 'signed_in' => true] + ($q->fetch(PDO::FETCH_ASSOC) ?: []));
} catch (Throwable $e) {
    error_log('customer_session: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
    DwCustomer::out(500, ['ok' => false, 'error' => 'could not complete ' . $action]);
}
