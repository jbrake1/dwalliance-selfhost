<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// /store endpoints (2026-11-11, issue #8) over the store tables in dw_production.
// Who can do what:
//  - Public (no session) on a store domain: browse products, categories and search. The brand comes from the Host.
//  - Customer (signed in on the store domain, lib/DwCustomer.php): carts, checkout, own invoices, payments, shipments.
//  - Staff (JSON session + brand from /session): manage products, variants, descriptions, images, categories,
//    invoices, payments and shipments. Needs SUPER_ADMIN, or STORE rights on the brand, or EDITOR of a domain owned
//    by the company concerned.
// Every create, update and delete runs in a transaction with Dw::history (INSERT after, UPDATE/DELETE before).
// Money is USD, DECIMAL(10,2), handled as integer cents. No payment provider is called (stub).
// 2026-11-13: flat-rate shipping + Hawaii GET at checkout (quote step), order emails to the customer and the store,
// order-now/store-contacts-you-to-pay wording, per-company settings (/store/settings/, table store_setting), and a
// PayPal skeleton (lib/DwPayPal.php) that stays off until store.json paypal.enabled and the company flag are both on.
// 2026-11-13 (staging): inventory foundation (/store/inventory/..., lib/DwInventory.php, docs/sql/005_inventory.sql).
// Movements are the source of truth; product_variant.stock is kept as the storefront mirror; checkout records 'sale'.
require_once '/var/www/html/lib/DwCustomer.php';
require_once '/var/www/html/lib/DwPayPal.php';
require_once '/var/www/html/lib/DwPayPalBilling.php';
require_once '/var/www/html/lib/DwInventory.php';
require_once '/var/www/html/lib/DwRestock.php';
require_once '/var/www/html/lib/DwGate.php';
// 2026-11-14 (staging, banner): DW's own billing for the No Banner upgrade. product.entitlements (no_banner),
// product_variant.billing_interval (MONTH/YEAR), cart/invoice lines carry target_domain_id (the site), and settle()
// writes brand_entitlement rows when an invoice is PAID (docs/sql/006_entitlements.sql). /store/entitlement is staff only
// 2026-11-14: order and confirmation email subject and body name the covered site (target_domain).
// on the seller brand (store.json entitlements.seller_domain, default dwalliance.com). lib/DwBanner.php reads the grants.

final class DwStore
{
    private static PDO $db;
    private static ?string $brand = null;
    private static ?array $staff = null;
    private static ?array $cust = null;
    private static array $in = [];
    private static string $action = '';
    private static string $rawBody = '';
    private static array $paypalHeaders = [];

    public const HELP = [
        'products' => ['list' => 'Public. Optional company_id, category_id, limit (1-100), offset. Active products with price and default variant.', 'read' => 'Public. product_id or slug. Product with variants, descriptions, images, categories and claiming companies.'],
        'products/categories' => ['list' => 'Public. Optional company_id. Categories of the store.'],
        'search' => ['search' => 'Public. q (2+ characters). Active products whose name or description contains q.'],
        'product' => ['create' => 'Staff. company_id, name, price; optional slug, active (default true), variant_name (default "Default"), variant_price, stock, description. Creates the product, its company claim and a default variant in one transaction. DW Alliance LLC store only: entitlements ("no_banner") plus variant_billing_interval (MONTH or YEAR) and variant_billing_interval_count.', 'update' => 'Staff. product_id plus name, slug, price, active or entitlements ("no_banner", or "" to clear; DW Alliance LLC store only).', 'delete' => 'Staff. product_id. Refused once the product is in a cart or an order (set active false instead).', 'claim' => 'Staff. product_id, company_id. Another company on the brand claims the product (product_company).', 'unclaim' => 'Staff. product_id, company_id. The last claim cannot be removed.'],
        'product/variants' => ['list' => 'Public. product_id.', 'create' => 'Staff. product_id, name; optional price (blank = product price), stock (blank = not tracked), position, description (JSON object), billing_interval (NONE, MONTH, YEAR), billing_interval_count (1-12; MONTH/YEAR on the DW Alliance LLC store only) and paypal_plan_id (P-… sandbox/live plan id).', 'update' => 'Staff. variant_id plus fields, including paypal_plan_id (empty clears it).', 'delete' => 'Staff. variant_id. Not the last variant, and not once in a cart or an order.'],
        'product/description' => ['create' => 'Staff. product_id, description; optional used_location (default "main"; one description per location).', 'update' => 'Staff. description_id plus description or used_location.', 'delete' => 'Staff. description_id.'],
        'product/categories' => ['create' => 'Staff. company_id, name; optional parent_id (same company), position, description (JSON object).', 'update' => 'Staff. category_id plus name, parent_id, position, description.', 'delete' => 'Staff. category_id (no child categories). Removes its product links.', 'link' => 'Staff. category_id, product_id; optional variant_id (default: first variant), position. The product must be claimed by the category\'s company.', 'unlink' => 'Staff. product_category_id.'],
        'product/images' => ['create' => 'Staff. id_type PRODUCT, PRODUCT_VARIANT or CATEGORY, associated_id, file_id (a /files file on the brand); optional position, description (JSON object).', 'update' => 'Staff. image_id plus file_id, position or description.', 'delete' => 'Staff. image_id.'],
        'cart' => ['add' => 'Public or customer. variant_id, quantity (1-999); optional cart_id, notes, company_id. For an upgrade such as No Banner also target_domain (the site it is for, e.g. "example.com"; you must be a SUPER_ADMIN of that site; one site per cart line); quantity = billing periods (1-12). Without cart_id a new cart is made and its server-generated cart_id is returned. One line per variant: adding again raises the quantity.', 'update' => 'cart_id, variant_id (or item_id), quantity (0 removes the line).', 'remove' => 'cart_id, variant_id (or item_id).', 'read' => 'cart_id.', 'mine' => 'Customer. Carts of the signed-in customer.', 'delete' => 'cart_id. Not once it has been checked out.'],
        'checkout' => ['quote' => 'Public or customer. cart_id plus shipping_address_id (signed-in customer) or state (estimate, e.g. "HI"). Subtotal, flat-rate shipping, Hawaii GET and total, computed exactly as checkout will. Nothing is saved.',
            'checkout' => 'Customer. cart_id, shipping_address_id (one of your /address/ addresses); optional billing_address_id (default: the shipping address). Creates a PENDING invoice with invoice.cart_id set, order lines (product_id + variant_id + price), flat-rate shipping, Hawaii GET for HI shipping addresses, and one SHIPPING and one BILLING address copy. Tracked stock comes off now. No payment is taken: the store contacts the customer to arrange payment. Emails a confirmation to the customer and a copy to the store.'],
        'settings' => ['read' => 'Staff. company_id. Effective store settings: defaults from store.json with this company\'s overrides.', 'update' => 'Staff (SUPER_ADMIN or STORE UPDATE). company_id plus any of shipping_flat, free_shipping_over, hi_get_rate (percent, e.g. "4.712"), tax_shipping (true/false), store_email, paypal_enabled (true/false; also requires store.json paypal.enabled). Send null to go back to the default.'],
        'invoice' => ['read' => 'Customer (own) or staff. invoice_id. With lines, addresses, payments and shipments.', 'list' => 'Customer: own invoices. Staff: optional company_id, status.', 'update' => 'Staff. invoice_id plus tax, shipping (recomputes total) or status (PENDING, VOIDED, REFUNDED). PAID is set by payments.'],
        'invoice/payment' => ['create' => 'invoice_id, amount (USD, e.g. "19.99"), method CARD, PAYPAL, CASH or OTHER. Customer: CARD or PAYPAL, recorded PENDING (payment provider not connected yet). Staff: records a COMPLETED payment by default, or status PENDING; optional provider_ref.', 'update' => 'Staff. payment_id, status COMPLETED, FAILED or REFUNDED; optional provider_ref.', 'list' => 'Customer (own) or staff. invoice_id.',
            'paypal_start' => 'Customer. invoice_id. One-time Orders v2. Answers 501 until store.json paypal.enabled and the store\'s paypal_enabled flag are both on.',
            'paypal_capture' => 'Customer. invoice_id, paypal_order_id. Same switch as paypal_start.',
            'paypal_subscription_client' => 'Public once PayPal is on. Returns the public client id (never the secret) for the JS SDK (vault=true&intent=subscription).',
            'paypal_quote' => 'Customer (site owner). Same inputs as paypal_subscribe (variant_id, domain, shipping_address_id). Price, Hawaii GET and total exactly as paypal_subscribe will charge, plus whether the 30-day money-back guarantee applies. Nothing is saved and PayPal is not called.',
            'paypal_subscribe' => 'Customer (site owner). variant_id, domain (the covered site), shipping_address_id (Hawaii GET is computed from this address). Optional billing_address_id. Creates a PENDING invoice (qty 1) and a PayPal subscription with custom_id set to the invoice and site. Returns approve_url. If the plan has no tax, Hawaii GET is added as a per-subscription plan price override.'],
        'entitlement' => ['list' => 'Staff (DW Alliance LLC store: SUPER_ADMIN or STORE READ). Optional domain, status (ACTIVE, CANCELLED, REVOKED), entitlement (no_banner, store or watermark; default no_banner).',
            'status' => 'Staff. domain; optional entitlement (default no_banner). no_banner: whether the site shows the DW banner now, and until when it is hidden. store / watermark: the add-on gate for that site\'s brand.',
            'grant' => 'Staff (STORE CREATE). domain; optional entitlement (no_banner, store or watermark; default no_banner), months or years (a paid-style period starting at the end of the current one), otherwise no end date; note. Free grants are allowed.',
            'revoke' => 'Staff (STORE UPDATE). entitlement_id. Ends it now: the banner comes back on the next request.',
            'cancel' => 'Staff (STORE UPDATE). domain; optional entitlement (only that add-on; default every type). No renewal: the site keeps No Banner (and any add-on) until the end of the period already paid (no grace), then it ends. No-end grants end now. Also cancels a linked PayPal subscription when there is one. Never refunds: the 30-day money-back guarantee runs only on the owner\'s cancel_mine or a cancel made in PayPal.',
            'cancel_mine' => 'Customer (site owner). domain; optional entitlement (no_banner, store or watermark: cancel just that one; default every subscription on the site). Cancels every subscription on a site you own and its PayPal subscription. 30-day money-back guarantee: within 30 days of the first payment ever made for that product (by you or for that site, once only) every grant the subscription paid for ends now and everything paid under it is refunded through PayPal (Hawaii GET included). After 30 days the grant runs to paid_through with no extra grace and nothing is refunded. Refunds are made only through the platform the customer paid with (PayPal): a temporary PayPal error is retried automatically (3 tries, same PayPal-Request-Id); if PayPal still cannot refund, no refund is given (no manual refund), the cancel and revoke stand, the invoice becomes REFUND_FAILED and an information-only alert is sent.',
            'cancel_preview' => 'Customer (site owner). domain. What cancel_mine would do: each subscription, its first payment, whether the money-back window is open and when it ends.',
            'mine' => 'Customer (site owner). domain. Your No Banner grants and subscription status for that site.',
            'features' => 'Staff (any brand). Paid add-ons for your brand (store = Shopping cart, watermark = Watermarks): allowed, state (seller, subscribed, not_enforced, grace, required), price and a prefilled subscribe link.',
            'my_sites' => 'Customer. DW Alliance sites you own (your sign-in email is a SUPER_ADMIN email of the site), with what each has active now. Site names only.'],
        'banner' => [
            'read' => 'Staff (DW Alliance LLC store: SUPER_ADMIN or STORE READ). Global banner_default / banner_style and each brand\'s banner_mode / banner_style, plus whether the site currently shows the banner.',
            'update_global' => 'Staff (SUPER_ADMIN or STORE UPDATE). banner_default on|off; optional banner_style A, B or C; optional watermark_default on|off (owner-mark global default, refs #51). Written to app_setting (history in app_setting_history). After banner_default is first written, the DB is the tenant source of truth for the HTML banner. watermark_default is independent: it does not hide the DW promo mark on a free site.',
            'update_brand' => 'Staff (SUPER_ADMIN or STORE UPDATE). brand (the brand code) or domain, plus banner_mode default|on|off and/or banner_style default|A|B|C. Written to brand (history in brand_history). DW Alliance LLC seller brand cannot be turned on (those sites never show the banner).'],
        'paypal_webhook' => ['notify' => 'PayPal only. Every event is verified with /v1/notifications/verify-webhook-signature. Unverified events are rejected. Idempotent by event id. Maps billing and sale events onto brand_entitlement.'],
        'subscription_return' => ['status' => 'Public or customer. invoice_id or paypal_subscription_id. Pending or active state after the buyer returns from PayPal. GET /store/subscription_return/ also renders a short status page.'],
        'inventory' => [
            'receive' => 'Staff (STORE UPDATE for the store). variant_id or product_id (default variant), quantity (1+); optional location_id (default: the store\'s default location; company_id picks the store when the product has several), unit_cost (USD, e.g. "4.25"), reference_type, reference_id, notes, lot_id, serial, bin. Inserts a receipt movement.',
            'adjust' => 'Staff (STORE UPDATE). variant_id or product_id, and either quantity (signed, not 0: -2 takes two off) or counted (what is physically there; the difference is recorded). Optional location_id/company_id, notes, reference_type, reference_id. Inserts an adjustment movement; never overwrites a quantity. Refused if it would take a location below 0.',
            'stock' => 'Staff (STORE READ). variant_id or product_id; optional company_id. Per location: on_hand, reserved, available (on_hand - reserved), plus totals.',
            'rebuild' => 'Staff (STORE UPDATE brand-wide, or for company_id). Recomputes inventory_balance from inventory_movement and reports any differences it fixed.'],
        'inventory/movements' => ['list' => 'Staff (STORE READ). company_id (required unless you have brand-wide rights); optional variant_id, product_id, location_id, movement_type, reference_type, reference_id, limit (1-500, default 100), offset. Newest first.'],
        'inventory/balances' => ['list' => 'Staff (STORE READ). company_id (required unless brand-wide); optional location_id, product_id. The cached on_hand / reserved / available per product, variant and location.'],
        'inventory/locations' => ['list' => 'Staff (STORE READ). company_id; optional include_inactive (true). A store gets a default location "Main" the first time one is needed.',
            'create' => 'Staff (STORE CREATE). company_id, name; optional type (store, warehouse, supplier, virtual, other; default store), is_default (true makes it the default).',
            'update' => 'Staff (STORE UPDATE). location_id plus name, type, is_active or is_default. The default location cannot be deactivated (make another one the default first).'],
        'shipment' => ['create' => 'Staff. invoice_id, items [{invoice_product_id, quantity}]; optional carrier, service, tracking_number, tracking_url, shipping_cost, status. Ships to the order\'s SHIPPING address copy. Several shipments per order are fine; quantities cannot exceed what was ordered.', 'update' => 'Staff. shipment_id plus carrier, service, tracking_number, tracking_url, shipping_cost or status (PENDING, LABEL_CREATED, SHIPPED, IN_TRANSIT, DELIVERED, RETURNED, CANCELLED).', 'delete' => 'Staff. shipment_id.', 'read' => 'Customer (own) or staff. shipment_id.', 'list' => 'Customer (own) or staff. invoice_id.'],
    ];

