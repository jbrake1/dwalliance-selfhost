<?php
declare(strict_types=1);
// /permissions — per-domain editor switch and grants (staging 2026-11-04).
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';
require_once '/var/www/html/lib/DwPermissions.php';
require_once '/var/www/html/lib/DwBanner.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $data = is_array($json) ? $json : $_POST;
    if (!isset($data['action']) && isset($_GET['action']) && is_string($_GET['action'])) {
        $data['action'] = $_GET['action'];
    }
    return $data;
}

function shown(array $sent): array
{
    foreach (['session', 'brand'] as $k) {
        if (array_key_exists($k, $sent)) {
            $sent[$k] = '(sent, not shown)';
        }
    }
    return $sent;
}

function expected(): array
{
    return [
'action' => 'list, set_editor_enabled, set_banner_style, set_watermark, grant_editor, revoke_editor, grant_super_admin, revoke_super_admin, grant_affiliate, revoke_affiliate',
        'session' => 'Optional for scripts. Otherwise __Host-dw_s (customer domain) or session cookie; Admin UI never asks for it.',
        'brand' => 'optional when it matches the signed-in session brand; otherwise required',
        'rights' => 'SUPER_ADMIN on this brand',
        'domain' => 'domain_name owned by this brand (for domain actions)',
        'email' => 'person email on this brand (for grant/revoke)',
        'enabled' => 'boolean for set_editor_enabled',
        'style' => 'A, B or C for set_banner_style',
        'watermark_mode' => 'default, on or off for set_watermark (owner mark only)',
        'watermark_config' => 'optional JSON/object for the owner mark style, or kind/text/image_file_id/position/size/opacity/color/angle fields',
    ];
}

function fail(array $sent, string $error, int $code = 400): void
{
    Dw::jsonOut($code, ['ok' => false, 'error' => $error, 'you_sent' => shown($sent), 'expected' => expected()]);
    exit;
}

function help(): void
{
    Dw::jsonOut(200, [
        'path' => '/permissions',
        'session' => 'Optional for scripts. Otherwise magic-link __Host-dw_s (or session cookie). Brand may be omitted when it matches the session. Caller must be SUPER_ADMIN. Cookie writes need same-origin.',
        'actions' => [
            'list' => 'Domains of the brand with editor_enabled, editors per domain, super_admins, affiliate_managers, and affiliate_gate. No codes returned.',
            'set_editor_enabled' => 'domain (domain_name), enabled (true/false). Per-domain editor switch; off by default.',
            'set_banner_style' => 'domain, style: A (full-width bar fixed to the bottom of the screen, default), B (slimmer bar, also fixed to the bottom) or C (corner badge, also fixed). All three stay on screen while visitors scroll and cannot be dismissed. Only the No Banner upgrade removes it.',
            'set_watermark' => 'domain; optional watermark_mode (default, on or off) and owner-mark style (kind text|image, text, image_file_id, position, size, opacity, color, angle). File-level off still wins for the owner mark. The DW promo mark on a free site cannot be turned off here.',
            'grant_editor' => 'domain, email. Person must already exist on this brand with an email row. Grants EDITOR for that domain only.',
            'revoke_editor' => 'domain, email.',
            'grant_super_admin' => 'email. Brand-wide SUPER_ADMIN (LOGIN/SUPER_ADMIN). Refreshes session.rights.',
            'revoke_super_admin' => 'email. Refuses if it would remove the last SUPER_ADMIN.',
            'grant_affiliate' => 'email. Brand-wide AFFILIATE (READ/CREATE/UPDATE/DELETE). Super admins already manage the program.',
            'revoke_affiliate' => 'email. Removes the AFFILIATE permission only.',
        ],
        'notes' => [
            'EDITOR is per domain; SUPER_ADMIN can edit every domain of the brand and is the only role that can change these settings.',
            'markup.json brands remain a fallback when no domain_setting row exists, so existing sites keep working.',
            'Suggested edits are emailed to all SUPER_ADMIN addresses on the brand; if none, the legacy alert_to is used.',
            'Admin UI: GET /permissions/ (HTML). Uses the magic-link __Host-dw_s cookie (credentials include); never paste a session id into the page.',
            'Cookie-authenticated writes require same-origin (Sec-Fetch-Site or Origin), matching /session/logout. Scripts may still pass session in JSON.',
            'brand may be omitted when it matches the signed-in session brand.',
        ],
        'examples' => [
            'list_cookie' => "await fetch('/permissions/',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})}).then(r=>r.json())",
            'list_script' => "await fetch('/permissions/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list',session:'SESSION_ID',brand:'BRANDCODE'})}).then(r=>r.json())",
            'set_editor_enabled' => "await fetch('/permissions/',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'set_editor_enabled',domain:'example.com',enabled:true})}).then(r=>r.json())",
            'grant_editor' => "await fetch('/permissions/',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'grant_editor',domain:'example.com',email:'editor@example.com'})}).then(r=>r.json())",
        ],
    ]);
    exit;
}

function need(array $sent, string $key): string
{
    $v = trim((string)($sent[$key] ?? ''));
    if ($v === '') {
        fail($sent, $key . ' is required');
    }
    if (strlen($v) > 200) {
        fail($sent, $key . ' is too long');
    }
    return $v;
}

