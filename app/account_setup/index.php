<?php
declare(strict_types=1);
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwSignup.php';
require_once '/var/www/html/lib/DwSignupLog.php';
header('Content-Type: application/json; charset=utf-8');
// Rights every new account gets on its new brand: module => rights
const NEW_ACCOUNT_RIGHTS = [
    'LOGIN' => ['READ'],
    'FILE' => ['CREATE', 'READ', 'UPDATE', 'DELETE'],
    'CRM' => ['CREATE', 'READ', 'UPDATE', 'DELETE'],
    'NOTE' => ['CREATE', 'READ', 'UPDATE', 'DELETE'],
    'DNS' => ['CREATE', 'READ', 'UPDATE', 'DELETE'],
    'DOMAIN' => ['CREATE', 'READ', 'UPDATE', 'DELETE'],
];

function confirmCode(): string
{
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
    $out = '';
    for ($i = 0; $i < 6; $i++) {
        $out .= $alphabet[random_int(0, 35)];
    }
    return $out;
}

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

function fail(array $sent, array $expect, string $error, int $code = 400, string $reason = 'bad_input', array $detail = []): void
{
    if (array_key_exists('confirmation_id', $sent)) {
        $sent['confirmation_id'] = '(sent, not shown)';
    }
    alertSet(['outcome' => 'FAILED (' . $code . '): ' . $error]);
    failLog($reason, $code, $error, $detail);
    Dw::jsonOut($code, ['ok' => false, 'error' => $error, 'you_sent' => $sent, 'expected' => $expect]);
    exit;
}

function help(): void
{
    Dw::jsonOut(200, [
        'path' => '/account_setup',
        'session' => false,
        'flow' => [
            '1. POST email. Response is id. A 6-char confirmation_id is emailed. It expires 30 minutes after created.',
            '2. POST id, confirmation_id, first_name, last_name, and optional company_name. After 5 wrong confirmation_ids the id is cancelled; start again with step 1.',
            '3. That writes person, email, optional company, and a brand row. The brand stored on those rows is the new brand code.',
            '4. If company_name is entered, one relation row is written for the new person and that company.',
            '5. The new person gets LOGIN read, and create, read, update, and delete on FILE, CRM, NOTE, DNS, and DOMAIN.',
            '6. A second email delivers the brand code. Use it with /session. It is never returned in JSON.'
        ],
        'step1' => ['required' => ['email'], 'returns' => ['id']],
        'step2' => ['required' => ['id', 'confirmation_id', 'first_name', 'last_name'], 'optional' => ['company_name'], 'returns' => ['person_id', 'company_id', 'relation_id', 'brand_emailed']],
        'javascript' => "const step1 = await fetch('/account_setup/', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'person@example.com' }) }).then(r => r.json());"
    ]);
    exit;
}

// Signup alert (internal, not in public docs): since 2026-11-04 the admin (ADMIN_EMAIL) is mailed ONLY when a brand
// is created (step 2 success). Every failed outcome, on any node, is written to the shared
// signup_failure table instead (lib/DwSignupLog.php, failLog below); step-1 code sends and other
// successes are neither mailed nor logged. The mail is sent from a shutdown function after the JSON
// response is flushed (fastcgi_finish_request), so a slow or failed relay never delays or breaks a
// signup; failures go to the PHP error log only. The alert must never carry the confirmation_id,
// brand code, session ids or passwords, only the fields set in $ALERT.
define('ALERT_TO', DwConfig::get('ADMIN_EMAIL', DwBrand::supportEmail())); // ADMIN_EMAIL in DwConfig
$ALERT = null;
$STEP = 0;
$FAIL_LOGGED = false;

// One signup_failure row for this request. Never throws, never stops the request.
function failLog(string $reason, int $code, string $error = '', array $detail = [], ?string $setupId = null, ?string $kind = null): void
{
    global $ALERT, $STEP, $FAIL_LOGGED;
    $FAIL_LOGGED = true;
    try {
        $db = ($GLOBALS['db'] ?? null) instanceof PDO ? $GLOBALS['db'] : null;
        $sid = $setupId ?? ($STEP === 2 ? (string)($GLOBALS['id'] ?? '') : null);
        DwSignupLog::failure($db, [
            'reason' => $reason,
            'step' => $STEP,
            'http_status' => $code,
            'error' => $error,
            'email' => is_array($ALERT) ? (string)($ALERT['email'] ?? '') : '',
            'account_setup_id' => $sid,
            'kind' => $kind,
            'detail' => $detail,
        ]);
    } catch (Throwable $e) {
        error_log('account_setup: failure log error (' . get_class($e) . ')');
    }
}

