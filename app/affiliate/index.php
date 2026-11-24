<?php
declare(strict_types=1);
// /affiliate/ — staff dashboard and admin API (rates, transfers). Public join / r / keep-ref are not gated.
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';
require_once '/var/www/html/lib/DwPermissions.php';
require_once '/var/www/html/lib/DwAffiliate.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function requestBody(): array
{
    $raw = (string)file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (is_array($j)) {
        return $j;
    }
    return $_POST;
}

function wantsJson(array $body): bool
{
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $ct = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (str_contains($ct, 'application/json') || str_contains($accept, 'application/json')) {
        return true;
    }
    return isset($body['format']) && $body['format'] === 'json';
}

/** Short label for an affiliate id (never a referral code). */
function affShort(string $id): string
{
    return strlen($id) > 10 ? substr($id, 0, 8) . '…' : $id;
}

function affLabel(array $row): string
{
    $n = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    return ($n !== '' ? $n : 'Affiliate') . ' (' . affShort((string)$row['affiliate_id']) . ')';
}

/** host and path of a landing URL with the query string removed (the query holds the ?ref= code). */
function landingHostPath(string $u): string
{
    $u = preg_replace('/[?#].*$/s', '', $u);
    return (string)$u;
}

function jsonOut(int $code, array $payload): never
{
    Dw::jsonOut($code, $payload);
    exit;
}

$session = null;
$bearer = null;
$brand = '';
$gate = null;
$affiliates = [];
$referrals = [];
$transfers = [];
$defaultPercent = DwAffiliate::DEFAULT_COMMISSION_PERCENT;
$discountPercent = number_format(DwAffiliate::DISCOUNT_PERCENT, 2, '.', '');
$discountMonths = (string)DwAffiliate::DISCOUNT_MONTHS;
$programEnabled = true;
$programTerms = '';
$error = '';
$noticeOk = '';
$signedIn = false;
$allowed = false;
$personId = '';
$db = Dw::pdo();
$body = $_SERVER['REQUEST_METHOD'] === 'POST' ? requestBody() : [];
$action = is_array($body) ? (string)($GLOBALS['DW_AFF_ACTION'] ?? ($body['action'] ?? '')) : '';
if (isset($GLOBALS['DW_AFF_ACTION']) && isset($body['action']) && (string)$body['action'] !== $GLOBALS['DW_AFF_ACTION']) {
    jsonOut(400, ['ok' => false, 'error' => 'this path is action ' . $GLOBALS['DW_AFF_ACTION'] . '; leave action out or send ' . $GLOBALS['DW_AFF_ACTION']]);
}
// OAuth Bearer (refs #74): JSON only, POST only. affiliate:transfer to decide transfers, affiliate:manage for the rest.
require_once '/var/www/html/lib/DwAffiliateApi.php';
require_once '/var/www/html/lib/DwAffiliateReport.php';
if (DwAffiliateApi::hasBearer()) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(405, ['ok' => false, 'error' => 'POST a JSON body with an action']);
    }
    $scope = in_array($action, ['transfer_approve', 'transfer_reject'], true) ? DwAffiliateApi::SCOPE_TRANSFER : DwAffiliateApi::SCOPE_MANAGE;
    $bearer = DwAffiliateApi::context($db, $scope);
    $session = ['person_id' => $bearer['person_id'], 'brand' => $bearer['brand']];
} else {
    try {
        $session = DwPermissions::resolveSession([]);
    } catch (Throwable $e) {
        $session = null;
    }
}
if (is_array($session) && isset($session['person_id'], $session['brand'])) {
    $signedIn = true;
    $brand = (string)$session['brand'];
    $personId = (string)$session['person_id'];
    try {
        $allowed = DwAffiliate::canManage($db, $personId, $brand);
        $gate = DwAffiliate::gateCheck($db, $brand);
    } catch (Throwable $e) {
        error_log('affiliate dashboard: ' . $e->getMessage());
        $error = 'Could not load the affiliate dashboard.';
    }
}