function needEmail(array $sent): string
{
    $email = strtolower(need($sent, 'email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        fail($sent, 'email is not valid');
    }
    return $email;
}

function needDomainName(array $sent): string
{
    $d = strtolower(need($sent, 'domain'));
    $d = rtrim($d, '.');
    if (!DwDomain::validHost($d)) {
        fail($sent, 'domain is not a valid hostname');
    }
    return $d;
}

function boolEnabled(array $sent): bool
{
    if (!array_key_exists('enabled', $sent)) {
        fail($sent, 'enabled is required');
    }
    $v = $sent['enabled'];
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) || is_float($v)) {
        return (int)$v === 1;
    }
    $s = strtolower(trim((string)$v));
    if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($s, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    fail($sent, 'enabled must be true or false');
    return false;
}

function displayName(array $person): string
{
    $n = trim((string)($person['first_name'] ?? '') . ' ' . (string)($person['last_name'] ?? ''));
    return $n !== '' ? $n : (string)($person['email'] ?? '');
}

function adminPage(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    require_once '/var/www/html/lib/DwBrand.php';
    DwBrand::themeOutput(); // page text follows the configured brand (lib/DwBrand.php)
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Permissions — DW Alliance</title>
<style>
  :root { color-scheme: light dark; --fg:#1a1a1a; --muted:#555; --line:#ddd; --card:#f7f7f7; --accent:#0b5fff; --danger:#b00020; }
  @media (prefers-color-scheme: dark) {
    :root { --fg:#f2f2f2; --muted:#b0b0b0; --line:#333; --card:#1c1c1c; --accent:#6ea8ff; --danger:#ff8a80; }
  }
  body{margin:0;font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;color:var(--fg)}
  main{max-width:52rem;margin:0 auto;padding:2rem 1.25rem 4rem}
  h1{font-size:1.75rem;margin:0 0 .5rem} h2{font-size:1.15rem;margin:1.75rem 0 .6rem}
  .muted{color:var(--muted)} a{color:var(--accent)}
  .card{border:1px solid var(--line);background:var(--card);border-radius:.6rem;padding:.9rem 1rem;margin:.75rem 0}
  label{display:block;margin:.5rem 0 .2rem;font-size:.9rem} input,button,select{font:inherit;padding:.5rem .7rem;border-radius:.45rem;border:1px solid var(--line);background:transparent;color:var(--fg)}
  button{background:var(--accent);color:#fff;border-color:var(--accent);cursor:pointer} button.secondary{background:transparent;color:var(--accent)}
  button.danger{background:transparent;color:var(--danger);border-color:var(--danger)}
  table{width:100%;border-collapse:collapse;font-size:.92rem} th,td{text-align:left;padding:.45rem .3rem;border-bottom:1px solid var(--line);vertical-align:top}
  .row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:end;margin:.4rem 0} .err{color:var(--danger)} .ok{color:green}
  code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.88rem}
</style>
</head>
<body>
<main>
  <h1>Permissions</h1>
  <p class="muted">Turn the Suggest-an-edit tool on per domain, pick the DW banner style, set the owner watermark (text or logo, position, size, opacity), grant editors or super admins, and grant affiliate managers. Only a <strong>SUPER_ADMIN</strong> of the brand can use this. Sign in with the normal magic link; this page never asks for a session id. The DW Alliance promo mark on a free site cannot be turned off here.</p>
  <p class="muted"><a href="/">Home</a> · <a href="/session/login/">Sign in</a> · API help: <code>POST /permissions/</code> with an empty body.</p>

  <div class="card" id="signed-out" hidden>
    <h2>Sign in required</h2>
    <p class="muted">Use the magic-link sign-in for this site, then return here. On the app host, sign in to your customer domain first, then open <code>/permissions/</code> on that domain.</p>
    <p><a href="/session/login/">Sign in</a></p>
    <p id="status-out" class="muted" role="status"></p>
  </div>

  <div id="panel" hidden>
    <p id="status" class="muted" role="status"></p>
    <div class="card">
      <h2>Super admins</h2>
      <div id="sa-list"></div>
      <div class="row">
        <div><label for="sa-email">Grant super admin</label><input id="sa-email" type="email" placeholder="person@example.com"></div>
        <button type="button" id="btn-sa-grant">Grant</button>
      </div>
    </div>
    <div class="card">
      <h2>Affiliate program</h2>
      <div id="aff-gate"></div>
      <div id="aff-list"></div>
      <div class="row">
        <div><label for="aff-email">Grant affiliate manager</label><input id="aff-email" type="email" placeholder="person@example.com"></div>
        <button type="button" id="btn-aff-grant">Grant</button>
      </div>
      <p><strong>First 10 affiliates free; then $10/month</strong> per brand (not per affiliate). Existing affiliates keep working if the brand goes past 10 without a subscription.</p>
      <p class="muted">Brand-wide. Super admins can already manage affiliates. This grant is for people who should manage the program without being a super admin. It is not granted automatically on new accounts. First 10 affiliates are free per brand; then $10/month for the brand (not per affiliate). Existing affiliates keep working if the brand goes past 10 without a subscription.</p>
    </div>
    <div class="card">
      <h2>Domains</h2>
      <div id="dom-list"></div>
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
    const r = await fetch('/permissions/', {
      method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(body)
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || j.ok === false) {
      const err = (j && j.error) ? j.error : ('HTTP ' + r.status);
      const e = new Error(err);
      e.status = r.status;
      e.payload = j;
      throw e;
    }
    return j;
  }

  function render(data){
    const sas = (data.super_admins||[]).map(s => '<tr><td>'+escapeHtml(s.email)+'</td><td>'+escapeHtml(s.name||'')+'</td><td><button type="button" class="danger btn-sa-revoke" data-email="'+escapeAttr(s.email)+'">Revoke</button></td></tr>').join('');
    $('sa-list').innerHTML = sas ? '<table><thead><tr><th>Email</th><th>Name</th><th></th></tr></thead><tbody>'+sas+'</tbody></table>' : '<p class="muted">None listed.</p>';
    const affs = (data.affiliate_managers||[]).map(s => '<tr><td>'+escapeHtml(s.email)+'</td><td>'+escapeHtml(s.name||'')+'</td><td><button type="button" class="danger btn-aff-revoke" data-email="'+escapeAttr(s.email)+'">Revoke</button></td></tr>').join('');
    if ($('aff-list')) {
      $('aff-list').innerHTML = affs ? '<table><thead><tr><th>Email</th><th>Name</th><th></th></tr></thead><tbody>'+affs+'</tbody></table>' : '<p class="muted">None listed.</p>';
    }
    if ($('aff-gate')) {
      const g = data.affiliate_gate || {};
      const o = g.offer || {};
      const price = o.price ? ('$'+o.price+'/month') : '$10/month';
      const pricing = g.pricing_summary || ('First '+(g.free_limit||10)+' affiliates free; then '+price);
      const usage = g.usage_summary || '';
      let gateHtml = '<p><strong>'+escapeHtml(pricing)+'</strong></p>';
      if (usage) gateHtml += '<p>'+escapeHtml(usage)+'</p>';
      gateHtml += '<p class="muted">Add-on state: <code>'+escapeHtml(g.state||'unknown')+'</code>. Existing affiliates keep working. A subscription is required only to add or approve more than the free allowance. Subscriptions are not created automatically.</p>';
      if (g.notice && g.notice !== pricing+'. '+usage+'.') gateHtml += '<p class="muted">'+escapeHtml(g.notice)+'</p>';
      if (!g.can_add && o.subscribe_url) gateHtml += '<p><a href="'+escapeAttr(o.subscribe_url)+'">Subscribe — '+escapeHtml(pricing)+'</a></p>';
      else if (o.subscribe_url) gateHtml += '<p class="muted"><a href="'+escapeAttr(o.subscribe_url)+'">Subscribe when you go past the free allowance</a></p>';
      $('aff-gate').innerHTML = gateHtml;
    }
    const doms = (data.domains||[]).map(d => {

      const eds = (d.editors||[]).map(e => '<li>'+escapeHtml(e.email)+' <button type="button" class="danger btn-ed-revoke" data-domain="'+escapeAttr(d.domain)+'" data-email="'+escapeAttr(e.email)+'">Revoke</button></li>').join('');
      const src = d.editor_enabled_source ? ' <span class="muted">('+escapeHtml(d.editor_enabled_source)+')</span>' : '';
      return '<div class="card"><strong>'+escapeHtml(d.domain)+'</strong>'+src+
        '<div class="row"><label>Editor enabled <input type="checkbox" class="ed-toggle" data-domain="'+escapeAttr(d.domain)+'" '+(d.editor_enabled?'checked':'')+'></label></div>'+
        (/^www\./.test(d.domain) ? '' : '<div class="row"><label>DW banner style <select class="bs-select" data-domain="'+escapeAttr(d.domain)+'">'+
          [['A','A: full-width bar fixed to the bottom of the screen'],['B','B: slim bar fixed to the bottom of the screen'],['C','C: small corner badge, also fixed']].map(o => '<option value="'+o[0]+'"'+(d.banner_style===o[0]?' selected':'')+'>'+o[1]+'</option>').join('')+
          '</select></label></div><p class="muted">Free sites show the DW Alliance LLC banner on every page. You choose the style; the No Banner upgrade removes it.</p>'+
          watermarkForm(d))+
        '<p class="muted">Editors</p><ul>'+(eds||'<li class="muted">None</li>')+'</ul>'+
        '<div class="row"><input class="ed-email" data-domain="'+escapeAttr(d.domain)+'" type="email" placeholder="editor@example.com"><button type="button" class="btn-ed-grant" data-domain="'+escapeAttr(d.domain)+'">Grant editor</button></div></div>';
    }).join('');
    $('dom-list').innerHTML = doms || '<p class="muted">No domains on this brand.</p>';
  }


  function watermarkOfferLine(g){
    const o = (g && g.offer) || {};
    const msg = o.message || ((o.name||'Watermarks')+' is a paid add-on'+(o.price ? ' ($'+o.price+'/month)' : '')+'.');
    const used = g && g.usage ? (' '+g.usage.used+' of '+g.usage.free_count+' free watermarked files in use.') : '';
    const extra = g && g.state === 'grace' && g.notice ? ' '+g.notice : '';
    const link = (g && !g.allowed) ? (' <a href="'+escapeAttr(o.subscribe_url||'https://dwalliance.com/pricing.html')+'">Subscribe</a>') : '';
    return '<p class="muted">'+escapeHtml(msg)+escapeHtml(used)+escapeHtml(extra)+link+'</p>';
  }
  function watermarkForm(d){
    if (/^www\./.test(d.domain)) return '';
    const gateNote = watermarkOfferLine(d.watermark_gate || {});
    const c = d.watermark_config || {};
    const mode = d.watermark_mode || 'default';
    const kind = c.kind === 'image' ? 'image' : 'text';
    const pos = c.position || 'diagonal';
    const positions = [['diagonal','Diagonal'],['tile','Tiled'],['bottom-right','Bottom right'],['bottom-left','Bottom left'],['top-right','Top right'],['top-left','Top left'],['center','Center']];
    return '<div class="card" style="margin:.6rem 0 0;padding:.7rem .8rem"><strong>Owner watermark</strong>'+
      gateNote+'<p class="muted">Applies to this site\'s public JPG, PNG, WEBP and PDF files. File-level off on <a href="/files/">/files/</a> still wins. This does not hide the DW promo mark on a free site.</p>'+
      '<div class="row">'+
      '<div><label>Mode <select class="wm-mode" data-domain="'+escapeAttr(d.domain)+'">'+
        [['default','Default (follow global)'],['on','On'],['off','Off']].map(o => '<option value="'+o[0]+'"'+(mode===o[0]?' selected':'')+'>'+o[1]+'</option>').join('')+
      '</select></label></div>'+
      '<div><label>Kind <select class="wm-kind" data-domain="'+escapeAttr(d.domain)+'">'+
        [['text','Text'],['image','Logo image']].map(o => '<option value="'+o[0]+'"'+(kind===o[0]?' selected':'')+'>'+o[1]+'</option>').join('')+
      '</select></label></div>'+
      '<div><label>Position <select class="wm-pos" data-domain="'+escapeAttr(d.domain)+'">'+
        positions.map(o => '<option value="'+o[0]+'"'+(pos===o[0]?' selected':'')+'>'+o[1]+'</option>').join('')+
      '</select></label></div>'+
      '</div>'+
      '<div class="row">'+
      '<div><label>Text <input class="wm-text" data-domain="'+escapeAttr(d.domain)+'" type="text" maxlength="80" value="'+escapeAttr(c.text||'')+'" placeholder="Your site name"></label></div>'+
      '<div><label>Logo file id <input class="wm-img" data-domain="'+escapeAttr(d.domain)+'" type="text" maxlength="50" value="'+escapeAttr(c.image_file_id||'')+'" placeholder="from /files/"></label></div>'+
      '<div><label>Size % <input class="wm-size" data-domain="'+escapeAttr(d.domain)+'" type="number" min="4" max="40" step="1" value="'+(c.size!=null?c.size:12)+'"></label></div>'+
      '<div><label>Opacity <input class="wm-op" data-domain="'+escapeAttr(d.domain)+'" type="number" min="0.05" max="1" step="0.05" value="'+(c.opacity!=null?c.opacity:0.22)+'"></label></div>'+
      '<div><label>Color <input class="wm-color" data-domain="'+escapeAttr(d.domain)+'" type="text" maxlength="7" value="'+escapeAttr(c.color||'#FFFFFF')+'"></label></div>'+
      '<div><label>Angle <input class="wm-angle" data-domain="'+escapeAttr(d.domain)+'" type="number" min="0" max="360" step="1" value="'+(c.angle!=null?c.angle:45)+'"></label></div>'+
      '<button type="button" class="btn-wm-save" data-domain="'+escapeAttr(d.domain)+'">Save watermark</button>'+
      '</div></div>';
  }

  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function escapeAttr(s){ return escapeHtml(s).replace(/`/g,''); }

  async function reload(){
    const data = await api({action:'list'});
    render(data);
    $('signed-out').hidden = true;
    $('panel').hidden = false;
    status('Loaded.', true);
  }

  async function boot(){
    try {
      await reload();
    } catch(e){
      $('panel').hidden = true;
      $('signed-out').hidden = false;
      if (e.status === 401) statusOut('Not signed in.', false);
      else if (e.status === 403) statusOut(e.message || 'Not allowed.', false);
      else statusOut(e.message || 'Could not load.', false);
    }
  }

  $('btn-reload').addEventListener('click', () => reload().catch(e => status(e.message, false)));
  $('btn-aff-grant') && $('btn-aff-grant').addEventListener('click', async () => {
    try { await api({action:'grant_affiliate', email:$('aff-email').value.trim()}); $('aff-email').value=''; status('Granted affiliate manager.', true); await reload(); }
    catch(e){ status(e.message, false); }
  });
  $('btn-sa-grant').addEventListener('click', async () => {

    try { await api({action:'grant_super_admin', email:$('sa-email').value.trim()}); $('sa-email').value=''; status('Granted super admin.', true); await reload(); }
    catch(e){ status(e.message, false); }
  });
  document.addEventListener('click', async (ev) => {
    const t = ev.target;
    if (!(t instanceof HTMLElement)) return;
    try {
      if (t.classList.contains('btn-aff-revoke')) {
        await api({action:'revoke_affiliate', email:t.dataset.email});
        status('Revoked affiliate manager.', true); await reload();
      } else if (t.classList.contains('btn-sa-revoke')) {

        await api({action:'revoke_super_admin', email:t.dataset.email});
        status('Revoked super admin.', true); await reload();
      } else if (t.classList.contains('btn-ed-revoke')) {
        await api({action:'revoke_editor', domain:t.dataset.domain, email:t.dataset.email});
        status('Revoked editor.', true); await reload();
      } else if (t.classList.contains('btn-ed-grant')) {
        const input = document.querySelector('input.ed-email[data-domain="'+t.dataset.domain+'"]');
        await api({action:'grant_editor', domain:t.dataset.domain, email:(input&&input.value||'').trim()});
        status('Granted editor.', true); await reload();
      } else if (t.classList.contains('btn-wm-save')) {
        const d = t.dataset.domain;
        const q = (sel) => document.querySelector(sel+'[data-domain="'+d+'"]');
        await api({
          action:'set_watermark', domain:d,
          watermark_mode: q('select.wm-mode').value,
          kind: q('select.wm-kind').value,
          position: q('select.wm-pos').value,
          text: q('input.wm-text').value,
          image_file_id: q('input.wm-img').value,
          size: q('input.wm-size').value,
          opacity: q('input.wm-op').value,
          color: q('input.wm-color').value,
          angle: q('input.wm-angle').value
        });
        status('Owner watermark saved.', true); await reload();
      }
    } catch(e){ status(e.message, false); }
  });
  document.addEventListener('change', async (ev) => {
    const t = ev.target;
    if (t instanceof HTMLSelectElement && t.classList.contains('bs-select')) {
      try { await api({action:'set_banner_style', domain:t.dataset.domain, style:t.value}); status('Banner style saved.', true); await reload(); }
      catch(e){ status(e.message, false); }
      return;
    }
    if (!(t instanceof HTMLInputElement) || !t.classList.contains('ed-toggle')) return;
    try {
      await api({action:'set_editor_enabled', domain:t.dataset.domain, enabled: !!t.checked});
      status(t.checked ? 'Editor enabled.' : 'Editor disabled.', true);
      await reload();
    } catch(e){ status(e.message, false); t.checked = !t.checked; }
  });

  boot();
})();
</script>
</body>
</html>
HTML;
    exit;
}

// ----- GET: admin UI (cookie / magic-link); JSON hint not used for browsers -----
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    adminPage();
}

if ($method !== 'POST') {
    Dw::jsonOut(405, ['ok' => false, 'error' => 'POST required']);
    exit;
}

$sent = body();
if ($sent === []) {
    help();
}

$action = strtolower(trim((string)($sent['action'] ?? '')));
$allowed = ['list', 'set_editor_enabled', 'set_banner_style', 'set_watermark', 'grant_editor', 'revoke_editor', 'grant_super_admin', 'revoke_super_admin', 'grant_affiliate', 'revoke_affiliate'];
if (!in_array($action, $allowed, true)) {
    fail($sent, 'action must be one of: ' . implode(', ', $allowed));
}

$explicitSession = isset($sent['session']) && is_string($sent['session']) && trim($sent['session']) !== '';
$host = DwDomain::requestHost();
$rawHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? $host));
if (str_contains($rawHost, ':')) {
    $rawHost = explode(':', $rawHost, 2)[0];
}
// Cookie / magic-link auth: same-origin only (Sec-Fetch-Site or Origin), matching /session/logout.
// Scripts that pass session in JSON are exempt so curl/automation still works.
if (!$explicitSession) {
    $sfs = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    $src = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($src === '' || $src === 'null') {
        $src = (string)($_SERVER['HTTP_REFERER'] ?? '');
    }
    $oh = parse_url($src, PHP_URL_HOST);
    $sameOrigin = $sfs !== '' ? $sfs === 'same-origin' : (is_string($oh) && hash_equals($rawHost, strtolower($oh)));
    if (!$sameOrigin) {
        fail($sent, 'cross-site request refused', 403);
    }
}

try {
    $session = DwPermissions::resolveSession($sent);
} catch (Throwable $e) {
    fail($sent, 'database unavailable', 500);
}
if ($session === null) {
    fail($sent, 'a valid session is required', 401);
}

$brand = trim((string)($sent['brand'] ?? ''));
if ($brand === '') {
    $brand = (string)$session['brand'];
}
if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $brand)) {
    fail($sent, 'brand is not valid');
}
if (!hash_equals((string)$session['brand'], $brand)) {
    fail($sent, 'brand does not match this session', 403);
}
if (!DwPermissions::isSuperAdmin((string)$session['person_id'], $brand)) {
    fail($sent, 'this session needs SUPER_ADMIN on this brand', 403);
}

$by = (string)$session['person_id'];

try {
    $db = Dw::pdo();

    if ($action === 'list') {
        $doms = $db->prepare('SELECT id, domain_name FROM domain WHERE code = ? ORDER BY domain_name');
        $doms->execute([$brand]);
        $domains = [];
        foreach ($doms->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $en = $db->prepare('SELECT setting_value FROM domain_setting WHERE domain_id = ? AND setting_name = ?');
            $en->execute([$d['id'], DwPermissions::SETTING_EDITOR]);
            $val = $en->fetchColumn();
            $enabled = is_string($val) ? ($val === '1' || strtolower($val) === 'true' || $val === 'on') : DwPermissions::markupJsonFallback($brand);
            $eds = $db->prepare(
                "SELECT e.email, p.first_name, p.last_name FROM domain_editor de
                 JOIN person p ON p.id = de.person_id
                 LEFT JOIN email e ON e.owner_id = p.id AND e.owner_type = 'PRSN' AND e.brand = de.brand
                 WHERE de.domain_id = ? AND de.brand = ?
                 ORDER BY e.email"
            );
            $eds->execute([$d['id'], $brand]);
            $editors = [];
            $seen = [];
            foreach ($eds->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $em = strtolower((string)($row['email'] ?? ''));
                if ($em === '' || isset($seen[$em])) {
                    continue;
                }
                $seen[$em] = true;
                $editors[] = ['email' => $em, 'name' => displayName($row)];
            }
            $wmMode = 'default';
            $wmCfg = null;
            $full = $db->prepare('SELECT watermark_mode, watermark_config FROM domain WHERE id = ?');
            $full->execute([$d['id']]);
            $wm = $full->fetch(PDO::FETCH_ASSOC) ?: [];
            if (isset($wm['watermark_mode']) && in_array((string)$wm['watermark_mode'], ['default', 'on', 'off'], true)) {
                $wmMode = (string)$wm['watermark_mode'];
            }
            if (isset($wm['watermark_config']) && is_string($wm['watermark_config']) && $wm['watermark_config'] !== '') {
                $decoded = json_decode($wm['watermark_config'], true);
                $wmCfg = is_array($decoded) ? $decoded : null;
            }
            $domains[] = [
                'domain' => $d['domain_name'],
                'banner_style' => DwBanner::styleFor($db, $brand, DwBanner::bare((string)$d['domain_name'])),
                'watermark_mode' => $wmMode,
                'watermark_config' => $wmCfg,
                'watermark_gate' => (function () use ($db, $brand) {
                    require_once '/var/www/html/lib/DwGate.php';
                    require_once '/var/www/html/lib/DwWatermark.php';
                    $g = DwGate::check($db, $brand, 'watermark');
                    $g['usage'] = DwWatermark::ownerUsage($db, $brand);
                    return $g;
                })(),
                'editor_enabled' => $enabled,
                'editor_enabled_source' => is_string($val) ? 'domain_setting' : (DwPermissions::markupJsonFallback($brand) ? 'markup_json_fallback' : 'default_off'),
                'editors' => $editors,
            ];
        }
        $saq = $db->prepare(
            "SELECT DISTINCT e.email, p.first_name, p.last_name FROM data_access d
             JOIN person p ON p.id = d.person_id
             JOIN email e ON e.owner_id = p.id AND e.owner_type = 'PRSN' AND e.brand = d.brand
             WHERE d.brand = ? AND d.right_name = 'SUPER_ADMIN'
             ORDER BY e.email"
        );
        $saq->execute([$brand]);
        $super = [];
        $seenSa = [];
        foreach ($saq->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $em = strtolower((string)$row['email']);
            if (isset($seenSa[$em])) {
                continue;
            }
            $seenSa[$em] = true;
            $super[] = ['email' => $em, 'name' => displayName($row)];
        }
        require_once '/var/www/html/lib/DwAffiliate.php';
        $affMgrs = DwAffiliate::listManagers($db, $brand);
        $affGate = DwAffiliate::gateCheck($db, $brand);
        Dw::jsonOut(200, ['ok' => true, 'domains' => $domains, 'super_admins' => $super, 'affiliate_managers' => $affMgrs, 'affiliate_gate' => $affGate]);

        exit;
    }

    if ($action === 'set_editor_enabled') {
        $domainName = needDomainName($sent);
        $enabled = boolEnabled($sent);
        $dom = DwPermissions::domainRow($domainName, $brand);
        if ($dom === null) {
            fail($sent, 'domain is not claimed by this brand', 404);
        }
        $val = $enabled ? '1' : '0';
        $db->beginTransaction();
        $find = $db->prepare('SELECT id FROM domain_setting WHERE domain_id = ? AND setting_name = ? FOR UPDATE');
        $find->execute([$dom['id'], DwPermissions::SETTING_EDITOR]);
        $existing = $find->fetchColumn();
        if (is_string($existing) && $existing !== '') {
            $db->prepare('UPDATE domain_setting SET setting_value = ?, updated = NOW(), updated_by_person_id = ? WHERE id = ?')
                ->execute([$val, $by, $existing]);
            DwPermissions::archiveSetting($db, $existing, 'UPDATE');
            $id = $existing;
        } else {
            $id = Dw::randId();
            $db->prepare('INSERT INTO domain_setting (id, domain_id, brand, setting_name, setting_value, created, created_by_person_id, updated, updated_by_person_id) VALUES (?,?,?,?,?,NOW(),?,NOW(),?)')
                ->execute([$id, $dom['id'], $brand, DwPermissions::SETTING_EDITOR, $val, $by, $by]);
            DwPermissions::archiveSetting($db, $id, 'INSERT');
        }
        $db->commit();
        require_once '/var/www/html/lib/DwHtmlCache.php';
        DwHtmlCache::bump($db, $brand, $by);
        Dw::jsonOut(200, ['ok' => true, 'domain' => $domainName, 'editor_enabled' => $enabled]);
        exit;
    }

    if ($action === 'set_banner_style') {
        // Owner picks the look of the DW banner (2026-11-14). Stored on the bare domain row; www uses it too.
        // There is deliberately no value that hides the banner: only a No Banner entitlement (DW billing) does that.
        $domainName = needDomainName($sent);
        $style = strtoupper(trim((string)($sent['style'] ?? '')));
        if (!in_array($style, DwBanner::STYLES, true)) {
            fail($sent, 'style must be A (full-width fixed bar), B (slim fixed bar) or C (fixed corner badge)');
        }
        $bare = DwBanner::bare($domainName);
        $dom = DwPermissions::domainRow($bare, $brand) ?? DwPermissions::domainRow($domainName, $brand);
        if ($dom === null) {
            fail($sent, 'domain is not claimed by this brand', 404);
        }
        $db->beginTransaction();
        $find = $db->prepare('SELECT id FROM domain_setting WHERE domain_id = ? AND setting_name = ? FOR UPDATE');
        $find->execute([$dom['id'], DwBanner::SETTING_STYLE]);
        $existing = $find->fetchColumn();
        if (is_string($existing) && $existing !== '') {
            $db->prepare('UPDATE domain_setting SET setting_value = ?, updated = NOW(), updated_by_person_id = ? WHERE id = ?')
                ->execute([$style, $by, $existing]);
            DwPermissions::archiveSetting($db, $existing, 'UPDATE');
        } else {
            $id = Dw::randId();
            $db->prepare('INSERT INTO domain_setting (id, domain_id, brand, setting_name, setting_value, created, created_by_person_id, updated, updated_by_person_id) VALUES (?,?,?,?,?,NOW(),?,NOW(),?)')
                ->execute([$id, $dom['id'], $brand, DwBanner::SETTING_STYLE, $style, $by, $by]);
            DwPermissions::archiveSetting($db, $id, 'INSERT');
        }
        $db->commit();
        require_once '/var/www/html/lib/DwHtmlCache.php';
        DwHtmlCache::bump($db, $brand, $by);
        Dw::jsonOut(200, ['ok' => true, 'domain' => $dom['domain_name'], 'banner_style' => $style]);
        exit;
    }

    if ($action === 'set_watermark') {
        require_once '/var/www/html/lib/DwWatermark.php';
        require_once '/var/www/html/lib/DwHtmlCache.php';
        $domainName = needDomainName($sent);
        $bare = DwBanner::bare($domainName);
        $dom = DwPermissions::domainRow($bare, $brand) ?? DwPermissions::domainRow($domainName, $brand);
        if ($dom === null) {
            fail($sent, 'domain is not claimed by this brand', 404);
        }
        $mode = null;
        if (isset($sent['watermark_mode']) && $sent['watermark_mode'] !== '') {
            $mode = strtolower(trim((string)$sent['watermark_mode']));
            if (!in_array($mode, ['default', 'on', 'off'], true)) {
                fail($sent, 'watermark_mode must be default, on or off');
            }
        }
        $cfgIn = $sent['watermark_config'] ?? null;
        if (is_string($cfgIn) && $cfgIn !== '') {
            $decoded = json_decode($cfgIn, true);
            $cfgIn = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($cfgIn)) {
            $cfgIn = [];
            foreach (['kind', 'text', 'image_file_id', 'position', 'size', 'opacity', 'color', 'angle'] as $k) {
                if (array_key_exists($k, $sent)) {
                    $cfgIn[$k] = $sent[$k];
                }
            }
        }
        $hasCfg = $cfgIn !== [];
        if ($mode === null && !$hasCfg) {
            fail($sent, 'send watermark_mode and/or the owner-mark style fields');
        }
        $curModeStmt = $db->prepare('SELECT watermark_mode FROM domain WHERE id = ?');
        $curModeStmt->execute([$dom['id']]);
        $newMode = $mode ?? (string)($curModeStmt->fetchColumn() ?: 'default');
        if ($newMode === '') {
            $newMode = 'default';
        }
        require_once '/var/www/html/lib/DwWatermark.php';
        require_once '/var/www/html/lib/DwGate.php';
        $wmGate = DwWatermark::ownerMarkRefuseChange($db, $brand, ['domain_modes' => [(string)$dom['id'] => $newMode]]);
        if ($wmGate !== null) {
            Dw::jsonOut(402, DwGate::denyBody($wmGate) + ['you_sent' => shown($sent)]);
            exit;
        }
        $defaultText = $bare !== '' ? $bare : (string)$dom['domain_name'];
        $db->beginTransaction();
        $cur = $db->prepare('SELECT watermark_mode, watermark_config FROM domain WHERE id = ? FOR UPDATE');
        $cur->execute([$dom['id']]);
        $row = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
        $newMode = $mode ?? (string)($row['watermark_mode'] ?? 'default');
        $existingCfg = (string)($row['watermark_config'] ?? '');
        $merged = is_string($existingCfg) && $existingCfg !== '' ? (json_decode($existingCfg, true) ?: []) : [];
        if ($hasCfg) {
            $merged = array_merge(is_array($merged) ? $merged : [], $cfgIn);
        }
        $cfgJson = DwWatermark::encodeConfig($merged + ['text' => $merged['text'] ?? $defaultText]);
        Dw::history($db, 'domain', (string)$dom['id'], 'UPDATE');
        $db->prepare('UPDATE domain SET watermark_mode = ?, watermark_config = ? WHERE id = ?')
            ->execute([$newMode, $cfgJson, $dom['id']]);
        $db->commit();
        DwHtmlCache::bump($db, $brand, $by, 'set_watermark');
        Dw::jsonOut(200, ['ok' => true, 'domain' => $dom['domain_name'], 'watermark_mode' => $newMode, 'watermark_config' => json_decode($cfgJson, true)]);
        exit;
    }

    if ($action === 'grant_editor' || $action === 'revoke_editor') {

        $domainName = needDomainName($sent);
        $email = needEmail($sent);
        $dom = DwPermissions::domainRow($domainName, $brand);
        if ($dom === null) {
            fail($sent, 'domain is not claimed by this brand', 404);
        }
        $person = DwPermissions::personByEmail($email, $brand);
        if ($person === null) {
            fail($sent, 'no person with that email on this brand; create them with /crm and /relation first', 404);
        }
        $pid = (string)$person['id'];
        if ($action === 'grant_editor') {
            $db->beginTransaction();
            $ex = $db->prepare('SELECT id FROM domain_editor WHERE domain_id = ? AND person_id = ? LIMIT 1');
            $ex->execute([$dom['id'], $pid]);
            $already = $ex->fetchColumn();
            if (is_string($already) && $already !== '') {
                $db->commit();
                Dw::jsonOut(200, ['ok' => true, 'domain' => $domainName, 'email' => $email, 'existed' => true]);
                exit;
            }
            $id = Dw::randId();
            $db->prepare('INSERT INTO domain_editor (id, domain_id, brand, person_id, created, created_by_person_id) VALUES (?,?,?,?,NOW(),?)')
                ->execute([$id, $dom['id'], $brand, $pid, $by]);
            DwPermissions::archiveEditor($db, $id, 'INSERT');
            Dw::refreshSessionRights($db, $pid, $brand);
            $db->commit();
            Dw::jsonOut(200, ['ok' => true, 'domain' => $domainName, 'email' => $email, 'existed' => false]);
            exit;
        }
        // revoke
        $db->beginTransaction();
        $ex = $db->prepare('SELECT id FROM domain_editor WHERE domain_id = ? AND person_id = ? AND brand = ? LIMIT 1 FOR UPDATE');
        $ex->execute([$dom['id'], $pid, $brand]);
        $eid = $ex->fetchColumn();
        if (!is_string($eid) || $eid === '') {
            $db->commit();
            Dw::jsonOut(200, ['ok' => true, 'domain' => $domainName, 'email' => $email, 'existed' => false]);
            exit;
        }
        DwPermissions::archiveEditor($db, $eid, 'DELETE');
        $db->prepare('DELETE FROM domain_editor WHERE id = ?')->execute([$eid]);
        Dw::refreshSessionRights($db, $pid, $brand);
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'domain' => $domainName, 'email' => $email, 'revoked' => true]);
        exit;
    }

    if ($action === 'grant_super_admin' || $action === 'revoke_super_admin') {
        $email = needEmail($sent);
        $person = DwPermissions::personByEmail($email, $brand);
        if ($person === null) {
            fail($sent, 'no person with that email on this brand; create them with /crm and /relation first', 404);
        }
        $pid = (string)$person['id'];
        if ($action === 'grant_super_admin') {
            $db->beginTransaction();
            $ex = $db->prepare("SELECT id FROM data_access WHERE person_id = ? AND brand = ? AND right_name = 'SUPER_ADMIN' LIMIT 1");
            $ex->execute([$pid, $brand]);
            $already = $ex->fetchColumn();
            if (is_string($already) && $already !== '') {
                $db->commit();
                Dw::jsonOut(200, ['ok' => true, 'email' => $email, 'existed' => true]);
                exit;
            }
            $id = Dw::randId();
            $db->prepare("INSERT INTO data_access (id, person_id, brand, created, created_by_person_id, right_name, module_name) VALUES (?,?,?,NOW(),?,'SUPER_ADMIN','LOGIN')")
                ->execute([$id, $pid, $brand, $by]);
            $db->prepare("INSERT INTO data_access_history (history_id, id, person_id, brand, created, created_by_person_id, right_name, module_name, action, archived_at) VALUES (?,?,?,?,NOW(),?,'SUPER_ADMIN','LOGIN','INSERT',NOW())")
                ->execute([Dw::randId(), $id, $pid, $brand, $by]);
            Dw::refreshSessionRights($db, $pid, $brand);
            $db->commit();
            Dw::jsonOut(200, ['ok' => true, 'email' => $email, 'existed' => false]);
            exit;
        }
        // revoke — last SUPER_ADMIN guard
        $db->beginTransaction();
        $ex = $db->prepare("SELECT id FROM data_access WHERE person_id = ? AND brand = ? AND right_name = 'SUPER_ADMIN' FOR UPDATE");
        $ex->execute([$pid, $brand]);
        $rows = $ex->fetchAll(PDO::FETCH_COLUMN);
        if ($rows === []) {
            $db->commit();
            Dw::jsonOut(200, ['ok' => true, 'email' => $email, 'existed' => false]);
            exit;
        }
        $cnt = $db->prepare("SELECT COUNT(DISTINCT person_id) FROM data_access WHERE brand = ? AND right_name = 'SUPER_ADMIN' FOR UPDATE");
        $cnt->execute([$brand]);
        if ((int)$cnt->fetchColumn() <= 1) {
            $db->rollBack();
            fail($sent, 'cannot revoke the last SUPER_ADMIN on this brand', 409);
        }
        foreach ($rows as $rid) {
            $db->prepare("INSERT INTO data_access_history (history_id, id, person_id, brand, created, created_by_person_id, right_name, module_name, action, archived_at)
                SELECT ?, id, person_id, brand, created, created_by_person_id, right_name, module_name, 'DELETE', NOW() FROM data_access WHERE id = ?")
                ->execute([Dw::randId(), $rid]);
            $db->prepare('DELETE FROM data_access WHERE id = ?')->execute([$rid]);
        }
        Dw::refreshSessionRights($db, $pid, $brand);
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'email' => $email, 'revoked' => true]);
        exit;
    }

    if ($action === 'grant_affiliate' || $action === 'revoke_affiliate') {
        require_once '/var/www/html/lib/DwAffiliate.php';
        $email = needEmail($sent);
        $person = DwPermissions::personByEmail($email, $brand);
        if ($person === null) {
            fail($sent, 'no person with that email on this brand; create them with /crm and /relation first', 404);
        }
        $pid = (string)$person['id'];
        if ($action === 'grant_affiliate') {
            $res = DwAffiliate::grantManager($db, $pid, $brand, $by);
            Dw::jsonOut(200, ['ok' => true, 'email' => $email, 'existed' => !empty($res['existed'])]);
            exit;
        }
        $res = DwAffiliate::revokeManager($db, $pid, $brand);
        Dw::jsonOut(200, ['ok' => true, 'email' => $email, 'existed' => !empty($res['existed']), 'revoked' => !empty($res['revoked'])]);
        exit;
    }

    fail($sent, 'action not implemented');
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('permissions: ' . $e->getMessage());
    fail($sent, 'request failed', 500);
}
