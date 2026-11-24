<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
require_once '/var/www/html/lib/DwBrand.php';
DwBrand::themeOutput(); // page text follows the configured brand (lib/DwBrand.php)
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DW Alliance app</title>
<style>
  :root { color-scheme: light dark; --fg: #1a1a1a; --muted: #555; --line: #ddd; --card: #f7f7f7; --accent: #0b5fff; }
  @media (prefers-color-scheme: dark) {
    :root { --fg: #f2f2f2; --muted: #b0b0b0; --line: #333; --card: #1c1c1c; --accent: #6ea8ff; }
  }
  body { margin: 0; font: 16px/1.5 system-ui, -apple-system, Segoe UI, sans-serif; color: var(--fg); }
  main { max-width: 52rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
  h1 { font-size: 1.75rem; margin: 0 0 .5rem; }
  h2 { font-size: 1.2rem; margin: 2rem 0 .75rem; }
  h3 { font-size: 1rem; margin: 1.25rem 0 .4rem; }
  p, li { color: var(--fg); }
  .muted { color: var(--muted); }
  a { color: var(--accent); }
  .cards { display: grid; gap: .75rem; margin: 1rem 0 0; }
  .card { border: 1px solid var(--line); background: var(--card); border-radius: .6rem; padding: .9rem 1rem; }
  .card strong { display: block; margin-bottom: .2rem; }
  code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .9rem; }
  pre { overflow: auto; border: 1px solid var(--line); background: var(--card); border-radius: .5rem; padding: .85rem 1rem; }
  ol { padding-left: 1.2rem; }
  ol > li { margin: .55rem 0; }
  .card ul { margin: .45rem 0 0; padding-left: 1.2rem; }
  .card ul li { margin: .15rem 0; font-size: .92rem; color: var(--muted); }
  .card ul li code { font-size: .85rem; }
  .note { border-left: 3px solid var(--accent); padding: .2rem 0 .2rem .8rem; margin: 1rem 0; color: var(--muted); }
</style>
</head>
<body>
<main>
  <h1>DW Alliance app</h1>
  <p class="muted">API overview for <code>app.dwalliance.com</code>. Create an account, start a session, then upload an HTML page and its related files.</p>

  <h2>Modules</h2>
  <div class="cards">
    <div class="card">
      <strong><a href="/account_setup/">/account_setup/</a></strong>
      <span class="muted">Create a person, email, optional company, and a new brand. No session required.</span>
      <ul>
        <li>POST <code>email</code>. Response is <code>id</code>. A 6-character confirmation code is emailed and expires after 30 minutes.</li>
        <li>POST <code>id</code>, <code>confirmation_id</code>, <code>first_name</code>, <code>last_name</code>, and optional <code>company_name</code>.</li>
        <li>Writes a person, email, optional company, and a brand. The brand code is emailed only, never returned in JSON.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/session/">/session/</a></strong>
      <span class="muted">Email a one-time session id for an email + brand. Response is ok and mailed only.</span>
      <ul>
        <li>POST <code>email</code> and <code>brand</code> (the brand code).</li>
        <li>If they match, a session id is emailed. The HTTP body is only <code>ok</code> and <code>mailed</code>.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/crm/">/crm/</a></strong>
      <span class="muted">Create, update, and delete people and companies. Needs a session, brand, and CRM CRUD.</span>
      <ul>
        <li><code>create_person</code> - <code>first_name</code>, <code>last_name</code>. Writes a person on the session brand.</li>
        <li><code>update_person</code> - <code>person_id</code>, <code>first_name</code>, <code>last_name</code>.</li>
        <li><code>delete_person</code> - <code>person_id</code>. Also removes that person's relation rows on this brand.</li>
        <li><code>create_company</code> - <code>name</code>. Writes a company on the session brand.</li>
        <li><code>update_company</code> - <code>company_id</code>, <code>name</code>.</li>
        <li><code>delete_company</code> - <code>company_id</code>. Also removes relation rows for that company on this brand.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/relation/">/relation/</a></strong>
      <span class="muted">Create, update, delete, and read a person's link to a company. Needs a session, brand, and CRM CRUD.</span>
      <ul>
        <li><code>create</code> - <code>person_id</code>, <code>company_id</code>.</li>
        <li><code>update</code> - <code>id</code>, and <code>person_id</code> and/or <code>company_id</code>.</li>
        <li><code>delete</code> - <code>id</code>.</li>
        <li><code>read</code> - <code>id</code>, or <code>person_id</code>, or <code>company_id</code>.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/dns/">/dns/</a></strong>
      <span class="muted">Create, update, delete, and read DNS records for a company. Needs a session, brand, DNS right, and company_id. The zone must be claimed first with /domain.</span>
      <ul>
        <li><code>create</code> - <code>company_id</code>, <code>domain</code>, <code>rr_type</code>, <code>rr_fqdn</code>, and the fields that type uses. The zone must already belong to this company.</li>
        <li><code>update</code> - <code>company_id</code>, <code>rr_id</code>, and any fields to replace.</li>
        <li><code>delete</code> - <code>company_id</code> and <code>rr_id</code>.</li>
        <li><code>read</code> - <code>company_id</code>. Optional <code>rr_id</code> or <code>domain</code>.</li>
        <li>Claim the zone first with <a href="/domain/">/domain/</a>; <code>/dns/</code> no longer claims names.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/domain/">/domain/</a></strong>
      <span class="muted">Claim, read, and release a domain and its www for a company. Needs a session, brand, DOMAIN right, and company_id.</span>
      <ul>
        <li><code>create</code> - <code>company_id</code> and <code>domain_name</code>. Claims the name and <code>www.</code> the name.</li>
        <li><code>read</code> - <code>company_id</code>. Optional <code>domain_name</code>. Lists names this company has claimed on this brand.</li>
        <li><code>delete</code> - <code>company_id</code> and <code>domain_name</code>. Releases the name and its www after its DNS records are deleted.</li>
        <li>After a claim, add and change the zone's records with <a href="/dns/">/dns/</a>. Release is refused while <code>/dns/</code> still has records in the zone.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/permissions/">/permissions/</a></strong>
      <span class="muted">Per-domain editor switch, domain EDITOR grants, and SUPER_ADMIN grants. Needs a session and SUPER_ADMIN on the brand. <a href="/permissions/">Open the admin UI</a>.</span>
      <ul>
        <li><code>list</code> - domains of the brand with editor_enabled, editors per domain, and super admins.</li>
        <li><code>set_editor_enabled</code> - <code>domain</code>, <code>enabled</code> (true/false). Per-domain editor switch; off by default.</li>
        <li><code>grant_editor</code> - <code>domain</code>, <code>email</code>. Grants EDITOR for that domain only.</li>
        <li><code>revoke_editor</code> - <code>domain</code>, <code>email</code>.</li>
        <li><code>grant_super_admin</code> - <code>email</code>. Brand-wide SUPER_ADMIN.</li>
        <li><code>revoke_super_admin</code> - <code>email</code>. Refused if it would remove the last SUPER_ADMIN.</li>
        <li>Admin UI is GET <code>/permissions/</code>. API help is POST with an empty body.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/files/">/files/</a></strong>
      <span class="muted">Create, update, delete, read, and search files. Uploads need a session with FILE rights. Search returns only files that person uploaded.</span>
      <ul>
        <li><code>create</code> - needs FILE CREATE or SUPER_ADMIN. Multipart <code>file</code> or JSON <code>file_data</code> as base64.</li>
        <li><code>update</code> - needs FILE UPDATE or SUPER_ADMIN, and <code>id</code>.</li>
        <li><code>delete</code> - needs FILE DELETE or SUPER_ADMIN, and <code>id</code>.</li>
        <li><code>read</code> - needs <code>id</code>. A PUBLIC file needs no session.</li>
        <li><code>search</code> - needs FILE READ. Optional filters: file name, type, size, or created date.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/notes/">/notes/</a></strong>
      <span class="muted">Create, update, delete, read, and search notes with optional GPS. Needs a session with NOTE rights. Only that person's notes on the session brand.</span>
      <ul>
        <li><code>create</code> - needs NOTE CREATE or SUPER_ADMIN. <code>note</code> is required; <code>gps</code> is optional.</li>
        <li><code>update</code> - needs NOTE UPDATE or SUPER_ADMIN, and <code>id</code>.</li>
        <li><code>delete</code> - needs NOTE DELETE or SUPER_ADMIN, and <code>id</code>. Also removes the note_files links for that note.</li>
        <li><code>read</code> - needs NOTE READ or SUPER_ADMIN, and <code>id</code>.</li>
        <li><code>search</code> - needs NOTE READ. Optional filters: note text or created date.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/note_files/">/note_files/</a></strong>
      <span class="muted">Link your notes to your files: create, update, delete, read, and search by note_id or file_id. Needs a session with NOTE rights.</span>
      <ul>
        <li><code>create</code> - needs NOTE CREATE or SUPER_ADMIN. <code>note_id</code> and <code>file_id</code> are required; both must be yours on this brand.</li>
        <li><code>update</code> - needs NOTE UPDATE or SUPER_ADMIN, and <code>id</code>. Optional <code>note_id</code> and/or <code>file_id</code>.</li>
        <li><code>delete</code> - needs NOTE DELETE or SUPER_ADMIN, and <code>id</code>. Removes only the link, not the note or file.</li>
        <li><code>read</code> - needs NOTE READ or SUPER_ADMIN, and <code>id</code>.</li>
        <li><code>search</code> - needs NOTE READ. Optional exact <code>note_id</code> or <code>file_id</code>.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/end_user_account_setup/">/end_user_account_setup/</a></strong>
      <span class="muted">Your customers sign up on your own domain (not on app.dwalliance.com). The brand comes from the domain name. US only; VPN and Tor are blocked; rate limited.</span>
      <ul>
        <li><code>request_code</code> - <code>email</code>. A 6-character code is emailed (30 minutes). The response is <code>id</code>; the code is never in JSON.</li>
        <li><code>verify</code> - <code>id</code> and the emailed <code>confirmation_id</code>. Creates the customer and signs them in for 30 days on that domain.</li>
        <li><code>profile</code> - after verify: <code>first_name</code>, <code>last_name</code>, optional <code>middle_name</code>, <code>company_name</code>, <code>telephone</code> <code>{phone_number, line_type}</code>, and <code>shipping</code> / <code>billing</code> address objects.</li>
        <li><code>status</code> - shows the signed-in customer's sign-up. Unfinished sign-ups are deleted after 90 days.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/customer_session/">/customer_session/</a></strong>
      <span class="muted">Returning customers sign in on your domain with an emailed code. Separate from staff <code>/session/</code>.</span>
      <ul>
        <li><code>request_code</code> - <code>email</code>. Always the same answer, whether or not the email is a customer.</li>
        <li><code>verify</code> - the <code>id</code> from request_code and the emailed <code>code</code>. Sets a secure cookie for that domain, valid 30 days. No session id is ever in JSON.</li>
        <li><code>whoami</code> and <code>logout</code>.</li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/address/">/address/</a></strong>
      <span class="muted">Create, edit, delete, read, and list addresses. Customers manage their own on your domain; staff manage people and companies on their brand with CRM rights. Every change is kept in history.</span>
      <ul>
        <li>Create an address
          <ul>
            <li>POST <code>action</code> <code>create</code> with <code>line_1</code>, <code>city</code>, <code>state</code>, <code>zipcode</code>.</li>
            <li>Optional <code>line_2</code>, <code>line_3</code>, <code>country</code> (default US), <code>address_type</code> Billing, Shipping, or Other (default Shipping).</li>
            <li>Staff also send <code>session</code>, <code>brand</code>, <code>owner_type</code> (PRSN or CMPNY) and <code>owner_id</code>; needs CRM CREATE or SUPER_ADMIN.</li>
            <li>The answer is <code>address_id</code>.</li>
          </ul>
        </li>
        <li>Edit an address
          <ul>
            <li>POST <code>action</code> <code>update</code> with <code>address_id</code> and only the fields to change.</li>
            <li>Send an empty string to clear <code>line_2</code> or <code>line_3</code>. Required fields cannot be cleared.</li>
            <li>Staff need CRM UPDATE or SUPER_ADMIN.</li>
          </ul>
        </li>
        <li>Delete an address
          <ul>
            <li>POST <code>action</code> <code>delete</code> with <code>address_id</code>. Staff need CRM DELETE or SUPER_ADMIN.</li>
            <li>Orders already placed keep their own copy of the address, so deleting never changes an order.</li>
          </ul>
        </li>
        <li>Find addresses
          <ul>
            <li><code>read</code> with <code>address_id</code>, or <code>list</code> (customers: their own; staff: <code>owner_type</code> + <code>owner_id</code>). Optional <code>address_type</code> filter.</li>
          </ul>
        </li>
      </ul>
    </div>
    <div class="card">
      <strong><a href="/store/">/store/</a></strong>
      <span class="muted">Online store for every brand: products, variants, categories, carts, checkout, invoices, payment records, and shipments. US dollars only. Customers shop on your own domain; staff manage it with a session. <a href="/store/">Read the full store guide</a>.</span>
      <ul>
        <li>Browse (public, on your domain)
          <ul>
            <li><code>/store/products/</code> - <code>list</code> (optional <code>company_id</code>, <code>category_id</code>) and <code>read</code> (<code>product_id</code> or <code>slug</code>).</li>
            <li><code>/store/products/categories/</code> - <code>list</code> categories.</li>
            <li><code>/store/search/</code> - <code>q</code>: products whose name or description contains it.</li>
          </ul>
        </li>
        <li>Manage the catalog (staff: <code>session</code> + <code>brand</code>; SUPER_ADMIN, a STORE right, or EDITOR of the company's domain)
          <ul>
            <li><code>/store/product/</code> - <code>create</code> (<code>company_id</code>, <code>name</code>, <code>price</code>; a Default variant is made too), <code>update</code>, <code>delete</code>, <code>claim</code>, <code>unclaim</code>.</li>
            <li><code>/store/product/variants/</code> - <code>list</code>, <code>create</code>, <code>update</code>, <code>delete</code> (price, stock, position).</li>
            <li><code>/store/product/description/</code> - one description per <code>used_location</code>.</li>
            <li><code>/store/product/categories/</code> - company categories: <code>create</code>, <code>update</code>, <code>delete</code>, <code>link</code>, <code>unlink</code>.</li>
            <li><code>/store/product/images/</code> - attach a <code>/files/</code> file to a product, variant, or category.</li>
          </ul>
        </li>
        <li>Buy (customers, on your domain)
          <ul>
            <li><code>/store/cart/</code> - <code>add</code> returns a server-made <code>cart_id</code>; <code>update</code>, <code>remove</code>, <code>read</code>, <code>mine</code>, <code>delete</code>. One line per variant.</li>
            <li><code>/store/checkout/</code> - signed-in customer, <code>cart_id</code>, <code>shipping_address_id</code>, optional <code>billing_address_id</code> (from <code>/address/</code>). Makes a PENDING invoice.</li>
          </ul>
        </li>
        <li>After the order
          <ul>
            <li><code>/store/invoice/</code> - <code>read</code>, <code>list</code>; staff <code>update</code> tax, shipping, or status.</li>
            <li><code>/store/invoice/payment/</code> - record payments; PAID when the total is covered. Online card/PayPal processing is not connected yet: customer requests are recorded as PENDING and no charge is made.</li>
            <li><code>/store/shipment/</code> - staff ship order lines (partial shipments allowed) with carrier and tracking; customers can read them.</li>
          </ul>
        </li>
        <li>Every create, update, and delete is kept in history.</li>
      </ul>
    </div>
  </div>
  <p class="note">Brand SUPER_ADMINs manage the Suggest-an-edit tool per domain with <a href="/permissions/">/permissions/</a> (turn the editor on, grant domain editors, grant or revoke super admins). Suggested edits are emailed to that brand&rsquo;s super admins.</p>
  <p class="note">If you are creating a website and want a shared menu system, include a menu.json. On your own domain, every site also gets an automatic <code>/sitemap.xml</code> and <code>/robots.txt</code> (see <a href="#sitemap">Search engines</a>).</p>

  <h2>1. Create an account</h2>
  <ol>
    <li>POST JSON to <code>/account_setup/</code> with <code>{"email":"you@example.com"}</code>. You get back <code>id</code>. A 6-character confirmation code is emailed from support@dwalliance.com. It expires after 30 minutes.</li>
    <li>POST JSON again with <code>id</code>, <code>confirmation_id</code>, <code>first_name</code>, <code>last_name</code>, and optional <code>company_name</code>.</li>
    <li>Success returns <code>ok</code>, <code>person_id</code>, <code>company_id</code>, <code>relation_id</code>, and <code>brand_emailed</code>. A second email delivers your brand code. Keep that code; it is not in the JSON.</li>
  </ol>
  <pre>// step 1
await fetch('/account_setup/', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email: 'you@example.com' })
}).then(r => r.json());

