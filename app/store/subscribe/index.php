<?php
declare(strict_types=1);
// Self-serve subscribe page on the DW Alliance LLC store (dwalliance.com). Plan -> sign in or create a customer login
// (emailed code) -> pick a site you own -> billing address (Hawaii GET) -> price summary -> PayPal subscription button.
// APIs: /customer_session/, /end_user_account_setup/, /address/, /store/products/, /store/entitlement/ my_sites,
// /store/invoice/payment/ paypal_quote, paypal_subscription_client, paypal_subscribe. Return: /store/subscription_return/.
// Referred clients (refs #64): a visitor who arrived through an affiliate (dw_ref cookie, or ?ref= on this URL) gets 20% off the
// first 12 months of each plan that has a referred plan. $referred maps variant_id => discounted price, for display only;
// the quote and the PayPal plan are decided server-side again at paypal_quote / paypal_subscribe.
$referred = null;
try {
    require_once '/var/www/html/lib/Dw.php';
    require_once '/var/www/html/lib/DwStore.php';
    require_once '/var/www/html/lib/DwAffiliate.php';
    $db = Dw::pdo();
    if (DwAffiliate::attributionFromRequest($db) !== null && DwAffiliate::discountEnabled()) {
        $vs = $db->query("SELECT v.id, COALESCE(v.price, p.price) AS price, v.billing_interval, v.billing_interval_count, v.paypal_plan_id, p.entitlements
            FROM product_variant v JOIN product p ON p.id = v.product_id
            WHERE p.active = 1 AND p.entitlements IS NOT NULL AND p.entitlements <> '' AND FIND_IN_SET('affiliate', p.entitlements) = 0")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($vs as $v) {
            $r = DwAffiliate::referredPlan((string)$v['paypal_plan_id'], (string)$v['price'], (string)$v['billing_interval'], (int)$v['billing_interval_count']);
            if ($r !== null) {
                $map[(string)$v['id']] = DwAffiliate::moneyOf($r['discounted_cents']);
            }
        }
        if ($map !== []) {
            $referred = ['percent' => DwAffiliate::DISCOUNT_PERCENT, 'months' => DwAffiliate::DISCOUNT_MONTHS, 'prices' => $map];
        }
    }
} catch (Throwable $e) {
    $referred = null;
}
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: strict-origin-when-cross-origin');
require_once '/var/www/html/lib/DwBrand.php';
DwBrand::themeOutput(); // page text follows the configured brand (lib/DwBrand.php)
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Subscribe — DW Alliance</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:42rem;margin:2rem auto;padding:0 1rem;color:#1a1a1a;line-height:1.5}
    h1{margin-bottom:.25rem} .step{border:1px solid #ddd;border-radius:10px;padding:1rem 1.25rem;margin:1rem 0}
    .step h2{font-size:1.1rem;margin:0 0 .5rem} .step.off{opacity:.45;pointer-events:none}
    .plans{display:grid;grid-template-columns:1fr 1fr;gap:.5rem} .plans label{border:1px solid #ccc;border-radius:8px;padding:.6rem;cursor:pointer}
    .plans input{margin-right:.4rem} input[type=email],input[type=text],select{width:100%;max-width:24rem;padding:.45rem;border:1px solid #bbb;border-radius:6px;font-size:1rem;box-sizing:border-box}
    button{padding:.5rem 1rem;border-radius:6px;border:1px solid #0a5;background:#0a5;color:#fff;font-size:1rem;cursor:pointer} button.alt{background:#fff;color:#0a5}
    button:disabled{opacity:.5;cursor:default} .msg{color:#555;font-size:.95rem} .err{color:#b00020} .ok{color:#0a5}
    table.sum td{padding:.2rem .8rem .2rem 0} table.sum tr.tot td{font-weight:700;border-top:1px solid #ccc}
    .referred{background:#eaf5ee;border:1px solid #1f6b3a;color:#1f6b3a;border-radius:8px;padding:.6rem .9rem;font-weight:600;margin:1rem 0}
    .fine{font-size:.9rem;color:#555} #paypal-buttons{margin-top:1rem;max-width:24rem}
  </style>
</head>
<body>
  <p><a href="/pricing.html">&larr; Pricing</a></p>
  <h1>Subscribe</h1>
  <p class="msg">Choose a plan, sign in, pick your site and pay with PayPal. It takes about two minutes.</p>
<?php if ($referred !== null) { ?>
  <p class="referred" id="referred-banner">Referred by an affiliate: <?= (int)$referred['percent'] ?>% off your first year</p>
<?php } ?>

  <div class="step" id="s-plan"><h2>1. Plan</h2>
    <div class="plans" id="plans"><span class="msg">Loading plans&hellip;</span></div>
  </div>

  <div class="step off" id="s-acct"><h2>2. Your account</h2>
    <div id="acct-in" hidden><p class="ok" id="acct-who"></p><button class="alt" id="acct-out" type="button">Use a different email</button></div>
    <div id="acct-form">
      <p class="msg">Use the email you sign in to your DW Alliance site with (a super admin of that site). We&rsquo;ll email you a 6-character code.</p>
      <p><input type="email" id="email" autocomplete="email" placeholder="you@example.com"></p>
      <p><label><input type="radio" name="who" value="new" checked> I&rsquo;m new to the DW Alliance store</label><br>
         <label><input type="radio" name="who" value="back"> I&rsquo;ve bought or signed in here before</label></p>
      <p><button id="send-code" type="button">Email me a code</button></p>
      <div id="code-box" hidden>
        <p><input type="text" id="code" maxlength="12" autocomplete="one-time-code" placeholder="6-character code"></p>
        <p><button id="verify" type="button">Continue</button></p>
      </div>
      <p class="msg" id="acct-msg" aria-live="polite"></p>
    </div>
  </div>

  <div class="step off" id="s-site"><h2>3. Your site</h2>
    <p><select id="site"></select></p>
    <p class="msg" id="site-msg" aria-live="polite"></p>
  </div>

  <div class="step off" id="s-addr"><h2>4. Billing address</h2>
    <p class="msg">Used for sales tax: Hawaii addresses pay Hawaii GET. Nothing is shipped.</p>
    <div id="addr-pick" hidden><p><select id="addr-list"></select></p></div>
    <div id="addr-new">
      <p><input type="text" id="a-line1" placeholder="Street address" autocomplete="address-line1"></p>
      <p><input type="text" id="a-city" placeholder="City" autocomplete="address-level2"></p>
      <p><select id="a-state" autocomplete="address-level1"></select></p>
      <p><input type="text" id="a-zip" placeholder="ZIP code" maxlength="10" autocomplete="postal-code"></p>
    </div>
    <p><button id="addr-go" type="button">Show my price</button></p>
    <p class="msg" id="addr-msg" aria-live="polite"></p>
  </div>

  <div class="step off" id="s-pay"><h2>5. Review and pay</h2>
    <table class="sum" id="sum"></table>
    <p class="fine" id="guar"></p>
    <p class="fine">Billed automatically through PayPal each period until you cancel. Cancel any time on the <a href="/store/subscription_cancel/">cancel page</a> or in your PayPal account. Refunds go only to the original payment method (PayPal); if a refund can&rsquo;t be made there, no refund is given. See the <a href="/terms.html">terms</a>.</p>
    <div id="paypal-buttons"></div>
    <p><button class="alt" id="pp-fallback" type="button" hidden>Continue to PayPal</button></p>
    <p class="msg" id="pay-msg" aria-live="polite"></p>
  </div>

  <p class="fine">Questions? Email <a href="mailto:support@dwalliance.com">support@dwalliance.com</a>.</p>

<script>
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var STATES = ['AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','PR','GU','VI','AS','MP'];
  var st = {variant: null, label: '', signedIn: false, setupId: null, loginId: null, site: '', addressId: null, quote: null, sdk: false};
  var qs = new URLSearchParams(location.search);
  var wantPlan = (qs.get('plan') || '').toLowerCase(), wantBill = (qs.get('billing') || '').toLowerCase(), wantSite = (qs.get('site') || '').toLowerCase();
  var ENT_NAMES = {no_banner: 'no banner', store: 'shopping cart', watermark: 'watermarks'};
  var REFERRED = <?= $referred === null ? 'null' : json_encode($referred, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function api(path, body) {
    return fetch(path, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)})
      .then(function (r) { return r.json().catch(function () { return {ok: false, error: 'unexpected reply (' + r.status + ')'}; }); })
      .catch(function () { return {ok: false, error: 'network error, please try again'}; });
  }
  function say(id, text, bad) { var e = $(id); e.textContent = text || ''; e.className = bad ? 'msg err' : 'msg'; }
  function on(id, yes) { $(id).classList.toggle('off', !yes); }
  function opt(sel, value, text) { var o = document.createElement('option'); o.value = value; o.textContent = text; sel.appendChild(o); return o; }
  function per(i) { return i === 'YEAR' ? 'year' : 'month'; }
  function resetFrom(step) {
    var order = ['s-acct', 's-site', 's-addr', 's-pay'];
    for (var i = order.indexOf(step) + 1; i < order.length; i++) { on(order[i], false); }
    if (order.indexOf(step) < 3) { $('paypal-buttons').innerHTML = ''; $('pp-fallback').hidden = true; st.quote = null; }
  }

  STATES.forEach(function (s) { opt($('a-state'), s, s); });
  opt($('a-state'), '', 'State').selected = true;
  $('a-state').insertBefore($('a-state').lastChild, $('a-state').firstChild);

  // 1. plans from the live catalog: every active product that grants something (No Banner, Basic, add-ons)
  api('/store/products/', {action: 'list', limit: 100}).then(function (l) {
    var prods = ((l.ok && l.products) || []).filter(function (p) { return p.active && p.entitlements && p.entitlements.length; });
    return Promise.all(prods.map(function (p) { return api('/store/products/', {action: 'read', product_id: p.product_id}); }));
  }).then(function (rs) {
    var box = $('plans'); box.textContent = '';
    var opts = [], picked = null;
    rs.forEach(function (r) {
      if (!r.ok) { return; }
      var slug = r.product.slug.replace(/-test$/, '');
      (r.product.variants || []).filter(function (v) { return /^P-[A-Z0-9]+$/.test(v.paypal_plan_id || '') && (v.billing_interval === 'MONTH' || v.billing_interval === 'YEAR'); }).forEach(function (v) {
        opts.push({slug: slug, name: r.product.name, v: v, ent: (r.product.entitlements || [])[0] || ''});
      });
    });
    var rank = function (o) { return (o.ent === 'no_banner' ? 0 : 2) + (o.v.billing_interval === 'YEAR' ? 1 : 0); };
    opts.sort(function (a, b) { return rank(a) - rank(b) || parseFloat(a.v.price) - parseFloat(b.v.price); });
    opts.forEach(function (o) {
      var v = o.v, l = document.createElement('label'), inp = document.createElement('input');
      inp.type = 'radio'; inp.name = 'plan'; inp.value = v.variant_id;
      var text = (o.ent && o.ent !== 'no_banner' ? 'Add-on: ' : '') + o.name + ' — $' + v.price + ' / ' + per(v.billing_interval);
      if (REFERRED && REFERRED.prices[v.variant_id]) {
        text += ' (referred: $' + REFERRED.prices[v.variant_id] + ' / ' + per(v.billing_interval) + ' for the first ' + REFERRED.months + ' months, then $' + v.price + ')';
      }
      inp.dataset.label = text; inp.dataset.ent = o.ent;
      l.appendChild(inp); l.appendChild(document.createTextNode(text)); box.appendChild(l);
      var bill = v.billing_interval === 'YEAR' ? ['yearly', 'annual', 'year'] : ['monthly', 'month', ''];
      if (o.slug === wantPlan && bill.indexOf(wantBill) >= 0) { picked = inp; }
      inp.addEventListener('change', function () { st.variant = inp.value; st.label = inp.dataset.label; st.ent = inp.dataset.ent; planChosen(); });
    });
    if (!opts.length) { box.innerHTML = '<span class="msg err">Plans could not be loaded. Please email support@dwalliance.com.</span>'; return; }
    if (picked) { picked.checked = true; st.variant = picked.value; st.label = picked.dataset.label; st.ent = picked.dataset.ent; planChosen(); }
  });

  function planChosen() {
    on('s-acct', true);
    if (st.site && st.addressId) { resetFrom('s-addr'); quote(); }
    if (st.signedIn) { return; }
    api('/customer_session/', {action: 'whoami'}).then(function (r) { if (r.ok && r.signed_in) { signedIn(r.email || ''); } });
  }

  // 2. account
  $('send-code').addEventListener('click', function () {
    var email = $('email').value.trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { say('acct-msg', 'Enter your email address.', true); return; }
    var isNew = document.querySelector('input[name=who]:checked').value === 'new';
    $('send-code').disabled = true;
    api(isNew ? '/end_user_account_setup/' : '/customer_session/', {action: 'request_code', email: email}).then(function (r) {
      $('send-code').disabled = false;
      if (!r.ok) { say('acct-msg', r.error || 'Could not send a code.', true); return; }
      st.setupId = isNew ? r.id : null; st.loginId = isNew ? null : r.id;
      $('code-box').hidden = false; $('code').focus();
      say('acct-msg', isNew ? 'Check your email for a code (it expires in 30 minutes). If you already have a login here, the email will say so: choose “bought or signed in here before” and send a new code.'
        : 'If that email has a login here, a code is on its way (it expires in 15 minutes). No email? Choose “I’m new” and send a new code.');
    });
  });
  $('verify').addEventListener('click', function () {
    var code = $('code').value.trim();
    if (!code) { say('acct-msg', 'Enter the code from the email.', true); return; }
    $('verify').disabled = true;
    var req = st.setupId ? api('/end_user_account_setup/', {action: 'verify', id: st.setupId, confirmation_id: code})
                         : api('/customer_session/', {action: 'verify', id: st.loginId, code: code});
    req.then(function (r) {
      $('verify').disabled = false;
      if (!r.ok) {
        say('acct-msg', /already has an account/.test(r.error || '') ? 'You already have a login here. Choose “bought or signed in here before” and send a new code.' : (r.error || 'That code did not work.'), true);
        return;
      }
      $('code').value = '';
      api('/customer_session/', {action: 'whoami'}).then(function (w) { signedIn((w && w.email) || $('email').value.trim()); });
    });
  });
  $('acct-out').addEventListener('click', function () {
    api('/customer_session/', {action: 'logout'}).then(function () {
      st.signedIn = false; st.site = ''; st.addressId = null;
      $('acct-in').hidden = true; $('acct-form').hidden = false; $('code-box').hidden = true; say('acct-msg', '');
      resetFrom('s-acct');
    });
  });

  function signedIn(email) {
    st.signedIn = true;
    $('acct-who').textContent = 'Signed in' + (email ? ' as ' + email : '') + '.';
    $('acct-in').hidden = false; $('acct-form').hidden = true;
    loadSites();
  }

  // 3. sites
  function loadSites() {
    on('s-site', true);
    var sel = $('site'); sel.textContent = ''; say('site-msg', 'Loading your sites…');
    api('/store/entitlement/', {action: 'my_sites'}).then(function (r) {
      if (!r.ok) { say('site-msg', r.error || 'Could not load your sites.', true); return; }
      if (!r.sites.length) {
        sel.hidden = true;
        var m = $('site-msg'); m.className = 'msg err';
        m.textContent = 'We could not find a DW Alliance site where this email is a super admin. Sign in with your site’s super admin email, or create your site first at ';
        var a = document.createElement('a'); a.href = 'https://app.dwalliance.com/'; a.textContent = 'app.dwalliance.com'; m.appendChild(a); m.appendChild(document.createTextNode('.'));
        return;
      }
      sel.hidden = false; say('site-msg', '');
      opt(sel, '', 'Choose the site this plan is for');
      r.sites.forEach(function (s) {
        var note = s.active.length ? ' (has: ' + s.active.map(function (a) { return ENT_NAMES[a.entitlement] || a.entitlement; }).join(', ') + ')' : '';
        var o = opt(sel, s.domain, s.domain + note); o.dataset.ents = s.active.map(function (a) { return a.entitlement; }).join(',');
      });
      var want = r.sites.findIndex(function (s) { return s.domain === wantSite; });
      if (want >= 0) { sel.selectedIndex = want + 1; siteChosen(); }
      else if (r.sites.length === 1) { sel.selectedIndex = 1; siteChosen(); }
    });
  }
  $('site').addEventListener('change', siteChosen);
  function siteChosen() {
    st.site = $('site').value; resetFrom('s-site');
    if (!st.site) { return; }
    var ents = ($('site').selectedOptions[0].dataset.ents || '').split(',');
    say('site-msg', st.ent && ents.indexOf(st.ent) >= 0 ? 'This site already has this (' + (ENT_NAMES[st.ent] || st.ent) + '). Buying again adds a second subscription; to switch plans, cancel the old one on the cancel page.' : '');
    on('s-addr', true); loadAddresses();
  }

  // 4. address
  function loadAddresses() {
    api('/address/', {action: 'list'}).then(function (r) {
      var sel = $('addr-list'); sel.textContent = '';
      var list = (r.ok && r.addresses) || [];
      if (!list.length) { $('addr-pick').hidden = true; $('addr-new').hidden = false; return; }
      list.forEach(function (a) { opt(sel, a.address_id, [a.line_1, a.city, a.state, a.zipcode].filter(Boolean).join(', ')); });
      opt(sel, '', 'Use a new address');
      $('addr-pick').hidden = false; $('addr-new').hidden = true;
    });
  }
  $('addr-list').addEventListener('change', function () { $('addr-new').hidden = $('addr-list').value !== ''; });
  $('addr-go').addEventListener('click', function () {
    if (!$('addr-pick').hidden && $('addr-list').value) { st.addressId = $('addr-list').value; quote(); return; }
    var a = {action: 'create', address_type: 'Billing', line_1: $('a-line1').value.trim(), city: $('a-city').value.trim(), state: $('a-state').value, zipcode: $('a-zip').value.trim(), country: 'US'};
    if (!a.line_1 || !a.city || !a.state || !a.zipcode) { say('addr-msg', 'Fill in street, city, state and ZIP.', true); return; }
    $('addr-go').disabled = true;
    api('/address/', a).then(function (r) {
      $('addr-go').disabled = false;
      if (!r.ok) { say('addr-msg', r.error || 'Could not save the address.', true); return; }
      st.addressId = r.address_id; quote();
    });
  });

  // 5. quote and PayPal
  function quote() {
    say('addr-msg', 'Getting your price…');
    api('/store/invoice/payment/', {action: 'paypal_quote', variant_id: st.variant, domain: st.site, shipping_address_id: st.addressId}).then(function (r) {
      if (!r.ok) { say('addr-msg', r.error || 'Could not price this plan.', true); return; }
      say('addr-msg', '');
      var q = st.quote = r.quote, t = $('sum'); t.textContent = '';
      var row = function (a, b, cls) { var tr = t.insertRow(); if (cls) { tr.className = cls; } tr.insertCell().textContent = a; tr.insertCell().textContent = b; };
      row('Plan', st.label); row('Site', q.domain);
      var rf = q.referred;
      if (rf) {
        row('Regular price', '$' + rf.regular_subtotal + ' / ' + per(q.billing_interval));
        row('Referred by an affiliate: ' + rf.percent + '% off', '\u2212$' + rf.saved_each_period + ' / ' + per(q.billing_interval));
        row('Price for the first ' + rf.months + ' months', '$' + q.subtotal + ' / ' + per(q.billing_interval));
      } else {
        row('Price', '$' + q.subtotal + ' / ' + per(q.billing_interval));
      }
      row(q.tax_label || 'Sales tax', '$' + q.tax);
      row(rf ? 'Total each ' + per(q.billing_interval) + ' for the first ' + rf.months + ' months' : 'Total each ' + per(q.billing_interval), '$' + q.total, 'tot');
      if (rf) { row('Then, from month ' + (rf.months + 1), '$' + rf.regular_total + ' / ' + per(q.billing_interval) + ' (regular price' + (parseFloat(rf.regular_tax) > 0 ? ' with tax' : '') + ')'); }
      $('guar').textContent = q.guarantee;
      on('s-pay', true); showPaypal();
    });
  }
  function subscribe() {
    say('pay-msg', '');
    return api('/store/invoice/payment/', {action: 'paypal_subscribe', variant_id: st.variant, domain: st.site, shipping_address_id: st.addressId}).then(function (r) {
      if (!r.ok) { say('pay-msg', r.error || 'Could not start the PayPal subscription.', true); throw new Error('subscribe'); }
      st.invoiceId = r.invoice && r.invoice.invoice_id; st.approveUrl = r.approve_url;
      return r;
    });
  }
  function returnUrl(sid) {
    return '/store/subscription_return/?invoice_id=' + encodeURIComponent(st.invoiceId || '') + (sid ? '&subscription_id=' + encodeURIComponent(sid) : '');
  }
  function fallback() { $('pp-fallback').hidden = false; }
  $('pp-fallback').addEventListener('click', function () {
    $('pp-fallback').disabled = true;
    subscribe().then(function (r) { location.href = r.approve_url; }).catch(function () { $('pp-fallback').disabled = false; });
  });
  function renderButtons() {
    $('paypal-buttons').innerHTML = '';
    try {
      window.paypal.Buttons({
        style: {layout: 'vertical', label: 'subscribe', shape: 'rect'},
        createSubscription: function () { return subscribe().then(function (r) { return r.paypal_subscription_id; }); },
        onApprove: function (data) { location.href = returnUrl(data.subscriptionID); },
        onCancel: function () { say('pay-msg', 'Payment cancelled; nothing was charged. You can try again.'); },
        onError: function () { if (!$('pay-msg').textContent) { say('pay-msg', 'PayPal could not finish. Try again, or use “Continue to PayPal”.', true); } fallback(); }
      }).render('#paypal-buttons').catch(fallback);
    } catch (e) { fallback(); }
  }
  function showPaypal() {
    if (st.sdk) { renderButtons(); return; }
    api('/store/invoice/payment/', {action: 'paypal_subscription_client'}).then(function (r) {
      if (!r.ok || !/^[A-Za-z0-9_-]{20,}$/.test(r.client_id || '')) { fallback(); return; }
      var s = document.createElement('script');
      s.src = 'https://www.paypal.com/sdk/js?client-id=' + encodeURIComponent(r.client_id) + '&vault=true&intent=subscription&currency=USD';
      s.setAttribute('data-env', r.env || '');
      s.onload = function () { st.sdk = true; renderButtons(); };
      s.onerror = fallback;
      document.head.appendChild(s);
    });
  }
})();
</script>
</body>
</html>
