<?php
declare(strict_types=1);
// Site-owner cancel page with the 30-day money-back guarantee (staging). API: /store/entitlement/ cancel_preview, cancel_mine.
header('Content-Type: text/html; charset=UTF-8');
require_once '/var/www/html/lib/DwBrand.php';
DwBrand::themeOutput(); // page text follows the configured brand (lib/DwBrand.php)
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cancel a subscription — DW Alliance</title>
</head>
<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem;">
  <h1>Cancel a subscription</h1>
  <p><strong>30-day money-back guarantee.</strong> Cancel within 30 days of your first payment for a full refund; your access ends immediately.
     After 30 days you can still cancel at any time: you keep what you paid for until the end of the period, and nothing is refunded.
     The guarantee applies once per customer and once per site for each plan or add-on.
     Refunds are issued only to the original payment method (PayPal). If a refund cannot be made there, no refund is given.</p>
  <form id="f">
    <p><label>Site (for example <?= htmlspecialchars(DwConfig::get('EXAMPLE_SITE', 'example.com'), ENT_QUOTES) ?>)<br><input name="domain" required size="40"></label></p>
    <button type="submit">Check my subscriptions</button>
  </form>
  <div id="subs"></div>
  <div id="confirm" hidden>
    <p id="whichbox" hidden><label>What to cancel<br><select id="which"></select></label></p>
    <p><label><input type="checkbox" id="ok"> <span id="oktext">I understand.</span></label></p>
    <button type="button" id="go" disabled>Cancel now</button>
  </div>
  <p id="done" style="font-weight:600;"></p>
  <script>
    const post = async (b) => (await fetch('/store/entitlement/', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) })).json();
    const hst = (iso) => iso ? new Date(iso).toLocaleString('en-US', { timeZone: 'Pacific/Honolulu', dateStyle: 'medium', timeStyle: 'short' }) + ' HST' : '';
    const el = (t, txt) => { const e = document.createElement(t); e.textContent = txt; return e; };
    let domain = '';
    document.getElementById('f').addEventListener('submit', async (e) => {
      e.preventDefault();
      domain = new FormData(e.target).get('domain');
      const j = await post({ action: 'cancel_preview', domain });
      const box = document.getElementById('subs'); box.textContent = '';
      if (!j.ok) { box.appendChild(el('p', j.error || 'Could not load your subscriptions')); return; }
      const subs = j.guarantee || [];
      if (!subs.length) { box.appendChild(el('p', 'No PayPal subscriptions found for ' + j.domain + '.')); return; }
      let refund = false;
      const names = { no_banner: 'No Banner / Basic', store: 'Shopping cart add-on', watermark: 'Watermarks add-on' };
      const kinds = [...new Set(subs.map((g) => g.entitlement).filter(Boolean))];
      const pick = document.getElementById('which'); pick.textContent = '';
      if (kinds.length > 1) {
        const all = document.createElement('option'); all.value = ''; all.textContent = 'Everything on ' + j.domain; pick.appendChild(all);
        kinds.forEach((k) => { const o = document.createElement('option'); o.value = k; o.textContent = 'Only: ' + (names[k] || k); pick.appendChild(o); });
      }
      document.getElementById('whichbox').hidden = kinds.length < 2;
      for (const g of subs) {
        const d = document.createElement('div'); d.style = 'border:1px solid #ccc;border-radius:8px;padding:.75rem;margin:.75rem 0';
        d.appendChild(el('h2', g.product_name || 'Subscription'));
        if (g.first_payment_at) d.appendChild(el('p', 'First payment: ' + hst(g.first_payment_at)));
        if (g.eligible) { refund = true; d.appendChild(el('p', 'Full refund available until ' + hst(g.refund_window_ends) + '.')); }
        d.appendChild(el('p', g.message));
        box.appendChild(d);
      }
      document.getElementById('oktext').textContent = refund
        ? 'Cancel now and refund my payment in full to my original payment method (PayPal). I understand my access ends immediately, and that no refund is given if PayPal cannot make it.'
        : 'Cancel now. I keep access until the end of the period I paid for; nothing is refunded.';
      document.getElementById('confirm').hidden = false;
    });
    document.getElementById('ok').addEventListener('change', (e) => { document.getElementById('go').disabled = !e.target.checked; });
    document.getElementById('go').addEventListener('click', async () => {
      document.getElementById('go').disabled = true;
      const which = document.getElementById('which').value;
      const j = await post(which ? { action: 'cancel_mine', domain, entitlement: which } : { action: 'cancel_mine', domain });
      const out = document.getElementById('done');
      if (!j.ok) { out.textContent = j.error || 'Cancel failed'; return; }
      const mb = j.money_back || [];
      const paid = mb.reduce((a, m) => a + parseFloat(m.refunded_total || '0'), 0);
      const failed = mb.some((m) => m.status === 'REFUND_FAILED');
      out.textContent = 'Cancelled.' + (mb.length ? (failed ? ' Your access has ended. PayPal could not make the refund, and refunds are issued only to the original payment method, so no refund was given.'
        : ' Your access has ended and $' + paid.toFixed(2) + ' is being refunded to your PayPal account.') : ' You keep access until the end of the period you paid for.');
    });
  </script>
</body>
</html>