// step 2
await fetch('/account_setup/', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    id: 'SETUP_ID',
    confirmation_id: 'xxxxxx',
    first_name: 'Ada',
    last_name: 'Lovelace',
    company_name: 'Example LLC'
  })
}).then(r => r.json());</pre>
  <p class="note">New brands get default data_access for LOGIN (READ) and CREATE/READ/UPDATE/DELETE on FILE, CRM, NOTE, DNS, and DOMAIN.</p>

  <h2>2. Start a session</h2>
  <ol>
    <li>POST JSON to <code>/session/</code> with your email and brand code.</li>
    <li>If they match, a session id is emailed. The HTTP body is only <code>{"ok":true,"mailed":true}</code>.</li>
    <li>Send that session id later as JSON field <code>session</code> or cookie <code>session</code>.</li>
  </ol>
  <pre>await fetch('/session/', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email: 'you@example.com', brand: 'BRANDCODE' })
}).then(r => r.json());</pre>

  <h2>3. Upload an HTML document and its files</h2>
  <p>Use <code>/files/</code> with action <code>create</code>. Set <code>public_private</code> to <code>PUBLIC</code> for pages browsers should open. Keep related assets under the same brand and a shared <code>file_path</code>.</p>
  <ol>
    <li>Upload <code>index.html</code> (or any page) with <code>file_path</code> like <code>site</code>.</li>
    <li>Upload CSS, JS, images, fonts the same way. Match the relative paths your HTML uses.</li>
    <li>Open the public URL: <code>/BRANDCODE/file_path/file_name</code>. Example: <code>/BRANDCODE/site/index.html</code>.</li>
  </ol>
  <h3>Shared menu</h3>
  <p>A site with only a few pages should still keep the navigation in one PUBLIC <code>menu.json</code> next to those pages. Each page loads that file and fills its nav from it. Adding a page or removing one is an update to <code>menu.json</code>. The other HTML files stay as they are.</p>
  <pre>[
  {"label": "Home", "href": "index.html"},
  {"label": "About", "href": "about.html"},
  {"label": "Contact", "href": "contact.html"},
  {"label": "Sitemap", "href": "/sitemap.xml"}
]</pre>
  <p class="muted">Add the <code>Sitemap</code> item so people and search engines can find every page. <code>/sitemap.xml</code> works on your own domain from any folder; see <a href="#sitemap">Search engines</a>.</p>
  <h3>JSON create (base64)</h3>
  <pre>const html = btoa('<!DOCTYPE html><html><body>Hello</body></html>');
