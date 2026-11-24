<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// PayPal helper (2026-11-14). OFF unless /var/lib/app/data/store.json has paypal.enabled true AND the
// company's store_setting.paypal_enabled is 1 (DwStore checks both before calling in).
// Credentials live in a root-owned file outside every web root:
//   <paypal.credentials_dir>/default.json or <company_id>.json
//   {"client_id":"...","client_secret":"...","webhook_id":"..."}.
// They are never sent in HTTP JSON, never logged, never committed. Sandbox first: paypal.env stays "sandbox"
// until it is explicitly set to "live".
final class DwPayPalError extends RuntimeException
{
    public int $http;

    public function __construct(string $msg, int $http = 502)
    {
        parent::__construct($msg);
        $this->http = $http;
    }
}

final class DwPayPal
{
    private static function cfg(): array
    {
        $p = DwStore::storeCfg()['paypal'] ?? [];
        return is_array($p) ? $p : [];
    }

    private static function base(): string
    {
        return (self::cfg()['env'] ?? 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    // Callers must talk to the host that matches store.json paypal.env (sandbox or live).
    public static function requireConfiguredApi(): void
    {
        $env = ((self::cfg()['env'] ?? 'sandbox') === 'live') ? 'live' : 'sandbox';
        $host = parse_url(self::base(), PHP_URL_HOST);
        $want = $env === 'live' ? 'api-m.paypal.com' : 'api-m.sandbox.paypal.com';
        if ($host !== $want) {
            throw new DwPayPalError('PayPal is not in the configured mode', 503);
        }
    }

    private static function credentialsFile(string $companyId): ?array
    {
        $dir = (string)(self::cfg()['credentials_dir'] ?? '/var/lib/app/data/paypal');
        foreach ([$companyId, 'default'] as $name) {
            if (!preg_match('/^[0-9a-zA-Z._-]{1,50}$/', $name)) {
                continue;
            }
            $raw = @file_get_contents($dir . '/' . $name . '.json');
            $j = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($j) && !empty($j['client_id']) && !empty($j['client_secret'])) {
                $wh = $j['webhook_id'] ?? null;
                if (!is_string($wh) || $wh === '') {
                    $sib = @file_get_contents($dir . '/webhook-sandbox.json');
                    $s = is_string($sib) ? json_decode($sib, true) : null;
                    if (is_array($s) && !empty($s['webhook_id'])) {
                        $wh = (string)$s['webhook_id'];
                    }
                }
                return [(string)$j['client_id'], (string)$j['client_secret'], is_string($wh) ? $wh : ''];
            }
        }
        return null;
    }

    private static function credentials(string $companyId): array
    {
        $c = self::credentialsFile($companyId);
        if ($c === null) {
            throw new DwPayPalError('PayPal is not set up for this store yet', 503);
        }
        return $c;
    }

    public static function publicClientId(string $companyId): string
    {
        return self::credentials($companyId)[0];
    }

    public static function webhookId(string $companyId): string
    {
        $id = self::credentials($companyId)[2];
        if ($id === '') {
            throw new DwPayPalError('PayPal webhook is not set up yet', 503);
        }
        return $id;
    }

    private static function call(string $method, string $path, ?array $body, array $headers): array
    {
        $ch = curl_init(self::base() . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if ($code < 200 || $code >= 300 || !is_array($j)) {
            error_log('paypal ' . $path . ' http ' . $code); // never log the body or credentials
            throw new DwPayPalError('PayPal did not answer as expected', 502);
        }
        return $j;
    }

    // Returns HTTP code and parsed JSON (or null). Used when 204/404 are meaningful (cancel, delete).
    private static function callCode(string $method, string $path, ?array $body, array $headers): array
    {
        $ch = curl_init(self::base() . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        return [$code, is_array($j) ? $j : null];
    }

    private static function token(string $companyId): string
    {
        [$id, $secret] = self::credentials($companyId);
        $ch = curl_init(self::base() . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERPWD => $id . ':' . $secret,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if ($code !== 200 || empty($j['access_token'])) {
            throw new DwPayPalError('PayPal sign-in failed', 502);
        }
        return (string)$j['access_token'];
    }

    private static function bearer(string $companyId): array
    {
        return ['Content-Type: application/json', 'Authorization: Bearer ' . self::token($companyId)];
    }

    // Orders v2: create an order for the invoice balance. Returns ['id', 'approve_url'].
    public static function createOrder(string $companyId, string $invoiceId, string $amount): array
    {
        $j = self::call('POST', '/v2/checkout/orders', ['intent' => 'CAPTURE', 'purchase_units' => [[
            'custom_id' => $invoiceId, 'invoice_id' => $invoiceId, 'amount' => ['currency_code' => 'USD', 'value' => $amount]]]],
            self::bearer($companyId) + [2 => 'PayPal-Request-Id: create-' . $invoiceId . '-' . $amount]);
        $approve = null;
        foreach ((array)($j['links'] ?? []) as $l) {
            if (($l['rel'] ?? '') === 'approve' || ($l['rel'] ?? '') === 'payer-action') {
                $approve = (string)$l['href'];
            }
        }
        return ['id' => (string)($j['id'] ?? ''), 'approve_url' => $approve];
    }

    // Orders v2: capture server-side.
    public static function captureOrder(string $companyId, string $orderId): array
    {
        if (!preg_match('/^[A-Z0-9]{5,64}$/', $orderId)) {
            throw new DwPayPalError('paypal_order_id is not valid', 400);
        }
        $j = self::call('POST', '/v2/checkout/orders/' . $orderId . '/capture', null,
            array_merge(self::bearer($companyId), ['PayPal-Request-Id: capture-' . $orderId]));
        $pu = $j['purchase_units'][0] ?? [];
        $cap = $pu['payments']['captures'][0] ?? [];
        return ['status' => (string)($cap['status'] ?? $j['status'] ?? ''), 'amount' => (string)($cap['amount']['value'] ?? ''),
            'capture_id' => (string)($cap['id'] ?? ''), 'invoice_id' => (string)($cap['invoice_id'] ?? $pu['invoice_id'] ?? $pu['custom_id'] ?? '')];
    }

    // Billing Subscriptions: create. $body is the API payload (plan_id, custom_id, application_context, optional plan override).
    // Returns id, status, approve_url. Never returns the access token.
    public static function createSubscription(string $companyId, array $body): array
    {
        if (!preg_match('/^P-[A-Z0-9]+$/', (string)($body['plan_id'] ?? ''))) {
            throw new DwPayPalError('paypal plan_id is not valid', 400);
        }
        $j = self::call('POST', '/v1/billing/subscriptions', $body, self::bearer($companyId));
        $approve = null;
        foreach ((array)($j['links'] ?? []) as $l) {
            if (($l['rel'] ?? '') === 'approve') {
                $approve = (string)$l['href'];
            }
        }
        return [
            'id' => (string)($j['id'] ?? ''),
            'status' => (string)($j['status'] ?? ''),
            'approve_url' => $approve,
            'custom_id' => (string)($j['custom_id'] ?? $body['custom_id'] ?? ''),
            'plan_id' => (string)($j['plan_id'] ?? $body['plan_id']),
        ];
    }

    public static function getSubscription(string $companyId, string $subscriptionId): array
    {
        if (!preg_match('/^I-[A-Z0-9]+$/', $subscriptionId)) {
            throw new DwPayPalError('paypal_subscription_id is not valid', 400);
        }
        $j = self::call('GET', '/v1/billing/subscriptions/' . $subscriptionId, null, self::bearer($companyId));
        $billing = is_array($j['billing_info'] ?? null) ? $j['billing_info'] : [];
        $last = is_array($billing['last_payment'] ?? null) ? $billing['last_payment'] : [];
        $amt = is_array($last['amount'] ?? null) ? $last['amount'] : [];
        return [
            'id' => (string)($j['id'] ?? $subscriptionId),
            'status' => (string)($j['status'] ?? ''),
            'plan_id' => (string)($j['plan_id'] ?? ''),
            'custom_id' => (string)($j['custom_id'] ?? ''),
            'next_billing_time' => isset($billing['next_billing_time']) ? (string)$billing['next_billing_time'] : null,
            'last_payment_time' => isset($last['time']) ? (string)$last['time'] : null,
            'last_payment_amount' => isset($amt['value']) ? (string)$amt['value'] : null,
            'last_payment_currency' => isset($amt['currency_code']) ? (string)$amt['currency_code'] : null,
        ];
    }

    // Cancel. Already-cancelled is treated as success (idempotent).
    public static function cancelSubscription(string $companyId, string $subscriptionId, string $reason = 'Cancelled by the site owner'): void
    {
        if (!preg_match('/^I-[A-Z0-9]+$/', $subscriptionId)) {
            throw new DwPayPalError('paypal_subscription_id is not valid', 400);
        }
        $reason = mb_substr(trim($reason), 0, 250);
        if ($reason === '') {
            $reason = 'Cancelled by the site owner';
        }
        [$code] = self::callCode('POST', '/v1/billing/subscriptions/' . $subscriptionId . '/cancel',
            ['reason' => $reason], self::bearer($companyId));
        if (in_array($code, [200, 204, 404], true)) {
            return;
        }
        // 422 RESOURCE_ALREADY_CANCELLED etc.
        if ($code === 422) {
            return;
        }
        error_log('paypal cancel subscription http ' . $code);
        throw new DwPayPalError('PayPal did not cancel the subscription', 502);
    }

    // Verify a webhook. $headers are the PayPal transmission headers; $raw is the exact body string.
    // Returns true only when verification_status is SUCCESS.
    // Full refund of one subscription payment (v1 sale). PayPal-Request-Id makes a retry with the same
    // $requestId return the first result instead of refunding twice. "Already fully refunded" counts as done.
    // Returns ['outcome' => refunded|already|failed, 'refund_id', 'amount', 'http', 'error'].
    public static function refundSale(string $companyId, string $saleId, string $requestId, string $note = 'DW Alliance 30-day money-back guarantee'): array
    {
        $note = DwBrand::t($note);
        if (!preg_match('/^[A-Z0-9]{6,40}$/', $saleId)) {
            return ['outcome' => 'failed', 'refund_id' => null, 'amount' => null, 'http' => 0, 'error' => 'no PayPal sale id for this payment'];
        }
        $h = self::bearer($companyId);
        $h[] = 'PayPal-Request-Id: ' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', $requestId), 0, 100);
        [$code, $j] = self::callCode('POST', '/v1/payments/sale/' . $saleId . '/refund', ['description' => mb_substr($note, 0, 250)], $h);
        return DwPayPalBilling::refundOutcome($code, $j);
    }

    // Completed payments (sale ids) of a subscription between two UTC times. Used when a payment was
    // recorded from the return page (provider_ref = paypal-api:… marker) before its sale id was known.
    public static function subscriptionSales(string $companyId, string $subscriptionId, string $startUtc, string $endUtc): array
    {
        if (!preg_match('/^I-[A-Z0-9]+$/', $subscriptionId)) {
            throw new DwPayPalError('paypal_subscription_id is not valid', 400);
        }
        $j = self::call('GET', '/v1/billing/subscriptions/' . $subscriptionId . '/transactions?start_time=' . rawurlencode($startUtc) . '&end_time=' . rawurlencode($endUtc),
            null, self::bearer($companyId));
        $out = [];
        foreach ((array)($j['transactions'] ?? []) as $t) {
            if (strtoupper((string)($t['status'] ?? '')) === 'COMPLETED' && !empty($t['id'])) {
                $out[] = ['id' => (string)$t['id'], 'amount' => (string)($t['amount_with_breakdown']['gross_amount']['value'] ?? ''), 'time' => (string)($t['time'] ?? '')];
            }
        }
        return $out;
    }

    public static function verifyWebhook(string $companyId, array $headers, string $raw): bool
    {
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            return false;
        }
        $algo = (string)($headers['paypal-auth-algo'] ?? $headers['PAYPAL-AUTH-ALGO'] ?? '');
        $cert = (string)($headers['paypal-cert-url'] ?? $headers['PAYPAL-CERT-URL'] ?? '');
        $tid = (string)($headers['paypal-transmission-id'] ?? $headers['PAYPAL-TRANSMISSION-ID'] ?? '');
        $sig = (string)($headers['paypal-transmission-sig'] ?? $headers['PAYPAL-TRANSMISSION-SIG'] ?? '');
        $time = (string)($headers['paypal-transmission-time'] ?? $headers['PAYPAL-TRANSMISSION-TIME'] ?? '');
        if ($algo === '' || $cert === '' || $tid === '' || $sig === '' || $time === '') {
            return false;
        }
        $host = strtolower((string)parse_url($cert, PHP_URL_HOST));
        if (!in_array($host, ['api.paypal.com', 'api.sandbox.paypal.com', 'api-m.paypal.com', 'api-m.sandbox.paypal.com'], true)) {
            return false;
        }
        $scheme = parse_url($cert, PHP_URL_SCHEME);
        if ($scheme !== 'https') {
            return false;
        }
        try {
            $j = self::call('POST', '/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $algo,
                'cert_url' => $cert,
                'transmission_id' => $tid,
                'transmission_sig' => $sig,
                'transmission_time' => $time,
                'webhook_id' => self::webhookId($companyId),
                'webhook_event' => $event,
            ], self::bearer($companyId));
        } catch (DwPayPalError $e) {
            return false;
        }
        return ($j['verification_status'] ?? '') === 'SUCCESS';
    }
}