$asJson = wantsJson($body) || $bearer !== null || isset($GLOBALS['DW_AFF_ACTION']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    if (!$signedIn) {
        if (wantsJson($body) || $asJson) {
            jsonOut(401, ['ok' => false, 'error' => 'sign in required']);
        }
        $error = 'Sign in required.';
    } elseif (!$allowed) {
        if (wantsJson($body) || $asJson) {
            jsonOut(403, ['ok' => false, 'error' => 'This session needs SUPER_ADMIN or the affiliate permission on this brand.']);
        }
        $error = 'This session needs SUPER_ADMIN or the affiliate permission on this brand.';
    } else {
        $result = ['ok' => false, 'error' => 'unknown action'];
        $via = $bearer !== null ? $bearer['via'] : 'session';
        $refRaw = $body['affiliate'] ?? $body['affiliate_id'] ?? $body['code'] ?? '';
        $refRaw = is_string($refRaw) ? trim($refRaw) : '';
        if ($bearer !== null && $action === 'transfer') {
            $result = ['ok' => false, 'http' => 403, 'error' => 'connected apps file transfer requests (action transfer_request); staff approve them with transfer_approve'];
        } elseif ($bearer !== null && $action === 'update_program_settings') {
            $result = DwAffiliateApi::apiProgramSettings($db, $brand, $body, $personId);
        } elseif ($bearer !== null && $action === 'update_default_rate') {
            $result = DwAffiliate::setDefaultCommissionPercent($db, $brand, $body['percent'] ?? $body['default_commission_percent'] ?? null, $personId);
        } elseif ($action === 'read') {
            $result = DwAffiliateApi::staffRead($db, $brand);
        } elseif ($action === 'report') {
            $in = $body;
            unset($in['action'], $in['session']);
            $result = DwAffiliateReport::staff($db, $brand, $in);
        } elseif ($action === 'email_report') {
            // Brand report to the staff member's own verified email on this brand only (never an address from the request).
            $in = $body;
            unset($in['action'], $in['session'], $in['email'], $in['recipient']);
            $result = DwAffiliateReport::email($db, fn(array $i, int $m) => DwAffiliateReport::staff($db, $brand, $i, $m),
                DwAffiliateApi::personEmail($db, $personId, $brand) ?? '', DwAffiliate::brandDisplayName($db, $brand), $in);
        } elseif ($action === 'backfill_login') {
            // One-off for affiliates who joined before the join flow granted LOGIN: same rights code, with history (refs #77).
            $result = $bearer !== null
                ? ['ok' => false, 'http' => 403, 'error' => 'sign-in rights are granted from the dashboard by a SUPER_ADMIN']
                : DwAffiliate::backfillJoinLogin($db, $brand, $personId);
            if (!empty($result['ok'])) {
                $result = ['ok' => true, 'status' => 'done', 'checked' => $result['checked'], 'granted' => $result['granted']];
            }
        } elseif ($action === 'email_affiliate_link') {
            // Staff can have an affiliate's own code and link emailed to the affiliate (never shown or returned, refs #77).
            $raw = $body['affiliate_id'] ?? $body['affiliate'] ?? '';
            $resolved = is_string($raw) && trim($raw) !== '' ? DwAffiliate::resolve($db, trim($raw), $brand) : null;
            if ($bearer !== null) {
                $result = ['ok' => false, 'http' => 403, 'error' => 'staff send an affiliate their link from the dashboard'];
            } elseif ($resolved === null) {
                $result = ['ok' => false, 'error' => 'affiliate not found'];
            } else {
                $sent = DwAffiliateApi::emailReferralLink($db, $resolved['affiliate_id'], $brand, 'staff');
                $result = !empty($sent['ok']) ? ['ok' => true, 'status' => 'sent'] : ['ok' => false, 'error' => 'could not email this affiliate (inactive, no email on file, or the send failed)'];
            }
        } elseif ($action === 'transfer_request') {
            $result = DwAffiliateApi::requestTransfer($db, $brand, $refRaw, $body, $personId, $via);
        } elseif ($action === 'transfer_requests') {
            $result = DwAffiliateApi::listTransferRequests($db, $brand, isset($body['status']) ? (string)$body['status'] : 'pending');
        } elseif ($action === 'transfer_approve' || $action === 'transfer_reject') {
            $result = DwAffiliateApi::decideTransfer($db, $brand, trim((string)($body['request_id'] ?? '')), $action === 'transfer_approve', $personId, $via, (string)($body['note'] ?? ''));
        } elseif ($action === 'update_default_rate' || $action === 'update_program_settings') {
            if ($action === 'update_default_rate' && !array_key_exists('client_discount_percent', $body) && !array_key_exists('program_enabled', $body) && !array_key_exists('terms', $body)) {
                $result = DwAffiliate::setDefaultCommissionPercent($db, $brand, $body['percent'] ?? null, $personId);
            } else {
                $result = DwAffiliate::setProgramSettings($db, $brand, $body, $personId);
            }
        } elseif ($action === 'update_override') {
            $raw = $body['affiliate'] ?? $body['affiliate_id'] ?? $body['code'] ?? '';
            $resolved = is_string($raw) && $raw !== '' ? DwAffiliate::resolve($db, $raw, $brand) : null;
            $clear = array_key_exists('percent', $body)
                && ($body['percent'] === null || $body['percent'] === '' || $body['percent'] === 'default' || $body['percent'] === 'clear');
            if ($resolved === null) {
                $result = ['ok' => false, 'error' => 'affiliate not found'];
            } else {
                $result = DwAffiliate::setCommissionOverride($db, $resolved['affiliate_id'], $body['percent'] ?? null, $clear, $brand);
            }
        } elseif ($action === 'transfer') {
            $raw = $body['affiliate'] ?? $body['affiliate_id'] ?? $body['code'] ?? '';
            $resolved = is_string($raw) && $raw !== '' ? DwAffiliate::resolve($db, $raw, $brand) : null;
            if ($resolved === null) {
                $result = ['ok' => false, 'error' => 'affiliate not found'];
            } else {
                $result = DwAffiliate::transferOwnership(
                    $db,
                    $resolved['affiliate_id'],
                    (string)($body['to_email'] ?? ''),
                    (string)($body['reason'] ?? ''),
                    (string)($body['notes'] ?? ''),
                    $personId,
                    isset($body['effective_at']) ? (string)$body['effective_at'] : null,
                    isset($body['to_company']) ? (string)$body['to_company'] : null,
                    $brand
                );
                if (!empty($result['ok'])) {
                    // Codes go out only by email: the transfer code goes to the staff member who recorded it (refs #74).
                    $tid = (string)($result['transfer']['id'] ?? '');
                    $result = ['ok' => true, 'status' => 'recorded', 'id' => $tid,
                               'emailed' => DwAffiliateApi::emailTransferCode($db, $brand, $personId, $tid)];
                }
            }
        }
        if (wantsJson($body) || $asJson) {
            $code = !empty($result['ok']) ? 200 : (int)($result['http'] ?? (($result['error'] ?? '') === 'affiliate not found' ? 404 : 400));
            if (($result['error'] ?? '') === 'This session needs SUPER_ADMIN or the affiliate permission on this brand.') {
                $code = 403;
            }
            unset($result['http']);
            jsonOut($code, DwAffiliateApi::clean($result));
        }
        if (!empty($result['ok'])) {
            $noticeOk = $action === 'transfer'
                ? 'Transfer recorded.' . (!empty($result['emailed']) ? ' The transfer code was emailed to you.' : ' The code email could not be sent; it is in the transfers list.')
                : ($action === 'email_affiliate_link' ? 'The affiliate was emailed their link and code.' : 'Saved.');
        } else {
            $error = (string)($result['error'] ?? 'The change could not be saved.');
        }
    }
}