await fetch('/files/', {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    action: 'create',
    session: 'SESSION_ID_FROM_EMAIL',
    file_name: 'index.html',
    file_path: 'site',
    file_type: 'HTML',
    public_private: 'PUBLIC',
    file_data: html
  })
}).then(r => r.json());</pre>
  <h3>Multipart create</h3>
  <pre>const body = new FormData();
body.append('action', 'create');
body.append('session', 'SESSION_ID_FROM_EMAIL');
body.append('file_name', 'hero.png');
body.append('file_path', 'site');
body.append('public_private', 'PUBLIC');
body.append('file', fileInput.files[0]);
await fetch('/files/', { method: 'POST', credentials: 'include', body }).then(r => r.json());</pre>
  <p class="muted">Also supported: <code>update</code>, <code>delete</code>, <code>read</code>, and <code>search</code>. Search matches file name, file type, exact file size, or created date. Set <code>order</code> to ascend or descend, and <code>limit</code> from 1 to 100. Search returns only files that person uploaded. PUBLIC files can be read without a session. Max upload size is about 10 MB. GET each module path with no body for its live help JSON.</p>

  <h2>4. Edit DNS</h2>
  <p>POST JSON to <code>/dns/</code>. Send <code>session</code>, <code>brand</code>, and <code>company_id</code>. The person must belong to that company. Claim the zone first with <code>/domain/</code>. <code>/dns/</code> only changes records in zones that company has claimed.</p>
  <pre>await fetch('/dns/', {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    action: 'create',
    session: 'SESSION_ID',
    brand: 'BRANDCODE',
    company_id: 'COMPANY_ID',
    domain: 'example.com',
    rr_type: '+',
    rr_fqdn: 'www.example.com',
    rr_ip: '192.0.2.10'
  })
}).then(r => r.json());</pre>
  <p class="muted">Record types are <code>+</code> <code>=</code> <code>C</code> <code>@</code> <code>.</code> <code>&amp;</code> <code>^</code> <code>Z</code> and <code>'</code> for TXT. <code>rr_ttl</code> defaults to 600. The response for read is <code>records</code> with <code>rr_id</code>. GET <code>/dns/</code> with no body for the live field list.</p>
  <p class="note">Protected for every brand: <code>app.dwalliance.com</code>, <code>ns1.dwalliance.com</code>, <code>ns2.dwalliance.com</code>, <code>ns3.dwalliance.com</code>, <code>mail.dwalliance.com</code>, <code>store.dwalliance.com</code>, every name under them, and the MX, NS, and SOA records of <code>dwalliance.com</code>. <code>/dns/</code> refuses to create, update, or delete them, and <code>/domain/</code> refuses to claim them.</p>

  <h2>5. Claim a domain</h2>
  <p>POST JSON to <code>/domain/</code> with action <code>create</code>, <code>session</code>, <code>brand</code>, <code>company_id</code>, and <code>domain_name</code>. It claims the name and <code>www.</code> the name for your company and brand. Names another company or brand already holds are refused, and so are <code>app.dwalliance.com</code>, IP addresses, and localhost. Action <code>read</code> lists your names. Action <code>delete</code> releases a name and its www after its DNS records are deleted.</p>
  <pre>await fetch('/domain/', {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    action: 'create',
    session: 'SESSION_ID',
    brand: 'BRANDCODE',
    company_id: 'COMPANY_ID',
    domain_name: 'example.com'
  })
}).then(r => r.json());</pre>
  <p class="muted">The response lists <code>domain_id</code>, <code>domain_name</code>, and <code>status</code> (<code>created</code> or <code>already_owned</code>) for each name.</p>

  <h2>6. Host a website</h2>
  <p>Your brand can host a static website from its PUBLIC files. Upload every page and asset with <code>/files/</code> action <code>create</code> and <code>public_private</code> set to <code>PUBLIC</code>. Leave <code>file_path</code> empty for files at the top of the site, or set a folder such as <code>about</code>. A top-level <code>index.html</code> is your homepage.</p>
  <ol>
    <li>Upload <code>index.html</code> with an empty <code>file_path</code>.</li>
    <li>Upload the other pages, CSS, JS, images, and fonts the same way. Use relative links that match each file's <code>file_path</code> and <code>file_name</code>. Do not name a top-level file or folder after a module path such as <code>lib</code>, <code>files</code>, <code>session</code>, <code>account_setup</code>, <code>relation</code>, <code>crm</code>, <code>dns</code>, <code>domain</code>, <code>notes</code>, <code>note_files</code>, <code>docs</code>, or <code>skill</code>; on your own domain those paths reach the platform, not your files.</li>
    <li>To change a file, send <code>update</code> with its <code>id</code> and the new file. To remove it, send <code>delete</code> with its <code>id</code>.</li>
  </ol>
  <pre>const body = new FormData();
