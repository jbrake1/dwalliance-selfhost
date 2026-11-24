<?php
declare(strict_types=1);
// /store/banner/ — staff UI and API for global and per-brand banner config (refs #45).
require_once '/var/www/html/lib/DwStore.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    require_once '/var/www/html/lib/DwBrand.php';
    DwBrand::themeOutput(); // page text follows the configured brand (lib/DwBrand.php)
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Banner settings — DW Alliance</title>
<style>
  :root { color-scheme: light dark; --fg:#1a1a1a; --muted:#555; --line:#ddd; --card:#f7f7f7; --accent:#0b5fff; --danger:#b00020; }
  @media (prefers-color-scheme: dark) {
    :root { --fg:#f2f2f2; --muted:#b0b0b0; --line:#333; --card:#1c1c1c; --accent:#6ea8ff; --danger:#ff8a80; }
  }
  body{margin:0;font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;color:var(--fg)}
  main{max-width:58rem;margin:0 auto;padding:2rem 1.25rem 4rem}
  h1{font-size:1.75rem;margin:0 0 .5rem} h2{font-size:1.15rem;margin:1.75rem 0 .6rem}
  .muted{color:var(--muted)} a{color:var(--accent)}
  .card{border:1px solid var(--line);background:var(--card);border-radius:.6rem;padding:.9rem 1rem;margin:.75rem 0}
  label{display:block;margin:.5rem 0 .2rem;font-size:.9rem}
  input,button,select{font:inherit;padding:.5rem .7rem;border-radius:.45rem;border:1px solid var(--line);background:transparent;color:var(--fg)}
  button{background:var(--accent);color:#fff;border-color:var(--accent);cursor:pointer}
  button.secondary{background:transparent;color:var(--accent)}
  table{width:100%;border-collapse:collapse;font-size:.92rem}
  th,td{text-align:left;padding:.45rem .3rem;border-bottom:1px solid var(--line);vertical-align:middle}
  .row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:end;margin:.4rem 0}
  .err{color:var(--danger)} .ok{color:green}
  .pill{display:inline-block;font-size:.8rem;padding:.1rem .45rem;border-radius:999px;border:1px solid var(--line)}
  .shown{border-color:#1a7f4b;color:#1a7f4b} .hidden{opacity:.75} .skipped{opacity:.7}
  code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.88rem}
</style>
</head>
<body>
<main>
  <h1>Banner settings</h1>
  <p class="muted">Turn the DW Alliance promo banner on or off for a brand, or leave it on the global default. An active <strong>No Banner</strong> entitlement always hides it, and DW’s own sites are always skipped. The <strong>owner watermark</strong> global default is also set here; it does not hide the DW promo watermark on a free site (only a paid No Banner subscription does). Sign in with the magic link on the DW Alliance store; this page never asks for a session id.</p>
  <p class="muted"><a href="/store/">Store guide</a> · <a href="/session/login/">Sign in</a> · API help: <code>POST /store/banner/</code> with an empty body.</p>

  <div class="card" id="signed-out" hidden>
    <h2>Sign in required</h2>
    <p class="muted">Use the magic-link sign-in for the DW Alliance LLC store, then return here. You need SUPER_ADMIN or a STORE right on that brand.</p>
    <p><a href="/session/login/">Sign in</a></p>
    <p id="status-out" class="muted" role="status"></p>
  </div>

  <div id="panel" hidden>
    <p id="status" class="muted" role="status"></p>
    <div class="card">
      <h2>Global default</h2>
      <p class="muted" id="auth-note"></p>
      <div class="row">
        <div>
          <label for="g-default">Default for brands set to “Use default”</label>
          <select id="g-default">
            <option value="off">Off — hide the banner</option>
            <option value="on">On — show the banner</option>
          </select>
        </div>
        <div>
          <label for="g-style">Default style</label>
          <select id="g-style">
            <option value="A">A: full-width bar fixed to the bottom</option>
            <option value="B">B: slim bar, also fixed to the bottom</option>
            <option value="C">C: small corner badge, also fixed</option>
          </select>
        </div>
        <div>
          <label for="g-wm">Owner watermark default</label>
          <select id="g-wm">
            <option value="off">Off — sites must turn owner marks on</option>
            <option value="on">On — owner marks on unless a domain or file turns them off</option>
          </select>
        </div>
        <button type="button" id="btn-global">Save default</button>
      </div>
    </div>
    <div class="card">
      <h2>Brands</h2>
      <p class="muted">“On” and “Off” override the global default for every domain of that brand. Site owners can still pick style A, B or C on <a href="/permissions/">/permissions/</a>; that owner choice wins over the brand and global style. Staff free grants stay on <a href="/store/entitlement/">/store/entitlement/</a>.</p>
      <div id="brand-list"></div>
    </div>
    <p class="muted"><button type="button" class="secondary" id="btn-reload">Reload</button></p>
  </div>
</main>
<script>
(function(){
  const $ = (id) => document.getElementById(id);
  const status = (msg, ok) => { const el=$('status'); if(!el) return; el.textContent=msg; el.className=ok===true?'ok':(ok===false?'err':'muted'); };
  const statusOut = (msg, ok) => { const el=$('status-out'); if(!el) return; el.textContent=msg; el.className=ok===true?'ok':(ok===false?'err':'muted'); };

  async function api(body){
    const r = await fetch('/store/banner/', {
      method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(body)
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || j.ok === false) {
      const err = new Error(j.error || ('HTTP ' + r.status));
      err.status = r.status;
      err.body = j;
      throw err;
    }
    return j;
  }

  function pill(effective){
    const cls = effective === 'shown' ? 'shown' : (effective === 'skipped' ? 'skipped' : 'hidden');
    const label = effective === 'shown' ? 'Banner shown' : (effective === 'skipped' ? 'Always skipped' : 'Banner hidden');
    return '<span class="pill '+cls+'">'+label+'</span>';
  }

  function modeSelect(brand, current){
    const opts = [['default','Use default'],['on','On'],['off','Off']];
    return '<select data-brand="'+brand+'" data-field="banner_mode">'+
      opts.map(o => '<option value="'+o[0]+'"'+(current===o[0]?' selected':'')+'>'+o[1]+'</option>').join('')+
      '</select>';
  }
  function styleSelect(brand, current){
    const opts = [['default','Use default'],['A','A'],['B','B'],['C','C']];
    return '<select data-brand="'+brand+'" data-field="banner_style">'+
      opts.map(o => '<option value="'+o[0]+'"'+(current===o[0]?' selected':'')+'>'+o[1]+'</option>').join('')+
      '</select>';
  }

  function render(data){
    const g = data.global || {};
    $('g-default').value = g.banner_default === 'on' ? 'on' : 'off';
    $('g-style').value = (g.banner_style === 'B' || g.banner_style === 'C') ? g.banner_style : 'A';
    $('g-wm').value = g.watermark_default === 'on' ? 'on' : 'off';
    const notes = [];
    notes.push(data.db_authoritative ? 'The database is the tenant source of truth.' : 'No global default has been saved yet; banner.json is still the fallback.');
    if (data.kill_switch) notes.push('Emergency kill switch is on (banner.json enabled is false). Nothing will show until that is cleared.');
    $('auth-note').textContent = notes.join(' ');
    const rows = (data.brands || []).map(b => {
      const ent = b.entitlement ? 'No Banner entitlement in force' : '';
      const seller = b.seller ? 'DW’s own site' : '';
      const extra = [seller, ent].filter(Boolean).join(' · ');
      return '<tr>'+
        '<td>'+(b.domain ? b.domain : '')+'<div class="muted"><code>'+b.brand+'</code>'+(b.company ? ' · '+b.company : '')+'</div></td>'+
        '<td>'+(b.seller ? '<span class="muted">Always skipped</span>' : modeSelect(b.brand, b.banner_mode))+'</td>'+
        '<td>'+styleSelect(b.brand, b.banner_style)+'<div class="muted">Effective '+b.effective_style+'</div></td>'+
        '<td>'+pill(b.effective)+(extra ? '<div class="muted">'+extra+'</div>' : '')+'</td>'+
        '</tr>';
    }).join('');
    $('brand-list').innerHTML = '<table><thead><tr><th>Site</th><th>Mode</th><th>Style</th><th>Effective</th></tr></thead><tbody>'+(rows || '<tr><td colspan="4" class="muted">No brands.</td></tr>')+'</tbody></table>';
  }

  async function reload(){
    const data = await api({action:'read'});
    render(data);
    return data;
  }

  async function boot(){
    try {
      await reload();
      $('signed-out').hidden = true;
      $('panel').hidden = false;
    } catch (e) {
      $('panel').hidden = true;
      $('signed-out').hidden = false;
      statusOut(e.status === 401 || e.status === 403 ? (e.message || 'Sign in required.') : (e.message || 'Could not load.'), false);
    }
  }

  $('btn-global').addEventListener('click', async () => {
    try {
      const data = await api({action:'update_global', banner_default: $('g-default').value, banner_style: $('g-style').value, watermark_default: $('g-wm').value});
      render(data);
      status('Global default saved.', true);
    } catch (e) { status(e.message, false); }
  });
  $('btn-reload').addEventListener('click', async () => {
    try { await reload(); status('Reloaded.', true); } catch (e) { status(e.message, false); }
  });
  $('brand-list').addEventListener('change', async (ev) => {
    const t = ev.target;
    if (!(t instanceof HTMLSelectElement) || !t.dataset.brand) return;
    const body = {action:'update_brand', brand: t.dataset.brand};
    body[t.dataset.field] = t.value;
    try {
      const data = await api(body);
      render(data);
      status('Saved '+t.dataset.field.replace('_',' ')+' for '+t.dataset.brand+'.', true);
    } catch (e) { status(e.message, false); boot(); }
  });

  boot();
})();
</script>
</body>
</html>
HTML;
    exit;
}

DwStore::route('banner');