if ($signedIn && $allowed && ($gate['allowed'] ?? false)) {
    try {
        $programSettings = DwAffiliate::programSettings($db, $brand);
        $defaultPercent = $programSettings['default_commission_percent'];
        $discountPercent = $programSettings['client_discount_percent'];
        $discountMonths = (string)$programSettings['client_discount_months'];
        $programEnabled = $programSettings['program_enabled'];
        $programTerms = $programSettings['terms_custom'] ? $programSettings['terms'] : '';
        $aq = $db->prepare(
            "SELECT a.affiliate_id, a.status, a.created, a.commission_rate_override,
                    a.person_id, a.owner_person_id,
                    p.first_name, p.last_name, e.email,
                    op.first_name AS owner_first, op.last_name AS owner_last, oe.email AS owner_email
               FROM affiliate a
               JOIN person p ON p.id = a.person_id
               LEFT JOIN email e ON e.owner_id = p.id AND e.owner_type = 'PRSN' AND e.brand = p.brand
               JOIN person op ON op.id = a.owner_person_id
               LEFT JOIN email oe ON oe.owner_id = op.id AND oe.owner_type = 'PRSN' AND oe.brand = op.brand
              WHERE p.brand = ?
              ORDER BY a.created DESC
              LIMIT 200"
        );
        $aq->execute([$brand]);
        $affiliates = $aq->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rq = $db->prepare(
            "SELECT r.created, a.affiliate_id, p.first_name AS aff_first, p.last_name AS aff_last,
                    r.source_type, r.converted_at, r.landing_url, r.commission_percent
               FROM affiliate_referral r
               JOIN affiliate a ON a.affiliate_id = r.affiliate_id
               JOIN person p ON p.id = a.person_id
              WHERE p.brand = ?
              ORDER BY r.created DESC
              LIMIT 25"
        );
        $rq->execute([$brand]);
        $referrals = $rq->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $tq = $db->prepare(
            "SELECT t.id, t.reason, t.effective_at, t.approved_at, a.affiliate_id, p.first_name AS aff_first, p.last_name AS aff_last,
                    pf.first_name AS from_first, pf.last_name AS from_last,
                    pt.first_name AS to_first, pt.last_name AS to_last
               FROM affiliate_ownership_transfer t
               JOIN affiliate a ON a.affiliate_id = t.affiliate_id
               JOIN person p ON p.id = a.person_id
               JOIN person pf ON pf.id = t.from_owner_person_id
               JOIN person pt ON pt.id = t.to_owner_person_id
              WHERE p.brand = ?
              ORDER BY t.effective_at DESC, t.created DESC
              LIMIT 25"
        );
        $tq->execute([$brand]);
        $transfers = $tq->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('affiliate dashboard load: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Could not load the affiliate dashboard.';
    }
} elseif ($signedIn && $allowed && !($gate['allowed'] ?? false) && $error === '') {
    $error = (string)($gate['notice'] ?? 'The affiliate program add-on is not enabled for this brand.');
} elseif ($signedIn && !$allowed && $error === '') {
    $error = 'This session needs SUPER_ADMIN or the affiliate permission on this brand.';
}

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
$notice = is_array($gate) ? (string)($gate['notice'] ?? '') : '';
$offer = is_array($gate) ? (array)($gate['offer'] ?? []) : [];
$price = (string)($offer['price'] ?? DwAffiliate::GATE_PRICE_PLACEHOLDER);
$state = is_array($gate) ? (string)($gate['state'] ?? '') : '';
require_once '/var/www/html/lib/DwBrand.php';
DwBrand::themeOutput(); // page text follows the configured brand (lib/DwBrand.php)
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Affiliate program — DW Alliance</title>
<style>
  :root { color-scheme: light dark; --fg:#1a1a1a; --muted:#555; --line:#ddd; --card:#f7f7f7; --accent:#0b5fff; }
  @media (prefers-color-scheme: dark) {
    :root { --fg:#f2f2f2; --muted:#b0b0b0; --line:#333; --card:#1c1c1c; --accent:#6ea8ff; }
  }
  body{margin:0;font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;color:var(--fg)}
  main{max-width:52rem;margin:0 auto;padding:2rem 1.25rem 4rem}
  h1{font-size:1.75rem;margin:0 0 .5rem} h2{font-size:1.15rem;margin:1.75rem 0 .6rem}
  .muted{color:var(--muted)} a{color:var(--accent)}
  .card{border:1px solid var(--line);background:var(--card);border-radius:.6rem;padding:.9rem 1rem;margin:.75rem 0}
  table{width:100%;border-collapse:collapse;font-size:.95rem}
  th,td{text-align:left;padding:.35rem .4rem;border-bottom:1px solid var(--line);vertical-align:top}
  .err{color:#b00020} .ok{color:#0a7a2f}
  label{display:block;margin:.5rem 0 .15rem;font-size:.9rem}
  input,select,textarea,button{font:inherit}
  input,select,textarea{width:100%;max-width:24rem;padding:.3rem .4rem}
  textarea{min-height:4rem}
  button{margin-top:.6rem;padding:.35rem .8rem}
  code{font-size:.9em}
  form.stack p{margin:.4rem 0}
</style>
</head>
<body>
<main>
  <h1>Affiliate program</h1>
  <p class="muted"><a href="/">Home</a> · <a href="/affiliate/join/">Join as an affiliate</a> · <a href="/permissions/">Permissions</a> · <a href="/session/login/">Sign in</a></p>
<?php if (!$signedIn): ?>
  <div class="card">
    <h2>Sign in required</h2>
    <p><strong>First 10 affiliates free; then $10/month</strong> per brand (not per affiliate), through the usual PayPal subscribe flow. Existing affiliates keep working if a brand goes past 10 without a subscription.</p>
    <p class="muted">Use the magic-link sign-in for this site, then return here. Only a super admin or someone with the affiliate permission can open the dashboard. Public join, share links, and <code>?ref=</code> on any page stay open without a login.</p>
    <p><a href="/session/login/">Sign in</a></p>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Add-on status</h2>
    <p><strong><?= h((string)($gate['pricing_summary'] ?? ('First 10 affiliates free; then $' . $price . '/month'))) ?></strong></p>
    <p><?= h((string)($gate['usage_summary'] ?? '')) ?></p>
    <p>State: <code><?= h($state !== '' ? $state : 'unknown') ?></code><?= $allowed ? '' : ' · access: not granted' ?><?= !empty($gate['can_add']) ? '' : ' · subscribe required to add more' ?></p>
    <p class="muted">The first 10 affiliates are free for this brand. After that the add-on is a flat $<?= h($price) ?>/month per brand, not per affiliate, through the usual PayPal subscribe flow. Transferable rights, commission rates, and the ledger are included in this add-on; there is no separate charge. Existing affiliates keep working if you go past 10 without a subscription. Subscriptions are not created automatically, and no one is charged until a brand owner subscribes.</p>
    <?php if ($notice !== ''): ?><p class="muted"><?= h($notice) ?></p><?php endif; ?>
    <?php if (!empty($offer['subscribe_url'])): ?>
      <?php if (empty($gate['can_add'])): ?>
      <p><a href="<?= h((string)$offer['subscribe_url']) ?>">Subscribe to add more affiliates — <?= h((string)($gate['pricing_summary'] ?? ('$' . $price . '/month'))) ?></a></p>
      <?php else: ?>
      <p class="muted"><a href="<?= h((string)$offer['subscribe_url']) ?>">Subscribe when you go past the free allowance</a></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($noticeOk !== ''): ?>
  <p class="ok"><?= h($noticeOk) ?></p>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
  <p class="err"><?= h($error) ?></p>
  <?php endif; ?>
  <?php if ($allowed && ($gate['allowed'] ?? false)): ?>
  <div class="card" id="brand-affiliate-settings">
    <h2>Brand affiliate settings</h2>
    <p>These settings apply only to this brand. Changing the default commission or the client discount applies only to referrals recorded after the change. Each referred client's commission rate stays fixed for the life of that client's subscription. A matching PayPal referred plan is required before a custom discount actually bills at the reduced price; otherwise attribution still records and the client pays the regular plan.</p>
    <p>Current default commission: <strong><?= h($defaultPercent) ?>%</strong>. Client discount: <strong><?= h($discountPercent) ?>%</strong> for <strong><?= h($discountMonths) ?></strong> months. Program: <strong><?= $programEnabled ? 'enabled' : 'disabled' ?></strong>.</p>
    <form class="stack" method="post">
      <input type="hidden" name="action" value="update_program_settings">
      <label><input type="hidden" name="program_enabled" value="0"><input type="checkbox" name="program_enabled" value="1"<?= $programEnabled ? ' checked' : '' ?>> Program enabled (join and referral capture)</label>
      <label for="default-percent">Default commission percent for new referrals (0–100)</label>
      <input id="default-percent" name="default_commission_percent" type="text" inputmode="decimal" value="<?= h($defaultPercent) ?>" required>
      <label for="discount-percent">Referred-client discount percent (0–100)</label>
      <input id="discount-percent" name="client_discount_percent" type="text" inputmode="decimal" value="<?= h($discountPercent) ?>" required>
      <label for="discount-months">Discount duration in months (0–120)</label>
      <input id="discount-months" name="client_discount_months" type="text" inputmode="numeric" value="<?= h($discountMonths) ?>" required>
      <label for="program-terms">Join-page terms (leave blank to use the generated default; mark any custom wording for legal review)</label>
      <textarea id="program-terms" name="terms" maxlength="20000" style="max-width:100%;min-height:8rem"><?= h($programTerms) ?></textarea>
<?php if (DwConfig::get('AFFILIATE_TERMS_NOTICE') !== ''): ?>
      <p class="muted" role="note"><strong><?= h(DwConfig::get('AFFILIATE_TERMS_NOTICE')) ?></strong></p>
<?php endif; ?>
      <button type="submit">Save brand settings</button>
    </form>
  </div>
  <div class="card">
    <h2>Affiliates</h2>
    <?php if ($affiliates === []): ?>
    <p class="muted">None yet.</p>
    <?php else: ?>
    <table><thead><tr><th>ID</th><th>Original</th><th>Owner</th><th>Override</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($affiliates as $row):
        $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        $owner = trim((string)($row['owner_first'] ?? '') . ' ' . (string)($row['owner_last'] ?? ''));
        $over = $row['commission_rate_override'];
        $overLabel = $over === null || $over === '' ? 'brand default (' . $defaultPercent . '%)' : ((string)$over . '%'); ?>
      <tr>
        <td><code title="Affiliate id"><?= h(affShort((string)$row['affiliate_id'])) ?></code></td>
        <td><?= h($name) ?><br><span class="muted"><?= h((string)($row['email'] ?? '')) ?></span></td>
        <td><?= h($owner) ?><br><span class="muted"><?= h((string)($row['owner_email'] ?? '')) ?></span></td>
        <td><?= h($overLabel) ?></td>
        <td><?= h((string)$row['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <h2>Set an affiliate override</h2>
    <p class="muted">Optional. When set, new referrals from this affiliate use the override instead of the brand default. Existing referred clients keep the rate already stored on their attribution. Leave the percent blank to clear the override.</p>
    <form class="stack" method="post">
      <input type="hidden" name="action" value="update_override">
      <label for="ov-aff">Affiliate</label>
      <select id="ov-aff" name="affiliate_id" required>
        <option value="">Choose an affiliate</option>
        <?php foreach ($affiliates as $row): ?><option value="<?= h((string)$row['affiliate_id']) ?>"><?= h(affLabel($row)) ?></option><?php endforeach; ?>
      </select>
      <label for="ov-pct">Override percent (blank = use brand default)</label>
      <input id="ov-pct" name="percent" type="text" inputmode="decimal" placeholder="e.g. 10">
      <button type="submit">Save override</button>
    </form>
    <h2>Email an affiliate their link</h2>
    <p class="muted">Codes are never shown on screen. This sends the affiliate their own referral link and code, to the email address on their account.</p>
    <form class="stack" method="post">
      <input type="hidden" name="action" value="email_affiliate_link">
      <label for="em-aff">Affiliate</label>
      <select id="em-aff" name="affiliate_id" required>
        <option value="">Choose an affiliate</option>
        <?php foreach ($affiliates as $row): ?><option value="<?= h((string)$row['affiliate_id']) ?>"><?= h(affLabel($row)) ?></option><?php endforeach; ?>
      </select>
      <button type="submit">Email them their link</button>
    </form>
  </div>
  <div class="card" id="transfer-admin">
    <h2>Transfer an affiliate right</h2>
    <p class="muted">Staff on this brand only. Both parties must be identified. The new owner must already be a person on this brand (looked up by email). There is no public marketplace. Commission already recorded stays with the previous owner; invoices paid after the transfer go to the new owner.</p>
    <form class="stack" method="post">
      <input type="hidden" name="action" value="transfer">
      <label for="tr-aff">Affiliate</label>
      <select id="tr-aff" name="affiliate_id" required>
        <option value="">Choose an affiliate</option>
        <?php foreach ($affiliates as $row): ?><option value="<?= h((string)$row['affiliate_id']) ?>"><?= h(affLabel($row)) ?></option><?php endforeach; ?>
      </select>
      <label for="tr-email">New owner email</label>
      <input id="tr-email" name="to_email" type="email" required>
      <label for="tr-co">New owner company name (optional, must already exist)</label>
      <input id="tr-co" name="to_company" type="text">
      <label for="tr-reason">Reason</label>
      <select id="tr-reason" name="reason" required>
        <option value="sale">Sale</option>
        <option value="inheritance">Inheritance</option>
        <option value="corporate_transfer">Corporate transfer</option>
        <option value="other">Other</option>
      </select>
      <label for="tr-when">Effective at (optional; leave blank for now)</label>
      <input id="tr-when" name="effective_at" type="text" placeholder="YYYY-MM-DD HH:MM:SS">
      <label for="tr-notes">Notes</label>
      <textarea id="tr-notes" name="notes" maxlength="2000"></textarea>
      <button type="submit">Register and approve transfer</button>
    </form>
    <h2>Recent transfers</h2>
    <?php if ($transfers === []): ?>
    <p class="muted">None yet.</p>
    <?php else: ?>
    <table><thead><tr><th>Transfer id</th><th>Affiliate</th><th>From</th><th>To</th><th>Reason</th><th>Effective</th></tr></thead><tbody>
    <?php foreach ($transfers as $row):
        $from = trim((string)($row['from_first'] ?? '') . ' ' . (string)($row['from_last'] ?? ''));
        $to = trim((string)($row['to_first'] ?? '') . ' ' . (string)($row['to_last'] ?? '')); ?>
      <tr>
        <td><code><?= h((string)$row['id']) ?></code></td>
        <td><?= h(trim((string)$row['aff_first'] . ' ' . (string)$row['aff_last'])) ?> <code><?= h(affShort((string)$row['affiliate_id'])) ?></code></td>
        <td><?= h($from) ?></td>
        <td><?= h($to) ?></td>
        <td><?= h((string)$row['reason']) ?></td>
        <td><?= h((string)$row['effective_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Recent referrals</h2>
    <?php if ($referrals === []): ?>
    <p class="muted">None yet.</p>
    <?php else: ?>
    <table><thead><tr><th>When</th><th>Affiliate</th><th>Source</th><th>Rate</th><th>Converted</th><th>Landing</th></tr></thead><tbody>
    <?php foreach ($referrals as $row): ?>
      <tr>
        <td><?= h((string)$row['created']) ?></td>
        <td><?= h(trim((string)$row['aff_first'] . ' ' . (string)$row['aff_last'])) ?> <code><?= h(affShort((string)$row['affiliate_id'])) ?></code></td>
        <td><?= h((string)$row['source_type']) ?></td>
        <td><?= h((string)($row['commission_percent'] ?? '')) ?>%</td>
        <td><?= !empty($row['converted_at']) ? 'yes' : 'no' ?></td>
        <td class="muted"><?= h(landingHostPath((string)($row['landing_url'] ?? ''))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