body.append('action', 'create');
body.append('session', 'SESSION_ID_FROM_EMAIL');
body.append('file_name', 'index.html');
body.append('file_path', '');
body.append('file_type', 'HTML');
body.append('public_private', 'PUBLIC');
body.append('file', fileInput.files[0]);
await fetch('/files/', { method: 'POST', credentials: 'include', body }).then(r => r.json());</pre>

  <h2>7. Use your own domain</h2>
  <p>Your site can answer on your own domain, such as <code>example.com</code> and <code>www.example.com</code>, with no brand code in the address.</p>
  <ol>
    <li>Point the domain here. At your registrar, either set the nameservers to <code>ns1.dwalliance.com</code>, <code>ns2.dwalliance.com</code>, and <code>ns3.dwalliance.com</code>, or keep your DNS host and add A records for <code>example.com</code> and <code>www.example.com</code> to <code><?= htmlspecialchars(DwConfig::get('PUBLIC_IP', '203.0.113.10'), ENT_QUOTES) ?></code>.</li>
    <li>Claim the domain with <code>/domain/</code> action <code>create</code>. That claims <code>example.com</code> and <code>www.example.com</code> for your company and brand.</li>
    <li>If you use our nameservers, create those A records with <code>/dns/</code>.</li>
    <li>Ask DW Alliance to turn on hosting for the domain. We connect it to your brand and enable HTTPS for it. This step is not self-serve yet.</li>
  </ol>
  <pre>await fetch('/dns/', {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    action: 'create',
    session: 'SESSION_ID',
    brand: 'BRANDCODE',
    company_id: 'COMPANY_ID',
    domain: 'example.com',
    rr_type: '+',
    rr_fqdn: 'www.example.com',
    rr_ip: '<?= htmlspecialchars(DwConfig::get('PUBLIC_IP', '203.0.113.10'), ENT_QUOTES) ?>'
  })
}).then(r => r.json());</pre>
  <p class="muted">Send the same create again with <code>rr_fqdn</code> set to <code>example.com</code> for the bare domain. Once hosting is on, <code>https://example.com/</code> shows your top-level <code>index.html</code>, and <code>https://example.com/{file_path}/{file_name}</code> shows any other PUBLIC file. A folder address ending in <code>/</code> shows that folder's <code>index.html</code>, so <code>https://example.com/about/</code> shows <code>about/index.html</code>. Missing files return 404, and PHP files are never served.</p>

  <h2 id="sitemap">8. Search engines: sitemap.xml and robots.txt</h2>
  <p>Once your own domain is on, it gets two files automatically. You do not upload anything.</p>
  <ul>
    <li><code>https://example.com/sitemap.xml</code> lists every PUBLIC <code>.html</code> page of your site with its last change time (<code>lastmod</code>). PRIVATE files, images, CSS, JS and other non-HTML files, error pages such as <code>404.html</code>, and pages with <code>&lt;meta name="robots" content="noindex"&gt;</code> are left out. A top-level <code>index.html</code> is listed as <code>https://example.com/</code>, and <code>about/index.html</code> as <code>https://example.com/about/</code>.</li>
    <li><code>https://example.com/robots.txt</code> lets every search engine crawl the site and points them to your sitemap.</li>
  </ul>
  <p>Replace <code>example.com</code> with your domain; <code>www.example.com</code> works the same way and lists <code>www</code> addresses. For example, the DW Alliance site itself is at <a href="https://dwalliance.com/sitemap.xml">https://dwalliance.com/sitemap.xml</a> and <a href="https://dwalliance.com/robots.txt">https://dwalliance.com/robots.txt</a>. The default <code>robots.txt</code> is:</p>
  <pre># Default robots.txt from DW Alliance. Upload your own robots.txt (PUBLIC, top of the site) to replace it.
