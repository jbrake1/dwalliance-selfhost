<?php
declare(strict_types=1);
// Plain-language store overview, served by GET /store/kit/ (issue #31). Static text only; no data is read.
// "kit" is not a store API action. On a customer domain, keep the brand's own /files page (or 404).
require_once '/var/www/html/lib/DwCustomer.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    require_once '/var/www/html/lib/DwBrand.php';
    echo json_encode(['ok' => false, 'error' => DwBrand::t('not a store API path; see https://app.dwalliance.com/store/')], JSON_UNESCAPED_SLASHES);
    exit;
}
$onStore = false;
try {
    $onStore = DwCustomer::brandFromHost() !== null;
} catch (Throwable $e) {
}
if ($onStore) {
    require '/var/www/html/404.php';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
require_once '/var/www/html/lib/DwBrand.php';
DwBrand::themeOutput(); // page text follows the configured brand (lib/DwBrand.php)
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>What's in the DW Alliance store</title>
<meta name="description" content="A plain-language tour of the DW Alliance store: products, cart and checkout, customer accounts, shipping and tax, inventory, order emails and payments.">
<style>
  :root { color-scheme: light dark; --fg: #1a1a1a; --muted: #555; --line: #ddd; --card: #f7f7f7; --accent: #0b5fff; --banner: #fff3bf; --banner-fg: #2b2100; }
  @media (prefers-color-scheme: dark) { :root { --fg: #f2f2f2; --muted: #b0b0b0; --line: #333; --card: #1c1c1c; --accent: #6ea8ff; --banner: #5c4a12; --banner-fg: #fff6d6; } }
  body { margin: 0; font: 16px/1.55 system-ui, -apple-system, Segoe UI, sans-serif; color: var(--fg); }
  .draft-banner { background: var(--banner); color: var(--banner-fg); text-align: center; font-size: .85rem; font-weight: 600; padding: .4rem 1rem; margin: 0; }
  main { max-width: 54rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
  h1 { font-size: 1.8rem; margin: 0 0 .5rem; } h2 { font-size: 1.25rem; margin: 2.2rem 0 .75rem; } h3 { font-size: 1rem; margin: 1.3rem 0 .4rem; }
  .muted { color: var(--muted); } a { color: var(--accent); }
  .cards { display: grid; gap: .75rem; margin: 1rem 0 0; }
  @media (min-width: 40rem) { .cards { grid-template-columns: 1fr 1fr; } }
  .card { border: 1px solid var(--line); background: var(--card); border-radius: .6rem; padding: .9rem 1rem; }
  .card strong { display: block; margin-bottom: .2rem; }
  .note { border-left: 3px solid var(--accent); padding: .2rem 0 .2rem .8rem; margin: 1rem 0; color: var(--muted); }
  ol { padding-left: 1.2rem; } ol > li { margin: .55rem 0; }
  code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .9rem; }
</style>
</head>
<body>
<main>
  <p><a href="/store/">&larr; Store guide</a> · <a href="/">DW Alliance app</a></p>
  <h1>What's in the DW Alliance store</h1>
  <p class="muted">A plain-language tour of what is live today. The technical endpoint list is on the <a href="/store/">store guide</a>. DW Alliance builds, maintains and extends the technology that lets clients' AIs build their dream websites, including a shop on the client's own domain.</p>

  <p>The store is built into every DW Alliance brand. Customers shop on the store's own domain. Staff manage it with a DW Alliance session.<?php $dwShow = DwConfig::get('STORE_SHOWCASE_DOMAIN'); if ($dwShow !== ''): ?> The first live tenant store is <a href="https://<?= htmlspecialchars($dwShow, ENT_QUOTES) ?>/store/"><?= htmlspecialchars($dwShow, ENT_QUOTES) ?></a>.<?php endif; ?></p>

  <h2>What is live today</h2>
  <div class="cards">
    <div class="card"><strong>Products and variants</strong>A catalog with categories, photos, search, and sizes or options. Creating a product creates a default variant in the same step. You can hide a product without losing its order history.</div>
    <div class="card"><strong>Cart and checkout</strong>Customers add items without an account, then sign in to check out. A quote shows shipping and tax first. Checkout creates a PENDING invoice with a frozen copy of the addresses.</div>
    <div class="card"><strong>Customer accounts</strong>Email-code sign-up and sign-in. No passwords. Saved addresses, past orders, and a delete-account action. Deleting an account removes sign-in, phone numbers, saved addresses and open carts; orders stay in the store's records. An account cannot be deleted while an order is waiting for payment.</div>
    <div class="card"><strong>Shipping and tax, per store</strong>Each store sets a flat shipping rate and an optional free-over threshold. Tax is also per store. Hawaii GET, for example, applies only to Hawaii shipping addresses, and is not charged on shipping unless the store turns that on.</div>
    <div class="card"><strong>Order emails</strong>When an order is placed, a confirmation goes to the customer and a copy goes to the store owner's email, which is set in the store's settings.</div>
    <div class="card"><strong>Inventory</strong>Locations (a default "Main" location is created when first needed), a movement history that is never overwritten, and staff actions to receive, adjust, check stock or rebuild balances from the history. A sale movement is written at checkout.</div>
  </div>

  <h2>How a sale works</h2>
  <ol>
    <li>Staff add products and receive stock into a location.</li>
    <li>A visitor browses and fills a cart on the store's domain.</li>
    <li>They sign in with an emailed code, pick a shipping address, see the shipping and tax quote, and place the order.</li>
    <li>The app writes a sale movement, emails the customer and the store, and creates a PENDING invoice.</li>
    <li>The store arranges payment, records it against the invoice, and adds a shipment with tracking.</li>
  </ol>

  <h2>Payments</h2>
  <p>Each store connects its own PayPal account. The payment link on checkout isn't on yet, so no charge is made automatically. A customer's card or PayPal request is recorded as pending only. Staff record a completed payment (for example after payment by phone, PayPal or in person) and the invoice becomes PAID when the total is covered.</p>
  <p class="note">Until the payment link is switched on, treat online checkout as "order placed, payment arranged separately." Customers are never charged automatically.</p>

  <h2>A kit for your AI</h2>
<?php if (DwBrand::isHostedService()): ?>
  <p>A copyable kit — page templates, scripts, a config file and a step-by-step runbook, based on <?= DwConfig::get('STORE_SHOWCASE_NAME') !== '' ? 'the live ' . htmlspecialchars(DwConfig::get('STORE_SHOWCASE_NAME'), ENT_QUOTES) . ' store' : 'a live store' ?> — is drafted for <a href="https://dwalliance.com/store-kit.html">dwalliance.com/store-kit.html</a>. That marketing-site page is also in preview and is not live yet. When it is approved, you can point your AI at the runbook and ask it to show a preview before anything on your domain goes live.</p>
<?php endif; ?>
  <p>The full endpoint reference, including inventory, settings, quotes and delete account, is in the <a href="/store/">store guide</a>.</p>
</main>
</body>
</html>
