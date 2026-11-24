<?php
declare(strict_types=1);

require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwAffiliate.php';

header('Cache-Control: no-store');

$joinDb = Dw::pdo();
try {
    DwAffiliate::captureReferralFromRequest($joinDb);
} catch (Throwable $e) {
}

$joinBrand = null;
$joinSettings = null;
try {
    $joinBrand = DwAffiliate::requestBrand($joinDb);
    $joinSettings = $joinBrand !== null ? DwAffiliate::programSettings($joinDb, $joinBrand) : null;
} catch (Throwable $e) {
    error_log('affiliate join program settings: ' . $e->getMessage());
}

function joinProgramView(array $view): array
{
    global $joinDb, $joinBrand, $joinSettings;
    if ($joinBrand === null || $joinSettings === null) {
        $view['program_available'] = false;
        $view['page_title'] = 'Affiliate program';
        $view['heading'] = 'Affiliate program';
        $view['intro'] = 'This site is not set up for the affiliate program.';
        $view['terms'] = '';
        return $view;
    }
    if (!$joinSettings['program_enabled']) {
        $view['program_available'] = false;
        $view['page_title'] = 'Affiliate program';
        $view['heading'] = 'Affiliate program';
        $view['intro'] = 'The affiliate program is not enabled on this site.';
        $view['terms'] = '';
        return $view;
    }
    $name = DwAffiliate::brandDisplayName($joinDb, $joinBrand);
    $view['program_available'] = true;
    $view['page_title'] = 'Join the ' . $name . ' affiliate program';
    $view['heading'] = 'Affiliate program';
    $view['intro'] = 'Join as a ' . $name . ' affiliate. After you verify your email, tell us who you are and the channels you will use.';
    $view['terms'] = $joinSettings['terms'];
    return $view;
}

function joinUrl(): string
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? DwBrand::appHost()));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
        $host = DwBrand::appHost();
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . $host . '/affiliate/join/';
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
    exit;
}