function alertSet(array $fields): void
{
    global $ALERT;
    if (is_array($ALERT)) {
        $ALERT = array_merge($ALERT, $fields);
    }
}

function alertClean($v): string
{
    $v = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$v) ?? '';
    return mb_substr(trim($v), 0, 300);
}

function alertSend(): void
{
    global $ALERT, $FAIL_LOGGED;
    if (!is_array($ALERT)) {
        return;
    }
    try {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if (($ALERT['outcome'] ?? null) === null && !$FAIL_LOGGED) {
            // Fatal error or an exit we did not plan for: log it, never mail it.
            $err = error_get_last();
            failLog('exception', http_response_code() ?: 500, 'unexpected exit', $err ? ['php_error_type' => $err['type'], 'php_error_line' => $err['line']] : []);
        }
        if (($ALERT['account_created'] ?? false) !== true || ALERT_OFF) {
            return; // success-only: no mail for failures, blocks, limits or step-1 code sends
        }
        unset($ALERT['account_created']);
        $a = $ALERT;
        $a['outcome'] = $a['outcome'] ?? ('unexpected exit, HTTP ' . http_response_code());
        $lines = [];
        foreach ($a as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $lines[] = str_pad((string)$k, 16) . ': ' . alertClean(is_bool($v) ? ($v ? 'yes' : 'no') : $v);
        }
        $subject = DwBrand::mark() . ' signup ' . alertClean($a['step'] ?? '?') . ' ' . alertClean($a['outcome']) . ' - ' . alertClean($a['email'] ?? 'no email');
        if (!Dw::sendMail(ALERT_TO, mb_substr($subject, 0, 150), implode("\n", $lines) . "\n")) {
            error_log('account_setup: signup alert mail failed (relay refused or unreachable)');
        }
    } catch (Throwable $e) {
        error_log('account_setup: signup alert error: ' . $e->getMessage());
    }
}

function uniqueBrandCode(PDO $db): string
{
    for ($n = 0; $n < 20; $n++) {
        $id = Dw::randId();
        $code = substr($id, 0, 10);
        $q = $db->prepare('SELECT 1 FROM brand WHERE code = ? OR id = ?');
        $q->execute([$code, $id]);
        if (!$q->fetch()) {
            return $id . '|' . $code;
        }
    }
    fail([], [], 'could not allocate a unique brand id', 400, 'brand_alloc_failed');
}