    // ------------------------------------------------------------------ entry
    public static function route(string $res): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($res === '' && $method === 'GET') {
            // app host: the store guide. A customer domain keeps its own /store/ page (brand files via 404.php).
            $onStore = false;
            try {
                $onStore = DwCustomer::brandFromHost() !== null;
            } catch (Throwable $e) {
            }
            require $onStore ? '/var/www/html/404.php' : '/var/www/html/store/guide.php';
            exit;
        }
        if ($res === 'subscription_return' && $method === 'GET') {
            try {
                self::$db = Dw::pdo();
                self::$in = ['action' => 'status', 'invoice_id' => (string)($_GET['invoice_id'] ?? ''), 'paypal_subscription_id' => (string)($_GET['subscription_id'] ?? $_GET['paypal_subscription_id'] ?? ''), 'cancelled' => (string)($_GET['cancelled'] ?? '')];
                self::$action = 'status';
                self::authPublicStore();
                self::rSubscription_return(true);
            } catch (Throwable $e) {
                error_log('store subscription_return GET: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
                self::fail(500, 'could not load subscription status');
            }
        }
        if ($res === 'paypal_webhook') {
            self::routePaypalWebhook($method);
        }
        if ($method !== 'POST') {
            self::help($res);
        }
        self::$in = DwCustomer::body();
        self::$action = strtolower(trim((string)(self::$in['action'] ?? '')));
        if ($res === 'search' && self::$action === '') {
            self::$action = 'search';
        }
        if ($res === 'checkout' && self::$action === '' && isset(self::$in['cart_id'])) {
            self::$action = 'checkout';
        }
        if ($res === 'paypal_webhook') {
            self::$action = 'notify';
        }
        if (self::$action === '' || $res === '') {
            self::help($res);
        }
        $actions = array_keys(self::HELP[$res] ?? []);
        if (!in_array(self::$action, $actions, true)) {
            self::fail(400, 'action must be one of ' . implode(', ', $actions));
        }
        try {
            self::$db = Dw::pdo();
            self::auth();
            self::storeGate($res);
            $fn = 'r' . str_replace(' ', '', ucwords(str_replace('/', ' ', $res)));
            self::$fn();
        } catch (Throwable $e) {
            if (isset(self::$db) && self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                self::fail(409, 'that would duplicate an existing row (one line per variant per cart, one claim per company, one description per location, unique slug)');
            }
            error_log('store ' . $res . ' ' . self::$action . ': ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
            self::fail(500, 'could not complete ' . self::$action);
        }
    }

    private static function help(string $res): void
    {
        $base = ['ok' => true, 'path' => '/store/' . ($res === '' ? '' : $res . '/'), 'guide' => 'https://' . DwBrand::appHost() . '/store/'];
        if ($res === '') {
            $paths = [];
            foreach (self::HELP as $r => $acts) {
                $paths['/store/' . $r . '/'] = array_map([DwBrand::class, 't'], $acts);
            }
            DwCustomer::out(200, $base + ['what' => 'Store API. POST JSON with action to each path below.', 'paths' => $paths]);
        }
        DwCustomer::out(200, $base + ['actions' => array_map([DwBrand::class, 't'], self::HELP[$res] ?? [])]);
    }

    private static function fail(int $code, string $msg): void
    {
        if (isset(self::$db) && self::$db->inTransaction()) {
            self::$db->rollBack();
        }
        DwCustomer::out($code, ['ok' => false, 'error' => DwBrand::t($msg)]);
    }

    private static function ok(array $p): void
    {
        DwCustomer::out(200, ['ok' => true] + $p + (self::$gateNotice !== null ? ['notice' => self::$gateNotice['notice'], 'gate' => self::$gateNotice] : []));
    }

    // Shopping cart add-on gate (refs pt8u): staff store-admin writes on a brand without an active 'store' grant.
    // Reads, carts, checkout, payments, invoices and shipments (fulfilment of paid orders) are never gated.
    public const STORE_GATED = [
        'product' => ['create', 'update', 'delete', 'claim', 'unclaim'],
        'product/variants' => ['create', 'update', 'delete'],
        'product/description' => ['create', 'update', 'delete'],
        'product/categories' => ['create', 'update', 'delete'],
        'product/images' => ['create', 'update', 'delete'],
        'inventory' => ['receive', 'adjust', 'rebuild'],
        'inventory/locations' => ['create', 'update'],
        'settings' => ['update'],
    ];
    private static ?array $gateNotice = null;

    private static function storeGate(string $res): void
    {
        if (self::$staff === null || self::$brand === null || !in_array(self::$action, self::STORE_GATED[$res] ?? [], true)) {
            return;
        }
        $c = DwGate::check(self::$db, self::$brand, 'store');
        if (!$c['allowed']) {
            DwCustomer::out(402, DwGate::denyBody($c));
        }
        if ($c['state'] === 'grace') {
            self::$gateNotice = $c;
        }
    }

    private static function entName(bool $required = false, ?string $default = 'no_banner'): ?string
    {
        $e = strtolower(trim((string)(self::$in['entitlement'] ?? '')));
        if ($e === '') {
            if ($required) {
                self::fail(400, 'entitlement is required (' . implode(', ', DwGate::ENTITLEMENTS) . ')');
            }
            return $default;
        }
        if (!in_array($e, DwGate::ENTITLEMENTS, true)) {
            self::fail(400, 'entitlement must be one of ' . implode(', ', DwGate::ENTITLEMENTS));
        }
        return $e;
    }

    // Staff: JSON session + matching brand. Otherwise: brand from Host plus optional customer cookie.
    private static function auth(): void
    {
        if (isset(self::$in['session']) && self::$in['session'] !== '') {
            $s = Dw::session(self::$in);
            if ($s === null) {
                self::fail(401, 'a valid session is required');
            }
            $b = trim((string)(self::$in['brand'] ?? ''));
            if ($b === '' || !hash_equals((string)$s['brand'], $b)) {
                self::fail(403, 'brand does not match this session');
            }
            self::$staff = $s;
            self::$brand = (string)$s['brand'];
            return;
        }
        $r = DwCustomer::brandFromHost();
        if ($r !== null) {
            self::$brand = $r[0];
            self::$cust = DwCustomer::current(self::$db);
        }
    }

    private static function needBrand(): string
    {
        if (self::$brand === null) {
            self::fail(400, 'call this on the store\'s own domain, or send a staff session and brand');
        }
        return self::$brand;
    }

    private static function needStaff(): array
    {
        if (self::$staff === null) {
            self::fail(401, 'this needs a staff session and brand (from /session/)');
        }
        return self::$staff;
    }

    private static function needCustomer(): array
    {
        if (self::$cust === null) {
            self::fail(401, 'sign in on the store domain first (/customer_session/ or /end_user_account_setup/)');
        }
        return self::$cust;
    }

    private static function brandWide(string $right): bool
    {
        $s = self::needStaff();
        $q = self::$db->prepare("SELECT 1 FROM data_access WHERE person_id = ? AND brand = ? AND (right_name = 'SUPER_ADMIN' OR (module_name = 'STORE' AND right_name IN (?, 'WRITE'))) LIMIT 1");
        $q->execute([$s['person_id'], self::$brand, $right]);
        return (bool)$q->fetchColumn();
    }

    // Staff may act for this company: SUPER_ADMIN, STORE right on the brand, or EDITOR of a domain the company owns.
    private static function canCompany(string $right, string $companyId): bool
    {
        if (self::brandWide($right)) {
            return true;
        }
        $q = self::$db->prepare('SELECT 1 FROM domain_editor de JOIN domain d ON d.id = de.domain_id WHERE de.person_id = ? AND de.brand = ? AND d.code = ? AND d.company_id = ? LIMIT 1');
        $q->execute([self::$staff['person_id'], self::$brand, self::$brand, $companyId]);
        return (bool)$q->fetchColumn();
    }

    private static function needCompany(string $right, string $companyId): void
    {
        self::needStaff();
        if (!self::one('SELECT 1 FROM company WHERE id = ? AND brand = ?', [$companyId, self::$brand])) {
            self::fail(404, 'company not found on this brand');
        }
        if (!self::canCompany($right, $companyId)) {
            self::fail(403, 'needs SUPER_ADMIN, STORE ' . $right . ', or EDITOR of a domain owned by that company');
        }
    }

    private static function needProduct(string $right, string $productId): array
    {
        self::needStaff();
        $p = self::one('SELECT * FROM product WHERE id = ? AND brand = ?', [$productId, self::$brand]);
        if (!$p) {
            self::fail(404, 'product not found on this brand');
        }
        if (self::brandWide($right)) {
            return $p;
        }
        foreach (self::col('SELECT company_id FROM product_company WHERE product_id = ? AND brand = ?', [$productId, self::$brand]) as $c) {
            if (self::canCompany($right, (string)$c)) {
                return $p;
            }
        }
        self::fail(403, 'needs SUPER_ADMIN, STORE ' . $right . ', or EDITOR for a company that claims this product');
        return [];
    }

    // ------------------------------------------------------------------ small helpers
    private static function q(string $sql, array $a = []): PDOStatement
    {
        $s = self::$db->prepare($sql);
        $s->execute($a);
        return $s;
    }

    private static function one(string $sql, array $a = []): ?array
    {
        $r = self::q($sql, $a)->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private static function all(string $sql, array $a = []): array
    {
        return self::q($sql, $a)->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function col(string $sql, array $a = []): array
    {
        return self::q($sql, $a)->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function begin(): void
    {
        self::$db->beginTransaction();
    }

    private static function commit(): void
    {
        self::$db->commit();
    }

    private static function insert(string $table, array $row): string
    {
        $row = ['id' => $row['id'] ?? Dw::randId()] + $row;
        $cols = array_keys($row);
        self::q('INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($row));
        Dw::history(self::$db, $table, (string)$row['id'], 'INSERT');
        return (string)$row['id'];
    }

    private static function update(string $table, string $id, array $set): void
    {
        if ($set === []) {
            return;
        }
        Dw::history(self::$db, $table, $id, 'UPDATE');
        self::q('UPDATE `' . $table . '` SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $id]);
    }

    private static function delete(string $table, string $id): void
    {
        Dw::history(self::$db, $table, $id, 'DELETE');
        self::q('DELETE FROM `' . $table . '` WHERE id = ?', [$id]);
    }

    private static function has(string $k): bool
    {
        return array_key_exists($k, self::$in) && self::$in[$k] !== null && self::$in[$k] !== '';
    }

    private static function id(string $k, bool $required = true): ?string
    {
        $v = trim((string)(is_scalar(self::$in[$k] ?? null) ? self::$in[$k] : ''));
        if ($v === '') {
            if ($required) {
                self::fail(400, $k . ' is required');
            }
            return null;
        }
        if (strlen($v) > 50) {
            self::fail(400, $k . ' is not valid');
        }
        return $v;
    }

    private static function str(string $k, int $max, bool $required = false): ?string
    {
        $v = DwCustomer::text(self::$in, $k, $max);
        if ($required && $v === null) {
            self::fail(400, $k . ' is required');
        }
        return $v;
    }

    private static function int(string $k, int $min, int $max): ?int
    {
        if (!self::has($k)) {
            return null;
        }
        $v = filter_var(self::$in[$k], FILTER_VALIDATE_INT);
        if ($v === false || $v < $min || $v > $max) {
            self::fail(400, $k . " must be a whole number from $min to $max");
        }
        return $v;
    }

    private static function bool(string $k): ?int
    {
        if (!self::has($k)) {
            return null;
        }
        $v = filter_var(self::$in[$k], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($v === null) {
            self::fail(400, $k . ' must be true or false');
        }
        return $v ? 1 : 0;
    }

    private static function json(string $k): ?string
    {
        if (!self::has($k)) {
            return null;
        }
        $v = self::$in[$k];
        if (is_string($v)) {
            $v = ['text' => $v];
        }
        if (!is_array($v)) {
            self::fail(400, $k . ' must be a JSON object or text');
        }
        $j = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($j) > 20000) {
            self::fail(400, $k . ' is too large');
        }
        return $j;
    }

    // USD amount -> integer cents. Accepts "19.99", 19.99, "20".
    private static function cents(string $k, bool $required, bool $allowZero = true): ?int
    {
        if (!self::has($k)) {
            if ($required) {
                self::fail(400, $k . ' is required (USD, e.g. "19.99")');
            }
            return null;
        }
        $raw = self::$in[$k];
        $v = is_float($raw) ? number_format($raw, 2, '.', '') : trim((string)(is_scalar($raw) ? $raw : ''));
        if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $v, $m)) {
            self::fail(400, $k . ' must be a USD amount like 19.99 (up to 99999999.99)');
        }
        $c = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '0', 2, '0');
        if (!$allowZero && $c === 0) {
            self::fail(400, $k . ' must be more than 0');
        }
        return $c;
    }

    private static function money(?int $c): ?string
    {
        return $c === null ? null : sprintf('%d.%02d', intdiv($c, 100), $c % 100);
    }

    private static function toCents($dec): int
    {
        [$a, $b] = array_pad(explode('.', (string)$dec, 2), 2, '0');
        return (int)$a * 100 + (int)str_pad(substr($b, 0, 2), 2, '0');
    }

    private static function slug(string $s): string
    {
        $s = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
        return substr($s === '' ? 'product' : $s, 0, 180);
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------ public catalog
    private static function productCard(array $p): array
    {
        $v = self::one('SELECT id FROM product_variant WHERE product_id = ? ORDER BY position, last_updated LIMIT 1', [$p['id']]);
        return ['product_id' => $p['id'], 'name' => $p['name'], 'slug' => $p['slug'], 'price' => $p['price'], 'currency' => 'USD', 'active' => (bool)$p['active'],
            'default_variant_id' => $v['id'] ?? null, 'entitlements' => ($p['entitlements'] ?? '') === '' || ($p['entitlements'] ?? null) === null ? [] : explode(',', (string)$p['entitlements'])];
    }

    private static function variantOut(array $v, string $productPrice): array
    {
        return ['variant_id' => $v['id'], 'name' => $v['name'], 'price' => $v['price'] ?? $productPrice, 'stock' => $v['stock'] === null ? null : (int)$v['stock'],
            'position' => (int)$v['position'], 'description' => json_decode((string)$v['description'], true),
            'billing_interval' => $v['billing_interval'] ?? 'NONE', 'billing_interval_count' => (int)($v['billing_interval_count'] ?? 1),
            'paypal_plan_id' => $v['paypal_plan_id'] ?? null];
    }

    private static function rProducts(): void
    {
        $brand = self::needBrand();
        if (self::$action === 'read') {
            $pid = self::id('product_id', false);
            $p = $pid !== null ? self::one('SELECT * FROM product WHERE id = ? AND brand = ?', [$pid, $brand])
                : self::one('SELECT * FROM product WHERE slug = ? AND brand = ?', [self::str('slug', 200, true), $brand]);
            if (!$p || (!(int)$p['active'] && self::$staff === null)) {
                self::fail(404, 'product not found');
            }
            $out = self::productCard($p);
            $out['variants'] = array_map(fn($v) => self::variantOut($v, (string)$p['price']),
                self::all('SELECT * FROM product_variant WHERE product_id = ? AND brand = ? ORDER BY position, last_updated', [$p['id'], $brand]));
            $out['descriptions'] = self::all('SELECT id AS description_id, used_location, description FROM product_description WHERE product_id = ? AND brand = ?', [$p['id'], $brand]);
            $vids = array_column($out['variants'], 'variant_id');
            $imgSql = "SELECT * FROM product_images WHERE brand = ? AND ((id_type = 'PRODUCT' AND associated_id = ?)"
                . ($vids ? " OR (id_type = 'PRODUCT_VARIANT' AND associated_id IN (" . implode(',', array_fill(0, count($vids), '?')) . '))' : '') . ') ORDER BY position';
            $out['images'] = array_map(fn($i) => ['image_id' => $i['id'], 'id_type' => $i['id_type'], 'associated_id' => $i['associated_id'], 'file_id' => $i['file_id'],
                'position' => (int)$i['position'], 'description' => json_decode((string)$i['description'], true)], self::all($imgSql, [$brand, $p['id'], ...$vids]));
            $out['categories'] = self::all('SELECT pc.id AS product_category_id, c.id AS category_id, c.name, pc.variant_id FROM product_category pc JOIN category c ON c.id = pc.category_id WHERE pc.product_id = ? AND pc.brand = ?', [$p['id'], $brand]);
            $out['company_ids'] = self::col('SELECT company_id FROM product_company WHERE product_id = ? AND brand = ?', [$p['id'], $brand]);
            self::ok(['product' => $out]);
        }
        $limit = self::int('limit', 1, 100) ?? 50;
        $offset = self::int('offset', 0, 100000) ?? 0;
        $sql = 'SELECT DISTINCT p.* FROM product p';
        $where = ['p.brand = ?'];
        $args = [$brand];
        if (($c = self::id('company_id', false)) !== null) {
            $sql .= ' JOIN product_company pco ON pco.product_id = p.id';
            $where[] = 'pco.company_id = ?';
            $args[] = $c;
        }
        if (($cat = self::id('category_id', false)) !== null) {
            $sql .= ' JOIN product_category pca ON pca.product_id = p.id';
            $where[] = 'pca.category_id = ?';
            $args[] = $cat;
        }
        if (self::$staff === null || !self::bool('include_inactive')) {
            $where[] = 'p.active = 1';
        }
        $rows = self::all($sql . ' WHERE ' . implode(' AND ', $where) . " ORDER BY p.name LIMIT $limit OFFSET $offset", $args);
        self::ok(['products' => array_map(fn($r) => self::productCard($r), $rows)]);
    }

    private static function rProductsCategories(): void
    {
        $brand = self::needBrand();
        $sql = 'SELECT id AS category_id, company_id, parent_id, name, description, position FROM category WHERE brand = ?';
        $args = [$brand];
        if (($c = self::id('company_id', false)) !== null) {
            $sql .= ' AND company_id = ?';
            $args[] = $c;
        }
        $rows = self::all($sql . ' ORDER BY company_id, position, name', $args);
        foreach ($rows as &$r) {
            $r['description'] = json_decode((string)$r['description'], true);
            $r['position'] = (int)$r['position'];
        }
        self::ok(['categories' => $rows]);
    }

    private static function rSearch(): void
    {
        $brand = self::needBrand();
        $q = self::str('q', 100, true);
        if (mb_strlen($q) < 2) {
            self::fail(400, 'q needs at least 2 characters');
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $limit = self::int('limit', 1, 100) ?? 50;
        $rows = self::all("SELECT DISTINCT p.* FROM product p LEFT JOIN product_description d ON d.product_id = p.id
            WHERE p.brand = ? AND p.active = 1 AND (p.name LIKE ? OR d.description LIKE ?) ORDER BY p.name LIMIT $limit", [$brand, $like, $like]);
        self::ok(['q' => $q, 'products' => array_map(fn($r) => self::productCard($r), $rows)]);
    }

    // ------------------------------------------------------------------ product management
    private static function rProduct(): void
    {
        self::needStaff();
        $brand = self::$brand;
        $a = self::$action;
        $by = (string)self::$staff['person_id'];
        if ($a === 'create') {
            $company = self::id('company_id');
            self::needCompany('CREATE', $company);
            $name = self::str('name', 200, true);
            $price = self::cents('price', true);
            $slug = self::str('slug', 200);
            $active = self::bool('active') ?? 1;
            $vname = self::str('variant_name', 200) ?? 'Default';
            $vprice = self::cents('variant_price', false);
            $stock = self::int('stock', 0, 1000000000);
            $desc = self::str('description', 20000);
            $ent = self::entitlementsIn();
            $bill = self::billingIn('variant_billing_interval', 'variant_billing_interval_count');
            if ($ent !== null && $ent !== '' && ($bill['billing_interval'] ?? 'NONE') === 'NONE') {
                self::fail(400, 'an upgrade product is sold monthly or yearly: send variant_billing_interval MONTH or YEAR');
            }
            self::begin();
            if ($slug !== null) {
                $slug = self::slug($slug);
                if (self::one('SELECT 1 FROM product WHERE brand = ? AND slug = ?', [$brand, $slug])) {
                    self::fail(409, 'slug is already used on this brand');
                }
            } else {
                $base = self::slug($name);
                $slug = $base;
                for ($i = 2; self::one('SELECT 1 FROM product WHERE brand = ? AND slug = ?', [$brand, $slug]); $i++) {
                    $slug = $base . '-' . $i;
                }
            }
            $pid = self::insert('product', ['name' => $name, 'slug' => $slug, 'price' => self::money($price), 'active' => $active, 'last_updated_by' => $by, 'brand' => $brand]
                + ($ent !== null && $ent !== '' ? ['entitlements' => $ent] : []));
            self::insert('product_company', ['product_id' => $pid, 'company_id' => $company, 'brand' => $brand, 'created_by' => $by]);
            $vid = self::insert('product_variant', ['product_id' => $pid, 'name' => $vname, 'price' => self::money($vprice), 'stock' => $stock, 'position' => 0, 'last_updated_by' => $by, 'brand' => $brand] + $bill);
            if ($stock !== null && $stock > 0) {
                self::invMove($vid, $company, null, $stock, 'adjustment', 'opening', null, null, 'Opening stock (product create)', $by);
            } elseif ($stock !== null) {
                self::invEnsureTracked($vid, $company, $by);
            }
            $did = $desc !== null ? self::insert('product_description', ['product_id' => $pid, 'description' => $desc, 'used_location' => 'main', 'last_updated_by' => $by, 'brand' => $brand]) : null;
            self::commit();
            self::ok(['product_id' => $pid, 'slug' => $slug, 'variant_id' => $vid, 'description_id' => $did]);
        }
        $pid = self::id('product_id');
        $right = ['update' => 'UPDATE', 'delete' => 'DELETE', 'claim' => 'CREATE', 'unclaim' => 'DELETE'][$a];
        self::needProduct($right, $pid);
        if ($a === 'update') {
            $set = [];
            if (($n = self::str('name', 200)) !== null) {
                $set['name'] = $n;
            }
            if (($c = self::cents('price', false)) !== null) {
                $set['price'] = self::money($c);
            }
            if (($b = self::bool('active')) !== null) {
                $set['active'] = $b;
            }
            if (($s = self::str('slug', 200)) !== null) {
                $set['slug'] = self::slug($s);
                if (self::one('SELECT 1 FROM product WHERE brand = ? AND slug = ? AND id <> ?', [$brand, $set['slug'], $pid])) {
                    self::fail(409, 'slug is already used on this brand');
                }
            }
            if (array_key_exists('entitlements', self::$in)) {
                $e = self::entitlementsIn();
                $set['entitlements'] = $e === '' ? null : $e;
            }
            if ($set === []) {
                self::fail(400, 'send name, slug, price, active or entitlements');
            }
            self::begin();
            self::update('product', $pid, $set + ['last_updated_by' => $by, 'last_updated' => self::now()]);
            self::commit();
            self::ok(['product_id' => $pid]);
        }
        if ($a === 'delete') {
            if (self::one('SELECT 1 FROM cart_item WHERE product_id = ? LIMIT 1', [$pid]) || self::one('SELECT 1 FROM invoice_product WHERE product_id = ? LIMIT 1', [$pid])) {
                self::fail(409, 'this product is in a cart or an order; set active false instead');
            }
            self::begin();
            $vids = self::col('SELECT id FROM product_variant WHERE product_id = ? AND brand = ? FOR UPDATE', [$pid, $brand]);
            foreach (self::col('SELECT id FROM product_category WHERE product_id = ? AND brand = ?', [$pid, $brand]) as $x) {
                self::delete('product_category', $x);
            }
            $imgSql = "SELECT id FROM product_images WHERE brand = ? AND ((id_type = 'PRODUCT' AND associated_id = ?)"
                . ($vids ? " OR (id_type = 'PRODUCT_VARIANT' AND associated_id IN (" . implode(',', array_fill(0, count($vids), '?')) . '))' : '') . ')';
            foreach (self::col($imgSql, [$brand, $pid, ...$vids]) as $x) {
                self::delete('product_images', $x);
            }
            foreach (self::col('SELECT id FROM product_description WHERE product_id = ? AND brand = ?', [$pid, $brand]) as $x) {
                self::delete('product_description', $x);
            }
            foreach ($vids as $x) {
                self::delete('product_variant', $x);
            }
            foreach (self::col('SELECT id FROM product_company WHERE product_id = ? AND brand = ?', [$pid, $brand]) as $x) {
                self::delete('product_company', $x);
            }
            self::delete('product', $pid);
            self::commit();
            self::ok(['product_id' => $pid, 'deleted' => true]);
        }
        $company = self::id('company_id');
        self::needCompany($right, $company);
        self::begin();
        if ($a === 'claim') {
            $id = self::insert('product_company', ['product_id' => $pid, 'company_id' => $company, 'brand' => $brand, 'created_by' => $by]);
            self::commit();
            self::ok(['product_company_id' => $id]);
        }
        $rows = self::col('SELECT id FROM product_company WHERE product_id = ? AND brand = ? FOR UPDATE', [$pid, $brand]);
        $mine = self::one('SELECT id FROM product_company WHERE product_id = ? AND company_id = ? AND brand = ?', [$pid, $company, $brand]);
        if (!$mine) {
            self::fail(404, 'that company does not claim this product');
        }
        if (count($rows) <= 1) {
            self::fail(409, 'the last company claim cannot be removed; delete the product instead');
        }
        if (self::one('SELECT 1 FROM product_category pc JOIN category c ON c.id = pc.category_id WHERE pc.product_id = ? AND c.company_id = ? LIMIT 1', [$pid, $company])) {
            self::fail(409, 'unlink the product from that company\'s categories first');
        }
        self::delete('product_company', $mine['id']);
        self::commit();
        self::ok(['product_id' => $pid, 'company_id' => $company, 'unclaimed' => true]);
    }

    private static function rProductVariants(): void
    {
        $brand = self::needBrand();
        $a = self::$action;
        if ($a === 'list') {
            $pid = self::id('product_id');
            $p = self::one('SELECT price, active FROM product WHERE id = ? AND brand = ?', [$pid, $brand]);
            if (!$p || (!(int)$p['active'] && self::$staff === null)) {
                self::fail(404, 'product not found');
            }
            $rows = self::all('SELECT * FROM product_variant WHERE product_id = ? AND brand = ? ORDER BY position, last_updated', [$pid, $brand]);
            self::ok(['variants' => array_map(fn($v) => self::variantOut($v, (string)$p['price']), $rows)]);
        }
        self::needStaff();
        $set = [];
        if (($n = self::str('name', 200, $a === 'create')) !== null) {
            $set['name'] = $n;
        }
        if (array_key_exists('price', self::$in)) {
            $set['price'] = self::money(self::cents('price', false));
        }
        // stock is not written directly any more (inventory, 2026-11-13): it becomes a movement below.
        $wantStock = array_key_exists('stock', self::$in) ? self::int('stock', 0, 1000000000) : false;
        if (($p = self::int('position', 0, 100000)) !== null) {
            $set['position'] = $p;
        }
        if (($d = self::json('description')) !== null) {
            $set['description'] = $d;
        }
        $set += self::billingIn('billing_interval', 'billing_interval_count');
        if (array_key_exists('paypal_plan_id', self::$in)) {
            $pp = self::$in['paypal_plan_id'];
            if ($pp === null || $pp === '') {
                $set['paypal_plan_id'] = null;
            } else {
                $pp = trim((string)$pp);
                if (!preg_match('/^P-[A-Z0-9]{10,}$/', $pp)) {
                    self::fail(400, 'paypal_plan_id must be a PayPal plan id (P-…)');
                }
                if (!self::isSeller()) {
                    self::fail(403, 'paypal_plan_id is only set on the DW Alliance LLC store');
                }
                $set['paypal_plan_id'] = $pp;
            }
        }
        $set['last_updated_by'] = self::$staff['person_id'];
        if ($a === 'create') {
            $pid = self::id('product_id');
            self::needProduct('CREATE', $pid);
            self::begin();
            $set['position'] = $set['position'] ?? (int)(self::one('SELECT COALESCE(MAX(position), -1) + 1 AS n FROM product_variant WHERE product_id = ?', [$pid])['n'] ?? 0);
            $id = self::insert('product_variant', ['product_id' => $pid, 'brand' => $brand] + $set + ($wantStock !== false && $wantStock !== null ? ['stock' => 0] : []));
            if ($wantStock !== false && $wantStock !== null) {
                $wantStock > 0 ? self::invMove($id, null, null, $wantStock, 'adjustment', 'opening', null, null, 'Opening stock (variant create)', (string)self::$staff['person_id'])
                    : self::invEnsureTracked($id, null, (string)self::$staff['person_id']);
            }
            self::commit();
            self::ok(['variant_id' => $id]);
        }
        $vid = self::id('variant_id');
        $v = self::one('SELECT id, product_id FROM product_variant WHERE id = ? AND brand = ?', [$vid, $brand]);
        if (!$v) {
            self::fail(404, 'variant not found on this brand');
        }
        self::needProduct($a === 'update' ? 'UPDATE' : 'DELETE', $v['product_id']);
        self::begin();
        if ($a === 'update') {
            self::update('product_variant', $vid, $set + ['last_updated' => self::now()]);
            $stockOut = null;
            if ($wantStock === null && DwInventory::tracked(self::$db, $brand, $vid)) {
                self::fail(409, 'this variant\'s stock is managed by inventory; it cannot be switched off here');
            } elseif ($wantStock === null) {
                self::update('product_variant', $vid, ['stock' => null]);
            } elseif ($wantStock !== false) {
                // Old-style "set stock to N": recorded as an adjustment for the difference (never an overwrite).
                $by = (string)self::$staff['person_id'];
                self::invEnsureTracked($vid, null, $by);
                $cur = DwInventory::getAvailable(self::$db, $brand, $vid);
                if ($wantStock !== $cur) {
                    $stockOut = self::invMove($vid, null, null, $wantStock - $cur, 'adjustment', 'stock_set', null, null, 'Stock set to ' . $wantStock . ' via product/variants update', $by);
                }
            }
            self::commit();
            self::ok(['variant_id' => $vid] + ($stockOut !== null ? ['movement_id' => $stockOut['movement_id'], 'stock' => $stockOut['stock']] : []));
        }
        if (count(self::col('SELECT id FROM product_variant WHERE product_id = ? FOR UPDATE', [$v['product_id']])) <= 1) {
            self::fail(409, 'a product keeps at least one variant');
        }
        if (self::one('SELECT 1 FROM cart_item WHERE variant_id = ? LIMIT 1', [$vid]) || self::one('SELECT 1 FROM invoice_product WHERE variant_id = ? LIMIT 1', [$vid])) {
            self::fail(409, 'this variant is in a cart or an order');
        }
        foreach (self::col('SELECT id FROM product_category WHERE variant_id = ?', [$vid]) as $x) {
            self::delete('product_category', $x);
        }
        foreach (self::col("SELECT id FROM product_images WHERE id_type = 'PRODUCT_VARIANT' AND associated_id = ? AND brand = ?", [$vid, $brand]) as $x) {
            self::delete('product_images', $x);
        }
        self::delete('product_variant', $vid);
        self::commit();
        self::ok(['variant_id' => $vid, 'deleted' => true]);
    }

    private static function rProductDescription(): void
    {
        self::needStaff();
        $brand = self::$brand;
        $a = self::$action;
        $by = self::$staff['person_id'];
        if ($a === 'create') {
            $pid = self::id('product_id');
            self::needProduct('CREATE', $pid);
            $d = self::str('description', 20000, true);
            self::begin();
            $id = self::insert('product_description', ['product_id' => $pid, 'description' => $d, 'used_location' => self::str('used_location', 100) ?? 'main', 'last_updated_by' => $by, 'brand' => $brand]);
            self::commit();
            self::ok(['description_id' => $id]);
        }
        $id = self::id('description_id');
        $row = self::one('SELECT product_id FROM product_description WHERE id = ? AND brand = ?', [$id, $brand]);
        if (!$row) {
            self::fail(404, 'description not found on this brand');
        }
        self::needProduct($a === 'update' ? 'UPDATE' : 'DELETE', $row['product_id']);
        self::begin();
        if ($a === 'update') {
            $set = array_filter(['description' => self::str('description', 20000), 'used_location' => self::str('used_location', 100)], fn($v) => $v !== null);
            if ($set === []) {
                self::fail(400, 'send description or used_location');
            }
            self::update('product_description', $id, $set + ['last_updated_by' => $by, 'last_updated' => self::now()]);
            self::commit();
            self::ok(['description_id' => $id]);
        }
        self::delete('product_description', $id);
        self::commit();
        self::ok(['description_id' => $id, 'deleted' => true]);
    }

    private static function rProductCategories(): void
    {
        self::needStaff();
        $brand = self::$brand;
        $a = self::$action;
        $by = self::$staff['person_id'];
        $parentCheck = function (?string $parent, string $company, ?string $self = null) use ($brand): void {
            if ($parent === null) {
                return;
            }
            if ($parent === $self || !self::one('SELECT 1 FROM category WHERE id = ? AND company_id = ? AND brand = ?', [$parent, $company, $brand])) {
                self::fail(400, 'parent_id must be another category of the same company');
            }
        };
        if ($a === 'create') {
            $company = self::id('company_id');
            self::needCompany('CREATE', $company);
            $parent = self::id('parent_id', false);
            $parentCheck($parent, $company);
            $name = self::str('name', 200, true);
            self::begin();
            $id = self::insert('category', ['company_id' => $company, 'parent_id' => $parent, 'name' => $name, 'description' => self::json('description'), 'position' => self::int('position', 0, 100000) ?? 0, 'last_updated_by' => $by, 'brand' => $brand]);
            self::commit();
            self::ok(['category_id' => $id]);
        }
        if ($a === 'unlink') {
            $id = self::id('product_category_id');
            $row = self::one('SELECT pc.id, c.company_id FROM product_category pc JOIN category c ON c.id = pc.category_id WHERE pc.id = ? AND pc.brand = ?', [$id, $brand]);
            if (!$row) {
                self::fail(404, 'product_category not found on this brand');
            }
            self::needCompany('DELETE', $row['company_id']);
            self::begin();
            self::delete('product_category', $id);
            self::commit();
            self::ok(['product_category_id' => $id, 'deleted' => true]);
        }
        $cid = self::id('category_id');
        $cat = self::one('SELECT * FROM category WHERE id = ? AND brand = ?', [$cid, $brand]);
        if (!$cat) {
            self::fail(404, 'category not found on this brand');
        }
        self::needCompany(['update' => 'UPDATE', 'delete' => 'DELETE', 'link' => 'CREATE'][$a], $cat['company_id']);
        if ($a === 'link') {
            $pid = self::id('product_id');
            if (!self::one('SELECT 1 FROM product_company WHERE product_id = ? AND company_id = ? AND brand = ?', [$pid, $cat['company_id'], $brand])) {
                self::fail(409, 'the product must be claimed by this category\'s company');
            }
            $vid = self::id('variant_id', false) ?? (self::one('SELECT id FROM product_variant WHERE product_id = ? ORDER BY position, last_updated LIMIT 1', [$pid])['id'] ?? null);
            if ($vid === null || !self::one('SELECT 1 FROM product_variant WHERE id = ? AND product_id = ? AND brand = ?', [$vid, $pid, $brand])) {
                self::fail(400, 'variant_id must be a variant of that product');
            }
            self::begin();
            $id = self::insert('product_category', ['category_id' => $cid, 'product_id' => $pid, 'variant_id' => $vid, 'position' => self::int('position', 0, 100000) ?? 0, 'brand' => $brand]);
            self::commit();
            self::ok(['product_category_id' => $id]);
        }
        if ($a === 'update') {
            $set = [];
            if (($n = self::str('name', 200)) !== null) {
                $set['name'] = $n;
            }
            if (array_key_exists('parent_id', self::$in)) {
                $parent = self::id('parent_id', false);
                $parentCheck($parent, $cat['company_id'], $cid);
                $set['parent_id'] = $parent;
            }
            if (($p = self::int('position', 0, 100000)) !== null) {
                $set['position'] = $p;
            }
            if (($d = self::json('description')) !== null) {
                $set['description'] = $d;
            }
            if ($set === []) {
                self::fail(400, 'send name, parent_id, position or description');
            }
            self::begin();
            self::update('category', $cid, $set + ['last_updated_by' => $by, 'last_updated' => self::now()]);
            self::commit();
            self::ok(['category_id' => $cid]);
        }
        self::begin();
        if (self::one('SELECT 1 FROM category WHERE parent_id = ? LIMIT 1', [$cid])) {
            self::fail(409, 'move or delete its child categories first');
        }
        foreach (self::col('SELECT id FROM product_category WHERE category_id = ?', [$cid]) as $x) {
            self::delete('product_category', $x);
        }
        foreach (self::col("SELECT id FROM product_images WHERE id_type = 'CATEGORY' AND associated_id = ? AND brand = ?", [$cid, $brand]) as $x) {
            self::delete('product_images', $x);
        }
        self::delete('category', $cid);
        self::commit();
        self::ok(['category_id' => $cid, 'deleted' => true]);
    }

    private static function rProductImages(): void
    {
        self::needStaff();
        $brand = self::$brand;
        $a = self::$action;
        $authFor = function (string $type, string $assoc, string $right) use ($brand): void {
            if ($type === 'PRODUCT') {
                self::needProduct($right, $assoc);
            } elseif ($type === 'PRODUCT_VARIANT') {
                $v = self::one('SELECT product_id FROM product_variant WHERE id = ? AND brand = ?', [$assoc, $brand]);
                if (!$v) {
                    self::fail(404, 'variant not found on this brand');
                }
                self::needProduct($right, $v['product_id']);
            } else {
                $c = self::one('SELECT company_id FROM category WHERE id = ? AND brand = ?', [$assoc, $brand]);
                if (!$c) {
                    self::fail(404, 'category not found on this brand');
                }
                self::needCompany($right, $c['company_id']);
            }
        };
        $fileCheck = function (string $fid) use ($brand): void {
            if (!self::one('SELECT 1 FROM files WHERE id = ? AND brand = ?', [$fid, $brand])) {
                self::fail(404, 'file_id not found on this brand (upload it with /files/)');
            }
        };
        if ($a === 'create') {
            $type = strtoupper(trim((string)(self::$in['id_type'] ?? '')));
            if (!in_array($type, ['PRODUCT', 'PRODUCT_VARIANT', 'CATEGORY'], true)) {
                self::fail(400, 'id_type must be PRODUCT, PRODUCT_VARIANT or CATEGORY');
            }
            $assoc = self::id('associated_id');
            $authFor($type, $assoc, 'CREATE');
            $fid = self::id('file_id');
            $fileCheck($fid);
            self::begin();
            $id = self::insert('product_images', ['associated_id' => $assoc, 'id_type' => $type, 'file_id' => $fid, 'description' => self::json('description'), 'position' => self::int('position', 0, 100000) ?? 0, 'last_updated_by' => self::$staff['person_id'], 'brand' => $brand]);
            self::commit();
            self::ok(['image_id' => $id]);
        }
        $id = self::id('image_id');
        $img = self::one('SELECT * FROM product_images WHERE id = ? AND brand = ?', [$id, $brand]);
        if (!$img) {
            self::fail(404, 'image not found on this brand');
        }
        $authFor($img['id_type'], $img['associated_id'], $a === 'update' ? 'UPDATE' : 'DELETE');
        if ($a === 'update') {
            $set = [];
            if (($f = self::id('file_id', false)) !== null) {
                $fileCheck($f);
                $set['file_id'] = $f;
            }
            if (($p = self::int('position', 0, 100000)) !== null) {
                $set['position'] = $p;
            }
            if (($d = self::json('description')) !== null) {
                $set['description'] = $d;
            }
            if ($set === []) {
                self::fail(400, 'send file_id, position or description');
            }
            self::begin();
            self::update('product_images', $id, $set + ['last_updated_by' => self::$staff['person_id'], 'last_updated' => self::now()]);
            self::commit();
            self::ok(['image_id' => $id]);
        }
        self::begin();
        self::delete('product_images', $id);
        self::commit();
        self::ok(['image_id' => $id, 'deleted' => true]);
    }

    // ------------------------------------------------------------------ carts
    // Anonymous carts are reached by their unguessable server id. A cart tied to a customer session is only
    // reachable by that same person (any of their sessions on this brand) or by staff of the cart's company.
    private static function cart(string $cartId, bool $lock = false): array
    {
        $brand = self::needBrand();
        $c = self::one('SELECT c.*, s.person_id AS owner_person FROM cart c LEFT JOIN session s ON s.id = c.session_id WHERE c.id = ? AND c.brand = ?' . ($lock ? ' FOR UPDATE' : ''), [$cartId, $brand]);
        if (!$c) {
            self::fail(404, 'cart not found');
        }
        if ($c['session_id'] !== null) {
            $mine = self::$cust !== null && hash_equals((string)$c['owner_person'], self::$cust['person_id']);
            $staff = self::$staff !== null && self::canCompany('READ', (string)$c['company_id']);
            if (!$mine && !$staff) {
                self::fail(404, 'cart not found');
            }
        }
        return $c;
    }

    // A signed-in customer adopts an anonymous cart the first time they touch it.
    private static function claimCart(array $c): void
    {
        if (self::$cust !== null && $c['session_id'] === null) {
            self::update('cart', $c['id'], ['session_id' => self::$cust['session_id'], 'updated' => self::now()]);
        }
    }

    private static function checkedOut(string $cartId): ?string
    {
        $r = self::one('SELECT id FROM invoice WHERE cart_id = ? LIMIT 1', [$cartId]);
        return $r['id'] ?? null;
    }

    private static function cartView(string $cartId): array
    {
        $c = self::one('SELECT * FROM cart WHERE id = ?', [$cartId]);
        $lines = self::all('SELECT ci.id, ci.product_id, ci.variant_id, ci.quantity, ci.notes, p.name AS product_name, v.name AS variant_name,
                COALESCE(v.price, p.price) AS unit_price, p.active, v.stock, td.domain_name AS target_domain
            FROM cart_item ci JOIN product p ON p.id = ci.product_id JOIN product_variant v ON v.id = ci.variant_id
            LEFT JOIN domain td ON td.id = ci.target_domain_id
            WHERE ci.cart_id = ? ORDER BY ci.created, ci.id', [$cartId]);
        $sub = 0;
        $out = [];
        foreach ($lines as $l) {
            $line = self::toCents($l['unit_price']) * (int)$l['quantity'];
            $sub += $line;
            $out[] = ['item_id' => $l['id'], 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'], 'product_name' => $l['product_name'], 'variant_name' => $l['variant_name'],
                'quantity' => (int)$l['quantity'], 'unit_price' => $l['unit_price'], 'line_total' => self::money($line), 'notes' => $l['notes'], 'target_domain' => $l['target_domain'],
                'available' => (bool)$l['active'] && ($l['stock'] === null || (int)$l['stock'] >= (int)$l['quantity'])];
        }
        return ['cart_id' => $cartId, 'company_id' => $c['company_id'], 'signed_in' => $c['session_id'] !== null, 'items' => $out, 'subtotal' => self::money($sub), 'currency' => 'USD',
            'invoice_id' => self::checkedOut($cartId)];
    }

    private static function rCart(): void
    {
        $brand = self::needBrand();
        $a = self::$action;
        if ($a === 'mine') {
            $cu = self::needCustomer();
            $ids = self::col('SELECT c.id FROM cart c JOIN session s ON s.id = c.session_id LEFT JOIN invoice i ON i.cart_id = c.id
                WHERE s.person_id = ? AND c.brand = ? AND i.id IS NULL ORDER BY c.updated DESC LIMIT 20', [$cu['person_id'], $brand]);
            self::ok(['carts' => array_map(fn($id) => self::cartView((string)$id), $ids)]);
        }
        if ($a === 'add') {
            $vid = self::id('variant_id');
            $qty = self::int('quantity', 1, 999) ?? 1;
            $notes = self::str('notes', 2000);
            $v = self::one('SELECT v.id, v.product_id, v.stock, v.billing_interval, p.active, p.entitlements FROM product_variant v JOIN product p ON p.id = v.product_id WHERE v.id = ? AND v.brand = ? AND p.brand = ?', [$vid, $brand, $brand]);
            if (!$v || !(int)$v['active']) {
                self::fail(404, 'variant not found or not for sale');
            }
            $target = null;
            if ((string)($v['entitlements'] ?? '') !== '') {
                if (!in_array($v['billing_interval'], ['MONTH', 'YEAR'], true) || !self::isSeller()) {
                    self::fail(409, 'this upgrade is not for sale yet');
                }
                if ($qty > 12) {
                    self::fail(400, 'quantity is the number of billing periods (1-12)');
                }
                $target = self::targetDomain(self::str('target_domain', 100, true));
                if (self::$cust !== null) {
                    self::needSiteOwner($target);
                }
            }
            self::begin();
            $cartId = self::id('cart_id', false);
            if ($cartId === null) {
                $claims = self::col('SELECT company_id FROM product_company WHERE product_id = ? AND brand = ?', [$v['product_id'], $brand]);
                $company = self::id('company_id', false);
                if ($company === null) {
                    if (count($claims) !== 1) {
                        self::fail(400, 'this product is sold by several companies; send company_id');
                    }
                    $company = (string)$claims[0];
                } elseif (!in_array($company, $claims, true)) {
                    self::fail(400, 'that company does not sell this product');
                }
                $cartId = self::insert('cart', ['session_id' => self::$cust['session_id'] ?? null, 'company_id' => $company, 'brand' => $brand]);
            } else {
                $c = self::cart($cartId, true);
                if (self::checkedOut($cartId)) {
                    self::fail(409, 'this cart was already checked out; start a new cart');
                }
                if (!self::one('SELECT 1 FROM product_company WHERE product_id = ? AND company_id = ?', [$v['product_id'], $c['company_id']])) {
                    self::fail(400, 'this cart belongs to another seller; start a new cart for this product');
                }
                self::claimCart($c);
            }
            $line = self::one('SELECT id, quantity, target_domain_id FROM cart_item WHERE cart_id = ? AND variant_id = ? FOR UPDATE', [$cartId, $vid]);
            if ($target !== null && $line && (string)$line['target_domain_id'] !== $target['id']) {
                self::fail(409, 'one site per cart line: check out this cart first, or start a new cart for the other site');
            }
            $newQty = min($target !== null ? 12 : 999, $qty + (int)($line['quantity'] ?? 0));
            if ($v['stock'] !== null && $newQty > (int)$v['stock']) {
                self::fail(409, 'only ' . (int)$v['stock'] . ' in stock');
            }
            if ($line) {
                $set = ['quantity' => $newQty, 'updated' => self::now()];
                if ($notes !== null) {
                    $set['notes'] = $notes;
                }
                self::update('cart_item', $line['id'], $set);
            } else {
                self::insert('cart_item', ['cart_id' => $cartId, 'product_id' => $v['product_id'], 'variant_id' => $vid, 'quantity' => $newQty, 'notes' => $notes]
                    + ($target !== null ? ['target_domain_id' => $target['id']] : []));
            }
            self::update('cart', $cartId, ['updated' => self::now()]);
            self::commit();
            self::ok(['cart' => self::cartView($cartId)]);
        }
        $cartId = self::id('cart_id');
        if ($a === 'read') {
            self::cart($cartId);
            self::ok(['cart' => self::cartView($cartId)]);
        }
        self::begin();
        $c = self::cart($cartId, true);
        if (self::checkedOut($cartId)) {
            self::fail(409, 'this cart was already checked out');
        }
        if ($a === 'delete') {
            foreach (self::col('SELECT id FROM cart_item WHERE cart_id = ?', [$cartId]) as $x) {
                self::delete('cart_item', $x);
            }
            self::delete('cart', $cartId);
            self::commit();
            self::ok(['cart_id' => $cartId, 'deleted' => true]);
        }
        $itemId = self::id('item_id', false);
        $line = $itemId !== null ? self::one('SELECT ci.*, v.stock FROM cart_item ci JOIN product_variant v ON v.id = ci.variant_id WHERE ci.id = ? AND ci.cart_id = ?', [$itemId, $cartId])
            : self::one('SELECT ci.*, v.stock FROM cart_item ci JOIN product_variant v ON v.id = ci.variant_id WHERE ci.variant_id = ? AND ci.cart_id = ?', [self::id('variant_id'), $cartId]);
        if (!$line) {
            self::fail(404, 'that line is not in this cart');
        }
        self::claimCart($c);
        $qty = $a === 'remove' ? 0 : self::int('quantity', 0, 999);
        if ($qty === null) {
            self::fail(400, 'quantity is required (0 removes the line)');
        }
        if ($qty === 0) {
            self::delete('cart_item', $line['id']);
        } else {
            if ($line['stock'] !== null && $qty > (int)$line['stock']) {
                self::fail(409, 'only ' . (int)$line['stock'] . ' in stock');
            }
            $set = ['quantity' => $qty, 'updated' => self::now()];
            if (($n = self::str('notes', 2000)) !== null) {
                $set['notes'] = $n;
            }
            self::update('cart_item', $line['id'], $set);
        }
        self::update('cart', $cartId, ['updated' => self::now()]);
        self::commit();
        self::ok(['cart' => self::cartView($cartId)]);
    }

    // ------------------------------------------------------------------ store settings, shipping and tax
    public const STORE_CONFIG = '/var/lib/app/data/store.json';
    // Built-in defaults if store.json is missing. PLACEHOLDERS until the owner sets them.
    private const STORE_DEFAULTS = ['shipping_flat' => '8.00', 'free_shipping_over' => null, 'hi_get_rate' => '4.7120', 'tax_shipping' => false,
        'hi_states' => ['HI', 'HAWAII'], 'payment_mode' => 'offline', 'paypal' => ['enabled' => false]];
    private static ?array $storeCfg = null;

    public static function storeCfg(): array
    {
        if (self::$storeCfg === null) {
            $raw = @file_get_contents(self::STORE_CONFIG);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            self::$storeCfg = array_replace(self::STORE_DEFAULTS, is_array($j) ? $j : []);
        }
        return self::$storeCfg;
    }

    // store_setting row for a company, or null. A missing table (before the DDL is applied) means "defaults".
    private static function settingRow(string $companyId): ?array
    {
        try {
            return self::one('SELECT * FROM store_setting WHERE brand = ? AND company_id = ?', [self::$brand, $companyId]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '42S02') {
                return null;
            }
            throw $e;
        }
    }

    // Effective settings: store.json defaults overlaid with the company's non-NULL overrides.
    private static function settings(string $companyId): array
    {
        $d = self::storeCfg();
        $r = self::settingRow($companyId) ?? [];
        $pick = fn(string $k) => array_key_exists($k, $r) && $r[$k] !== null ? $r[$k] : ($d[$k] ?? null);
        $rate = (string)$pick('hi_get_rate');
        return [
            'company_id' => $companyId,
            'shipping_flat' => self::money(self::toCents((string)$pick('shipping_flat'))),
            'free_shipping_over' => $pick('free_shipping_over') === null ? null : self::money(self::toCents((string)$pick('free_shipping_over'))),
            'hi_get_rate' => rtrim(rtrim(sprintf('%.4f', (float)$rate), '0'), '.'),
            'tax_shipping' => (bool)$pick('tax_shipping'),
            'store_email' => $r['store_email'] ?? null,
            'paypal_enabled' => (bool)($r['paypal_enabled'] ?? false) && !empty($d['paypal']['enabled']),
            'payment_mode' => 'offline',
            'overrides' => array_values(array_filter(['shipping_flat', 'free_shipping_over', 'hi_get_rate', 'tax_shipping', 'store_email'], fn($k) => ($r[$k] ?? null) !== null)),
        ];
    }

    private static function isHawaii(?string $state): bool
    {
        $s = strtoupper(trim((string)$state));
        $s = preg_replace('/[^A-Z]/', '', $s);
        return $s !== '' && in_array($s, array_map(fn($x) => preg_replace('/[^A-Z]/', '', strtoupper((string)$x)), (array)self::storeCfg()['hi_states']), true);
    }

    // Shipping + Hawaii GET for a subtotal, all in cents. Tax rounds half up to the cent.
    // $shipSub: the part of the subtotal that ships (upgrades such as No Banner do not). Default: all of it.
    private static function totals(int $sub, string $companyId, ?string $state, ?int $shipSub = null): array
    {
        $st = self::settings($companyId);
        $flat = self::toCents($st['shipping_flat']);
        $free = $st['free_shipping_over'] === null ? null : self::toCents($st['free_shipping_over']);
        $shipSub = $shipSub ?? $sub;
        $ship = $shipSub <= 0 ? 0 : (($free !== null && $shipSub >= $free) ? 0 : $flat);
        $hi = self::isHawaii($state);
        $rateMicro = (int)round((float)$st['hi_get_rate'] * 10000); // percent x 10000
        $base = $sub + ($st['tax_shipping'] ? $ship : 0);
        $tax = $hi ? intdiv($base * $rateMicro + 500000, 1000000) : 0;
        return ['subtotal' => self::money($sub), 'shipping' => self::money($ship), 'tax' => self::money($tax),
            'tax_label' => $hi ? 'Hawaii GET ' . $st['hi_get_rate'] . '%' : null, 'tax_rate' => $hi ? $st['hi_get_rate'] : '0',
            'total' => self::money($sub + $ship + $tax), 'currency' => 'USD', 'shipping_rule' => $shipSub <= 0 && $sub > 0 ? 'none' : ($ship === 0 && $sub > 0 ? 'free' : 'flat'),
            '_c' => [$sub, $ship, $tax]];
    }

    private static function rSettings(): void
    {
        self::needStaff();
        $company = self::id('company_id');
        if (self::$action === 'read') {
            self::needCompany('READ', $company);
            self::ok(['settings' => self::settings($company)]);
        }
        self::needCompany('UPDATE', $company);
        $set = [];
        foreach (['shipping_flat', 'free_shipping_over'] as $k) {
            if (array_key_exists($k, self::$in)) {
                $set[$k] = self::$in[$k] === null || self::$in[$k] === '' ? null : self::money(self::cents($k, false));
            }
        }
        if (array_key_exists('hi_get_rate', self::$in)) {
            $v = self::$in['hi_get_rate'];
            if ($v === null || $v === '') {
                $set['hi_get_rate'] = null;
            } elseif (!is_scalar($v) || !preg_match('/^\d{1,2}(\.\d{1,4})?$/', trim((string)$v)) || (float)$v > 20) {
                self::fail(400, 'hi_get_rate must be a percent like 4.712 (0 to 20, up to 4 decimals)');
            } else {
                $set['hi_get_rate'] = sprintf('%.4f', (float)$v);
            }
        }
        if (array_key_exists('tax_shipping', self::$in)) {
            $set['tax_shipping'] = self::$in['tax_shipping'] === null ? null : self::bool('tax_shipping');
        }
        if (array_key_exists('store_email', self::$in)) {
            $e = self::$in['store_email'];
            if ($e === null || $e === '') {
                $set['store_email'] = null;
            } elseif (($e = DwCustomer::email($e)) === null) {
                self::fail(400, 'store_email must be a valid email');
            } else {
                $set['store_email'] = $e;
            }
        }
        if (array_key_exists('paypal_enabled', self::$in)) {
            $set['paypal_enabled'] = self::$in['paypal_enabled'] === null ? null : (self::bool('paypal_enabled') ? 1 : 0);
        }
        if ($set === []) {
            self::fail(400, 'send shipping_flat, free_shipping_over, hi_get_rate, tax_shipping, store_email or paypal_enabled');
        }
        $by = self::$staff['person_id'];
        self::begin();
        $row = self::one('SELECT id FROM store_setting WHERE brand = ? AND company_id = ? FOR UPDATE', [self::$brand, $company]);
        if ($row) {
            self::update('store_setting', (string)$row['id'], $set + ['last_updated' => self::now(), 'last_updated_by' => $by]);
        } else {
            self::insert('store_setting', ['brand' => self::$brand, 'company_id' => $company, 'created' => self::now(), 'created_by' => $by] + $set);
        }
        self::commit();
        self::ok(['settings' => self::settings($company)]);
    }

    // ------------------------------------------------------------------ order emails
    // Plain text, no codes or secrets. Mail failures never undo an order; the answer reports what was sent.
    private static function orderEmails(string $invId, array $tot): array
    {
        $sent = ['customer' => false, 'store' => false];
        try {
            $i = self::one('SELECT * FROM invoice WHERE id = ?', [$invId]);
            $company = self::one('SELECT company_name FROM company WHERE id = ?', [$i['company_id']]);
            $store = trim((string)($company['company_name'] ?? '')) ?: 'The store';
            $host = self::$cust['host'] ?? '';
            $st = self::settings((string)$i['company_id']);
            $cust = self::one("SELECT p.first_name, p.last_name, (SELECT e.email FROM email e WHERE e.owner_id = p.id AND e.owner_type = 'PRSN' AND e.brand = p.brand ORDER BY e.created LIMIT 1) AS email,
                (SELECT t.phone_number FROM telephone t WHERE t.owner_id = p.id AND t.owner_type = 'PRSN' AND t.brand = p.brand ORDER BY t.created DESC LIMIT 1) AS phone
                FROM person p WHERE p.id = ?", [$i['person_id']]) ?? [];
            $ship = self::one("SELECT * FROM invoice_address WHERE invoice_id = ? AND type = 'SHIPPING'", [$invId]) ?? [];
            $lines = self::all('SELECT ip.product_name, ip.quantity, ip.unit_price, td.domain_name AS target_domain
                FROM invoice_product ip LEFT JOIN domain td ON td.id = ip.target_domain_id WHERE ip.invoice_id = ? ORDER BY ip.created, ip.id', [$invId]);
            $ref = strtoupper(substr($invId, 0, 8));
            $name = trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? ''));
            $sites = self::coveredSites($lines);
            $siteBit = $sites !== [] ? ' for ' . implode(', ', $sites) : '';
            $body = self::orderText($store, $ref, $lines, $tot, $ship);
            $custEmail = $cust['email'] ?? null;
            $storeEmail = $st['store_email'];
            if ($custEmail && self::mayEmail($custEmail)) {
                $txt = (trim((string)($cust['first_name'] ?? '')) !== '' ? 'Aloha ' . trim((string)$cust['first_name']) . ",\n\n" : "Aloha,\n\n") . 'Thank you for your order from ' . $store . ".\n\n" . $body
                    . "\nNo payment has been taken. " . $store . ' will contact you at this email address to arrange payment. Your items are reserved for you.'
                    . "\n\nSee your orders: https://" . $host . "/store/account.html\n" . ($storeEmail ? "Questions? Reply to this email or write to " . $storeEmail . ".\n" : '');
                $sent['customer'] = Dw::sendMail($custEmail, 'Your order ' . $ref . ' from ' . $store . $siteBit, $txt, $storeEmail);
            }
            if ($storeEmail && self::mayEmail($storeEmail)) {
                $txt = 'New order ' . $ref . ' on ' . $host . ".\n\nCustomer: " . ($name !== '' ? $name : '(no name given)') . "\nEmail: " . ($custEmail ?? '(none)')
                    . ($cust['phone'] ?? null ? "\nPhone: " . $cust['phone'] : '') . "\n\n" . $body
                    . "\nNo payment was taken online. Contact the customer to arrange payment, then record it with /store/invoice/payment/ (the order turns PAID)."
                    . "\nInvoice id: " . $invId . "\n";
                $sent['store'] = Dw::sendMail($storeEmail, 'New order ' . $ref . ' on ' . $host . $siteBit . ' (' . $tot['total'] . ' USD, payment pending)', $txt, $custEmail);
            }
        } catch (Throwable $e) {
            error_log('store order email: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
        }
        return $sent;
    }

    private static function orderText(string $store, string $ref, array $lines, array $tot, array $ship): string
    {
        $t = 'Order ' . $ref . "\n" . str_repeat('-', 40) . "\n";
        foreach ($lines as $l) {
            $site = trim((string)($l['target_domain'] ?? ''));
            $t .= (int)$l['quantity'] . ' x ' . $l['product_name']
                . ($site !== '' ? ' for ' . $site : '')
                . '  @ $' . $l['unit_price'] . '  = $' . self::money(self::toCents($l['unit_price']) * (int)$l['quantity']) . "\n";
        }
        $sites = self::coveredSites($lines);
        if ($sites !== []) {
            $t .= (count($sites) === 1 ? 'Covered site: ' : 'Covered sites: ') . implode(', ', $sites) . "\n";
        }
        $t .= str_repeat('-', 40) . "\nSubtotal: $" . $tot['subtotal'] . "\nShipping: $" . $tot['shipping'] . ($tot['shipping_rule'] === 'free' ? ' (free)' : ' (flat rate)') . "\n";
        if ($tot['tax_label'] !== null) {
            $t .= $tot['tax_label'] . ': $' . $tot['tax'] . "\n";
        }
        $t .= 'Total: $' . $tot['total'] . " USD\n";
        if ($ship) {
            $t .= "\nShip to:\n" . implode("\n", array_filter([$ship['line_1'] ?? null, $ship['line_2'] ?? null, $ship['line_3'] ?? null,
                trim(($ship['city'] ?? '') . ', ' . ($ship['state'] ?? '') . ' ' . ($ship['zipcode'] ?? '')), $ship['country'] ?? null])) . "\n";
        }
        return $t;
    }

    // Unique site names a No Banner or Basic line covers, in the order they appear on the invoice.
    private static function coveredSites(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $s = strtolower(trim((string)($l['target_domain'] ?? '')));
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ checkout
    private static function ownAddress(string $id): array
    {
        $a = self::one("SELECT * FROM address WHERE id = ? AND owner_type = 'PRSN' AND owner_id = ? AND brand = ?", [$id, self::$cust['person_id'], self::$brand]);
        if (!$a) {
            self::fail(404, 'address not found among your addresses (add one with /address/)');
        }
        return $a;
    }

    // Cart lines with prices; $lock inside a transaction.
    private static function cartLines(string $cartId, bool $lock): array
    {
        return self::all('SELECT ci.id, ci.product_id, ci.variant_id, ci.quantity, ci.target_domain_id, p.name, p.active, p.entitlements, v.stock, COALESCE(v.price, p.price) AS unit_price
            FROM cart_item ci JOIN product p ON p.id = ci.product_id JOIN product_variant v ON v.id = ci.variant_id
            WHERE ci.cart_id = ? ORDER BY ci.created, ci.id' . ($lock ? ' FOR UPDATE' : ''), [$cartId]);
    }

    private static function rCheckout(): void
    {
        if (self::$action === 'quote') {
            self::needBrand();
            $cartId = self::id('cart_id');
            $c = self::cart($cartId);
            if (($addrId = self::id('shipping_address_id', false)) !== null) {
                self::needCustomer();
                $state = self::ownAddress($addrId)['state'];
            } else {
                $state = self::str('state', 30);
            }
            $sub = 0;
            $shipSub = 0;
            $problems = [];
            foreach (self::cartLines($cartId, false) as $l) {
                if (!(int)$l['active']) {
                    $problems[] = $l['name'] . ' is no longer for sale';
                } elseif ($l['stock'] !== null && (int)$l['stock'] < (int)$l['quantity']) {
                    $problems[] = 'only ' . (int)$l['stock'] . ' of ' . $l['name'] . ' in stock';
                }
                $lc = self::toCents($l['unit_price']) * (int)$l['quantity'];
                $sub += $lc;
                $shipSub += (string)($l['entitlements'] ?? '') === '' ? $lc : 0;
            }
            $t = self::totals($sub, (string)$c['company_id'], $state, $shipSub);
            unset($t['_c']);
            self::ok(['quote' => $t + ['state' => $state, 'payment_mode' => 'offline', 'problems' => $problems,
                'payment' => 'No payment is taken online. The store contacts you after you order to arrange payment.']]);
        }
        $cu = self::needCustomer();
        $brand = self::$brand;
        $cartId = self::id('cart_id');
        $ship = self::ownAddress(self::id('shipping_address_id'));
        $bill = self::ownAddress(self::id('billing_address_id', false) ?? $ship['id']);
        self::begin();
        $c = self::cart($cartId, true);
        if (($inv = self::checkedOut($cartId)) !== null) {
            self::fail(409, 'this cart was already checked out (invoice_id ' . $inv . ')');
        }
        self::claimCart($c);
        $lines = self::cartLines($cartId, true);
        if ($lines === []) {
            self::fail(400, 'the cart is empty');
        }
        $sub = 0;
        $shipSub = 0;
        foreach ($lines as $l) {
            if (!(int)$l['active']) {
                self::fail(409, $l['name'] . ' is no longer for sale; remove it from the cart');
            }
            if ($l['stock'] !== null && (int)$l['stock'] < (int)$l['quantity']) {
                self::fail(409, 'only ' . (int)$l['stock'] . ' of ' . $l['name'] . ' in stock');
            }
            $lc = self::toCents($l['unit_price']) * (int)$l['quantity'];
            $sub += $lc;
            if ((string)($l['entitlements'] ?? '') !== '') {
                // Upgrade lines: the buyer must own the target site (checked again here, the cart may be anonymous).
                if ($l['target_domain_id'] === null) {
                    self::fail(409, $l['name'] . ' needs a target_domain; remove it and add it again with the site');
                }
                $t = self::one('SELECT id, domain_name, code FROM domain WHERE id = ?', [$l['target_domain_id']]);
                if (!$t) {
                    self::fail(409, 'the site for ' . $l['name'] . ' is no longer on DW Alliance');
                }
                self::needSiteOwner($t);
            } else {
                $shipSub += $lc;
            }
        }
        $tot = self::totals($sub, (string)$c['company_id'], (string)$ship['state'], $shipSub);
        [, $shipC, $taxC] = $tot['_c'];
        $by = $cu['person_id'];
        $invId = self::insert('invoice', ['person_id' => $by, 'cart_id' => $cartId, 'company_id' => $c['company_id'], 'brand' => $brand, 'status' => 'PENDING',
            'subtotal' => self::money($sub), 'tax' => self::money($taxC), 'shipping' => self::money($shipC), 'total' => self::money($sub + $shipC + $taxC), 'currency' => 'USD', 'created_by' => $by]);
        foreach ($lines as $l) {
            self::insert('invoice_product', ['invoice_id' => $invId, 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'], 'quantity' => (int)$l['quantity'],
                'unit_price' => $l['unit_price'], 'product_name' => mb_substr($l['name'], 0, 200), 'created_by' => $by]
                + ($l['target_domain_id'] !== null ? ['target_domain_id' => $l['target_domain_id']] : []));
            if ($l['stock'] !== null) {
                // Stock comes off when the order is placed (owner's decision, 2026-11-13), not when it is paid.
                // Inventory (2026-11-13): a 'sale' movement at the store's default location; the mirror follows.
                self::invSale((string)$c['company_id'], $l, $invId, (string)$by);
            }
        }
        foreach (['SHIPPING' => $ship, 'BILLING' => $bill] as $type => $ad) {
            self::insert('invoice_address', ['invoice_id' => $invId, 'address_id' => $ad['id'], 'type' => $type, 'line_1' => $ad['line_1'], 'line_2' => $ad['line_2'], 'line_3' => $ad['line_3'],
                'city' => $ad['city'], 'state' => $ad['state'], 'zipcode' => $ad['zipcode'], 'country' => $ad['country'], 'created_by' => $by, 'brand' => $brand]);
        }
        self::commit();
        $emails = self::orderEmails($invId, $tot);
        $store = (string)(self::one('SELECT company_name FROM company WHERE id = ?', [$c['company_id']])['company_name'] ?? 'The store');
        self::ok(['invoice' => self::invoiceView($invId), 'tax_label' => $tot['tax_label'], 'payment_mode' => 'offline',
            'payment' => 'Order received. No payment was taken online. ' . $store . ' will contact you by email to arrange payment.',
            'emails' => $emails]);
    }

    // ------------------------------------------------------------------ invoices
    private static function invoice(string $id, string $right = 'READ', bool $lock = false): array
    {
        $brand = self::needBrand();
        $i = self::one('SELECT * FROM invoice WHERE id = ? AND brand = ?' . ($lock ? ' FOR UPDATE' : ''), [$id, $brand]);
        if (!$i) {
            self::fail(404, 'invoice not found');
        }
        if (self::$staff !== null) {
            if (!self::canCompany($right, (string)$i['company_id'])) {
                self::fail(403, 'needs SUPER_ADMIN, STORE ' . $right . ', or EDITOR of a domain owned by that company');
            }
            return $i;
        }
        if (self::$cust === null || !hash_equals((string)$i['person_id'], self::$cust['person_id'])) {
            self::fail(self::$cust === null ? 401 : 404, self::$cust === null ? 'sign in on the store domain first' : 'invoice not found');
        }
        return $i;
    }

    private static function paidCents(string $invId): int
    {
        $r = self::one("SELECT COALESCE(SUM(amount), 0) AS s FROM payments WHERE invoice_id = ? AND status = 'COMPLETED'", [$invId]);
        return self::toCents($r['s'] ?? '0');
    }

    private static function invoiceView(string $id): array
    {
        $i = self::one('SELECT * FROM invoice WHERE id = ?', [$id]);
        $paid = self::paidCents($id);
        return ['invoice_id' => $i['id'], 'cart_id' => $i['cart_id'], 'company_id' => $i['company_id'], 'status' => $i['status'], 'subtotal' => $i['subtotal'], 'tax' => $i['tax'],
            'shipping' => $i['shipping'], 'total' => $i['total'], 'paid' => self::money($paid), 'balance' => self::money(max(0, self::toCents($i['total']) - $paid)),
            'currency' => $i['currency'], 'created' => $i['date_created'],
            'lines' => self::all('SELECT ip.id AS invoice_product_id, ip.product_id, ip.variant_id, ip.product_name, ip.quantity, ip.unit_price, td.domain_name AS target_domain
                FROM invoice_product ip LEFT JOIN domain td ON td.id = ip.target_domain_id WHERE ip.invoice_id = ? ORDER BY ip.created, ip.id', [$id]),
            'addresses' => self::all('SELECT id AS invoice_address_id, type, address_id, line_1, line_2, line_3, city, state, zipcode, country FROM invoice_address WHERE invoice_id = ? ORDER BY type DESC', [$id]),
            'paypal_subscription_id' => $i['paypal_subscription_id'] ?? null,
            'payments' => self::all('SELECT id AS payment_id, amount, currency, status, method, created FROM payments WHERE invoice_id = ? ORDER BY created, id', [$id]),
            'shipments' => self::shipments($id)];
    }

    private static function rInvoice(): void
    {
        $brand = self::needBrand();
        $a = self::$action;
        if ($a === 'list') {
            if (self::$staff !== null) {
                $company = self::id('company_id', false);
                $where = ['brand = ?'];
                $args = [$brand];
                if ($company !== null) {
                    self::needCompany('READ', $company);
                    $where[] = 'company_id = ?';
                    $args[] = $company;
                } elseif (!self::brandWide('READ')) {
                    self::fail(403, 'send company_id (or have STORE READ on the brand)');
                }
                if (($st = strtoupper(trim((string)(self::$in['status'] ?? '')))) !== '') {
                    if (!in_array($st, ['PENDING', 'PAID', 'VOIDED', 'REFUNDED'], true)) {
                        self::fail(400, 'status must be PENDING, PAID, VOIDED or REFUNDED');
                    }
                    $where[] = 'status = ?';
                    $args[] = $st;
                }
                $rows = self::all('SELECT id AS invoice_id, person_id, company_id, status, total, currency, date_created FROM invoice WHERE ' . implode(' AND ', $where) . ' ORDER BY date_created DESC LIMIT 200', $args);
            } else {
                $cu = self::needCustomer();
                $rows = self::all('SELECT id AS invoice_id, company_id, status, total, currency, date_created FROM invoice WHERE person_id = ? AND brand = ? ORDER BY date_created DESC LIMIT 200', [$cu['person_id'], $brand]);
            }
            self::ok(['invoices' => $rows]);
        }
        $id = self::id('invoice_id');
        if ($a === 'read') {
            self::invoice($id);
            self::ok(['invoice' => self::invoiceView($id)]);
        }
        self::needStaff();
        self::begin();
        $i = self::invoice($id, 'UPDATE', true);
        $set = [];
        $tax = self::cents('tax', false);
        $shipping = self::cents('shipping', false);
        if ($tax !== null || $shipping !== null) {
            if ($i['status'] !== 'PENDING') {
                self::fail(409, 'tax and shipping can only change while the invoice is PENDING');
            }
            $tax = $tax ?? self::toCents($i['tax']);
            $shipping = $shipping ?? self::toCents($i['shipping']);
            $set += ['tax' => self::money($tax), 'shipping' => self::money($shipping), 'total' => self::money(self::toCents($i['subtotal']) + $tax + $shipping)];
        }
        if (($st = strtoupper(trim((string)(self::$in['status'] ?? '')))) !== '') {
            if ($i['status'] === 'REFUND_FAILED') {
                self::fail(409, 'this invoice is REFUND_FAILED: PayPal could not make the money-back refund, and refunds are only made through the platform the customer paid with, so it is final');
            }
            if (!in_array($st, ['PENDING', 'VOIDED', 'REFUNDED'], true)) {
                self::fail(400, 'status must be PENDING, VOIDED or REFUNDED (PAID is set by payments)');
            }
            $set['status'] = $st;
        }
        if ($set === []) {
            self::fail(400, 'send tax, shipping or status');
        }
        self::update('invoice', $id, $set + ['last_updated' => self::now()]);
        if (!isset($set['status'])) {
            self::settle($id);
        } elseif (in_array($set['status'], ['VOIDED', 'REFUNDED'], true)) {
            self::revokeInvoice($id, 'invoice ' . strtolower($set['status']));   // banner back right away (Terms: refund = Free plan)
        } elseif ($set['status'] === 'PENDING') {
            self::settle($id);
        }
        self::commit();
        self::ok(['invoice' => self::invoiceView($id)]);
    }

    // PENDING <-> PAID follows completed payments. VOIDED and REFUNDED are left alone.
    private static function settle(string $invId): void
    {
        $i = self::one('SELECT status, total FROM invoice WHERE id = ?', [$invId]);
        if (!in_array($i['status'], ['PENDING', 'PAID'], true)) {
            return;
        }
        $want = self::paidCents($invId) >= self::toCents($i['total']) ? 'PAID' : 'PENDING';
        $becamePaid = $want === 'PAID' && $i['status'] !== 'PAID';
        if ($want !== $i['status']) {
            self::update('invoice', $invId, ['status' => $want, 'last_updated' => self::now()]);
        }
        // Entitlements follow the invoice: PAID grants (or re-activates) them; back to PENDING (a refunded or failed
        // payment) revokes them. Same transaction as the payment.
        if ($want === 'PAID') {
            self::grantInvoice($invId);
        } elseif ($i['status'] === 'PAID') {
            self::revokeInvoice($invId, 'invoice no longer fully paid');
        }
        // Restock: only on the PENDING -> PAID transition, mark matching alerts purchased (refs #52).
        if ($becamePaid) {
            DwRestock::onInvoicePaid(self::$db, $invId);
            // Affiliate referral conversion (refs #65). Never blocks the payment.
            try {
                require_once '/var/www/html/lib/DwAffiliate.php';
                $inv = self::one('SELECT person_id, company_id FROM invoice WHERE id = ?', [$invId]);
                DwAffiliate::attributeConversionFromRequest(self::$db, [
                    'person_id' => $inv['person_id'] ?? null,
                    'company_id' => $inv['company_id'] ?? null,
                    'reference_type' => 'order',
                    'reference_id' => $invId,
                ]);
            } catch (Throwable $e) {
            }
        }
    }

    // ------------------------------------------------------------------ entitlements (No Banner)
    private static function entCfg(): array
    {
        $c = (array)(self::storeCfg()['entitlements'] ?? []);
        return [
            'seller_domain' => (string)($c['seller_domain'] ?? DwBrand::domain()),
            'grace_days' => max(0, min(60, (int)($c['grace_days'] ?? 7))),
            'seller_company_id' => (string)($c['seller_company_id'] ?? ''),
            'subscription_return_url' => (string)($c['subscription_return_url'] ?? ''),
        ];
    }

    private static ?string $sellerBrand = null;

    private static function sellerBrand(): ?string
    {
        if (self::$sellerBrand === null) {
            self::$sellerBrand = DwDomain::brandForHost(self::entCfg()['seller_domain'], [Dw::class, 'pdo']) ?? '';
        }
        return self::$sellerBrand === '' ? null : self::$sellerBrand;
    }

    private static function isSeller(): bool
    {
        $s = self::sellerBrand();
        return $s !== null && self::$brand !== null && hash_equals($s, self::$brand);
    }

    private static function entitlementsIn(): ?string
    {
        if (!array_key_exists('entitlements', self::$in)) {
            return null;
        }
        $v = self::$in['entitlements'];
        $list = is_array($v) ? $v : ($v === null ? [] : explode(',', (string)$v));
        $list = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), $list), fn($x) => $x !== '')));
        foreach ($list as $x) {
            if (!in_array($x, DwGate::ENTITLEMENTS, true)) {
                self::fail(400, 'entitlements must be one of ' . implode(', ', DwGate::ENTITLEMENTS));
            }
        }
        if (count($list) > 1) {
            self::fail(400, 'a product grants one entitlement (sell add-ons as separate products)');
        }
        if ($list !== [] && !self::isSeller()) {
            self::fail(403, 'only the DW Alliance LLC store sells upgrades');
        }
        return implode(',', $list);
    }

    // billing_interval / billing_interval_count from the request (empty array when not sent).
    private static function billingIn(string $k, string $kc): array
    {
        $out = [];
        if (self::has($k)) {
            $bi = strtoupper(trim((string)self::$in[$k]));
            if (!in_array($bi, ['NONE', 'MONTH', 'YEAR'], true)) {
                self::fail(400, $k . ' must be NONE, MONTH or YEAR');
            }
            if ($bi !== 'NONE' && !self::isSeller()) {
                self::fail(403, 'monthly and yearly billing is only for the DW Alliance LLC store');
            }
            $out['billing_interval'] = $bi;
        }
        if (($n = self::int($kc, 1, 12)) !== null) {
            $out['billing_interval_count'] = $n;
        }
        return $out;
    }

    // A claimed customer site (bare domain row preferred). Not DW's own sites.
    private static function targetDomain(?string $name): array
    {
        $h = strtolower(rtrim(trim((string)$name), '.'));
        $h = preg_replace('~^https?://~', '', $h);
        $h = explode('/', $h, 2)[0];
        if ($h === '' || !DwDomain::validHost($h)) {
            self::fail(400, 'target_domain must be a site name like example.com');
        }
        $bare = strncmp($h, 'www.', 4) === 0 ? substr($h, 4) : $h;
        $d = self::one("SELECT id, domain_name, code FROM domain WHERE domain_name IN (?, ?) AND code IS NOT NULL ORDER BY domain_name = ? DESC LIMIT 1", [$bare, 'www.' . $bare, $bare]);
        if (!$d) {
            self::fail(404, 'that site is not on DW Alliance');
        }
        $seller = self::sellerBrand();
        if ($seller !== null && hash_equals($seller, (string)$d['code'])) {
            self::fail(409, 'DW Alliance LLC sites never show the banner');
        }
        return $d;
    }

    // The signed-in customer's email must be a SUPER_ADMIN email on the site's brand.
    private static function needSiteOwner(array $domain): void
    {
        $cu = self::needCustomer();
        require_once '/var/www/html/lib/DwPermissions.php';
        $mine = array_map('strtolower', self::col("SELECT email FROM email WHERE owner_id = ? AND owner_type = 'PRSN' AND brand = ?", [$cu['person_id'], self::$brand]));
        $owners = DwPermissions::superAdminEmails((string)$domain['code']);
        if (array_intersect($mine, $owners) === []) {
            self::fail(403, 'you can only buy this for a site you own: sign in with the email of a SUPER_ADMIN of ' . $domain['domain_name']);
        }
    }

    // Start of a new paid period for a site: the end of the latest paid period still in force (paid or in its grace
    // days), else now. A renewal paid during the grace days continues from the old end date, so the billing date stays.
    private static function periodStart(string $brand, string $domainId, string $ent = 'no_banner'): string
    {
        $r = self::one("SELECT COALESCE(MAX(paid_through), NOW()) AS s FROM brand_entitlement
            WHERE brand = ? AND domain_id = ? AND entitlement = ? AND status = 'ACTIVE' AND paid_through IS NOT NULL
              AND grace_until > NOW()", [$brand, $domainId, $ent]);
        return (string)$r['s'];
    }

    private static function periodRow(string $start, string $unit, int $n): array
    {
        $unit = $unit === 'YEAR' ? 'YEAR' : 'MONTH';
        $g = self::entCfg()['grace_days'];
        return self::one("SELECT ? AS starts_at, DATE_ADD(?, INTERVAL $n $unit) AS paid_through, DATE_ADD(DATE_ADD(?, INTERVAL $n $unit), INTERVAL $g DAY) AS grace_until", [$start, $start, $start]);
    }

    private static function grantInvoice(string $invId): void
    {
        // Affiliate add-on (refs #64): recorded by DwAffiliate under its own 'affiliate' entitlement.
        // This loop grants other product entitlements (no_banner, store, watermark). Affiliate lines are skipped here
        // so they are not double-granted.
        try {
            require_once '/var/www/html/lib/DwAffiliate.php';
            DwAffiliate::grantEntitlementFromInvoice(self::$db, $invId);
        } catch (Throwable $e) {
            error_log('affiliate entitlement grant failed: ' . $e->getMessage());
        }
        $i = self::one('SELECT brand, person_id, paypal_subscription_id FROM invoice WHERE id = ?', [$invId]);
        $lines = self::all("SELECT ip.id, ip.variant_id, ip.quantity, ip.target_domain_id, p.entitlements, v.billing_interval, v.billing_interval_count, d.code AS target_brand
            FROM invoice_product ip JOIN product p ON p.id = ip.product_id JOIN product_variant v ON v.id = ip.variant_id
            LEFT JOIN domain d ON d.id = ip.target_domain_id
            WHERE ip.invoice_id = ? AND p.entitlements IS NOT NULL AND p.entitlements <> ''", [$invId]);
        require_once '/var/www/html/lib/DwGate.php';
        foreach ($lines as $l) {
            $ent = explode(',', (string)$l['entitlements'])[0];
            if ($ent === 'affiliate') {
                continue;
            }
            if ($l['target_brand'] === null || !in_array($l['billing_interval'], ['MONTH', 'YEAR'], true)) {
                error_log('store entitlement: invoice line without a site or billing interval, not granted');
                continue;
            }
            $have = self::one('SELECT id, status FROM brand_entitlement WHERE source_invoice_product_id = ? FOR UPDATE', [$l['id']]);
            if ($have) {
                if ($have['status'] === 'REVOKED') {
                    self::update('brand_entitlement', $have['id'], ['status' => 'ACTIVE', 'note' => 'payment completed again', 'updated_by_person_id' => self::$staff['person_id'] ?? $i['person_id']]);
                    self::bumpBannerCache((string)$l['target_brand']);
                }
                continue;
            }
            if (!in_array($ent, DwGate::ENTITLEMENTS, true)) {
                error_log('store entitlement: unknown entitlement on product, not granted');
                continue;
            }
            $n = (int)$l['quantity'] * max(1, (int)$l['billing_interval_count']);
            $per = self::periodRow(self::periodStart((string)$l['target_brand'], (string)$l['target_domain_id'], $ent), (string)$l['billing_interval'], $n);
            $extra = [];
            if (!empty($i['paypal_subscription_id'])) {
                $extra = ['provider_ref' => $i['paypal_subscription_id'], 'provider_status' => 'ACTIVE'];
            }
            self::insert('brand_entitlement', ['brand' => $l['target_brand'], 'domain_id' => $l['target_domain_id'], 'entitlement' => $ent, 'status' => 'ACTIVE']
                + $per + ['source_brand' => $i['brand'], 'source_invoice_id' => $invId, 'source_invoice_product_id' => $l['id'], 'source_variant_id' => $l['variant_id'],
                'created_by_person_id' => self::$staff['person_id'] ?? $i['person_id']] + $extra);
            self::bumpBannerCache((string)$l['target_brand']);
        }
    }

    private static function revokeInvoice(string $invId, string $why): void
    {
        // Refund, void or reversal: the affiliate's commission on this invoice is reversed (refs #64). Never blocks the revoke.
        try {
            require_once '/var/www/html/lib/DwAffiliate.php';
            DwAffiliate::reverseCommission(self::$db, $invId);
        } catch (Throwable $e) {
            error_log('affiliate commission reverse failed: ' . get_class($e));
        }
        $brands = [];
        foreach (self::all("SELECT id, brand FROM brand_entitlement WHERE source_invoice_id = ? AND status <> 'REVOKED' FOR UPDATE", [$invId]) as $r) {
            self::update('brand_entitlement', (string)$r['id'], ['status' => 'REVOKED', 'note' => mb_substr($why, 0, 200), 'updated_by_person_id' => self::$staff['person_id'] ?? null]);
            $brands[(string)$r['brand']] = true;
        }
        foreach (array_keys($brands) as $b) {
            self::bumpBannerCache($b);
        }
    }

    private static function entView(array $r): array
    {
        return ['entitlement_id' => $r['id'], 'domain' => $r['domain_name'] ?? null, 'entitlement' => $r['entitlement'], 'status' => $r['status'],
            'starts_at' => $r['starts_at'], 'paid_through' => $r['paid_through'], 'grace_until' => $r['grace_until'],
            'provider_ref' => $r['provider_ref'] ?? null, 'provider_status' => $r['provider_status'] ?? null,
            'next_billing_time' => $r['next_billing_time'] ?? null,
            'source' => $r['source_invoice_id'] !== null ? 'invoice' : 'staff grant', 'invoice_id' => $r['source_invoice_id'], 'note' => $r['note'],
            'created' => $r['created'], 'updated' => $r['updated']];
    }

    // Staff session from JSON, the session cookie, or a magic-link site session on the seller domain.
    private static function authBannerStaff(): void
    {
        if (self::$staff !== null) {
            return;
        }
        $s = Dw::session(self::$in);
        if ($s === null) {
            $host = DwDomain::requestHost();
            if ($host !== '' && !DwDomain::isAppHost($host)) {
                require_once '/var/www/html/lib/DwEdit.php';
                $s = DwEdit::siteSession($host);
            }
        }
        if ($s === null) {
            return;
        }
        self::$staff = $s;
        self::$brand = (string)$s['brand'];
    }

    private static function needBannerStaff(string $right): array
    {
        $explicit = isset(self::$in['session']) && is_string(self::$in['session']) && trim(self::$in['session']) !== '';
        if (!$explicit) {
            $rawHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            if (str_contains($rawHost, ':')) {
                $rawHost = explode(':', $rawHost, 2)[0];
            }
            $sfs = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
            $src = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
            if ($src === '' || $src === 'null') {
                $src = (string)($_SERVER['HTTP_REFERER'] ?? '');
            }
            $oh = parse_url($src, PHP_URL_HOST);
            $sameOrigin = $sfs !== '' ? $sfs === 'same-origin' : (is_string($oh) && $rawHost !== '' && hash_equals($rawHost, strtolower($oh)));
            if (!$sameOrigin) {
                self::fail(403, 'cross-site request refused');
            }
        }
        self::authBannerStaff();
        self::needStaff();
        if (!self::isSeller()) {
            self::fail(403, 'banner settings are managed on the DW Alliance LLC store only');
        }
        if (!self::brandWide($right)) {
            self::fail(403, 'needs SUPER_ADMIN or STORE ' . $right . ' on the DW Alliance LLC brand');
        }
        return self::$staff;
    }

    private static function bannerBrand(?string $code, ?string $domain): array
    {
        $code = trim((string)$code);
        $domain = trim((string)$domain);
        if ($code === '' && $domain === '') {
            self::fail(400, 'send brand (the brand code) or domain');
        }
        if ($domain !== '') {
            $d = self::targetDomain($domain);
            $b = self::one('SELECT id, code, banner_mode, banner_style, dw_company_id FROM brand WHERE code = ?', [(string)$d['code']]);
            if (!$b) {
                self::fail(404, 'that brand was not found');
            }
            $b['domain'] = $d['domain_name'];
            return $b;
        }
        if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $code)) {
            self::fail(400, 'brand is not valid');
        }
        $b = self::one('SELECT id, code, banner_mode, banner_style, dw_company_id FROM brand WHERE code = ?', [$code]);
        if (!$b) {
            self::fail(404, 'that brand was not found');
        }
        $dom = self::one("SELECT domain_name FROM domain WHERE code = ? AND domain_name NOT LIKE 'www.%' ORDER BY domain_name LIMIT 1", [$code]);
        $b['domain'] = $dom ? ($dom['domain_name'] ?? null) : null;
        return $b;
    }

    private static function upsertAppSetting(string $name, string $value, string $by): void
    {
        $row = self::one('SELECT id FROM app_setting WHERE setting_name = ? FOR UPDATE', [$name]);
        if ($row) {
            self::update('app_setting', (string)$row['id'], ['setting_value' => $value, 'updated' => self::now(), 'updated_by_person_id' => $by]);
        } else {
            self::insert('app_setting', [
                'setting_name' => $name,
                'setting_value' => $value,
                'created' => self::now(),
                'created_by_person_id' => $by,
                'updated' => self::now(),
                'updated_by_person_id' => $by,
            ]);
        }
    }


    /** Shared DB epoch so every app node drops stale host-keyed HTML caches (refs #49). */
    private static function bumpBannerCache(?string $brand): void
    {
        require_once '/var/www/html/lib/DwHtmlCache.php';
        $by = (string)(self::$staff['person_id'] ?? self::$cust['person_id'] ?? 'system');
        DwHtmlCache::bump(self::$db, $brand, $by !== '' ? $by : 'system');
    }

    private static function bannerState(array $b): array
    {
        require_once '/var/www/html/lib/DwBanner.php';
        $mode = in_array((string)($b['banner_mode'] ?? ''), ['on', 'off'], true) ? (string)$b['banner_mode'] : 'default';
        $style = (string)($b['banner_style'] ?? 'default');
        if (!in_array($style, ['default', 'A', 'B', 'C'], true)) {
            $style = 'default';
        }
        $g = DwBanner::globalDefault();
        $seller = self::sellerBrand();
        $isSeller = $seller !== null && hash_equals($seller, (string)$b['code']);
        $host = (string)($b['domain'] ?? '');
        $entitled = false;
        $effectiveStyle = 'A';
        if ($host !== '') {
            $entitled = DwBanner::hasNoBanner(self::$db, (string)$b['code'], DwBanner::bare($host));
            $effectiveStyle = DwBanner::styleFor(self::$db, (string)$b['code'], DwBanner::bare($host));
        } else {
            $entitled = DwBanner::hasNoBanner(self::$db, (string)$b['code'], null);
            $effectiveStyle = DwBanner::styleFor(self::$db, (string)$b['code'], null);
        }
        $shown = !$isSeller && DwBanner::wanted($mode, $g, $entitled);
        $co = !empty($b['dw_company_id']) ? self::one('SELECT company_name FROM company WHERE id = ?', [$b['dw_company_id']]) : null;
        $company = $co['company_name'] ?? null;
        return [
            'brand' => $b['code'],
            'domain' => $host !== '' ? $host : null,
            'company' => $company,
            'banner_mode' => $mode,
            'banner_style' => $style,
            'seller' => $isSeller,
            'entitlement' => $entitled ? 'no_banner' : null,
            'effective' => $isSeller ? 'skipped' : ($shown ? 'shown' : 'hidden'),
            'effective_style' => $effectiveStyle,
        ];
    }

    private static function bannerRead(): array
    {
        require_once '/var/www/html/lib/DwBanner.php';
        $g = 'off';
        $gs = 'A';
        $rows = self::all("SELECT setting_name, setting_value FROM app_setting WHERE setting_name IN ('banner_default','banner_style','watermark_default')");
        $written = false;
        $wm = 'off';
        foreach ($rows as $r) {
            if ($r['setting_name'] === 'banner_default') {
                $g = $r['setting_value'] === 'on' ? 'on' : 'off';
                $written = true;
            }
            if ($r['setting_name'] === 'banner_style' && in_array($r['setting_value'], ['A', 'B', 'C'], true)) {
                $gs = $r['setting_value'];
            }
            if ($r['setting_name'] === 'watermark_default' && in_array($r['setting_value'], ['on', 'off'], true)) {
                $wm = $r['setting_value'];
            }
        }
        $brands = [];
        foreach (self::all('SELECT id, code, banner_mode, banner_style, dw_company_id FROM brand ORDER BY code') as $b) {
            $doms = self::all("SELECT domain_name FROM domain WHERE code = ? AND domain_name NOT LIKE 'www.%' ORDER BY domain_name", [$b['code']]);
            if ($doms === []) {
                $brands[] = self::bannerState($b + ['domain' => '']);
                continue;
            }
            foreach ($doms as $d) {
                $brands[] = self::bannerState($b + ['domain' => $d['domain_name']]);
            }
        }
        return [
            'db_authoritative' => $written,
            'global' => ['banner_default' => $g, 'banner_style' => $gs, 'watermark_default' => $wm],
            'brands' => $brands,
            'kill_switch' => DwBanner::killed(),
        ];
    }

    private static function rBanner(): void
    {
        $a = self::$action;
        $right = ['read' => 'READ', 'update_global' => 'UPDATE', 'update_brand' => 'UPDATE'][$a] ?? null;
        if ($right === null) {
            self::fail(400, 'unknown banner action');
        }
        $staff = self::needBannerStaff($right);
        $by = (string)$staff['person_id'];
        if ($a === 'read') {
            self::ok(self::bannerRead());
        }
        if ($a === 'update_global') {
            $setDefault = array_key_exists('banner_default', self::$in);
            $setStyle = array_key_exists('banner_style', self::$in);
            $setWm = array_key_exists('watermark_default', self::$in);
            if (!$setDefault && !$setStyle && !$setWm) {
                self::fail(400, 'send banner_default (on or off), banner_style (A, B or C), and/or watermark_default (on or off)');
            }
            $def = null;
            $style = null;
            $wm = null;
            if ($setDefault) {
                $def = strtolower(trim((string)self::$in['banner_default']));
                if (!in_array($def, ['on', 'off'], true)) {
                    self::fail(400, 'banner_default must be on or off');
                }
            }
            if ($setStyle) {
                $style = strtoupper(trim((string)self::$in['banner_style']));
                if (!in_array($style, ['A', 'B', 'C'], true)) {
                    self::fail(400, 'banner_style must be A, B or C');
                }
            }
            if ($setWm) {
                $wm = strtolower(trim((string)self::$in['watermark_default']));
                if (!in_array($wm, ['on', 'off'], true)) {
                    self::fail(400, 'watermark_default must be on or off');
                }
            }
            self::begin();
            if ($def !== null) {
                self::upsertAppSetting('banner_default', $def, $by);
            }
            if ($style !== null) {
                self::upsertAppSetting('banner_style', $style, $by);
            }
            if ($wm !== null) {
                self::upsertAppSetting('watermark_default', $wm, $by);
            }
            self::commit();
            require_once '/var/www/html/lib/DwBanner.php';
            DwBanner::resetCache();
            self::bumpBannerCache(null);
            self::ok(self::bannerRead());
        }
        if ($a === 'update_brand') {
            $b = self::bannerBrand(self::has('brand') ? (string)self::$in['brand'] : null, self::has('domain') ? self::str('domain', 100) : null);
            $seller = self::sellerBrand();
            $set = [];
            if (array_key_exists('banner_mode', self::$in)) {
                $m = strtolower(trim((string)self::$in['banner_mode']));
                if (!in_array($m, ['default', 'on', 'off'], true)) {
                    self::fail(400, 'banner_mode must be default, on or off');
                }
                if ($m === 'on' && $seller !== null && hash_equals($seller, (string)$b['code'])) {
                    self::fail(409, 'DW Alliance LLC sites never show the banner');
                }
                $set['banner_mode'] = $m;
            }
            if (array_key_exists('banner_style', self::$in)) {
                $s = trim((string)self::$in['banner_style']);
                $s = strtolower($s) === 'default' ? 'default' : strtoupper($s);
                if (!in_array($s, ['default', 'A', 'B', 'C'], true)) {
                    self::fail(400, 'banner_style must be default, A, B or C');
                }
                $set['banner_style'] = $s;
            }
            if ($set === []) {
                self::fail(400, 'send banner_mode and/or banner_style');
            }
            self::begin();
            self::update('brand', (string)$b['id'], $set);
            self::commit();
            $b = self::one('SELECT id, code, banner_mode, banner_style, dw_company_id FROM brand WHERE id = ?', [$b['id']]) + ['domain' => $b['domain'] ?? ''];
            self::bumpBannerCache((string)$b['code']);
            self::ok(['brand' => self::bannerState($b)] + self::bannerRead());
        }
    }

    private static function rEntitlement(): void
    {
        if (self::$action === 'cancel_mine' || self::$action === 'mine' || self::$action === 'cancel_preview') {
            self::cancelOrListMine();
        }
        if (self::$action === 'my_sites') {
            self::mySites();
        }
        if (self::$action === 'features') {
            self::needStaff();
            self::ok(['features' => array_map(fn($f) => DwGate::check(self::$db, (string)self::$brand, $f), array_combine(array_keys(DwGate::FEATURES), array_keys(DwGate::FEATURES)))]);
        }
        self::needStaff();
        if (!self::isSeller()) {
            self::fail(403, 'entitlements are managed on the DW Alliance LLC store only');
        }
        $a = self::$action;
        $right = ['list' => 'READ', 'status' => 'READ', 'grant' => 'CREATE', 'revoke' => 'UPDATE', 'cancel' => 'UPDATE'][$a] ?? null;
        if ($right === null) {
            self::fail(400, 'unknown entitlement action');
        }
        if (!self::brandWide($right)) {
            self::fail(403, 'needs SUPER_ADMIN or STORE ' . $right . ' on the DW Alliance LLC brand');
        }
        $by = (string)self::$staff['person_id'];
        $sel = 'SELECT be.*, d.domain_name FROM brand_entitlement be LEFT JOIN domain d ON d.id = be.domain_id';
        if ($a === 'list') {
            $where = ['be.entitlement = ?'];
            $args = [self::entName()];
            if (self::has('domain')) {
                $t = self::targetDomain(self::str('domain', 100));
                $where[] = 'be.domain_id = ?';
                $args[] = $t['id'];
            }
            if (($st = strtoupper(trim((string)(self::$in['status'] ?? '')))) !== '') {
                if (!in_array($st, ['ACTIVE', 'CANCELLED', 'REVOKED'], true)) {
                    self::fail(400, 'status must be ACTIVE, CANCELLED or REVOKED');
                }
                $where[] = 'be.status = ?';
                $args[] = $st;
            }
            self::ok(['entitlements' => array_map([self::class, 'entView'], self::all($sel . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY be.created DESC LIMIT 200', $args))]);
        }
        if ($a === 'status' && self::entName() !== 'no_banner') {
            $t = self::targetDomain(self::str('domain', 100, true));
            DwGate::reset();
            self::ok(['domain' => $t['domain_name'], 'entitlement' => self::entName(), 'gate' => DwGate::check(self::$db, (string)$t['code'], self::entName())]);
        }
        if ($a === 'status') {
            $t = self::targetDomain(self::str('domain', 100, true));
            require_once '/var/www/html/lib/DwBanner.php';
            $hidden = DwBanner::hasNoBanner(self::$db, (string)$t['code'], DwBanner::bare((string)$t['domain_name']));
            $until = self::one("SELECT MAX(COALESCE(grace_until, '9999-12-31 00:00:00')) AS u FROM brand_entitlement WHERE brand = ? AND entitlement = 'no_banner'
                AND status IN ('ACTIVE','CANCELLED') AND starts_at <= NOW() AND (grace_until IS NULL OR grace_until > NOW()) AND (domain_id IS NULL OR domain_id = ?)", [$t['code'], $t['id']]);
            self::ok(['domain' => $t['domain_name'], 'banner' => $hidden ? 'hidden' : 'shown', 'hidden_until' => $hidden ? (($until['u'] ?? null) === '9999-12-31 00:00:00' ? 'no end date' : $until['u']) : null,
                'grace_days' => self::entCfg()['grace_days']]);
        }
        if ($a === 'grant') {
            $t = self::targetDomain(self::str('domain', 100, true));
            $months = self::int('months', 1, 120);
            $years = self::int('years', 1, 10);
            if ($months !== null && $years !== null) {
                self::fail(400, 'send months or years, not both');
            }
            $ent = self::entName();
            self::begin();
            $row = ['brand' => $t['code'], 'domain_id' => $t['id'], 'entitlement' => $ent, 'status' => 'ACTIVE', 'source_brand' => self::$brand,
                'note' => self::str('note', 200) ?? 'staff grant', 'created_by_person_id' => $by, 'updated_by_person_id' => $by];
            if ($months !== null || $years !== null) {
                $row += self::periodRow(self::periodStart((string)$t['code'], (string)$t['id'], $ent), $years !== null ? 'YEAR' : 'MONTH', $years ?? $months);
            }
            $id = self::insert('brand_entitlement', $row);
            self::bumpBannerCache((string)$t['code']);
            self::commit();
            self::ok(['entitlement' => self::entView(self::one($sel . ' WHERE be.id = ?', [$id]))]);
        }
        if ($a === 'revoke') {
            $id = self::id('entitlement_id');
            self::begin();
            $r = self::one('SELECT id, status FROM brand_entitlement WHERE id = ? FOR UPDATE', [$id]);
            if (!$r) {
                self::fail(404, 'entitlement not found');
            }
            if ($r['status'] !== 'REVOKED') {
                self::update('brand_entitlement', $id, ['status' => 'REVOKED', 'note' => self::str('note', 200) ?? 'revoked by staff', 'updated_by_person_id' => $by]);
            }
            $br = self::one('SELECT brand FROM brand_entitlement WHERE id = ?', [$id]);
            if ($br) {
                self::bumpBannerCache((string)$br['brand']);
            }
            self::commit();
            self::ok(['entitlement' => self::entView(self::one($sel . ' WHERE be.id = ?', [$id]))]);
        }
        // cancel: no renewal. Paid periods keep running to paid_through (no grace); no-end grants end now.
        $t = self::targetDomain(self::str('domain', 100, true));
        self::begin();
        $only = self::entName(false, null);   // optional: one entitlement type; default every type (no_banner and add-ons)
        $rows = self::all("SELECT id, paid_through, provider_ref, provider_status FROM brand_entitlement WHERE domain_id = ? AND status = 'ACTIVE'" . ($only !== null ? ' AND entitlement = ?' : '') . " FOR UPDATE",
            $only !== null ? [$t['id'], $only] : [$t['id']]);
        $now = (string)self::one('SELECT NOW() AS n')['n'];
        foreach ($rows as $r) {
            self::update('brand_entitlement', (string)$r['id'], ['status' => 'CANCELLED', 'grace_until' => $r['paid_through'] ?? $now, 'paid_through' => $r['paid_through'] ?? $now,
                'note' => self::str('note', 200) ?? 'cancelled: no renewal', 'provider_status' => !empty($r['provider_ref']) ? 'CANCELLED' : ($r['provider_status'] ?? null),
                'updated_by_person_id' => $by]);
        }
        $subs = [];
        foreach ($rows as $r) {
            $ref = (string)($r['provider_ref'] ?? '');
            if (preg_match('/^I-[A-Z0-9]+$/', $ref)) {
                $subs[$ref] = true;
            }
        }
        self::bumpBannerCache((string)$t['code']);
        self::commit();
        foreach (array_keys($subs) as $sid) {
            try {
                DwPayPal::cancelSubscription(self::sellerCompanyId(), $sid, 'Cancelled by staff');
            } catch (DwPayPalError $e) {
                error_log('store staff cancel paypal http ' . $e->http);
            }
        }
        self::ok(['domain' => $t['domain_name'], 'cancelled' => count($rows),
            'entitlements' => array_map([self::class, 'entView'], self::all($sel . " WHERE be.domain_id = ? ORDER BY be.created DESC LIMIT 50", [$t['id']]))]);
    }

    // ------------------------------------------------------------------ payments (no provider is called)
    private static function rInvoicePayment(): void
    {
        self::needBrand();
        $a = self::$action;
        if ($a === 'paypal_subscription_client') {
            self::needBrand();
            if (!self::isSeller()) {
                self::fail(403, 'subscriptions are sold on the DW Alliance LLC store only');
            }
            $company = self::sellerCompanyId();
            $st = self::settings($company);
            if (!$st['paypal_enabled']) {
                self::fail(501, 'PayPal is not switched on for this store yet');
            }
            try {
                self::ok(['client_id' => DwPayPal::publicClientId($company), 'env' => (DwStore::storeCfg()['paypal']['env'] ?? 'sandbox')]);
            } catch (DwPayPalError $e) {
                self::fail($e->http, $e->getMessage());
            }
        }
        if ($a === 'paypal_subscribe' || $a === 'paypal_quote') {
            self::paypalSubscribe();
        }
        if ($a === 'paypal_start' || $a === 'paypal_capture') {
            self::needCustomer();
            $id = self::id('invoice_id');
            $i = self::invoice($id);
            $st = self::settings((string)$i['company_id']);
            if (!$st['paypal_enabled']) {
                self::fail(501, 'PayPal is not switched on for this store yet. Your order is saved and the store will contact you to arrange payment.');
            }
            if ($i['status'] !== 'PENDING') {
                self::fail(409, 'this order is not waiting for payment');
            }
            $balance = self::toCents($i['total']) - self::paidCents($id);
            try {
                if ($a === 'paypal_start') {
                    $order = DwPayPal::createOrder((string)$i['company_id'], $id, self::money($balance));
                    self::ok(['paypal_order_id' => $order['id'], 'approve_url' => $order['approve_url']]);
                }
                $po = self::str('paypal_order_id', 64, true);
                $cap = DwPayPal::captureOrder((string)$i['company_id'], $po);
                if ($cap['status'] !== 'COMPLETED' || $cap['amount'] !== self::money($balance) || $cap['invoice_id'] !== $id) {
                    self::fail(409, 'PayPal did not confirm the full amount for this order');
                }
                self::begin();
                self::invoice($id, 'READ', true);
                $pid = self::insert('payments', ['invoice_id' => $id, 'brand' => self::$brand, 'company_id' => $i['company_id'], 'amount' => $cap['amount'], 'currency' => 'USD',
                    'status' => 'COMPLETED', 'method' => 'PAYPAL', 'provider_ref' => substr($cap['capture_id'], 0, 100), 'created_by' => self::$cust['person_id']]);
                self::settle($id);
                self::commit();
                self::ok(['payment_id' => $pid, 'status' => 'COMPLETED', 'invoice' => self::invoiceView($id)]);
            } catch (DwPayPalError $e) {
                self::fail($e->http, $e->getMessage());
            }
        }
        if ($a === 'list') {
            $id = self::id('invoice_id');
            self::invoice($id);
            self::ok(['payments' => self::all('SELECT id AS payment_id, amount, currency, status, method, created FROM payments WHERE invoice_id = ? ORDER BY created, id', [$id])]);
        }
        if ($a === 'create') {
            $id = self::id('invoice_id');
            $amount = self::cents('amount', true, false);
            $method = strtoupper(trim((string)(self::$in['method'] ?? '')));
            $cur = strtoupper(trim((string)(self::$in['currency'] ?? 'USD')));
            if ($cur !== 'USD') {
                self::fail(400, 'only USD is supported');
            }
            self::begin();
            $i = self::invoice($id, 'CREATE', true);
            if ($i['status'] !== 'PENDING') {
                self::fail(409, 'payments can only be added while the invoice is PENDING');
            }
            $balance = self::toCents($i['total']) - self::paidCents($id);
            if ($amount > $balance) {
                self::fail(400, 'amount is more than the balance of ' . self::money(max(0, $balance)));
            }
            if (self::$staff !== null) {
                if (!in_array($method, ['PAYPAL', 'CARD', 'CASH', 'OTHER'], true)) {
                    self::fail(400, 'method must be PAYPAL, CARD, CASH or OTHER');
                }
                $status = strtoupper(trim((string)(self::$in['status'] ?? 'COMPLETED')));
                if (!in_array($status, ['PENDING', 'COMPLETED'], true)) {
                    self::fail(400, 'status must be PENDING or COMPLETED');
                }
                $ref = self::str('provider_ref', 100);
                $by = self::$staff['person_id'];
                $note = null;
            } else {
                if (!in_array($method, ['PAYPAL', 'CARD'], true)) {
                    self::fail(400, 'method must be CARD or PAYPAL');
                }
                $status = 'PENDING';
                $ref = null;
                $by = self::$cust['person_id'];
                $note = 'Payment provider not connected yet: no charge was made. The store will confirm your payment.';
            }
            $pid = self::insert('payments', ['invoice_id' => $id, 'brand' => self::$brand, 'company_id' => $i['company_id'], 'amount' => self::money($amount), 'currency' => 'USD',
                'status' => $status, 'method' => $method, 'provider_ref' => $ref, 'created_by' => $by]);
            self::settle($id);
            self::commit();
            self::ok(['payment_id' => $pid, 'status' => $status, 'note' => $note, 'invoice' => self::invoiceView($id)]);
        }
        self::needStaff();
        $pid = self::id('payment_id');
        self::begin();
        $p = self::one('SELECT * FROM payments WHERE id = ? AND brand = ? FOR UPDATE', [$pid, self::$brand]);
        if (!$p) {
            self::fail(404, 'payment not found');
        }
        self::invoice((string)$p['invoice_id'], 'UPDATE', true);
        $set = [];
        if (($st = strtoupper(trim((string)(self::$in['status'] ?? '')))) !== '') {
            if (!in_array($st, ['COMPLETED', 'FAILED', 'REFUNDED', 'PENDING'], true)) {
                self::fail(400, 'status must be PENDING, COMPLETED, FAILED or REFUNDED');
            }
            $set['status'] = $st;
        }
        if (($ref = self::str('provider_ref', 100)) !== null) {
            $set['provider_ref'] = $ref;
        }
        if ($set === []) {
            self::fail(400, 'send status or provider_ref');
        }
        self::update('payments', $pid, $set);
        self::settle((string)$p['invoice_id']);
        self::commit();
        self::ok(['payment_id' => $pid, 'invoice' => self::invoiceView((string)$p['invoice_id'])]);
    }

    // ------------------------------------------------------------------ PayPal webhook (skeleton, off)
    private static function rPaypal_webhook(): void
    {
        $type = null;
        $eid = null;
        if (empty(self::storeCfg()['paypal']['enabled'])) {
            self::webhookOut(501, 'PayPal is not switched on', $type, $eid);
        }
        $raw = self::$rawBody;
        $event = json_decode($raw, true);
        if (is_array($event)) {
            $type = DwPayPalBilling::eventType($event) ?: null;
            $eid = DwPayPalBilling::eventId($event);
        }
        $company = self::sellerCompanyId();
        $ok = false;
        try {
            DwPayPal::requireConfiguredApi();
            $ok = DwPayPal::verifyWebhook($company, self::$paypalHeaders, $raw);
        } catch (DwPayPalError $e) {
            $ok = false;
        }
        if (!$ok) {
            self::webhookOut(400, 'unverified', $type, $eid);
        }
        if (!is_array($event) || $eid === null) {
            self::webhookOut(400, 'invalid event', $type, $eid);
        }
        $sid = DwPayPalBilling::subscriptionIdFromEvent($event);
        $sale = DwPayPalBilling::saleIdFromEvent($event);
        $custom = DwPayPalBilling::customIdFromEvent($event);
        $parsed = DwPayPalBilling::parseCustomId($custom);
        $invoiceHint = $parsed['invoice_id'];
        try {
            self::begin();
            try {
                $rowId = self::insert('paypal_webhook_event', [
                    'event_id' => $eid,
                    'event_type' => (string)($type ?? ''),
                    'subscription_id' => $sid,
                    'sale_id' => $sale,
                    'invoice_id' => $invoiceHint,
                    'processed' => 0,
                    'created_by' => 'paypal_webhook',
                ]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    self::commit();
                    self::webhookOut(200, 'already processed', $type, $eid);
                }
                throw $e;
            }
            $result = self::handlePaypalEvent($event, $rowId);
            self::update('paypal_webhook_event', $rowId, ['processed' => 1, 'result' => mb_substr((string)$result, 0, 200),
                'subscription_id' => $sid, 'sale_id' => $sale, 'invoice_id' => $invoiceHint]);
            self::commit();
            self::runAfterCommit();
            self::webhookOut(200, $result, $type, $eid);
        } catch (Throwable $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            error_log('store paypal_webhook handle: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
            self::webhookOut(500, 'handler error', $type, $eid);
        }
    }

    // ------------------------------------------------------------------ inventory (2026-11-13, staging)
    // Variant from variant_id, or product_id -> its default (first) variant. Always on this brand.
    private static function invVariant(): array
    {
        $vid = self::id('variant_id', false);
        if ($vid !== null) {
            $v = self::one('SELECT id, product_id FROM product_variant WHERE id = ? AND brand = ?', [$vid, self::$brand]);
        } else {
            $pid = self::id('product_id', false);
            if ($pid === null) {
                self::fail(400, 'variant_id or product_id is required');
            }
            $v = self::one('SELECT id, product_id FROM product_variant WHERE product_id = ? AND brand = ? ORDER BY position, id LIMIT 1', [$pid, self::$brand]);
        }
        if (!$v) {
            self::fail(404, 'product or variant not found on this brand');
        }
        return $v;
    }

    // Location for a movement: location_id, else the default location of company_id, else of the product's first
    // claiming store. The product must be claimed by the location's store. Must run inside a transaction.
    private static function invLocation(string $productId, ?string $companyId, ?string $locationId, string $by): array
    {
        if ($locationId !== null) {
            $l = self::one('SELECT * FROM inventory_location WHERE id = ? AND brand = ?', [$locationId, self::$brand]);
            if (!$l) {
                self::fail(404, 'location not found on this brand');
            }
        } else {
            if ($companyId === null) {
                $companyId = self::one('SELECT company_id FROM product_company WHERE product_id = ? AND brand = ? ORDER BY created, id LIMIT 1', [$productId, self::$brand])['company_id'] ?? null;
                if ($companyId === null) {
                    self::fail(409, 'this product is not claimed by any store');
                }
            }
            $l = DwInventory::defaultLocation(self::$db, (string)self::$brand, (string)$companyId, $by);
        }
        if (!self::one('SELECT 1 FROM product_company WHERE product_id = ? AND company_id = ? AND brand = ?', [$productId, $l['company_id'], self::$brand])) {
            self::fail(409, 'that store does not carry this product (no product_company claim)');
        }
        return $l;
    }

    // One movement through DwInventory, with errors mapped to HTTP answers. Joins the open transaction.
    private static function invMove(string $variantId, ?string $companyId, ?string $locationId, int $qty, string $type, ?string $refType, ?string $refId, $unitCost, ?string $notes, string $by, array $extra = []): array
    {
        $v = self::one('SELECT id, product_id FROM product_variant WHERE id = ? AND brand = ?', [$variantId, self::$brand]);
        if (!$v) {
            self::fail(404, 'variant not found on this brand');
        }
        $l = self::invLocation((string)$v['product_id'], $companyId, $locationId, $by);
        if (!(int)$l['is_active']) {
            self::fail(409, 'that location is not active');
        }
        try {
            $r = DwInventory::recordMovement(self::$db, ['brand' => self::$brand, 'variant_id' => $variantId, 'location_id' => $l['id'], 'quantity' => $qty,
                'movement_type' => $type, 'reference_type' => $refType, 'reference_id' => $refId, 'unit_cost' => $unitCost, 'notes' => $notes, 'created_by' => $by] + $extra);
        } catch (LengthException $e) {
            self::fail(409, $e->getMessage());
        } catch (DomainException $e) {
            self::fail(404, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            self::fail(400, $e->getMessage());
        }
        return $r + ['location_id' => $l['id'], 'company_id' => $l['company_id']];
    }

    // A variant with an old-style stock number but no inventory rows yet: open it at its current stock first, so the
    // mirror and the movements agree before anything else is recorded.
    private static function invEnsureTracked(string $variantId, ?string $companyId, string $by): void
    {
        if (DwInventory::tracked(self::$db, (string)self::$brand, $variantId)) {
            return;
        }
        $v = self::one('SELECT id, product_id, stock FROM product_variant WHERE id = ? AND brand = ? FOR UPDATE', [$variantId, self::$brand]);
        $l = self::invLocation((string)$v['product_id'], $companyId, null, $by);
        $cur = (int)($v['stock'] ?? 0);
        if ($cur !== 0) {
            DwInventory::recordMovement(self::$db, ['brand' => self::$brand, 'variant_id' => $variantId, 'location_id' => $l['id'], 'quantity' => $cur,
                'movement_type' => 'adjustment', 'reference_type' => 'opening', 'notes' => 'Opening stock from product_variant.stock', 'created_by' => $by, 'allow_negative' => true]);
        } else {
            self::$db->prepare('INSERT IGNORE INTO inventory_balance (product_id, variant_id, location_id, brand, company_id, quantity_on_hand, quantity_reserved, updated) VALUES (?, ?, ?, ?, ?, 0, 0, NOW(3))')
                ->execute([$v['product_id'], $variantId, $l['id'], self::$brand, $l['company_id']]);
            DwInventory::syncVariantStock(self::$db, (string)self::$brand, $variantId, $by);
        }
    }

    // Checkout: one 'sale' movement per tracked line, at the selling store's default location (may go below 0 there if
    // the stock sits at another location of the same variant; the availability check is on the variant total).
    private static function invSale(string $companyId, array $line, string $invId, string $by): void
    {
        self::invEnsureTracked((string)$line['variant_id'], $companyId, $by);
        $l = DwInventory::defaultLocation(self::$db, (string)self::$brand, $companyId, $by);
        DwInventory::recordMovement(self::$db, ['brand' => self::$brand, 'variant_id' => $line['variant_id'], 'location_id' => $l['id'], 'quantity' => -(int)$line['quantity'],
            'movement_type' => 'sale', 'reference_type' => 'invoice', 'reference_id' => $invId, 'created_by' => $by, 'allow_negative' => true]);
    }

    // Reads: company_id required unless the staff has the right brand-wide; returns the company filter (or null).
    private static function invScope(string $right): ?string
    {
        self::needStaff();
        $company = self::id('company_id', false);
        if ($company !== null) {
            self::needCompany($right, $company);
            return $company;
        }
        if (!self::brandWide($right)) {
            self::fail(400, 'company_id is required (your rights are per store)');
        }
        return null;
    }

    private static function invText(string $k, int $max): ?string
    {
        return self::str($k, $max);
    }

    private static function rInventory(): void
    {
        $s = self::needStaff();
        $by = (string)$s['person_id'];
        $a = self::$action;
        if ($a === 'rebuild') {
            $company = self::invScope('UPDATE');
            self::ok(DwInventory::rebuildBalances(self::$db, (string)self::$brand, $company, $by) + ['company_id' => $company]);
        }
        $v = self::invVariant();
        $company = self::id('company_id', false);
        if ($a === 'stock') {
            self::needProduct('READ', (string)$v['product_id']);
            if ($company !== null) {
                self::needCompany('READ', $company);
            }
            $st = DwInventory::getStock(self::$db, (string)self::$brand, null, (string)$v['id'], $company);
            self::ok(['product_id' => $v['product_id'], 'variant_id' => $v['id'], 'tracked' => DwInventory::tracked(self::$db, (string)self::$brand, (string)$v['id'])] + $st);
        }
        self::needProduct('UPDATE', (string)$v['product_id']);
        $loc = self::id('location_id', false);
        // The store that owns the location (or the one named) must be one this staff may update.
        if ($loc !== null) {
            $lc = self::one('SELECT company_id FROM inventory_location WHERE id = ? AND brand = ?', [$loc, self::$brand]);
            if (!$lc) {
                self::fail(404, 'location not found on this brand');
            }
            self::needCompany('UPDATE', (string)$lc['company_id']);
        } elseif ($company !== null) {
            self::needCompany('UPDATE', $company);
        }
        $unit = self::has('unit_cost') ? self::cents('unit_cost', false) : null;
        $extra = array_filter(['lot_id' => self::invText('lot_id', 50), 'serial' => self::invText('serial', 100), 'bin' => self::invText('bin', 50)], fn($x) => $x !== null);
        self::begin();
        if ($a === 'receive') {
            $qty = self::int('quantity', 1, 1000000);
            if ($qty === null) {
                self::fail(400, 'quantity is required (1 or more)');
            }
            $type = 'receipt';
        } else { // adjust
            if (self::has('counted')) {
                $counted = self::int('counted', 0, 1000000000);
                $l0 = self::invLocation((string)$v['product_id'], $company, $loc, $by);
                $loc = (string)$l0['id'];
                $qty = $counted - DwInventory::getAvailable(self::$db, (string)self::$brand, (string)$v['id'], $loc) - (int)(self::one('SELECT quantity_reserved FROM inventory_balance WHERE variant_id = ? AND location_id = ?', [$v['id'], $loc])['quantity_reserved'] ?? 0);
                if ($qty === 0) {
                    self::$db->rollBack();
                    self::ok(['variant_id' => $v['id'], 'location_id' => $loc, 'unchanged' => true, 'note' => 'the count matches what is on hand; nothing recorded']);
                }
            } else {
                $qty = self::int('quantity', -1000000, 1000000);
                if ($qty === null || $qty === 0) {
                    self::fail(400, 'send quantity (signed, not 0) or counted');
                }
            }
            $type = 'adjustment';
        }
        $r = self::invMove((string)$v['id'], $company, $loc, $qty, $type, self::invText('reference_type', 30), self::invText('reference_id', 50),
            $unit !== null ? sprintf('%.4f', $unit / 100) : null, self::invText('notes', 500), $by, $extra);
        self::commit();
        self::ok(['movement_id' => $r['movement_id'], 'movement_type' => $type, 'quantity' => $qty, 'product_id' => $v['product_id'], 'variant_id' => $v['id'],
            'location_id' => $r['location_id'], 'on_hand_at_location' => $r['on_hand'], 'stock' => $r['stock']]);
    }

    private static function rInventoryMovements(): void
    {
        $company = self::invScope('READ');
        $w = ['m.brand = ?'];
        $args = [self::$brand];
        if ($company !== null) {
            $w[] = 'm.company_id = ?';
            $args[] = $company;
        }
        foreach (['variant_id', 'product_id', 'location_id', 'reference_id'] as $k) {
            if (($x = self::id($k, false)) !== null) {
                $w[] = "m.$k = ?";
                $args[] = $x;
            }
        }
        foreach (['movement_type' => 20, 'reference_type' => 30] as $k => $max) {
            if (($x = self::str($k, $max)) !== null) {
                $w[] = "m.$k = ?";
                $args[] = $x;
            }
        }
        $limit = self::int('limit', 1, 500) ?? 100;
        $offset = self::int('offset', 0, 1000000000) ?? 0;
        $rows = self::all('SELECT m.id AS movement_id, m.created, m.movement_type, m.quantity, m.product_id, p.name AS product_name, m.variant_id, m.location_id, l.name AS location_name,
                m.company_id, m.reference_type, m.reference_id, m.unit_cost, m.notes, m.lot_id, m.serial, m.bin, m.created_by
            FROM inventory_movement m JOIN inventory_location l ON l.id = m.location_id LEFT JOIN product p ON p.id = m.product_id
            WHERE ' . implode(' AND ', $w) . ' ORDER BY m.created DESC, m.id LIMIT ' . $limit . ' OFFSET ' . $offset, $args);
        foreach ($rows as &$r) {
            $r['quantity'] = (int)$r['quantity'];
        }
        unset($r);
        self::ok(['movements' => $rows, 'limit' => $limit, 'offset' => $offset]);
    }

    private static function rInventoryBalances(): void
    {
        $company = self::invScope('READ');
        $w = ['b.brand = ?'];
        $args = [self::$brand];
        if ($company !== null) {
            $w[] = 'b.company_id = ?';
            $args[] = $company;
        }
        foreach (['location_id', 'product_id'] as $k) {
            if (($x = self::id($k, false)) !== null) {
                $w[] = "b.$k = ?";
                $args[] = $x;
            }
        }
        $rows = self::all('SELECT b.product_id, p.name AS product_name, b.variant_id, v.name AS variant_name, b.location_id, l.name AS location_name, l.is_active, b.company_id,
                b.quantity_on_hand, b.quantity_reserved, b.quantity_on_hand - b.quantity_reserved AS available, b.updated
            FROM inventory_balance b JOIN inventory_location l ON l.id = b.location_id LEFT JOIN product p ON p.id = b.product_id LEFT JOIN product_variant v ON v.id = b.variant_id
            WHERE ' . implode(' AND ', $w) . ' ORDER BY p.name, v.position, l.name LIMIT 1000', $args);
        foreach ($rows as &$r) {
            foreach (['quantity_on_hand', 'quantity_reserved', 'available'] as $k) {
                $r[$k] = (int)$r[$k];
            }
            $r['is_active'] = (bool)$r['is_active'];
        }
        unset($r);
        self::ok(['balances' => $rows]);
    }

    private static function rInventoryLocations(): void
    {
        $s = self::needStaff();
        $by = (string)$s['person_id'];
        $a = self::$action;
        if ($a === 'list') {
            $company = self::id('company_id');
            self::needCompany('READ', $company);
            self::begin();
            DwInventory::defaultLocation(self::$db, (string)self::$brand, $company, $by);
            self::commit();
            $inactive = self::bool('include_inactive') === 1;
            $rows = self::all('SELECT id AS location_id, name, type, is_active, is_default, created, last_updated FROM inventory_location WHERE brand = ? AND company_id = ?' . ($inactive ? '' : ' AND is_active = 1') . ' ORDER BY is_default DESC, name', [self::$brand, $company]);
            foreach ($rows as &$r) {
                $r['is_active'] = (bool)$r['is_active'];
                $r['is_default'] = (bool)$r['is_default'];
            }
            unset($r);
            self::ok(['company_id' => $company, 'locations' => $rows]);
        }
        $type = self::str('type', 20);
        if ($type !== null && !in_array($type, DwInventory::LOCATION_TYPES, true)) {
            self::fail(400, 'type must be one of ' . implode(', ', DwInventory::LOCATION_TYPES));
        }
        if ($a === 'create') {
            $company = self::id('company_id');
            self::needCompany('CREATE', $company);
            $name = self::str('name', 100, true);
            $makeDefault = self::bool('is_default') === 1;
            self::begin();
            DwInventory::defaultLocation(self::$db, (string)self::$brand, $company, $by);
            if (self::one('SELECT 1 FROM inventory_location WHERE brand = ? AND company_id = ? AND name = ?', [self::$brand, $company, $name])) {
                self::fail(409, 'this store already has a location with that name');
            }
            $l = DwInventory::createLocation(self::$db, (string)self::$brand, $company, $name, $type ?? 'store', false, $by);
            if ($makeDefault) {
                self::invSetDefault($company, (string)$l['id'], $by);
            }
            self::commit();
            self::ok(['location_id' => $l['id']]);
        }
        // update
        $id = self::id('location_id');
        $l = self::one('SELECT * FROM inventory_location WHERE id = ? AND brand = ?', [$id, self::$brand]);
        if (!$l) {
            self::fail(404, 'location not found on this brand');
        }
        self::needCompany('UPDATE', (string)$l['company_id']);
        $set = [];
        if (($n = self::str('name', 100)) !== null) {
            $set['name'] = $n;
        }
        if ($type !== null) {
            $set['type'] = $type;
        }
        $active = self::bool('is_active');
        if ($active !== null) {
            if ($active === 0 && (int)$l['is_default']) {
                self::fail(409, 'the default location cannot be deactivated; make another location the default first');
            }
            $set['is_active'] = $active;
        }
        self::begin();
        if ($set !== []) {
            self::update('inventory_location', $id, $set + ['last_updated' => self::now(), 'last_updated_by' => $by]);
        }
        if (self::bool('is_default') === 1 && !(int)$l['is_default']) {
            self::invSetDefault((string)$l['company_id'], $id, $by);
        }
        if ($active !== null && $active !== (int)$l['is_active']) {
            // Active locations count toward the storefront stock: refresh the mirror of every variant stocked here.
            foreach (self::col('SELECT DISTINCT variant_id FROM inventory_balance WHERE location_id = ?', [$id]) as $vid) {
                DwInventory::syncVariantStock(self::$db, (string)self::$brand, (string)$vid, $by);
            }
        }
        self::commit();
        self::ok(['location_id' => $id]);
    }

    private static function invSetDefault(string $company, string $id, string $by): void
    {
        foreach (self::col('SELECT id FROM inventory_location WHERE brand = ? AND company_id = ? AND is_default = 1 AND id <> ?', [self::$brand, $company, $id]) as $x) {
            self::update('inventory_location', (string)$x, ['is_default' => 0, 'last_updated' => self::now(), 'last_updated_by' => $by]);
        }
        self::update('inventory_location', $id, ['is_default' => 1, 'is_active' => 1, 'last_updated' => self::now(), 'last_updated_by' => $by]);
    }

    // ------------------------------------------------------------------ shipments
    private const SHIP_STATUS = ['PENDING', 'LABEL_CREATED', 'SHIPPED', 'IN_TRANSIT', 'DELIVERED', 'RETURNED', 'CANCELLED'];

    private static function shipmentFields(?array $old): array
    {
        $set = [];
        foreach (['carrier' => 50, 'service' => 100, 'tracking_number' => 100] as $k => $max) {
            if (array_key_exists($k, self::$in)) {
                $set[$k] = self::str($k, $max);
            }
        }
        if (array_key_exists('tracking_url', self::$in)) {
            $u = self::str('tracking_url', 500);
            if ($u !== null && (!preg_match('#^https://[^\s<>"]+$#i', $u) || filter_var($u, FILTER_VALIDATE_URL) === false)) {
                self::fail(400, 'tracking_url must be an https:// link');
            }
            $set['tracking_url'] = $u;
        }
        if (array_key_exists('shipping_cost', self::$in)) {
            $set['shipping_cost'] = self::money(self::cents('shipping_cost', false));
        }
        if (($st = strtoupper(trim((string)(self::$in['status'] ?? '')))) !== '') {
            if (!in_array($st, self::SHIP_STATUS, true)) {
                self::fail(400, 'status must be one of ' . implode(', ', self::SHIP_STATUS));
            }
            $set['status'] = $st;
            if (in_array($st, ['SHIPPED', 'IN_TRANSIT', 'DELIVERED'], true) && ($old['shipped_at'] ?? null) === null) {
                $set['shipped_at'] = self::now();
            }
            if ($st === 'DELIVERED' && ($old['delivered_at'] ?? null) === null) {
                $set['delivered_at'] = self::now();
            }
        }
        return $set;
    }

    private static function shipments(string $invId): array
    {
        $rows = self::all('SELECT id AS shipment_id, carrier, service, tracking_number, tracking_url, status, shipping_cost, invoice_address_id, shipped_at, delivered_at, created
            FROM shipment WHERE invoice_id = ? ORDER BY created, id', [$invId]);
        foreach ($rows as &$r) {
            $r['items'] = self::all('SELECT id AS shipment_item_id, invoice_product_id, quantity FROM shipment_item WHERE shipment_id = ? ORDER BY created, id', [$r['shipment_id']]);
        }
        return $rows;
    }

    private static function rShipment(): void
    {
        self::needBrand();
        $a = self::$action;
        if ($a === 'list') {
            $id = self::id('invoice_id');
            self::invoice($id);
            self::ok(['shipments' => self::shipments($id)]);
        }
        if ($a === 'read') {
            $sid = self::id('shipment_id');
            $s = self::one('SELECT invoice_id FROM shipment WHERE id = ? AND brand = ?', [$sid, self::$brand]);
            if (!$s) {
                self::fail(404, 'shipment not found');
            }
            self::invoice((string)$s['invoice_id']);
            foreach (self::shipments((string)$s['invoice_id']) as $row) {
                if ($row['shipment_id'] === $sid) {
                    self::ok(['shipment' => $row]);
                }
            }
            self::fail(404, 'shipment not found');
        }
        $by = self::needStaff()['person_id'];
        if ($a === 'create') {
            $id = self::id('invoice_id');
            $items = self::$in['items'] ?? null;
            if (!is_array($items) || $items === [] || count($items) > 200) {
                self::fail(400, 'items must be a list of {invoice_product_id, quantity}');
            }
            self::begin();
            $i = self::invoice($id, 'CREATE', true);
            if (in_array($i['status'], ['VOIDED', 'REFUNDED'], true)) {
                self::fail(409, 'this invoice is ' . $i['status']);
            }
            $addr = self::one("SELECT id FROM invoice_address WHERE invoice_id = ? AND type = 'SHIPPING'", [$id]);
            $want = [];
            foreach ($items as $it) {
                $ip = is_array($it) ? trim((string)($it['invoice_product_id'] ?? '')) : '';
                $q = is_array($it) ? filter_var($it['quantity'] ?? null, FILTER_VALIDATE_INT) : false;
                if ($ip === '' || $q === false || $q < 1 || $q > 100000) {
                    self::fail(400, 'each item needs invoice_product_id and a quantity of 1 or more');
                }
                $want[$ip] = ($want[$ip] ?? 0) + $q;
            }
            foreach ($want as $ip => $q) {
                $line = self::one('SELECT quantity FROM invoice_product WHERE id = ? AND invoice_id = ? FOR UPDATE', [$ip, $id]);
                if (!$line) {
                    self::fail(400, 'invoice_product_id ' . $ip . ' is not on this invoice');
                }
                $done = (int)(self::one("SELECT COALESCE(SUM(si.quantity), 0) AS n FROM shipment_item si JOIN shipment s ON s.id = si.shipment_id
                    WHERE si.invoice_product_id = ? AND s.status <> 'CANCELLED'", [$ip])['n'] ?? 0);
                if ($done + $q > (int)$line['quantity']) {
                    self::fail(409, 'only ' . max(0, (int)$line['quantity'] - $done) . ' left to ship for invoice_product_id ' . $ip);
                }
            }
            $set = self::shipmentFields(null);
            $sid = self::insert('shipment', ['invoice_id' => $id, 'brand' => self::$brand, 'company_id' => $i['company_id'], 'status' => $set['status'] ?? 'PENDING',
                'invoice_address_id' => $addr['id'] ?? null, 'created_by' => $by] + $set);
            foreach ($want as $ip => $q) {
                self::insert('shipment_item', ['shipment_id' => $sid, 'invoice_product_id' => $ip, 'quantity' => $q, 'created_by' => $by]);
            }
            self::commit();
            self::ok(['shipment_id' => $sid, 'shipments' => self::shipments($id)]);
        }
        $sid = self::id('shipment_id');
        self::begin();
        $s = self::one('SELECT * FROM shipment WHERE id = ? AND brand = ? FOR UPDATE', [$sid, self::$brand]);
        if (!$s) {
            self::fail(404, 'shipment not found');
        }
        self::invoice((string)$s['invoice_id'], $a === 'update' ? 'UPDATE' : 'DELETE', true);
        if ($a === 'update') {
            $set = self::shipmentFields($s);
            if ($set === []) {
                self::fail(400, 'send carrier, service, tracking_number, tracking_url, shipping_cost or status');
            }
            self::update('shipment', $sid, $set + ['last_updated' => self::now()]);
            self::commit();
            self::ok(['shipment_id' => $sid, 'shipments' => self::shipments((string)$s['invoice_id'])]);
        }
        foreach (self::col('SELECT id FROM shipment_item WHERE shipment_id = ?', [$sid]) as $x) {
            self::delete('shipment_item', $x);
        }
        self::delete('shipment', $sid);
        self::commit();
        self::ok(['shipment_id' => $sid, 'deleted' => true]);
    }

    // ------------------------------------------------------------------ PayPal Subscriptions (2026-11-14, staging, #42)
    private static function collectPaypalHeaders(): array
    {
        $h = [];
        if (function_exists('getallheaders')) {
            foreach ((getallheaders() ?: []) as $k => $v) {
                $h[strtolower((string)$k)] = (string)$v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with((string)$k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string)$k, 5)));
                $h[$name] = (string)$v;
            }
        }
        return $h;
    }

    private static function webhookOut(int $code, string $msg, ?string $type, ?string $eventId): void
    {
        $trunc = $eventId !== null && $eventId !== '' ? substr($eventId, 0, 12) : '-';
        error_log('store paypal_webhook type=' . ($type ?? '-') . ' event=' . $trunc . ' http=' . $code);
        DwCustomer::out($code, ['ok' => $code >= 200 && $code < 300, 'error' => $code >= 300 ? $msg : null, 'result' => $code < 300 ? $msg : null]);
    }

    private static function routePaypalWebhook(string $method): void
    {
        self::$rawBody = (string)(file_get_contents('php://input') ?: '');
        self::$paypalHeaders = self::collectPaypalHeaders();
        self::$in = ['action' => 'notify'];
        self::$action = 'notify';
        if ($method !== 'POST') {
            self::webhookOut(405, 'method not allowed', null, null);
        }
        try {
            self::$db = Dw::pdo();
            $hostBrand = null;
            try {
                $r = DwCustomer::brandFromHost();
                if (is_array($r) && !empty($r[0])) {
                    $hostBrand = (string)$r[0];
                }
            } catch (Throwable $e) {
                $hostBrand = null;
            }
            if ($hostBrand === null || $hostBrand === '') {
                require_once '/var/www/html/lib/DwDomain.php';
                $hostBrand = DwDomain::brandForHost(self::entCfg()['seller_domain'], [Dw::class, 'pdo']) ?: null;
            }
            self::$brand = is_string($hostBrand) && $hostBrand !== '' ? $hostBrand : null;
            if (self::$brand === null || self::$brand === '') {
                self::webhookOut(400, 'unknown store host', null, null);
            }
            self::rPaypal_webhook();
        } catch (Throwable $e) {
            if (isset(self::$db) && self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            error_log('store paypal_webhook: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
            self::webhookOut(500, 'could not process webhook', null, null);
        }
    }

    private static function authPublicStore(): void
    {
        $r = DwCustomer::brandFromHost();
        if (is_array($r) && !empty($r[0])) {
            self::$brand = (string)$r[0];
        }
        self::needBrand();
    }

    private static function mayEmail(?string $email): bool
    {
        $email = strtolower(trim((string)$email));
        if ($email === '' || DwCustomer::isTestAddress($email)) {
            return false;
        }
        $list = self::storeCfg()['entitlements']['order_email_allowlist'] ?? null;
        if (is_array($list) && $list !== []) {
            $want = array_map(static fn($e) => strtolower(trim((string)$e)), $list);
            return in_array($email, $want, true);
        }
        return true;
    }

    private static function sellerCompanyId(): string
    {
        $cfg = (string)(self::entCfg()['seller_company_id'] ?? '');
        if ($cfg !== '') {
            return $cfg;
        }
        $b = self::sellerBrand();
        if ($b) {
            $r = self::one("SELECT pc.company_id FROM product_company pc JOIN product p ON p.id = pc.product_id
                WHERE p.brand = ? AND p.entitlements IS NOT NULL AND p.entitlements <> '' LIMIT 1", [$b]);
            if ($r) {
                return (string)$r['company_id'];
            }
            $c = self::one('SELECT id FROM company WHERE brand = ? ORDER BY created LIMIT 1', [$b]);
            if ($c) {
                return (string)$c['id'];
            }
        }
        return 'default';
    }

    private static function returnBaseUrl(): string
    {
        $u = trim((string)(self::entCfg()['subscription_return_url'] ?? ''));
        return $u !== '' ? rtrim($u, '/') : 'https://' . DwBrand::domain() . '/store/subscription_return';
    }

    private static function handlePaypalEvent(array $event, string $rowId): string
    {
        $type = DwPayPalBilling::eventType($event);
        if (!DwPayPalBilling::known($type)) {
            return 'ignored';
        }
        $sid = DwPayPalBilling::subscriptionIdFromEvent($event);
        $custom = DwPayPalBilling::customIdFromEvent($event);
        $parsed = DwPayPalBilling::parseCustomId($custom);
        $inv = self::findSubscriptionInvoice($sid, $parsed['invoice_id']);
        $firstPaid = $sid !== null && self::one("SELECT id FROM payments WHERE paypal_subscription_id = ? AND status = 'COMPLETED' LIMIT 1", [$sid]) !== null;
        $sale = DwPayPalBilling::saleIdFromEvent($event);
        $saleExists = $sale !== null && self::one('SELECT id FROM payments WHERE provider_ref = ? LIMIT 1', [$sale]) !== null;
        $ctx = [
            'invoice_found' => $inv !== null,
            'invoice_status' => $inv['status'] ?? null,
            'payment_exists' => $saleExists,
            'first_payment_done' => $firstPaid,
        ];
        $d = DwPayPalBilling::decide($type, $ctx);
        $action = $d['action'];
        $status = DwPayPalBilling::statusFromEvent($event);
        $next = DwPayPalBilling::nextBillingFromEvent($event);
        if (in_array($action, ['attach_subscription', 'sync_status', 'attach_and_wait', 'grant_if_needed'], true)) {
            if ($sid !== null && $inv !== null && empty($inv['paypal_subscription_id'])) {
                self::update('invoice', (string)$inv['id'], ['paypal_subscription_id' => $sid, 'last_updated' => self::now()]);
                $inv['paypal_subscription_id'] = $sid;
            }
            if ($sid !== null) {
                self::syncEntitlementSubscription($sid, $status, $next, $inv['id'] ?? $parsed['invoice_id']);
            }
            if ($action === 'grant_if_needed' && $inv !== null && ($inv['status'] ?? '') === 'PAID') {
                self::grantInvoice((string)$inv['id']);
                self::syncEntitlementSubscription($sid, $status ?? 'ACTIVE', $next, $inv['id']);
            }
            return $action;
        }
        if ($action === 'noop_duplicate_sale') {
            return 'already recorded';
        }
        if ($action === 'settle_first') {
            if ($inv === null) {
                return 'no invoice';
            }
            return self::recordSubscriptionSale($event, $inv, false);
        }
        if ($action === 'renew') {
            return self::recordSubscriptionSale($event, $inv, true);
        }
        if ($action === 'note_grace') {
            if ($sid !== null) {
                self::syncEntitlementSubscription($sid, $status ?? 'SUSPENDED', $next, $inv['id'] ?? null);
            }
            return 'grace noted';
        }
        if ($action === 'cancel_entitlement') {
            return self::cancelBySubscription($sid, $status ?? 'CANCELLED', 'paypal ' . strtolower($type));
        }
        if ($action === 'revoke_period') {
            return self::revokeBySale($event, $sid);
        }
        return $action;
    }

    private static function findSubscriptionInvoice(?string $sid, ?string $invoiceId): ?array
    {
        if ($invoiceId) {
            $i = self::one('SELECT * FROM invoice WHERE id = ?', [$invoiceId]);
            if ($i) {
                return $i;
            }
        }
        if ($sid) {
            $i = self::one('SELECT * FROM invoice WHERE paypal_subscription_id = ? ORDER BY date_created DESC LIMIT 1', [$sid]);
            if ($i) {
                return $i;
            }
            $p = self::one('SELECT invoice_id FROM payments WHERE paypal_subscription_id = ? ORDER BY created DESC LIMIT 1', [$sid]);
            if ($p) {
                return self::one('SELECT * FROM invoice WHERE id = ?', [$p['invoice_id']]);
            }
        }
        return null;
    }

    private static function syncEntitlementSubscription(?string $sid, ?string $status, ?string $next, ?string $invoiceId): void
    {
        if ($sid === null) {
            return;
        }
        $rows = self::all("SELECT id FROM brand_entitlement WHERE provider_ref = ? AND status IN ('ACTIVE','CANCELLED')", [$sid]);
        if ($rows === [] && $invoiceId) {
            $rows = self::all("SELECT id FROM brand_entitlement WHERE source_invoice_id = ?", [$invoiceId]);
        }
        $set = [];
        if ($status !== null) {
            $set['provider_status'] = $status;
        }
        if ($next !== null) {
            $set['next_billing_time'] = $next;
        }
        $set['provider_ref'] = $sid;
        foreach ($rows as $r) {
            self::update('brand_entitlement', (string)$r['id'], $set);
        }
    }

    private static function recordSubscriptionSale(array $event, ?array $inv, bool $renew): string
    {
        $sid = DwPayPalBilling::subscriptionIdFromEvent($event);
        $sale = DwPayPalBilling::saleIdFromEvent($event);
        $amount = DwPayPalBilling::saleAmount($event);
        $currency = DwPayPalBilling::saleCurrency($event);
        if ($currency !== 'USD') {
            return 'ignored currency';
        }
        if ($sid === null || $sale === null || $amount === null) {
            return 'incomplete sale';
        }
        if ($inv !== null && ($inv['status'] ?? '') === 'PAID') {
            $pay = self::one("SELECT * FROM payments WHERE invoice_id = ? AND status = 'COMPLETED' AND (paypal_subscription_id = ? OR paypal_subscription_id IS NULL) ORDER BY created LIMIT 1", [$inv['id'], $sid]);
            if ($pay) {
                $ref = (string)($pay['provider_ref'] ?? '');
                if ($ref === $sale || $ref === '' || str_starts_with($ref, 'paypal-api:')) {
                    if ($ref !== $sale) {
                        self::update('payments', (string)$pay['id'], ['provider_ref' => substr($sale, 0, 100), 'paypal_subscription_id' => $sid]);
                    }
                    return 'sale attached';
                }
            }
        }
        if ($renew || ($inv !== null && ($inv['status'] ?? '') === 'PAID')) {
            $inv = self::openRenewalInvoice($sid, $inv, $amount);
            if ($inv === null) {
                return 'renew failed';
            }
        }
        if ($inv === null) {
            return 'no invoice';
        }
        if (($inv['status'] ?? '') !== 'PENDING') {
            return 'invoice not pending';
        }
        $pid = self::insert('payments', [
            'invoice_id' => $inv['id'],
            'brand' => $inv['brand'],
            'company_id' => $inv['company_id'],
            'amount' => self::money(self::toCents($amount)),
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'method' => 'PAYPAL',
            'provider_ref' => substr($sale, 0, 100),
            'paypal_subscription_id' => $sid,
            'created_by' => 'paypal_webhook',
        ]);
        if (empty($inv['paypal_subscription_id'])) {
            self::update('invoice', (string)$inv['id'], ['paypal_subscription_id' => $sid, 'last_updated' => self::now()]);
        }
        self::settle((string)$inv['id']);
        $next = null;
        $st = 'ACTIVE';
        try {
            $live = DwPayPal::getSubscription(self::sellerCompanyId(), $sid);
            $next = DwPayPalBilling::toUtcDatetime($live['next_billing_time'] ?? null);
            $st = $live['status'] ?? 'ACTIVE';
        } catch (Throwable $e) {
            // Grant still stands; next billing can arrive on UPDATED.
        }
        self::syncEntitlementSubscription($sid, $st, $next, (string)$inv['id']);
        self::paidSubscriptionEmails((string)$inv['id']);
        return $renew ? 'renewed ' . $pid : 'settled ' . $pid;
    }

    private static function openRenewalInvoice(string $sid, ?array $prev, string $amount): ?array
    {
        $src = $prev;
        if ($src === null) {
            $src = self::findSubscriptionInvoice($sid, null);
        }
        if ($src === null) {
            return null;
        }
        $line = self::one('SELECT ip.*, p.entitlements, v.billing_interval, v.billing_interval_count, v.paypal_plan_id
            FROM invoice_product ip JOIN product p ON p.id = ip.product_id JOIN product_variant v ON v.id = ip.variant_id
            WHERE ip.invoice_id = ? AND p.entitlements IS NOT NULL AND p.entitlements <> \'\' LIMIT 1', [$src['id']]);
        if (!$line) {
            return null;
        }
        $cents = self::toCents($amount);
        $id = self::insert('invoice', [
            'person_id' => $src['person_id'],
            'company_id' => $src['company_id'],
            'brand' => $src['brand'],
            'status' => 'PENDING',
            'subtotal' => self::money($cents),
            'tax' => self::money(0),
            'shipping' => self::money(0),
            'total' => self::money($cents),
            'currency' => 'USD',
            'paypal_subscription_id' => $sid,
            'created_by' => 'paypal_webhook',
        ]);
        self::insert('invoice_product', [
            'invoice_id' => $id,
            'product_id' => $line['product_id'],
            'variant_id' => $line['variant_id'],
            'quantity' => 1,
            'unit_price' => self::money($cents),
            'product_name' => mb_substr((string)$line['product_name'], 0, 200),
            'target_domain_id' => $line['target_domain_id'],
            'created_by' => 'paypal_webhook',
        ]);
        return self::one('SELECT * FROM invoice WHERE id = ?', [$id]);
    }

    private static function cancelBySubscription(?string $sid, string $providerStatus, string $note): string
    {
        if ($sid === null) {
            return 'no subscription';
        }
        // Cancelled in PayPal by the customer inside the money-back window: same as a customer cancel. Refunds run after commit.
        // Only while a grant is still ACTIVE: our own cancels (owner cancel_mine, staff cancel) mark the grants first, so the
        // webhook that echoes them back is a no-op here, and a staff cancel never triggers a refund by itself.
        $live = self::one("SELECT COUNT(*) AS n FROM brand_entitlement WHERE provider_ref = ? AND status = 'ACTIVE'", [$sid]);
        $g = ((int)($live['n'] ?? 0)) > 0 ? self::guaranteeInfo($sid) : ['eligible' => false];
        if ($g['eligible']) {
            $ids = self::guaranteeRevoke($sid, $g, $note);
            if ($ids !== []) {
                self::$afterCommit[] = fn() => self::guaranteeRefunds($sid, $ids);
            }
            return 'money-back: revoked, ' . count($ids) . ' refund(s) queued';
        }
        $rows = self::all("SELECT id, brand, paid_through FROM brand_entitlement WHERE provider_ref = ? AND status = 'ACTIVE' FOR UPDATE", [$sid]);
        $now = (string)self::one('SELECT NOW() AS n')['n'];
        $brands = [];
        foreach ($rows as $r) {
            self::update('brand_entitlement', (string)$r['id'], [
                'status' => 'CANCELLED',
                'grace_until' => $r['paid_through'] ?? $now,
                'paid_through' => $r['paid_through'] ?? $now,
                'provider_status' => $providerStatus,
                'note' => mb_substr($note, 0, 200),
            ]);
            if (!empty($r['brand'])) {
                $brands[(string)$r['brand']] = true;
            }
        }
        if ($brands === [] && $rows) {
            foreach (self::all("SELECT DISTINCT brand FROM brand_entitlement WHERE provider_ref = ?", [$sid]) as $b) {
                $brands[(string)$b['brand']] = true;
            }
        }
        foreach (array_keys($brands) as $b) {
            self::bumpBannerCache($b);
        }
        return 'cancelled ' . count($rows);
    }

    private static function revokeBySale(array $event, ?string $sid): string
    {
        $sale = DwPayPalBilling::parentSaleId($event) ?? DwPayPalBilling::saleIdFromEvent($event);
        $pay = null;
        if ($sale) {
            $pay = self::one('SELECT * FROM payments WHERE provider_ref = ? LIMIT 1', [$sale]);
        }
        if ($pay === null && $sid) {
            $pay = self::one("SELECT * FROM payments WHERE paypal_subscription_id = ? AND status = 'COMPLETED' ORDER BY created DESC LIMIT 1", [$sid]);
        }
        if ($pay === null) {
            return 'no payment';
        }
        $was = (string)($pay['status'] ?? '');
        if ($was !== 'REFUNDED') {
            self::update('payments', (string)$pay['id'], ['status' => 'REFUNDED']);
        }
        self::settle((string)$pay['invoice_id']);
        self::revokeInvoice((string)$pay['invoice_id'], 'paypal refund or reversal');
        if ($pay['refund_requested'] ?? null) {
            self::guaranteeInvoiceStatus((string)$pay['invoice_id']);   // our own money-back refund coming back as a webhook
            return 'money-back refund confirmed ' . $pay['invoice_id'];
        }
        if ($sid) {
            self::syncEntitlementSubscription($sid, 'ACTIVE', null, (string)$pay['invoice_id']);
        }
        return 'revoked ' . $pay['invoice_id'];
    }

    private static function paidSubscriptionEmails(string $invId): void
    {
        try {
            $i = self::one('SELECT * FROM invoice WHERE id = ?', [$invId]);
            $company = self::one('SELECT company_name FROM company WHERE id = ?', [$i['company_id']]);
            $store = trim((string)($company['company_name'] ?? '')) ?: 'The store';
            $st = self::settings((string)$i['company_id']);
            $cust = self::one("SELECT p.first_name, p.last_name, (SELECT e.email FROM email e WHERE e.owner_id = p.id AND e.owner_type = 'PRSN' AND e.brand = p.brand ORDER BY e.created LIMIT 1) AS email
                FROM person p WHERE p.id = ?", [$i['person_id']]) ?? [];
            $ship = self::one("SELECT * FROM invoice_address WHERE invoice_id = ? AND type = 'SHIPPING'", [$invId]) ?? [];
            $lines = self::all('SELECT ip.product_name, ip.quantity, ip.unit_price, td.domain_name AS target_domain
                FROM invoice_product ip LEFT JOIN domain td ON td.id = ip.target_domain_id WHERE ip.invoice_id = ? ORDER BY ip.created, ip.id', [$invId]);
            $tot = ['subtotal' => $i['subtotal'], 'shipping' => $i['shipping'], 'tax' => $i['tax'], 'tax_label' => self::toCents($i['tax']) > 0 ? 'Hawaii GET' : null,
                'total' => $i['total'], 'shipping_rule' => self::toCents($i['shipping']) === 0 ? 'none' : 'flat'];
            $ref = strtoupper(substr($invId, 0, 8));
            $sites = self::coveredSites($lines);
            $siteBit = $sites !== [] ? ' for ' . implode(', ', $sites) : '';
            $body = self::orderText($store, $ref, $lines, $tot, $ship ?: []);
            $custEmail = $cust['email'] ?? null;
            $storeEmail = $st['store_email'];
            $payNote = "\nPayment of $" . $i['total'] . " USD was received through PayPal. Your subscription is now active."
                . ($sites !== [] ? "\nCovered site: " . implode(', ', $sites) : '');
            if ($custEmail && self::mayEmail($custEmail)) {
                $hello = trim((string)($cust['first_name'] ?? '')) !== '' ? 'Aloha ' . trim((string)$cust['first_name']) . ",\n\n" : "Aloha,\n\n";
                Dw::sendMail($custEmail, 'Your subscription ' . $ref . ' from ' . $store . $siteBit, $hello . 'Thank you for your order from ' . $store . ".\n\n" . $body . $payNote . "\n", $storeEmail);
            }
            if ($storeEmail && self::mayEmail($storeEmail)) {
                Dw::sendMail($storeEmail, 'Paid subscription ' . $ref . $siteBit . ' (' . $i['total'] . ' USD)',
                    'Paid subscription ' . $ref . ".\n\nEmail: " . ($custEmail ?? '(none)') . "\n\n" . $body . $payNote . "\nInvoice id: " . $invId . "\n", $custEmail);
            }
        } catch (Throwable $e) {
            error_log('store paid subscription email: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
        }
    }

    private static function paypalSubscribe(): void
    {
        $cu = self::needCustomer();
        self::needBrand();
        if (!self::isSeller()) {
            self::fail(403, 'subscriptions are sold on the DW Alliance LLC store only');
        }
        $company = self::sellerCompanyId();
        $st = self::settings($company);
        if (!$st['paypal_enabled']) {
            self::fail(501, 'PayPal is not switched on for this store yet');
        }
        $vid = self::id('variant_id');
        $v = self::one('SELECT v.id, v.product_id, v.name AS variant_name, v.price AS variant_price, v.billing_interval, v.billing_interval_count, v.paypal_plan_id, v.stock,
            p.name, p.price, p.active, p.entitlements FROM product_variant v JOIN product p ON p.id = v.product_id WHERE v.id = ? AND v.brand = ? AND p.brand = ?',
            [$vid, self::$brand, self::$brand]);
        if (!$v || !(int)$v['active']) {
            self::fail(404, 'product not found');
        }
        if ((string)($v['entitlements'] ?? '') === '' || !in_array((string)$v['billing_interval'], ['MONTH', 'YEAR'], true)) {
            self::fail(400, 'this variant is not a subscription');
        }
        $plan = (string)($v['paypal_plan_id'] ?? '');
        if (!preg_match('/^P-[A-Z0-9]+$/', $plan)) {
            self::fail(409, 'this plan is not linked to PayPal yet');
        }
        $t = self::targetDomain(self::str('domain', 100, true));
        self::needSiteOwner($t);
        require_once '/var/www/html/lib/DwBanner.php';
        $ship = self::ownAddress(self::id('shipping_address_id'));
        $bill = self::ownAddress(self::id('billing_address_id', false) ?? $ship['id']);
        $unit = self::toCents((string)($v['variant_price'] ?? $v['price']));
        $qty = 1;
        $sub = $unit * $qty;
        // Referred-client discount (refs #64, DwAffiliate): a client attributed to an affiliate starts on the parallel
        // 'referred' plan (discounted first cycles, then the regular price). Only subscriptions reach this function.
        $aff = ['attribution' => null, 'discount' => null];
        try {
            require_once '/var/www/html/lib/DwAffiliate.php';
            $aff = DwAffiliate::planSubscription(self::$db, [
                'person_id' => $cu['person_id'], 'entitlement' => trim(explode(',', (string)$v['entitlements'])[0]),
                'plan_id' => $plan, 'price' => (string)($v['variant_price'] ?? $v['price']),
                'interval' => (string)$v['billing_interval'], 'interval_count' => (int)$v['billing_interval_count'],
            ]);
        } catch (Throwable $e) {
            error_log('affiliate discount lookup failed: ' . get_class($e));
        }
        $disc = $aff['discount'];
        $regSub = $sub;
        $regTot = self::totals($regSub, $company, (string)$ship['state'], 0);
        if ($disc !== null) {
            $plan = $disc['plan_id'];
            $unit = $disc['discounted_cents'];
            $sub = $unit * $qty;
        }
        $tot = $disc !== null ? self::totals($sub, $company, (string)$ship['state'], 0) : $regTot;
        [, , $taxC] = $tot['_c'];
        [, , $regTaxC] = $regTot['_c'];
        if (self::$action === 'paypal_quote') {
            $used = self::one("SELECT 1 FROM payments pa JOIN invoice i ON i.id = pa.invoice_id JOIN invoice_product ip ON ip.invoice_id = i.id
                WHERE ip.product_id = ? AND (i.person_id = ? OR ip.target_domain_id = ?) AND pa.status IN ('COMPLETED', 'REFUNDED') LIMIT 1",
                [$v['product_id'], $cu['person_id'], $t['id']]);
            $days = self::guaranteeDays();
            $bareQ = DwBanner::bare((string)$t['domain_name']);
            self::ok(['quote' => [
                'product_name' => $v['name'], 'variant_name' => $v['variant_name'], 'domain' => $bareQ !== '' ? $bareQ : $t['domain_name'],
                'billing_interval' => $v['billing_interval'], 'billing_interval_count' => (int)$v['billing_interval_count'],
                'subtotal' => self::money($sub), 'tax' => self::money($taxC), 'tax_label' => $tot['tax_label'], 'total' => self::money($sub + $taxC), 'currency' => 'USD',
                'referred' => $disc === null ? null : [
                    'percent' => $disc['percent'], 'months' => $disc['months'], 'cycles' => $disc['cycles'],
                    'regular_subtotal' => self::money($regSub), 'regular_tax' => self::money($regTaxC), 'regular_total' => self::money($regSub + $regTaxC),
                    'saved_each_period' => self::money($regSub - $sub),
                    'text' => DwAffiliate::discountText($disc, $regSub + $regTaxC, (string)$v['billing_interval']),
                ],
                'guarantee_applies' => $used === null, 'guarantee_days' => $days,
                'guarantee' => $used === null
                    ? 'Covered by the ' . $days . '-day money-back guarantee: cancel within ' . $days . ' days of your first payment for a full refund to PayPal; access ends immediately.'
                    : 'This account or site has paid for this product before. The ' . $days . '-day money-back guarantee covers the first purchase of a product only, so it does not apply here.',
            ]]);
        }
        $by = $cu['person_id'];
        $bare = '';
        require_once '/var/www/html/lib/DwBanner.php';
        $bare = DwBanner::bare((string)$t['domain_name']);
        self::begin();
        $invId = self::insert('invoice', ['person_id' => $by, 'company_id' => $company, 'brand' => self::$brand, 'status' => 'PENDING',
            'subtotal' => self::money($sub), 'tax' => self::money($taxC), 'shipping' => self::money(0), 'total' => self::money($sub + $taxC),
            'currency' => 'USD', 'created_by' => $by]);
        self::insert('invoice_product', ['invoice_id' => $invId, 'product_id' => $v['product_id'], 'variant_id' => $vid, 'quantity' => $qty,
            'unit_price' => self::money($unit), 'product_name' => mb_substr((string)$v['name'], 0, 200), 'target_domain_id' => $t['id'], 'created_by' => $by]);
        foreach (['SHIPPING' => $ship, 'BILLING' => $bill] as $type => $ad) {
            self::insert('invoice_address', ['invoice_id' => $invId, 'address_id' => $ad['id'], 'type' => $type, 'line_1' => $ad['line_1'], 'line_2' => $ad['line_2'], 'line_3' => $ad['line_3'],
                'city' => $ad['city'], 'state' => $ad['state'], 'zipcode' => $ad['zipcode'], 'country' => $ad['country'], 'created_by' => $by, 'brand' => self::$brand]);
        }
        if ($aff['attribution'] !== null) {
            // Attribution, discount terms and the commission base stay on record (affiliate_subscription). If that
            // cannot be written the subscription is not started, so a discount is never given without a record.
            try {
                DwAffiliate::recordSubscription(self::$db, $aff['attribution'], [
                    'invoice_id' => $invId, 'person_id' => $by, 'variant_id' => $vid, 'discount' => $disc,
                    'regular_unit' => $regSub, 'discounted_unit' => $sub, 'regular_tax' => $regTaxC, 'discounted_tax' => $taxC,
                ]);
            } catch (Throwable $e) {
                error_log('affiliate subscription record failed: ' . get_class($e));
                if (self::$db->inTransaction()) {
                    self::$db->rollBack();
                }
                self::fail(500, 'could not start the subscription, please try again');
            }
        }
        $custom = DwPayPalBilling::customId($invId, $bare !== '' ? $bare : (string)$t['domain_name']);
        $ret = self::returnBaseUrl();
        $body = [
            'plan_id' => $plan,
            'custom_id' => $custom,
            'application_context' => [
                'brand_name' => DwBrand::legalName(),
                'locale' => 'en-US',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => $ret . '/?invoice_id=' . rawurlencode($invId),
                'cancel_url' => $ret . '/?invoice_id=' . rawurlencode($invId) . '&cancelled=1',
            ],
        ];
        // Plans themselves stay untaxed. Hawaii GET is a per-subscription fixed-price override so only Hawaii buyers pay it.
        // PayPal keeps that override on later cycles for this subscription.
        if ($disc !== null) {
            // Referred plan: cycle 1 is the discounted TRIAL cycles, cycle 2 the regular price. Both carry Hawaii GET when it applies.
            if ($taxC > 0 || $regTaxC > 0) {
                $body['plan'] = ['billing_cycles' => [
                    ['sequence' => 1, 'total_cycles' => $disc['cycles'],
                        'pricing_scheme' => ['fixed_price' => ['value' => self::money($sub + $taxC), 'currency_code' => 'USD']]],
                    ['sequence' => 2, 'total_cycles' => 0,
                        'pricing_scheme' => ['fixed_price' => ['value' => self::money($regSub + $regTaxC), 'currency_code' => 'USD']]],
                ]];
            }
        } elseif ($taxC > 0) {
            $body['plan'] = ['billing_cycles' => [[
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => ['fixed_price' => ['value' => self::money($sub + $taxC), 'currency_code' => 'USD']],
            ]]];
        }
        try {
            DwPayPal::requireConfiguredApi();
            $subr = DwPayPal::createSubscription($company, $body);
        } catch (DwPayPalError $e) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            self::fail($e->http, $e->getMessage());
        }
        if (($subr['id'] ?? '') === '' || ($subr['approve_url'] ?? null) === null) {
            if (self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            self::fail(502, 'PayPal did not return an approval link');
        }
        self::update('invoice', $invId, ['paypal_subscription_id' => $subr['id'], 'last_updated' => self::now()]);
        if ($aff['attribution'] !== null) {
            DwAffiliate::linkPayPalSubscription(self::$db, $invId, (string)$subr['id']);
        }
        self::commit();
        self::ok([
            'invoice' => self::invoiceView($invId),
            'paypal_subscription_id' => $subr['id'],
            'status' => $subr['status'],
            'approve_url' => $subr['approve_url'],
            'custom_id' => $custom,
            'tax_label' => $tot['tax_label'],
            'total' => self::money($sub + $taxC),
        ]);
    }

    // Sites the signed-in customer owns: brands where one of their sign-in emails is a SUPER_ADMIN email.
    // Only site names leave this function (never brand codes). One row per site (bare name preferred over www.).
    private static function mySites(): void
    {
        $cu = self::needCustomer();
        self::needBrand();
        if (!self::isSeller()) {
            self::fail(403, 'subscriptions are sold on the DW Alliance LLC store only');
        }
        $mine = array_values(array_unique(array_filter(array_map(fn($e) => strtolower(trim((string)$e)),
            self::col("SELECT email FROM email WHERE owner_id = ? AND owner_type = 'PRSN' AND brand = ?", [$cu['person_id'], self::$brand])))));
        if ($mine === []) {
            self::ok(['sites' => []]);
        }
        $in = implode(',', array_fill(0, count($mine), '?'));
        $rows = self::all("SELECT d.domain_name, d.code FROM domain d WHERE d.code IN (
                SELECT da.brand FROM data_access da JOIN email e ON e.owner_id = da.person_id AND e.owner_type = 'PRSN' AND e.brand = da.brand
                WHERE da.right_name = 'SUPER_ADMIN' AND LOWER(TRIM(e.email)) IN ($in))
            ORDER BY d.domain_name LIMIT 500", $mine);
        $seller = (string)(self::sellerBrand() ?? '');
        $codes = [];
        foreach ($rows as $r) {
            if ($seller !== '' && hash_equals($seller, (string)$r['code'])) {
                continue;
            }
            $name = strtolower((string)$r['domain_name']);
            if (!DwDomain::validHost($name)) {
                continue;
            }
            $bare = strncmp($name, 'www.', 4) === 0 ? substr($name, 4) : $name;
            $codes[$bare] = (string)$r['code'];
        }
        ksort($codes);
        $sites = [];
        foreach ($codes as $bare => $code) {
            $act = self::all("SELECT entitlement, paid_through, provider_status FROM brand_entitlement WHERE brand = ? AND status = 'ACTIVE'
                AND (paid_through IS NULL OR COALESCE(grace_until, paid_through) > NOW()) ORDER BY entitlement", [$code]);
            $sites[] = ['domain' => $bare, 'active' => array_map(fn($a) => ['entitlement' => $a['entitlement'], 'paid_through' => $a['paid_through'],
                'renews' => strtoupper((string)($a['provider_status'] ?? '')) === 'ACTIVE'], $act)];
        }
        self::ok(['sites' => $sites]);
    }

    private static function cancelOrListMine(): void
    {
        self::needBrand();
        $t = self::targetDomain(self::str('domain', 100, true));
        self::needSiteOwner($t);
        $sel = 'SELECT be.*, d.domain_name FROM brand_entitlement be LEFT JOIN domain d ON d.id = be.domain_id';
        $subsOf = fn() => array_values(array_unique(array_filter(array_map('strval', self::col(
            "SELECT provider_ref FROM brand_entitlement WHERE domain_id = ? AND status IN ('ACTIVE','CANCELLED') AND provider_ref LIKE 'I-%'", [$t['id']])))));
        if (self::$action === 'mine' || self::$action === 'cancel_preview') {
            self::ok(['domain' => $t['domain_name'], 'guarantee' => array_map([self::class, 'guaranteeView'], array_map([self::class, 'guaranteeInfo'], $subsOf())),
                'entitlements' => array_map([self::class, 'entView'], self::all($sel . " WHERE be.domain_id = ? ORDER BY be.created DESC LIMIT 50", [$t['id']]))]);
        }
        self::begin();
        // Every entitlement type (no_banner and future add-ons) on this site.
        $only = self::entName(false, null);   // optional: cancel one add-on only; default every type on this site
        $rows = self::all("SELECT id, paid_through, provider_ref, provider_status FROM brand_entitlement WHERE domain_id = ? AND status = 'ACTIVE'" . ($only !== null ? ' AND entitlement = ?' : '') . " FOR UPDATE",
            $only !== null ? [$t['id'], $only] : [$t['id']]);
        $now = (string)self::one('SELECT NOW() AS n')['n'];
        $by = self::$cust['person_id'];
        $subs = [];
        $refund = [];
        $guar = [];
        foreach ($rows as $r) {
            $ref = (string)($r['provider_ref'] ?? '');
            if (preg_match('/^I-[A-Z0-9]+$/', $ref)) {
                $subs[$ref] = true;
                if (!array_key_exists($ref, $guar)) {
                    $guar[$ref] = self::guaranteeInfo($ref);
                    if ($guar[$ref]['eligible']) {
                        $refund[$ref] = self::guaranteeRevoke($ref, $guar[$ref], 'cancelled by site owner');
                    }
                }
                if ($guar[$ref]['eligible']) {
                    continue;   // already revoked above
                }
            }
            self::update('brand_entitlement', (string)$r['id'], ['status' => 'CANCELLED', 'grace_until' => $r['paid_through'] ?? $now, 'paid_through' => $r['paid_through'] ?? $now,
                'note' => 'cancelled by site owner', 'provider_status' => $ref !== '' ? 'CANCELLED' : ($r['provider_status'] ?? null),
                'updated_by_person_id' => $by]);
        }
        self::bumpBannerCache((string)$t['code']);
        self::commit();
        foreach (array_keys($subs) as $sid) {
            try {
                DwPayPal::cancelSubscription(self::sellerCompanyId(), $sid, 'Cancelled by the site owner');
            } catch (DwPayPalError $e) {
                error_log('store cancel_mine paypal http ' . $e->http);
            }
        }
        $results = [];
        foreach ($refund as $sid => $payIds) {
            $results[] = ['paypal_subscription_id' => $sid] + self::guaranteeRefunds($sid, $payIds);
        }
        self::ok(['domain' => $t['domain_name'], 'cancelled' => count($rows),
            'money_back' => $results,
            'guarantee' => array_map([self::class, 'guaranteeView'], array_values($guar)),
            'entitlements' => array_map([self::class, 'entView'], self::all($sel . " WHERE be.domain_id = ? ORDER BY be.created DESC LIMIT 50", [$t['id']]))]);
    }

    // ------------------------------------------------------------------ 30-day money-back guarantee
    private static array $afterCommit = [];

    private static function guaranteeDays(): int
    {
        return max(0, min(90, (int)(self::storeCfg()['entitlements']['guarantee_days'] ?? 30)));
    }

    // Invoices paid under one PayPal subscription (the first one and its renewals).
    private static function subscriptionInvoiceIds(string $sid): array
    {
        return array_values(array_unique(array_map('strval', array_merge(
            self::col('SELECT id FROM invoice WHERE paypal_subscription_id = ?', [$sid]),
            self::col('SELECT DISTINCT invoice_id FROM payments WHERE paypal_subscription_id = ?', [$sid])))));
    }

    // Is this subscription inside its 30-day money-back window? Once per product, per person and per site:
    // the first payment ever made for the product by this person or for this site must belong to this subscription.
    private static function guaranteeInfo(string $sid): array
    {
        $inv = self::subscriptionInvoiceIds($sid);
        $out = ['paypal_subscription_id' => $sid, 'eligible' => false, 'reason' => 'no_invoice', 'first_payment_at' => null, 'window_ends' => null,
            'days' => self::guaranteeDays(), 'invoices' => $inv, 'product_name' => null, 'utc_offset_s' => 0];
        if ($inv === []) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($inv), '?'));
        $line = self::one("SELECT ip.product_id, ip.target_domain_id, i.person_id, p.name, p.entitlements FROM invoice_product ip JOIN invoice i ON i.id = ip.invoice_id
            JOIN product p ON p.id = ip.product_id WHERE ip.invoice_id IN ($in) AND p.entitlements IS NOT NULL AND p.entitlements <> '' ORDER BY i.date_created LIMIT 1", $inv);
        if (!$line) {
            return ['reason' => 'no_subscription_line'] + $out;
        }
        $first = self::one("SELECT pa.invoice_id, pa.created FROM payments pa JOIN invoice i ON i.id = pa.invoice_id JOIN invoice_product ip ON ip.invoice_id = i.id
            WHERE ip.product_id = ? AND (i.person_id = ? OR ip.target_domain_id = ?) AND pa.status IN ('COMPLETED', 'REFUNDED') ORDER BY pa.created, pa.id LIMIT 1",
            [$line['product_id'], $line['person_id'], $line['target_domain_id']]);
        $clock = self::one('SELECT NOW() AS n, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS o');
        $d = DwPayPalBilling::guaranteeDecision($first['created'] ?? null, $first !== null && in_array((string)$first['invoice_id'], $inv, true), (string)$clock['n'], self::guaranteeDays());
        return array_merge($out, $d, ['first_payment_at' => $first['created'] ?? null, 'product_name' => $line['name'], 'entitlement' => explode(',', (string)$line['entitlements'])[0], 'utc_offset_s' => (int)$clock['o']]);
    }

    private static function guaranteeView(array $g): array
    {
        $utc = function (?string $t) use ($g): ?string {
            return $t === null ? null : (new DateTimeImmutable($t, new DateTimeZone('UTC')))->modify(-$g['utc_offset_s'] . ' seconds')->format('Y-m-d\TH:i:s\Z');
        };
        return ['paypal_subscription_id' => $g['paypal_subscription_id'], 'product_name' => $g['product_name'], 'entitlement' => $g['entitlement'] ?? null, 'eligible' => $g['eligible'], 'reason' => $g['reason'],
            'guarantee_days' => $g['days'], 'first_payment_at' => $utc($g['first_payment_at']), 'refund_window_ends' => $utc($g['window_ends']),
            'refund_policy' => 'Refunds are issued only to the original payment method (PayPal). If a refund cannot be made there, no refund is given.',
            'message' => $g['eligible']
                ? 'Cancel within ' . $g['days'] . ' days of your first payment for a full refund; your access ends immediately.'
                : ($g['reason'] === 'window_passed'
                    ? 'The ' . $g['days'] . '-day money-back window has passed. If you cancel, you keep access until the end of the period you paid for; nothing is refunded.'
                    : ($g['reason'] === 'guarantee_already_used'
                        ? 'The money-back guarantee was already used for this product on this account or site. If you cancel, you keep access until the end of the period you paid for.'
                        : 'If you cancel, you keep access until the end of the period you paid for.'))];
    }

    // DB half (inside the caller's transaction): revoke every grant this subscription paid for, whatever the
    // entitlement (no_banner today, add-ons later), and mark its completed PayPal payments for refund.
    // refund_requested is set once, before PayPal is called, so a crash between the two can be retried safely.
    private static function guaranteeRevoke(string $sid, array $g, string $why): array
    {
        $note = mb_substr('30-day money-back guarantee: ' . $why, 0, 200);
        foreach ($g['invoices'] as $inv) {
            self::revokeInvoice((string)$inv, $note);
        }
        foreach (self::all("SELECT id, brand, status FROM brand_entitlement WHERE provider_ref = ? FOR UPDATE", [$sid]) as $r) {
            self::update('brand_entitlement', (string)$r['id'], ['status' => 'REVOKED', 'provider_status' => 'CANCELLED', 'note' => $note]);
            self::bumpBannerCache((string)$r['brand']);
        }
        $in = implode(',', array_fill(0, count($g['invoices']), '?'));
        $ids = [];
        foreach (self::all("SELECT id, refund_requested FROM payments WHERE invoice_id IN ($in) AND status = 'COMPLETED' AND method = 'PAYPAL' AND refund_failed IS NULL FOR UPDATE", $g['invoices']) as $p) {
            if ($p['refund_requested'] === null) {
                self::update('payments', (string)$p['id'], ['refund_requested' => self::now(), 'refund_note' => $note]);
            }
            $ids[] = (string)$p['id'];
        }
        return $ids;
    }

    // HTTP half (outside any transaction): refund each payment in full through PayPal and record the result.
    private static function guaranteeRefunds(string $sid, array $paymentIds): array
    {
        $company = self::sellerCompanyId();
        $done = [];
        $failed = [];
        foreach ($paymentIds as $pid) {
            $p = self::one('SELECT * FROM payments WHERE id = ?', [$pid]);
            if (!$p) {
                continue;
            }
            if ($p['status'] === 'REFUNDED') {
                $done[] = ['invoice_id' => $p['invoice_id'], 'amount' => $p['amount'], 'outcome' => 'already'];
                self::guaranteeInvoiceStatus((string)$p['invoice_id']);
                continue;
            }
            $sale = (string)($p['provider_ref'] ?? '');
            if (!preg_match('/^[A-Z0-9]{6,40}$/', $sale)) {
                $sale = self::findSaleId($sid, $p) ?? '';
            }
            // Only through PayPal (the platform the customer paid with). Temporary errors: up to 3 tries, same request id.
            $r = DwPayPalBilling::refundWithRetries(function (int $n) use ($company, $sale, $pid): array {
                try {
                    return DwPayPal::refundSale($company, $sale, 'dw-mbg-' . $pid);
                } catch (Throwable $e) {
                    return ['outcome' => 'failed', 'refund_id' => null, 'amount' => null, 'http' => 0, 'error' => get_class($e)];
                }
            }, 3);
            self::begin();
            $p = self::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$pid]);
            if (in_array($r['outcome'], ['refunded', 'already'], true) || $p['status'] === 'REFUNDED') {
                if ($p['status'] !== 'REFUNDED' || ($r['refund_id'] ?? '') !== '') {
                    self::update('payments', $pid, ['status' => 'REFUNDED'] + (($r['refund_id'] ?? '') !== '' ? ['refund_ref' => substr((string)$r['refund_id'], 0, 64)] : []));
                }
                $done[] = ['invoice_id' => $p['invoice_id'], 'amount' => $p['amount'], 'outcome' => $r['outcome'], 'attempts' => $r['attempts']];
            } else {
                // Final: no refund is given outside PayPal. Recorded so it is never tried again.
                self::update('payments', $pid, ['refund_failed' => self::now(),
                    'refund_note' => mb_substr('REFUND FAILED (no refund given): ' . ($r['error'] ?? 'no answer') . ' after ' . $r['attempts'] . ' tr' . ($r['attempts'] === 1 ? 'y' : 'ies'), 0, 200)]);
                $failed[] = ['invoice_id' => $p['invoice_id'], 'amount' => $p['amount'], 'outcome' => 'failed', 'error' => $r['error'] ?? null, 'attempts' => $r['attempts']];
            }
            self::guaranteeInvoiceStatus((string)$p['invoice_id']);
            self::commit();
        }
        if ($failed !== []) {
            self::guaranteeAlert($sid, $failed);
        }
        $amount = array_sum(array_map(fn($x) => self::toCents((string)$x['amount']), $done));
        return ['refunded' => $done, 'failed' => $failed, 'refunded_total' => self::money($amount), 'status' => $failed === [] ? 'REFUNDED' : 'REFUND_FAILED'];
    }

    // Invoice follows its PayPal payments: a refund PayPal could not make -> REFUND_FAILED (final, no refund given);
    // a refund still in flight -> unchanged; all refunded -> REFUNDED (also if PayPal later reports a refund it did make).
    private static function guaranteeInvoiceStatus(string $invId): void
    {
        $i = self::one('SELECT status FROM invoice WHERE id = ?', [$invId]);
        $c = self::one("SELECT SUM(refund_failed IS NOT NULL) AS f, SUM(refund_failed IS NULL AND refund_requested IS NOT NULL) AS o
            FROM payments WHERE invoice_id = ? AND status = 'COMPLETED'", [$invId]);
        $want = ((int)($c['f'] ?? 0)) > 0 ? 'REFUND_FAILED' : (((int)($c['o'] ?? 0)) > 0 ? null : 'REFUNDED');
        if ($i && $want !== null && $i['status'] !== $want && in_array($i['status'], ['PAID', 'PENDING', 'REFUND_FAILED'], true)) {
            self::update('invoice', $invId, ['status' => $want, 'last_updated' => self::now()]);
        }
    }

    private static function findSaleId(string $sid, array $p): ?string
    {
        try {
            $from = gmdate('Y-m-d\TH:i:s.000\Z', strtotime((string)$p['created'] . ' UTC') - 3 * 86400);
            $to = gmdate('Y-m-d\TH:i:s.000\Z', time() + 86400);
            foreach (DwPayPal::subscriptionSales(self::sellerCompanyId(), $sid, $from, $to) as $t) {
                if (self::toCents($t['amount']) === self::toCents((string)$p['amount'])
                    && !self::one('SELECT id FROM payments WHERE provider_ref = ? AND id <> ?', [$t['id'], $p['id']])) {
                    return $t['id'];
                }
            }
        } catch (Throwable $e) {
            error_log('store guarantee sale lookup: ' . get_class($e));
        }
        return null;
    }

    private static function guaranteeAlert(string $sid, array $failed): void
    {
        $to = (string)(self::storeCfg()['entitlements']['alert_to'] ?? '');
        if ($to === '') {
            $m = json_decode((string)@file_get_contents('/var/lib/app/data/markup.json'), true);
            $to = is_array($m) ? (string)($m['alert_to'] ?? '') : '';
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $to = DwBrand::supportEmail();
        }
        $lines = array_map(fn($f) => '- invoice ' . $f['invoice_id'] . ': ' . $f['amount'] . ' USD (' . ($f['error'] ?? 'no answer') . ', ' . $f['attempts'] . ' tr' . ($f['attempts'] === 1 ? 'y' : 'ies') . ')', $failed);
        $ok = Dw::sendMail($to, 'For information: money-back refund not possible (' . count($failed) . ' payment' . (count($failed) === 1 ? '' : 's') . ')',
            "For information only, no action needed.\n\nA 30-day money-back cancel ended access and cancelled the PayPal subscription, but PayPal could not make the refund.\n"
            . "Refunds are only issued through the platform the customer paid with, so no refund was given. The invoice is REFUND_FAILED (final).\n\nSubscription: " . $sid . "\n"
            . implode("\n", $lines) . "\n");
        error_log('store guarantee refund failed for ' . count($failed) . ' payment(s); alert ' . ($ok ? 'sent' : 'not sent'));
    }

    private static function runAfterCommit(): void
    {
        $jobs = self::$afterCommit;
        self::$afterCommit = [];
        foreach ($jobs as $j) {
            try {
                $j();
            } catch (Throwable $e) {
                if (self::$db->inTransaction()) {
                    self::$db->rollBack();
                }
                error_log('store after-commit job: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
            }
        }
    }

    // Verified PayPal API read (server-side, sandbox credentials). Used when the return page
    // loads before the webhook has settled the first payment. Does not trust the query string
    // alone: custom_id must name this invoice, amount must match, and last_payment must exist.
    // A later SALE.COMPLETED webhook attaches the real sale id instead of opening a renewal.
    private static function applyVerifiedPaypalRead(string $invId, string $sid): void
    {
        if (!preg_match('/^I-[A-Z0-9]+$/', $sid)) {
            return;
        }
        try {
            DwPayPal::requireConfiguredApi();
            $live = DwPayPal::getSubscription(self::sellerCompanyId(), $sid);
        } catch (Throwable $e) {
            error_log('store subscription_return paypal read: ' . get_class($e));
            return;
        }
        if (strtoupper((string)($live['status'] ?? '')) !== 'ACTIVE') {
            return;
        }
        $parsed = DwPayPalBilling::parseCustomId($live['custom_id'] ?? null);
        if (($parsed['invoice_id'] ?? null) !== $invId) {
            return;
        }
        if (empty($live['last_payment_amount']) || empty($live['last_payment_time'])) {
            return;
        }
        self::begin();
        $inv = self::one('SELECT * FROM invoice WHERE id = ? FOR UPDATE', [$invId]);
        if (!$inv || ($inv['status'] ?? '') !== 'PENDING') {
            self::commit();
            return;
        }
        if (self::toCents((string)$live['last_payment_amount']) !== self::toCents((string)$inv['total'])) {
            self::commit();
            error_log('store subscription_return paypal read: amount mismatch');
            return;
        }
        $marker = 'paypal-api:' . $sid . ':' . substr((string)$live['last_payment_time'], 0, 40);
        if (self::one('SELECT id FROM payments WHERE invoice_id = ? AND (provider_ref = ? OR (status = ? AND paypal_subscription_id = ?)) LIMIT 1',
            [$invId, substr($marker, 0, 100), 'COMPLETED', $sid])) {
            self::commit();
            return;
        }
        self::insert('payments', [
            'invoice_id' => $invId,
            'brand' => $inv['brand'],
            'company_id' => $inv['company_id'],
            'amount' => self::money(self::toCents((string)$live['last_payment_amount'])),
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'method' => 'PAYPAL',
            'provider_ref' => substr($marker, 0, 100),
            'paypal_subscription_id' => $sid,
            'created_by' => 'paypal_api_read',
        ]);
        self::settle($invId);
        $next = DwPayPalBilling::toUtcDatetime($live['next_billing_time'] ?? null);
        self::syncEntitlementSubscription($sid, (string)$live['status'], $next, $invId);
        self::commit();
        self::paidSubscriptionEmails($invId);
    }

    private static function rSubscription_return(bool $html = false): void
    {
        self::needBrand();
        $cancelled = trim((string)(self::$in['cancelled'] ?? '')) !== '' && (string)self::$in['cancelled'] !== '0';
        $invId = trim((string)(self::$in['invoice_id'] ?? ''));
        $qSid = trim((string)(self::$in['paypal_subscription_id'] ?? ''));
        $inv = null;
        if ($invId !== '') {
            $inv = self::one('SELECT * FROM invoice WHERE id = ? AND brand = ?', [$invId, self::$brand]);
        } elseif ($qSid !== '' && preg_match('/^I-[A-Z0-9]+$/', $qSid)) {
            $inv = self::one('SELECT * FROM invoice WHERE paypal_subscription_id = ? AND brand = ? ORDER BY date_created DESC LIMIT 1', [$qSid, self::$brand]);
        }
        if (!$inv) {
            if ($html) {
                self::subscriptionReturnHtml('not_found', null, $cancelled);
            }
            self::fail(404, 'subscription not found');
        }
        $sid = (string)($inv['paypal_subscription_id'] ?? '');
        if ($sid === '' && preg_match('/^I-[A-Z0-9]+$/', $qSid)) {
            $sid = $qSid;
        }
        if (!$cancelled && ($inv['status'] ?? '') === 'PENDING' && $sid !== '') {
            self::applyVerifiedPaypalRead((string)$inv['id'], $sid);
            $inv = self::one('SELECT * FROM invoice WHERE id = ?', [$inv['id']]) ?: $inv;
        }
        $state = 'pending';
        $ent = null;
        if ($cancelled) {
            $state = 'cancelled';
        } elseif (($inv['status'] ?? '') === 'PAID') {
            $state = 'active';
        } elseif ($sid !== '') {
            $live = null;
            try {
                $live = DwPayPal::getSubscription(self::sellerCompanyId(), $sid);
            } catch (Throwable $e) {
                $live = null;
            }
            if ($live && in_array(strtoupper((string)$live['status']), ['CANCELLED', 'EXPIRED'], true)) {
                $state = 'cancelled';
            }
        }
        $entRow = self::one("SELECT be.*, d.domain_name FROM brand_entitlement be LEFT JOIN domain d ON d.id = be.domain_id
            WHERE be.source_invoice_id = ? OR be.provider_ref = ? ORDER BY be.created DESC LIMIT 1", [$inv['id'], $sid !== '' ? $sid : null]);
        if ($entRow && $entRow['status'] === 'ACTIVE' && ($inv['status'] ?? '') === 'PAID') {
            $state = 'active';
            $ent = self::entView($entRow);
        } elseif ($entRow) {
            $ent = self::entView($entRow);
        }
        $payload = ['state' => $state, 'invoice' => self::invoiceView((string)$inv['id']), 'entitlement' => $ent,
            'paypal_subscription_id' => $sid !== '' ? $sid : null];
        if ($html) {
            self::subscriptionReturnHtml($state, $payload, $cancelled);
        }
        self::ok($payload);
    }

    private static function subscriptionReturnHtml(string $state, ?array $payload, bool $cancelled): void
    {
        $title = $cancelled || $state === 'cancelled' ? 'Subscription cancelled' : ($state === 'active' ? 'Subscription active' : ($state === 'not_found' ? 'Subscription not found' : 'Subscription pending'));
        $msg = $cancelled || $state === 'cancelled'
            ? 'You cancelled before finishing. No payment was taken. You can start again from the store when you are ready.'
            : ($state === 'active'
                ? 'Your subscription is active. The DW banner on the covered site will stay hidden for this billing period.'
                : ($state === 'not_found'
                    ? 'We could not find that subscription.'
                    : 'Your approval was received. We are waiting for PayPal to confirm the first payment. This page can be refreshed in a moment.'));
        $site = htmlspecialchars((string)(($payload['entitlement']['domain'] ?? '') ?: ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sid = htmlspecialchars((string)($payload['paypal_subscription_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $inv = htmlspecialchars((string)(($payload['invoice']['invoice_id'] ?? '') ?: ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(200);
        $refresh = ($state === 'pending' && !$cancelled) ? '<meta http-equiv="refresh" content="5">' : '';
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . $refresh . '<title>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title></head><body style="font-family:system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem;">'
            . '<h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1><p>' . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . ($site !== '' ? '<p>Covered site: ' . $site . '</p>' : '')
            . ($inv !== '' ? '<p>Order: ' . $inv . '</p>' : '')
            . ($sid !== '' ? '<p>Subscription: ' . $sid . '</p>' : '')
            . ($state === 'active' ? '<p><strong>30-day money-back guarantee:</strong> cancel within 30 days of your first payment for a full refund; your access ends immediately. Refunds go only to the original payment method (PayPal); if that isn\'t possible, no refund is given. <a href="/store/subscription_cancel/">Cancel or get a refund</a></p>' : '')
            . DwBrand::t('<p><a href="https://dwalliance.com/">Back to DW Alliance</a></p>') . '</body></html>';
        exit;
    }

}