function wantsJson(): bool
{
    if (!empty($GLOBALS['DW_AFF_JSON'])) {
        return true;
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $ctype = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    return str_contains($accept, 'application/json') || str_contains($ctype, 'application/json');
}

function failPage(string $error, int $code = 400): void
{
    if (wantsJson()) {
        // Never include verification codes or links in JSON.
        Dw::jsonOut($code, ['ok' => false, 'error' => $error]);
        exit;
    }
    http_response_code($code);
    render(joinProgramView(['error' => $error, 'step' => 'email']));
    exit;
}

function okJson(array $payload): void
{
    if (isset($payload['token']) || isset($payload['code']) || isset($payload['v']) || isset($payload['link'])) {
        unset($payload['token'], $payload['code'], $payload['v'], $payload['link']);
    }
    Dw::jsonOut(200, $payload);
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function typeOptions(string $selected = ''): string
{
    $html = '';
    foreach (DwAffiliate::CHANNEL_TYPES as $value => $label) {
        $sel = $value === $selected ? ' selected' : '';
        $html .= '<option value="' . h($value) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html;
}

/**
 * @param array<string,mixed> $view
 */
function render(array $view): void
{
    $view = joinProgramView($view);
    $available = !empty($view['program_available']);
    $step = (string)($view['step'] ?? 'email');
    if (!$available) {
        $step = 'unavailable';
    }
    $error = (string)($view['error'] ?? '');
    $subscribeUrl = (string)(($view['gate']['offer']['subscribe_url'] ?? '') ?: '');
    $notice = (string)($view['notice'] ?? '');
    $email = (string)($view['email'] ?? '');
    $first = (string)($view['first_name'] ?? '');
    $last = (string)($view['last_name'] ?? '');
    $company = (string)($view['company_name'] ?? '');
    $affiliateId = (string)($view['affiliate_id'] ?? '');
    $channels = is_array($view['channels'] ?? null) ? $view['channels'] : [];
    $terms = (string)($view['terms'] ?? DwAffiliate::defaultTerms());
    $heading = (string)($view['heading'] ?? 'Affiliate program');
    $intro = (string)($view['intro'] ?? 'Join as a DW Alliance affiliate. After you verify your email, tell us who you are and the channels you will use.');
    $pageTitle = (string)($view['page_title'] ?? 'Join the DW Alliance affiliate program');
    header('Content-Type: text/html; charset=utf-8');
    require_once '/var/www/html/lib/DwBrand.php';
    DwBrand::themeOutput(); // page text follows the configured brand (lib/DwBrand.php)
    echo '<!DOCTYPE html>' . "\n";
    ?>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <style>
    :root { color-scheme: light; }
    body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; margin: 0; background: #f6f4f0; color: #1c1c1c; }
    main { max-width: 42rem; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
    h1 { font-size: 1.75rem; margin: 0 0 .5rem; }
    h2 { font-size: 1.15rem; margin: 1.75rem 0 .75rem; }
    p, label { line-height: 1.5; }
    .terms { background: #fff; border: 1px solid #ddd6c8; border-radius: 8px; padding: 1rem 1.1rem; margin: 1rem 0 1.5rem; }
    .card { background: #fff; border: 1px solid #ddd6c8; border-radius: 8px; padding: 1.15rem 1.2rem; margin: 0 0 1rem; }
    .error { background: #fdecea; border-color: #e3b4ae; color: #8a1f14; }
    .notice { background: #eef6ea; border-color: #c3d7b8; color: #245116; }
    label { display: block; font-weight: 600; margin: .7rem 0 .3rem; }
    input, select { width: 100%; box-sizing: border-box; padding: .5rem .6rem; border: 1px solid #c9c2b6; border-radius: 6px; font: inherit; }
    button { font: inherit; font-weight: 600; padding: .5rem .9rem; border-radius: 6px; border: 0; background: #173a2a; color: #fff; cursor: pointer; }
    button.secondary { background: #fff; color: #173a2a; border: 1px solid #173a2a; }
    button.danger { background: #8a1f14; }
    .row { display: flex; gap: .7rem; flex-wrap: wrap; }
    .row > * { flex: 1 1 10rem; }
    .actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .8rem; }
    .ids { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .85rem; word-break: break-all; }
    .code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 1.25rem; font-weight: 700; letter-spacing: .04em; }
    .muted { color: #5c574e; }
  </style>
</head>
<body>
<main>
  <h1><?= h($heading) ?></h1>
  <p class="muted"><?= h($intro) ?></p>
  <?php if (!$available): ?>
  <p>If you reached this page by mistake, visit the site you were invited from.</p>
  </main></body></html>
  <?php return; endif; ?>
  <div class="terms"><strong>Terms:</strong> <?= h($terms) ?></div>
<?php if ($error !== ''): ?>
  <div class="card error"><?= h($error) ?><?php if ($subscribeUrl !== ''): ?> <a href="<?= h($subscribeUrl) ?>">Subscribe</a><?php endif; ?></div>
<?php endif; ?>
<?php if ($notice !== ''): ?>
  <div class="card notice"><?= h($notice) ?></div>
<?php endif; ?>

<?php if ($step === 'check_email'): ?>
  <div class="card">
    <p>Check your email for a verification path or code. It expires in <?= (int)DwAffiliate::VERIFY_MINUTES ?> minutes. The code and path are sent only by email.</p>
    <form method="post" action="/affiliate/join/">
      <input type="hidden" name="action" value="verify">
      <label for="verify_code">Verification code</label>
      <input id="verify_code" name="code" required maxlength="64" autocomplete="one-time-code">
      <div class="actions"><button type="submit">Verify</button></div>
    </form>
  </div>
<?php elseif ($step === 'profile'): ?>
  <div class="card">
    <p>Signed in as <strong><?= h($email) ?></strong>.</p>
<?php if ($affiliateId !== ''): ?>
    <p>Your affiliate code and referral link are sent only by email, to <strong><?= h($email) ?></strong>.</p>
    <form method="post" action="/affiliate/join/">
      <input type="hidden" name="action" value="send_my_link">
      <div class="actions"><button class="secondary" type="submit">Email my code and link again</button></div>
    </form>
    <p class="muted">Internal id: <span class="ids"><?= h($affiliateId) ?></span></p>
<?php endif; ?>
    <form method="post" action="/affiliate/join/">
      <input type="hidden" name="action" value="save_profile">
      <div class="row">
        <div>
          <label for="first_name">First name</label>
          <input id="first_name" name="first_name" required maxlength="100" value="<?= h($first) ?>">
        </div>
        <div>
          <label for="last_name">Last name</label>
          <input id="last_name" name="last_name" required maxlength="100" value="<?= h($last) ?>">
        </div>
      </div>
      <label for="company_name">Company or business name (optional)</label>
      <input id="company_name" name="company_name" maxlength="100" value="<?= h($company) ?>">
      <div class="actions"><button type="submit">Save information</button></div>
    </form>
  </div>

<?php if ($affiliateId !== ''): ?>
  <h2>Your channels</h2>
<?php if ($channels === []): ?>
  <p class="muted">No channels yet. Add the places you will share from.</p>
<?php endif; ?>
<?php foreach ($channels as $ch): ?>
  <div class="card">
    <p class="muted">Channel code: sent by email.</p>
    <p class="muted">Internal id: <span class="ids"><?= h((string)$ch['affiliate_channel_id']) ?></span></p>
    <form method="post" action="/affiliate/join/">
      <input type="hidden" name="action" value="update_channel">
      <input type="hidden" name="affiliate_channel_id" value="<?= h((string)$ch['affiliate_channel_id']) ?>">
      <div class="row">
        <div>
          <label>Type</label>
          <select name="type" required><?= typeOptions((string)$ch['type']) ?></select>
        </div>
        <div>
          <label>Channel ID, handle, or URL</label>
          <input name="handle" required maxlength="255" value="<?= h((string)$ch['handle']) ?>">
        </div>
      </div>
      <div class="actions">
        <button type="submit">Save channel</button>
      </div>
    </form>
    <form method="post" action="/affiliate/join/" onsubmit="return confirm('Remove this channel?');">
      <input type="hidden" name="action" value="remove_channel">
      <input type="hidden" name="affiliate_channel_id" value="<?= h((string)$ch['affiliate_channel_id']) ?>">
      <div class="actions">
        <button class="danger" type="submit">Remove</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>

  <div class="card">
    <h2>Add a channel</h2>
    <form method="post" action="/affiliate/join/">
      <input type="hidden" name="action" value="add_channel">
      <div class="row">
        <div>
          <label for="new_type">Type</label>
          <select id="new_type" name="type" required><?= typeOptions() ?></select>
        </div>
        <div>
          <label for="new_handle">Channel ID, handle, or URL</label>
          <input id="new_handle" name="handle" required maxlength="255" placeholder="@handle, list name, or https://…">
        </div>
      </div>
      <div class="actions"><button type="submit">Add channel</button></div>
    </form>
  </div>
<?php endif; ?>

<?php else: ?>
  <div class="card">
    <form method="post" action="/affiliate/join/">
      <input type="hidden" name="action" value="request_verify">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" required maxlength="100" value="<?= h($email) ?>" autocomplete="email">
      <div class="actions"><button type="submit">Send verification email</button></div>
    </form>
    <form method="post" action="/affiliate/join/" style="margin-top:1.2rem">
      <input type="hidden" name="action" value="verify">
      <label for="verify_code_email">Already have a verification code?</label>
      <input id="verify_code_email" name="code" maxlength="64" autocomplete="one-time-code">
      <div class="actions"><button class="secondary" type="submit">Verify</button></div>
    </form>
  </div>
<?php endif; ?>
</main>
</body>
</html>
    <?php
}

try {
    $db = Dw::pdo();
} catch (Throwable $e) {
    failPage('service unavailable', 500);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$jsonBody = [];
$rawIn = file_get_contents('php://input') ?: '';
if ($rawIn !== '' && str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $decoded = json_decode($rawIn, true);
    if (is_array($decoded)) {
        $jsonBody = $decoded;
    }
}
$action = trim((string)($jsonBody['action'] ?? $_POST['action'] ?? $_GET['action'] ?? ''));
// One OpenAPI operation per path (refs #74): /affiliate/me/... folders set DW_AFF_ACTION.
if (isset($GLOBALS['DW_AFF_ACTION'])) {
    $GLOBALS['DW_AFF_JSON'] = true;
    if ($action !== '' && $action !== $GLOBALS['DW_AFF_ACTION']) {
        Dw::jsonOut(400, ['ok' => false, 'error' => 'this path is action ' . $GLOBALS['DW_AFF_ACTION'] . '; leave action out or send ' . $GLOBALS['DW_AFF_ACTION']]);
        exit;
    }
    $action = (string)$GLOBALS['DW_AFF_ACTION'];
}
$token = trim((string)($_GET['v'] ?? ''));
if ($action === 'join') {
    $action = 'save_profile';
}

/** Field from a JSON body or a form post. */
function joinField(string $k): string
{
    global $jsonBody;
    $v = $jsonBody[$k] ?? $_POST[$k] ?? '';
    return is_scalar($v) ? (string)$v : '';
}

// OAuth Bearer with affiliate:join (refs #74): the connected person is the affiliate; their email was verified at sign-in.
require_once '/var/www/html/lib/DwAffiliateApi.php';
require_once '/var/www/html/lib/DwAffiliateReport.php';
$bearerCtx = null;
$bearerSession = null;
if (DwAffiliateApi::hasBearer()) {
    $GLOBALS['DW_AFF_JSON'] = true;
    if (in_array($action, ['request_verify', 'verify'], true) || $token !== '') {
        Dw::jsonOut(400, ['ok' => false, 'error' => 'not needed: this connection already verified your email; call join (save_profile) directly']);
        exit;
    }
    if ($method !== 'POST' && $action === '') {
        $action = 'me';
    }
    $bearerCtx = DwAffiliateApi::context($db, DwAffiliateApi::SCOPE_JOIN);
    $bearerSession = DwAffiliateApi::joinSession($db, $bearerCtx);
}

if ($method === 'GET' && $token !== '') {
    $consumed = DwAffiliate::consumeVerification($db, $token);
    if ($consumed === null) {
        failPage('that verification link is invalid or has expired. Request a new email to continue.');
    }
    DwAffiliate::setSessionCookie($consumed['cookie']);
    redirect('/affiliate/join/');
}

if ($method === 'POST' && $action === 'request_verify') {
    $email = (string)($jsonBody['email'] ?? $_POST['email'] ?? '');
    $result = DwAffiliate::requestVerification($db, $email, joinUrl());
    if (empty($result['ok'])) {
        if (wantsJson()) {
            okJson(['ok' => false, 'error' => (string)($result['error'] ?? 'could not send the verification email')]);
        }
        failPage((string)($result['error'] ?? 'could not send the verification email'));
    }
    if (wantsJson()) {
        okJson(['ok' => true, 'mailed' => true]);
    }
    render(['step' => 'check_email', 'notice' => 'Check your email for a verification path or code. It is sent only by email.']);
    exit;
}

if ($method === 'POST' && $action === 'verify') {
    $code = trim((string)($jsonBody['code'] ?? $_POST['code'] ?? ''));
    $consumed = DwAffiliate::consumeVerification($db, $code);
    if ($consumed === null) {
        failPage('that verification code is invalid or has expired. Request a new email to continue.');
    }
    DwAffiliate::setSessionCookie($consumed['cookie']);
    if (wantsJson()) {
        okJson(['ok' => true, 'verified' => true]);
    }
    redirect('/affiliate/join/');
}

$cookie = (string)($_COOKIE[DwAffiliate::COOKIE] ?? '');
$session = $bearerSession ?? ($cookie !== '' ? DwAffiliate::sessionFromCookie($db, $cookie) : null);

// Join page button: email the code and link again (HTML form; codes are never shown on screen).
if ($method === 'POST' && $action === 'send_my_link' && !wantsJson() && $bearerSession === null) {
    if ($session === null) {
        failPage('verify your email first');
    }
    $brandNow = DwAffiliate::requestBrand($db);
    $found = $brandNow !== null ? DwAffiliate::findAffiliateByEmail($db, $session['email'], $brandNow) : null;
    $sent = $found !== null ? DwAffiliateApi::emailReferralLink($db, (string)$found['affiliate_id'], $brandNow, 'page') : ['ok' => false, 'error' => 'join the affiliate program first'];
    redirect('/affiliate/join/?sent=' . (!empty($sent['ok']) ? '1' : '0'));
}

// Self-service reads and the referral link by email (refs #74). Cookie session or Bearer; JSON only.
if (in_array($action, ['me', 'referrals', 'commission_summary', 'send_my_link', 'report', 'email_report'], true)) {
    $GLOBALS['DW_AFF_JSON'] = true;
    if ($session === null) {
        Dw::jsonOut(401, ['ok' => false, 'error' => 'verify your email first (or connect with the affiliate:join permission)']);
        exit;
    }
    $brandNow = DwAffiliate::requestBrand($db);
    if ($brandNow === null) {
        Dw::jsonOut(404, ['ok' => false, 'error' => 'this site is not set up for the affiliate program']);
        exit;
    }
    $aidNow = $session['affiliate_id'] ?? null;
    if ($aidNow === null) {
        $found = DwAffiliate::findAffiliateByEmail($db, $session['email'], $brandNow);
        $aidNow = $found['affiliate_id'] ?? null;
    } else {
        // A cookie session's affiliate must belong to this site's brand.
        $chk = $db->prepare('SELECT 1 FROM affiliate a JOIN person p ON p.id = a.person_id WHERE a.affiliate_id = ? AND p.brand = ?');
        $chk->execute([$aidNow, $brandNow]);
        if (!$chk->fetchColumn()) {
            $found = DwAffiliate::findAffiliateByEmail($db, $session['email'], $brandNow);
            $aidNow = $found['affiliate_id'] ?? null;
        }
    }
    if ($action === 'me') {
        $out = DwAffiliateApi::me($db, $aidNow, $brandNow);
    } elseif ($aidNow === null) {
        $out = ['ok' => false, 'error' => 'join the affiliate program first'];
    } elseif ($action === 'referrals') {
        $out = DwAffiliateApi::referrals($db, $aidNow, (int)(joinField('limit') ?: 50), (int)(joinField('offset') ?: 0));
    } elseif ($action === 'commission_summary') {
        $out = DwAffiliateApi::commissionSummary($db, $aidNow);
    } elseif ($action === 'report' || $action === 'email_report') {
        // On-demand reports (refs #74): own affiliate on this brand only. Email goes only to the affiliate's verified address.
        $in = is_array($jsonBody ?? null) && $jsonBody !== [] ? $jsonBody : $_POST;
        unset($in['action']);
        if ($action === 'report') {
            $out = DwAffiliateReport::self($db, $brandNow, $aidNow, $in);
        } else {
            $pq = $db->prepare('SELECT person_id FROM affiliate WHERE affiliate_id = ?');
            $pq->execute([$aidNow]);
            $to = DwAffiliateApi::personEmail($db, (string)$pq->fetchColumn(), $brandNow) ?? '';
            $out = DwAffiliateReport::email($db, fn(array $i, int $m) => DwAffiliateReport::self($db, $brandNow, $aidNow, $i, $m),
                $to, DwAffiliate::brandDisplayName($db, $brandNow), $in);
        }
    } else {
        $out = DwAffiliateApi::emailReferralLink($db, $aidNow, $brandNow);
    }
    $http = !empty($out['ok']) ? 200 : (int)($out['http'] ?? 400);
    unset($out['http']);
    Dw::jsonOut($http, DwAffiliateApi::clean($out));
    exit;
}

if ($method === 'POST' && in_array($action, ['save_profile', 'add_channel', 'update_channel', 'remove_channel'], true)) {
    if ($session === null) {
        failPage('verify your email first');
    }
    if ($action === 'save_profile') {
        $brandBefore = DwAffiliate::requestBrand($db);
        $hadAffiliate = $brandBefore !== null && DwAffiliate::findAffiliateByEmail($db, $session['email'], $brandBefore) !== null;
        $result = DwAffiliate::saveProfile(
            $db,
            $session,
            joinField('first_name'),
            joinField('last_name'),
            joinField('company_name')
        );
        $mailed = false;
        if (!empty($result['ok']) && !$hadAffiliate && $brandBefore !== null) {
            // Codes go out only by email (owner's rule): the new affiliate's code and link go to the verified address.
            $mailed = !empty(DwAffiliateApi::emailReferralLink($db, (string)$result['affiliate_id'], $brandBefore, 'join')['ok']);
        }
        if (wantsJson()) {
            $out = !empty($result['ok'])
                ? ['ok' => true, 'affiliate_id' => (string)$result['affiliate_id'], 'joined' => !$hadAffiliate, 'mailed' => $mailed, 'login_granted' => !empty($result['login_granted']),
                   'note' => $mailed ? 'Joined. Your referral code and link were emailed to your verified address.' : 'Saved. Your referral code and link are sent only by email: call send_my_link (emailMyReferralLink).']
                : ['ok' => false, 'error' => (string)($result['error'] ?? 'could not save')];
            if (empty($result['ok']) && isset($result['gate']['state'])) {
                $out['add_on_needed'] = true;
            }
            Dw::jsonOut(!empty($result['ok']) ? 200 : (isset($result['gate']) ? 402 : 400), DwAffiliateApi::clean($out));
            exit;
        }
        if (empty($result['ok'])) {
            $state = DwAffiliate::loadPageState($db, $session);
            $state['step'] = 'profile';
            $state['affiliate_id'] = (string)($state['affiliate']['affiliate_id'] ?? '');
                    $state['error'] = (string)($result['error'] ?? 'could not save');
            if (isset($result['gate']) && is_array($result['gate'])) {
                $state['gate'] = $result['gate'];
            }
            render($state);
            exit;
        }
        redirect('/affiliate/join/' . ($mailed ? '?sent=1' : ''));
    }
    if ($session['affiliate_id'] === null) {
        $found = DwAffiliate::findAffiliateByEmail($db, $session['email']);
        $session['affiliate_id'] = $found['affiliate_id'] ?? null;
    }
    if ($session['affiliate_id'] === null) {
        failPage('save your information before adding channels');
    }
    $aid = $session['affiliate_id'];
    if ($action === 'add_channel') {
        $result = DwAffiliate::addChannel($db, $aid, joinField('type'), joinField('handle'));
    } elseif ($action === 'update_channel') {
        $result = DwAffiliate::updateChannel($db, $aid, joinField('affiliate_channel_id'), joinField('type'), joinField('handle'));
    } else {
        $result = DwAffiliate::removeChannel($db, $aid, joinField('affiliate_channel_id'));
    }
    if (wantsJson()) {
        Dw::jsonOut(!empty($result['ok']) ? 200 : 400, DwAffiliateApi::clean($result));
        exit;
    }
    if (empty($result['ok'])) {
        $state = DwAffiliate::loadPageState($db, $session);
        $state['step'] = 'profile';
        $state['affiliate_id'] = (string)($state['affiliate']['affiliate_id'] ?? $aid);
            $state['error'] = (string)($result['error'] ?? 'could not update channels');
        render($state);
        exit;
    }
    redirect('/affiliate/join/');
}

if ($session !== null) {
    $state = DwAffiliate::loadPageState($db, $session);
    $state['step'] = 'profile';
    if (($_GET['sent'] ?? '') === '1') {
        $state['notice'] = 'Your affiliate code and referral link were emailed to ' . (string)$session['email'] . '.';
    } elseif (($_GET['sent'] ?? '') === '0') {
        $state['error'] = 'We could not email your code and link. Try again later.';
    }
    $state['affiliate_id'] = (string)($state['affiliate']['affiliate_id'] ?? '');
    render($state);
    exit;
}

if ($method === 'GET' && $action === '') {
    render(['step' => 'email']);
    exit;
}

failPage('verify your email first');