User-agent: *
Allow: /

Sitemap: https://example.com/sitemap.xml</pre>
  <p>A sitemap looks like this:</p>
  <pre>&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"&gt;
  &lt;url&gt;&lt;loc&gt;https://example.com/&lt;/loc&gt;&lt;lastmod&gt;2026-10-29T17:39:10Z&lt;/lastmod&gt;&lt;/url&gt;
  &lt;url&gt;&lt;loc&gt;https://example.com/contact.html&lt;/loc&gt;&lt;lastmod&gt;2026-10-28T04:06:49Z&lt;/lastmod&gt;&lt;/url&gt;
&lt;/urlset&gt;</pre>
  <p>The sitemap is built the first time someone asks for it and then cached. Creating, updating or deleting any of your files clears it, and the next request builds a fresh one. To keep a page out of it, make the file PRIVATE or add a <code>noindex</code> robots meta tag. Sites with more than 50,000 pages get a sitemap index at <code>/sitemap.xml</code> that points to <code>/sitemap-1.xml</code>, <code>/sitemap-2.xml</code>, and so on.</p>
  <h3>Use your own instead</h3>
  <p>Upload a PUBLIC file named <code>robots.txt</code> or <code>sitemap.xml</code> with an empty <code>file_path</code>, and your file is served instead of the automatic one. Delete it to go back to the automatic file. Keep a <code>Sitemap:</code> line in your own <code>robots.txt</code>.</p>
  <pre>const body = new FormData();