$sent = body();
if ($sent === []) {
    help();
}
$ALERT = [
    'step' => isset($sent['id']) && trim((string)$sent['id']) !== '' ? '2 (confirm, create account)' : '1 (request code)',
    'outcome' => null,
    'time_hst' => (new DateTime('now', new DateTimeZone('Pacific/Honolulu')))->format('Y-m-d H:i:s T'),
    'email' => isset($sent['email']) ? (string)$sent['email'] : '',
    'first_name' => isset($sent['first_name']) ? (string)$sent['first_name'] : '',
    'last_name' => isset($sent['last_name']) ? (string)$sent['last_name'] : '',
    'company_name' => isset($sent['company_name']) ? (string)$sent['company_name'] : '',
    'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'x_real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? '',
    'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'x_forwarded_host' => $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '',
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? '',
    'referer' => $_SERVER['HTTP_REFERER'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];
$STEP = isset($sent['id']) && trim((string)$sent['id']) !== '' ? 2 : 1;
// signup.alert_off in trust/config.json silences the success alert (staging tests only; production has no such setting).
// The shutdown handler always runs so unexpected exits are still logged.
define('ALERT_OFF', (bool)DwSignup::cfg('alert_off'));
register_shutdown_function('alertSend');
try {
    $db = Dw::pdo();
} catch (Throwable $e) {
    fail($sent, [], 'database unavailable', 500, 'db_unavailable');
}
try {
    require_once '/var/www/html/lib/DwAffiliate.php';
    DwAffiliate::captureReferralFromRequest($db);
} catch (Throwable $e) {
}
$email = trim((string)($sent['email'] ?? ''));
$id = trim((string)($sent['id'] ?? ''));
if ($id === '') {
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail($sent, ['email' => 'required, valid email'], 'email is required');
    }
    $setupId = Dw::randId();
    $code = confirmCode();
    $ip = DwSignup::clientIp();
    // Signup hardening (2026-11-04): lib/DwSignup.php. Network block first, then rate limits.
    alertSet(['client_ip' => $ip]);
    $risk = DwSignup::ipRisk($ip);
    if ($risk['action'] !== 'allow') {
        alertSet(['network' => $risk['kind'], 'outcome' => 'BLOCKED (403): network ' . $risk['kind']]);
        failLog(['tor' => 'blocked_tor', 'vpn' => 'blocked_vpn', 'hosting' => 'blocked_datacenter', 'country' => DwSignup::countryReason()][$risk['kind']] ?? 'blocked_network', 403, 'network ' . $risk['kind'], [], null, $risk['kind']);
        Dw::jsonOut(403, ['ok' => false, 'error' => DwSignup::blockMessage()]);
        exit;
    }
    if (DwSignup::cfg('block_existing_account') && DwSignup::existingAccount($db, DwSignup::normalizeEmail($email))) {
        fail($sent, ['email' => 'an email without an account. Use /session to sign in'], 'an account already exists for this email. Use /session to sign in', 409, 'existing_account');
    }
    $limit = DwSignup::rateLimit($db, $email, $ip);
    if ($limit !== null) {
        header('Retry-After: ' . $limit['retry_after']);
        alertSet(['rate_limit' => $limit['limit']]);
        fail($sent, ['email' => 'required, valid email'], $limit['error'], 429, $limit['limit'] === 'ip_per_hour' ? 'rate_limit_ip' : 'rate_limit_email', ['limit' => $limit['limit']]);
    }
    $db->prepare('INSERT INTO account_setup (id, remote_ip, confirmation_id, email, created) VALUES (?, ?, ?, ?, NOW())')->execute([$setupId, substr($ip, 0, 50), $code, $email]);
    $mailed = Dw::sendMail($email, DwBrand::name() . ' confirmation', "Your confirmation id is " . $code . "\nIt expires in 30 minutes.\n");
    alertSet(['outcome' => 'OK, code emailed', 'code_mail_sent' => $mailed]);
    if (!$mailed) {
        failLog('mail_send_failed', 200, 'confirmation email not accepted by the relay', [], $setupId);
    }
    Dw::jsonOut(200, ['ok' => true, 'id' => $setupId, 'emailed' => $mailed]);
    exit;
}
$expect = ['id' => '50 char id from step 1', 'confirmation_id' => '6 char code from the email, expires 30 minutes after created', 'first_name' => 'required', 'last_name' => 'required', 'company_name' => 'optional'];
$confirm = strtolower(trim((string)($sent['confirmation_id'] ?? '')));
$first = trim((string)($sent['first_name'] ?? ''));
$last = trim((string)($sent['last_name'] ?? ''));
$company = trim((string)($sent['company_name'] ?? ''));
if ($confirm === '' || $first === '' || $last === '') {
    fail($sent, $expect, 'id, confirmation_id, first_name, and last_name are required');
}
// Wrong-code cap (2026-11-04): each try spends 1/5 of the 30 minutes (lib/DwSignup.php).
if (!DwSignup::spendTry($db, $id)) {
    fail($sent, $expect, 'confirmation_id expired, unknown id, or too many wrong codes. Start again with step 1', 400, DwSignupLog::deadIdReason($db, $id));
}
$q = $db->prepare('SELECT confirmation_id, email FROM account_setup WHERE id = ?');
$q->execute([$id]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fail($sent, $expect, 'confirmation_id expired, unknown id, or too many wrong codes. Start again with step 1', 400, 'unknown_id');
}
alertSet(['email' => (string)$row['email']]);
if (strtolower($row['confirmation_id']) !== $confirm) {
    alertSet(['wrong_code' => true]);
    fail($sent, $expect, 'confirmation_id does not match. After ' . (int)DwSignup::cfg('wrong_code_max') . ' wrong codes the id is cancelled and you must start again with step 1', 400, 'wrong_code');
}
DwSignup::refundTry($db, $id);
$pair = uniqueBrandCode($db);
[$brandId, $brandCode] = explode('|', $pair, 2);
$personId = Dw::randId();
$companyId = $company !== '' ? Dw::randId() : null;
$emailId = Dw::randId();
$relationId = $companyId !== null ? Dw::randId() : null;
$email = trim((string)($row['email'] ?? $email));
try {
    $db->beginTransaction();
    $db->prepare('INSERT INTO person (id, first_name, last_name, created, brand) VALUES (?, ?, ?, NOW(), ?)')->execute([$personId, $first, $last, $brandCode]);
    Dw::history($db, 'person', $personId, 'INSERT');
    $db->prepare('INSERT INTO email (id, email, owner_id, owner_type, created, created_by, brand) VALUES (?, ?, ?, ?, NOW(), ?, ?)')->execute([$emailId, $email, $personId, 'PRSN', $personId, $brandCode]);
    Dw::history($db, 'email', $emailId, 'INSERT');
    if ($companyId !== null) {
        $db->prepare('INSERT INTO company (id, company_name, brand) VALUES (?, ?, ?)')->execute([$companyId, $company, $brandCode]);
        Dw::history($db, 'company', $companyId, 'INSERT');
        $db->prepare('INSERT INTO relation (id, person_id, company, created_on, created_by, brand) VALUES (?, ?, ?, NOW(), ?, ?)')->execute([$relationId, $personId, $companyId, $personId, $brandCode]);
        $db->prepare('INSERT INTO relation_history (history_id, id, person_id, company, created_on, created_by, brand, action) SELECT ?, id, person_id, company, created_on, created_by, brand, ? FROM relation WHERE id = ?')->execute([Dw::randId(), 'INSERT', $relationId]);
    }
    $db->prepare('INSERT INTO brand (id, code, created, dw_person_id, dw_company_id) VALUES (?, ?, NOW(), ?, ?)')->execute([$brandId, $brandCode, $personId, $companyId]);
    Dw::history($db, 'brand', $brandId, 'INSERT');
    $access = $db->prepare('INSERT INTO data_access (id, person_id, module_name, right_name, brand, created, created_by_person_id) VALUES (?, ?, ?, ?, ?, NOW(), ?)');
    $accessHistory = $db->prepare('INSERT INTO data_access_history (history_id, id, person_id, module_name, right_name, brand, created, created_by_person_id, action, archived_at) SELECT ?, id, person_id, module_name, right_name, brand, created, created_by_person_id, ?, NOW() FROM data_access WHERE id = ?');
    foreach (NEW_ACCOUNT_RIGHTS as $module => $list) {
        foreach ($list as $right) {
            $accessId = Dw::randId();
            $access->execute([$accessId, $personId, $module, $right, $brandCode, $personId]);
            $accessHistory->execute([Dw::randId(), 'INSERT', $accessId]);
        }
    }
    $db->prepare('DELETE FROM account_setup WHERE id = ?')->execute([$id]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fail($sent, $expect, 'could not complete account setup', 500, 'account_create_failed', ['exception' => get_class($e), 'sqlstate' => $e instanceof PDOException ? (string)$e->getCode() : '']);
}
try {
    require_once '/var/www/html/lib/DwAffiliate.php';
    DwAffiliate::attributeConversionFromRequest($db, [
        'person_id' => $personId,
        'company_id' => $companyId,
        'reference_type' => 'signup',
        'reference_id' => $brandId,
    ]);
} catch (Throwable $e) {
}
$brandMailed = Dw::sendMail($email, DwBrand::name() . ' brand', "Your brand code is " . $brandCode . "\nSend it as brand with your email to /session to get a session id.\nKeep it private.\n");
alertSet(['outcome' => 'OK, account created', 'person_id' => $personId, 'company_id' => $companyId ?? '(no company)', 'brand_mail_sent' => $brandMailed, 'account_created' => true]);
if (!$brandMailed) {
    failLog('brand_mail_failed', 200, 'brand email not accepted by the relay', ['person_id' => $personId]);
}
Dw::jsonOut(200, ['ok' => true, 'person_id' => $personId, 'company_id' => $companyId, 'relation_id' => $relationId, 'brand_emailed' => $brandMailed]);
