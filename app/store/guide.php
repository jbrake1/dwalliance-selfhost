<?php
declare(strict_types=1);
// Detailed store guide, served by GET /store/ (issues #8, #31). Static text only; no data is read.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
require_once '/var/www/html/lib/DwBrand.php';
DwBrand::themeOutput(); // page text follows the configured brand (lib/DwBrand.php)
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DW Alliance store guide</title>
<meta name="description" content="How the DW Alliance online store works: products, variants, inventory, restock notifications, carts, checkout, shipping, tax, invoices, payments, shipments and customer accounts.">
<style>
  :root { color-scheme: light dark; --fg: #1a1a1a; --muted: #555; --line: #ddd; --card: #f7f7f7; --accent: #0b5fff; --banner: #fff3bf; --banner-fg: #2b2100; }
  @media (prefers-color-scheme: dark) { :root { --fg: #f2f2f2; --muted: #b0b0b0; --line: #333; --card: #1c1c1c; --accent: #6ea8ff; --banner: #5c4a12; --banner-fg: #fff6d6; } }
  body { margin: 0; font: 16px/1.55 system-ui, -apple-system, Segoe UI, sans-serif; color: var(--fg); }
  .draft-banner { background: var(--banner); color: var(--banner-fg); text-align: center; font-size: .85rem; font-weight: 600; padding: .4rem 1rem; margin: 0; }
  main { max-width: 54rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
  h1 { font-size: 1.8rem; margin: 0 0 .5rem; } h2 { font-size: 1.25rem; margin: 2.2rem 0 .75rem; } h3 { font-size: 1rem; margin: 1.3rem 0 .4rem; }
  .muted { color: var(--muted); } a { color: var(--accent); }
  code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .9rem; }
  pre { overflow: auto; border: 1px solid var(--line); background: var(--card); border-radius: .5rem; padding: .85rem 1rem; }
  table { border-collapse: collapse; width: 100%; font-size: .92rem; } th, td { border: 1px solid var(--line); padding: .4rem .55rem; text-align: left; vertical-align: top; }
  th { background: var(--card); }
  .note { border-left: 3px solid var(--accent); padding: .2rem 0 .2rem .8rem; margin: 1rem 0; color: var(--muted); }
  nav ul { columns: 2; padding-left: 1.2rem; } li ul { margin: .25rem 0; }
  .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 1rem 0; }
  @media (max-width: 40rem) {
    nav ul { columns: 1; }
    pre { white-space: pre-wrap; word-break: break-word; }
    code { word-break: break-word; }
  }
</style>
</head>
<body>
<main>
  <p><a href="/">&larr; DW Alliance app</a></p>
  <h1>DW Alliance store</h1>
  <p class="muted">An online store built into every DW Alliance brand: a product catalog, inventory, carts, checkout, per-store shipping and tax, invoices, payment records and shipments, all in US dollars. Your customers shop on your own domain; you manage the store with your DW Alliance session.<?php $dwShow = DwConfig::get('STORE_SHOWCASE_DOMAIN'); if ($dwShow !== ''): ?> The first live tenant store is <a href="https://<?= htmlspecialchars($dwShow, ENT_QUOTES) ?>/store/"><?= htmlspecialchars($dwShow, ENT_QUOTES) ?></a>.<?php endif; ?></p>
  <p>For a plain-language tour of what is live today, see the <a href="kit/">detailed store page</a>.<?php if (DwBrand::isHostedService()): ?> A copyable kit for your AI will also live on <a href="https://dwalliance.com/store-kit.html">dwalliance.com/store-kit.html</a> when that page is approved.<?php endif; ?></p>

  <nav><ul>
    <li><a href="#how">How it fits together</a></li>
    <li><a href="#who">Who can do what</a></li>
    <li><a href="#setup">Set up a store</a></li>
    <li><a href="#catalog">Products, variants and categories</a></li>
    <li><a href="#inventory">Inventory</a></li>
    <li><a href="#restock">Restock notifications</a></li>
    <li><a href="#settings">Shipping, tax and store email</a></li>
    <li><a href="#banner">Banner on/off (staff)</a></li>
    <li><a href="#customers">Customer accounts and sign-in</a></li>
    <li><a href="#addresses">Customer addresses</a></li>
    <li><a href="#cart">Carts</a></li>
    <li><a href="#checkout">Checkout and invoices</a></li>
    <li><a href="#payments">Payments</a></li>
    <li><a href="#shipping">Shipments</a></li>
    <li><a href="#history">History and safety</a></li>
    <li><a href="#reference">Endpoint reference</a></li>
  </ul></nav>

  <h2 id="how">How it fits together</h2>
  <ul>
    <li><strong>Brand.</strong> Everything belongs to one brand. On a customer domain (for example <code>shop.example.com</code>) the brand is worked out from the domain name, so customers never need to know it.</li>
    <li><strong>Companies.</strong> A product is sold by one or more companies on the brand (a "claim"). Each category belongs to one company. A cart, its invoice, payments, shipments and inventory belong to the company selling the goods.</li>
    <li><strong>Products and variants.</strong> Every product has at least one variant (size, colour, pack). Creating a product creates a <code>Default</code> variant in the same step. Carts and orders always point at a variant.</li>
    <li><strong>Restock alerts.</strong> When an item is sold out, a visitor can enter their email, confirm it from a link, and get one email per product when that item is back. A purchase of the item, or the unsubscribe link, stops further mail.</li>
    <li><strong>Orders.</strong> Checkout turns a cart into a PENDING invoice with its lines, flat-rate shipping, tax where it applies, plus a copy of the shipping and billing address as they were at the time of the order. A confirmation is emailed to the customer and a copy to the store. Payments move it to PAID; shipments send the goods.</li>
    <li><strong>Money.</strong> US dollars only, two decimals, sent as text such as <code>"19.99"</code>. Shipping and tax are computed at checkout from the store's settings (a quote action shows the same figures first). Staff can still adjust tax and shipping on a PENDING invoice; the total is recalculated.</li>
  </ul>

  <h2 id="who">Who can do what</h2>
  <div class="table-wrap"><table>
    <tr><th>Who</th><th>How they are recognised</th><th>Can</th></tr>
    <tr><td>Visitor</td><td>Nothing. Calls are made on the store's own domain.</td><td>Browse products, categories and search; build a cart; request a shipping and tax quote; sign up for a restock alert on a sold-out item.</td></tr>
    <tr><td>Customer</td><td>Signed in on the store's domain with an email code (a secure cookie, 30 days).</td><td>Everything a visitor can, plus addresses, checkout, own invoices, payment records, shipment tracking, and deleting their own account.</td></tr>
    <tr><td>Store staff</td><td><code>session</code> and <code>brand</code> from <a href="/session/">/session/</a> in the JSON body.</td><td>Manage products, variants, descriptions, images, categories, store settings, restock email copy, inventory, invoices, payments and shipments. Needs SUPER_ADMIN, a STORE right on the brand (CREATE, UPDATE, DELETE, READ or WRITE), or to be an EDITOR of a domain owned by the company concerned.</td></tr>
  </table></div>
  <p class="note">STORE rights are granted like any other right with <a href="/permissions/">/permissions/</a>. Domain editors can manage the products and categories of the company that owns their domain.</p>

  <h2 id="addon">Shopping cart add-on</h2>
  <p>Running a store (adding or changing products, variants, categories, images, inventory, locations and store settings) is a paid add-on: <strong>Shopping cart, $10 a month</strong> per site, with the same 30-day money-back guarantee as our other plans. Without it those staff calls answer HTTP 402 with the price and a link that opens the <a href="https://dwalliance.com/store/subscribe/?plan=shopping-cart&amp;billing=monthly">subscribe page</a> with your site filled in. Shoppers are never affected: browsing, carts, checkout, payments and shipping paid orders keep working. Check your brand any time with <code>{"action":"features"}</code> on <code>/store/entitlement/</code> (staff session).</p>

  <h2 id="setup">Set up a store</h2>
  <ol>
    <li>Have a DW Alliance account and brand (<a href="/account_setup/">/account_setup/</a>) and a company on it.</li>
    <li>Claim your domain with <a href="/domain/">/domain/</a> for that company and point it at DW Alliance hosting (steps 4 to 7 on the <a href="/">app home page</a>).</li>
    <li>Start a staff session with <a href="/session/">/session/</a>.</li>
    <li>Set shipping, tax and the store-copy email with <a href="/store/settings/">/store/settings/</a>.</li>
    <li>Create categories and products (below). Upload product photos with <a href="/files/">/files/</a> and attach them. Receive stock with <a href="/store/inventory/">/store/inventory/</a>.</li>
    <li>Build your shop pages as normal HTML files on your domain. They call the store endpoints on the same domain with <code>fetch()</code>. A copyable kit is described on the <a href="kit/">detailed store page</a>.</li>
  </ol>

  <h2 id="catalog">Products, variants and categories</h2>
  <h3>Create a product</h3>
  <pre>POST https://app.dwalliance.com/store/product/
{"action":"create","session":"...","brand":"...","company_id":"...",
 "name":"Kona Coffee 1 lb","price":"24.00","stock":40,
 "description":"Medium roast from Holualoa."}</pre>
  <p>The answer has <code>product_id</code>, <code>slug</code> (made from the name and unique on the brand), the default <code>variant_id</code> and the <code>description_id</code>. Leave <code>stock</code> out to not track stock. If you pass <code>stock</code>, it becomes the opening inventory at the store's default location. After that, change stock through <a href="#inventory">inventory</a>.</p>
  <h3>Variants</h3>
  <ul>
    <li><code>/store/product/variants/</code> action <code>create</code> with <code>product_id</code>, <code>name</code> and optional <code>price</code> (blank uses the product price), <code>stock</code>, <code>position</code>.</li>
    <li>A product always keeps at least one variant. A variant that is in a cart or an order cannot be deleted.</li>
  </ul>
  <h3>Descriptions and images</h3>
  <ul>
    <li><code>/store/product/description/</code> keeps one description per <code>used_location</code> (for example <code>main</code>, <code>short</code>, <code>care</code>).</li>
    <li><code>/store/product/images/</code> attaches a <code>/files/</code> file (on the same brand) to a product, a variant or a category, with a <code>position</code> for ordering.</li>
  </ul>
  <h3>Categories</h3>
  <ul>
    <li>Categories belong to one company and can be nested with <code>parent_id</code> (same company).</li>
    <li>Action <code>link</code> puts a product in a category. The product must be claimed by the category's company.</li>
  </ul>
  <h3>Several sellers</h3>
  <p>Action <code>claim</code> on <code>/store/product/</code> lets another company on the brand sell the same product. A cart is always for one company; if a product has several sellers the cart must say which (<code>company_id</code>).</p>
  <h3>Retiring a product</h3>
  <p>Set <code>active</code> to false to hide it. Products that are already in a cart or an order cannot be deleted, so past orders stay complete.</p>

  <h2 id="inventory">Inventory</h2>
  <p>Stock is tracked per variant and location. The movement history is the source of truth and is never overwritten; balances are a cache that can be rebuilt from it. A store gets a default location named <code>Main</code> the first time one is needed.</p>
  <ul>
    <li><code>/store/inventory/locations/</code> &mdash; <code>list</code>, <code>create</code>, <code>update</code>. Types include store, warehouse, supplier, virtual or other. The default location cannot be deactivated until another one is made the default.</li>
    <li><code>/store/inventory/</code> <code>receive</code> &mdash; add stock (quantity 1+), optional <code>unit_cost</code>, <code>notes</code>, lot, serial or bin.</li>
    <li><code>/store/inventory/</code> <code>adjust</code> &mdash; a signed quantity, or a <code>counted</code> physical count (the difference is recorded). Refused if it would take a location below 0.</li>
    <li><code>/store/inventory/</code> <code>stock</code> &mdash; on hand, reserved and available (on hand minus reserved), per location and in total.</li>
    <li><code>/store/inventory/</code> <code>rebuild</code> &mdash; recomputes balances from the movement history and reports any differences it fixed.</li>
    <li><code>/store/inventory/movements/</code> <code>list</code> and <code>/store/inventory/balances/</code> <code>list</code> &mdash; newest movements first; cached balances per product, variant and location.</li>
    <li>At checkout, a <code>sale</code> movement is inserted for each line (reference type <code>invoice</code>). That history row is not overwritten later.</li>
  </ul>


  <h2 id="restock">Restock notifications</h2>
  <p>When a tracked variant is sold out, the product page shows a form instead of a “tell me when it’s back” email link. The visitor enters their email. A popup asks them not to close the window, check their email, and click the confirmation link; the popup closes once they have confirmed. The status check uses an unguessable handle, so it cannot be used to see whether someone else is on the list.</p>
  <p>An hourly job watches for a variant that was out of stock and is now in stock. Each confirmed, still-subscribed, non-bounced address gets <strong>one email per product</strong> with a single link to that product. They stay on the list after a send, so a later out-and-back-in can mail them again. Buying that item marks them unsubscribed with reason <code>purchased</code>. Clicking unsubscribe (or a mail app’s one-click unsubscribe) uses reason <code>clicked</code>. Addresses that bounce are marked and are not mailed again until they confirm a new signup.</p>
  <p>Every restock email includes a <code>List-Unsubscribe</code> header, a <code>List-Unsubscribe-Post</code> one-click header, and a visible unsubscribe link.</p>
  <h3>Email copy</h3>
  <p>Staff set a brand-level default, and can override it on a variant. Empty variant fields fall back to the brand default, then to the built-in text. Placeholders: <code>{{product_name}}</code>, <code>{{product_url}}</code>, <code>{{variant_name}}</code>, <code>{{store_name}}</code>, <code>{{unsubscribe_url}}</code>.</p>
  <pre>POST /store/restock/
{"session":"…","brand":"BRAND","action":"settings_read"}

{"session":"…","brand":"BRAND","action":"settings_update",
 "restock_subject":"{{product_name}} is back",
 "restock_from":"Your Store &lt;support@dwalliance.com&gt;",
 "restock_body":"…"}

{"session":"…","brand":"BRAND","action":"variant_email_update",
 "variant_id":"…","restock_subject":"","restock_from":"","restock_body":""}</pre>
  <p class="note">Public signup is <code>POST /store/restock/</code> with <code>action=signup</code>, <code>email</code> and <code>variant_id</code>, called on the store’s own domain. Confirm, poll and unsubscribe are query-token links on the same path. Staff can <code>list</code> subscribers and <code>history</code> for a notification or variant. The hourly send and bounce checker are cron jobs, not API actions.</p>

  <h2 id="settings">Shipping, tax and store email</h2>
  <p>Staff read and update per-company store settings with <code>/store/settings/</code>. Defaults come from the server's store configuration; a company can override any of:</p>
  <ul>
    <li><code>shipping_flat</code> &mdash; flat shipping rate, for example <code>"8.00"</code>.</li>
    <li><code>free_shipping_over</code> &mdash; optional subtotal at or above which shipping is free. Send <code>null</code> to go back to the default.</li>
    <li><code>hi_get_rate</code> &mdash; Hawaii GET as a percent, for example <code>"4.712"</code>. Applied only when the shipping address is in Hawaii.</li>
    <li><code>tax_shipping</code> &mdash; whether GET is also charged on shipping (default is no).</li>
    <li><code>store_email</code> &mdash; where the store's copy of each new order is sent.</li>
  </ul>
  <p><code>/store/checkout/</code> action <code>quote</code> takes a <code>cart_id</code> plus a <code>shipping_address_id</code> (signed-in customer) or a <code>state</code> (estimate, for example <code>"HI"</code>) and returns subtotal, shipping, tax and total, computed exactly as checkout will. Nothing is saved.</p>

  <h2 id="banner">Banner on/off (staff)</h2>
  <p>DW Alliance LLC store staff set the global default and each brand’s banner mode through <a href="/store/banner/"><code>/store/banner/</code></a> (GET is the admin page). POST actions: <code>read</code>, <code>update_global</code> (<code>banner_default</code> on or off, optional <code>banner_style</code> A/B/C), <code>update_brand</code> (<code>brand</code> or <code>domain</code>, <code>banner_mode</code> default/on/off, optional <code>banner_style</code>). Values are stored in <code>app_setting</code> and <code>brand</code>. An active No Banner entitlement always hides the banner; DW’s own sites are always skipped. Site owners still pick style A, B or C on <a href="/permissions/">/permissions/</a>.</p>


  <h2 id="customers">Customer accounts and sign-in</h2>
  <p>These calls are made on your own domain, never on app.dwalliance.com. Codes are only ever sent by email.</p>
  <ol>
    <li><strong>New customer:</strong> POST <code>{"action":"request_code","email":"..."}</code> to <code>/end_user_account_setup/</code>. A 6-character code is emailed and lasts 30 minutes.</li>
    <li>POST <code>{"action":"verify","id":"...","confirmation_id":"..."}</code>. The customer is now signed in for 30 days on that domain.</li>
    <li>Then <code>{"action":"profile",...}</code> adds their name, company name, phone, and shipping or billing address.</li>
    <li><strong>Returning customer:</strong> POST <code>{"action":"request_code","email":"..."}</code> to <code>/customer_session/</code>, then <code>verify</code> with the returned <code>id</code> and the emailed <code>code</code>. <code>whoami</code> and <code>logout</code> are also available.</li>
    <li><strong>Delete account:</strong> POST <code>{"action":"delete_account","confirm_email":"..."}</code> to <code>/end_user_account_setup/</code> while signed in (own session only). This deletes the sign-in email, phone numbers, saved addresses and open carts (history rows are kept), ends every session on this store, and signs the customer out. Orders and invoices are kept for the store's records. It is refused while the customer has an order waiting for payment; the store must cancel that order first.</li>
  </ol>
  <p class="note">Sign-up is US-only, blocks VPN and Tor traffic, and is rate limited. Unfinished sign-ups are deleted after 90 days.</p>

  <h2 id="addresses">Customer addresses</h2>
  <p><code>/address/</code> creates, edits, lists and deletes addresses. A signed-in customer manages their own; staff can manage people and companies on their brand. Checkout uses one shipping address and one billing address from this list.</p>
  <pre>POST /address/  {"action":"create","line_1":"75-5660 Palani Rd","city":"Kailua-Kona","state":"HI","zipcode":"96740","address_type":"Shipping"}</pre>

  <h2 id="cart">Carts</h2>
  <ul>
    <li>POST <code>{"action":"add","variant_id":"...","quantity":2}</code> to <code>/store/cart/</code>. Without <code>cart_id</code> a new cart is made and its id is returned. Keep it (for example in <code>localStorage</code>) and send it on later calls.</li>
    <li>Cart ids are long random server ids; nobody can choose their own. Adding the same variant again raises the quantity (one line per variant).</li>
    <li>Actions <code>update</code> (quantity 0 removes), <code>remove</code>, <code>read</code> and <code>delete</code>. Signed-in customers can use <code>mine</code> to find their open carts on any device.</li>
    <li>When a signed-in customer touches an anonymous cart, it becomes theirs and only they can see it.</li>
  </ul>

  <h2 id="checkout">Checkout and invoices</h2>
  <pre>POST /store/checkout/
{"action":"quote","cart_id":"...","shipping_address_id":"..."}

{"action":"checkout","cart_id":"...","shipping_address_id":"...","billing_address_id":"..."}</pre>
  <ul>
    <li>Checkout needs a signed-in customer. Availability is checked, a sale movement is written for each tracked line, and a PENDING invoice is created with <code>cart_id</code>, lines (product, variant, name and price at the time), flat-rate shipping, Hawaii GET when the shipping address is in Hawaii, and SHIPPING and BILLING address copies.</li>
    <li>A confirmation is emailed to the customer and a copy to the store (<code>store_email</code>).</li>
    <li>A cart can be checked out once. No payment is taken at checkout.</li>
    <li>Customers read their own invoices with <code>/store/invoice/</code> (<code>read</code>, <code>list</code>). Staff can list by company and status, adjust tax and shipping while PENDING (the total is recalculated), or mark an invoice VOIDED or REFUNDED.</li>
  </ul>

  <h2 id="payments">Payments</h2>
  <ul>
    <li>Staff record payments with <code>/store/invoice/payment/</code>: <code>amount</code>, <code>method</code> (CASH, CARD, PAYPAL or OTHER) and optional <code>provider_ref</code>. When completed payments reach the total, the invoice becomes PAID.</li>
    <li>Amounts can never be more than the balance due.</li>
    <li>Each store connects its own PayPal account. <code>paypal_start</code> and <code>paypal_capture</code> exist on <code>/store/invoice/payment/</code> but are not switched on yet (they answer 501 until the platform flag and the store's PayPal flag are both on).</li>
  </ul>
  <p class="note">The payment link on checkout is not on yet. A customer's CARD or PAYPAL request is recorded as PENDING and <strong>no charge is made</strong>; the store confirms the payment (for example after an invoice is paid by phone, PayPal or in person) by marking it COMPLETED. Customers are never charged automatically.</p>

  <h2 id="shipping">Shipments</h2>
  <ul>
    <li>Staff create shipments with <code>/store/shipment/</code>: the <code>invoice_id</code>, a list of <code>items</code> (<code>invoice_product_id</code> and <code>quantity</code>), and optional <code>carrier</code>, <code>service</code>, <code>tracking_number</code>, <code>tracking_url</code> (https only) and <code>shipping_cost</code>.</li>
    <li>An order can ship in several parts. You cannot ship more than was ordered (cancelled shipments do not count).</li>
    <li>Status: PENDING, LABEL_CREATED, SHIPPED, IN_TRANSIT, DELIVERED, RETURNED, CANCELLED. Shipped and delivered dates are filled in automatically.</li>
    <li>Every shipment goes to the order's shipping address copy. Customers can read their shipments and tracking links.</li>
  </ul>

  <h2 id="history">History and safety</h2>
  <ul>
    <li>Every create, update and delete keeps a history copy of the row, so changes can be traced and undone.</li>
    <li>Inventory movements are insert-only. Balances can be rebuilt from that history; a quantity is never overwritten in place.</li>
    <li>Sign-in codes, session ids and brand codes are never returned in JSON. The customer session is an HttpOnly, Secure cookie tied to one domain.</li>
    <li>Every store row is kept to its brand; staff only see companies they are allowed to act for.</li>
  </ul>

  <h2 id="reference">Endpoint reference</h2>
  <p>POST JSON with an <code>action</code>. A GET (or a POST with no action) on any path returns its list of actions. Staff add <code>session</code> and <code>brand</code> to the body.</p>
  <div class="table-wrap"><table>
    <tr><th>Path</th><th>Actions</th><th>Who</th></tr>
    <tr><td><code>/store/products/</code></td><td>list, read</td><td>Public</td></tr>
    <tr><td><code>/store/products/categories/</code></td><td>list</td><td>Public</td></tr>
    <tr><td><code>/store/search/</code></td><td>search (<code>q</code>)</td><td>Public</td></tr>
    <tr><td><code>/store/product/</code></td><td>create, update, delete, claim, unclaim</td><td>Staff</td></tr>
    <tr><td><code>/store/product/variants/</code></td><td>list (public), create, update, delete</td><td>Staff</td></tr>
    <tr><td><code>/store/product/description/</code></td><td>create, update, delete</td><td>Staff</td></tr>
    <tr><td><code>/store/product/categories/</code></td><td>create, update, delete, link, unlink</td><td>Staff</td></tr>
    <tr><td><code>/store/product/images/</code></td><td>create, update, delete</td><td>Staff</td></tr>
    <tr><td><code>/store/settings/</code></td><td>read, update</td><td>Staff</td></tr>
    <tr><td><code>/store/inventory/</code></td><td>receive, adjust, stock, rebuild</td><td>Staff</td></tr>
    <tr><td><code>/store/inventory/locations/</code></td><td>list, create, update</td><td>Staff</td></tr>
    <tr><td><code>/store/inventory/movements/</code></td><td>list</td><td>Staff</td></tr>
    <tr><td><code>/store/inventory/balances/</code></td><td>list</td><td>Staff</td></tr>
    <tr><td><code>/store/cart/</code></td><td>add, update, remove, read, mine, delete</td><td>Public / customer</td></tr>
    <tr><td><code>/store/checkout/</code></td><td>quote, checkout</td><td>Public (quote) / customer (checkout)</td></tr>
    <tr><td><code>/store/invoice/</code></td><td>read, list, update</td><td>Customer (own) / staff</td></tr>
    <tr><td><code>/store/invoice/payment/</code></td><td>create, update, list, paypal_start, paypal_capture</td><td>Customer (own) / staff</td></tr>
    <tr><td><code>/store/shipment/</code></td><td>create, update, delete, read, list</td><td>Staff / customer (read)</td></tr>
    <tr><td><code>/address/</code></td><td>create, update, delete, read, list</td><td>Customer (own) / staff</td></tr>
    <tr><td><code>/end_user_account_setup/</code></td><td>request_code, verify, status, profile, delete_account</td><td>Customer, on your domain</td></tr>
    <tr><td><code>/customer_session/</code></td><td>request_code, verify, whoami, logout</td><td>Customer, on your domain</td></tr>
  </table></div>
  <p class="muted">Always include the trailing slash, for example <code>/store/cart/</code>. Errors come back as <code>{"ok":false,"error":"..."}</code> with a matching HTTP status.</p>
</main>
</body>
</html>