body.append('action', 'create');
body.append('session', 'SESSION_ID_FROM_EMAIL');
body.append('file_name', 'robots.txt');
body.append('file_path', '');
body.append('public_private', 'PUBLIC');
body.append('file', new Blob(['User-agent: *\nDisallow: /drafts/\n\nSitemap: https://example.com/sitemap.xml\n'], { type: 'text/plain' }));
await fetch('https://app.dwalliance.com/files/', { method: 'POST', credentials: 'include', body }).then(r => r.json());</pre>
  <h3>Link your sitemap</h3>
  <p>Add <code>{"label": "Sitemap", "href": "/sitemap.xml"}</code> to your <code>menu.json</code> (or a link in your footer) so visitors and crawlers can reach it. Then submit <code>https://example.com/sitemap.xml</code> in Google Search Console and Bing Webmaster Tools. The automatic files are only on your own domain, not on <code>https://app.dwalliance.com/BRANDCODE/...</code> addresses.</p>

  <h2 id="addresses">9. Create, edit, and delete addresses</h2>
  <p>Use your own domain for customer calls (the cookie from <code>/customer_session/</code> or <code>/end_user_account_setup/</code> is sent automatically by the browser). Staff can call <code>https://app.dwalliance.com/address/</code> with <code>session</code> and <code>brand</code>.</p>
  <ol>
    <li>Create
      <ul>
        <li>Customer: <code>{"action":"create","line_1":"75-5660 Palani Rd","city":"Kailua-Kona","state":"HI","zipcode":"96740","address_type":"Shipping"}</code></li>
        <li>Staff: add <code>"session"</code>, <code>"brand"</code>, <code>"owner_type":"PRSN"</code> (or <code>"CMPNY"</code>) and <code>"owner_id"</code>.</li>
        <li>Keep the <code>address_id</code> from the answer.</li>
      </ul>
    </li>
    <li>Edit
      <ul>
        <li><code>{"action":"update","address_id":"...","line_2":"Suite 3"}</code> changes just that field.</li>
        <li><code>"line_2":""</code> clears it. <code>line_1</code>, <code>city</code>, <code>state</code>, and <code>zipcode</code> cannot be cleared.</li>
      </ul>
    </li>
    <li>Delete
      <ul>
        <li><code>{"action":"delete","address_id":"..."}</code>.</li>
        <li>Past orders are not affected; each order keeps its own shipping and billing copy.</li>
      </ul>
    </li>
    <li>List
      <ul>
        <li><code>{"action":"list"}</code> for a customer, or with <code>owner_type</code> and <code>owner_id</code> for staff. Add <code>"address_type":"Shipping"</code> to filter.</li>
      </ul>
    </li>
  </ol>
  <p class="note">Every create, update, and delete writes <code>address_history</code> (edits and deletes keep the old row), so changes can be traced. Checkout uses one shipping address and one billing address from this list.</p>

  <h2>Public URLs</h2>
  <p>After a PUBLIC file is created, the first request for <code>/{brand}/{file_path}/{file_name}</code> writes a cache on the app node and serves it. Files with an empty <code>file_path</code> are at <code>/{brand}/{file_name}</code>, so a homepage is <code>/{brand}/index.html</code>. On your own domain the same file is at <code>/{file_path}/{file_name}</code>. Updates and deletes clear that cache automatically, and any create, update or delete also clears your cached <a href="#sitemap">sitemap.xml</a>.</p>
</main>
</body>
</html>
