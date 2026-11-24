<?php
declare(strict_types=1);
// Pure helpers for PayPal Subscriptions (2026-11-14). DwStore owns the DB writes.
// custom_id format (PayPal limit 127 chars): inv={invoice_id};site={bare_domain}
// Hawaii GET: plans themselves stay untaxed; a subscription may carry a plan.billing_cycles
// quantity/price override so only Hawaii buyers pay GET. That override is sent at
// create-subscription and is expected to persist on renewals for that subscription.
final class DwPayPalBilling
{
    public const EVENTS = [
        'BILLING.SUBSCRIPTION.CREATED',
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.UPDATED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.EXPIRED',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
        'PAYMENT.SALE.COMPLETED',
        'PAYMENT.SALE.REFUNDED',
        'PAYMENT.SALE.REVERSED',
    ];

    // Result kinds used by unit tests and the webhook handler.
    public const KIND_ATTACH = 'attach';           // record sub id, no grant yet
    public const KIND_SYNC = 'sync';               // refresh status / next billing
    public const KIND_GRANT_OR_WAIT = 'grant_or_wait'; // ACTIVATED: may grant if first payment is already in
    public const KIND_SALE = 'sale';               // first payment settles; later payments renew
    public const KIND_NOTE_GRACE = 'note_grace';   // FAILED / SUSPENDED: keep grant, grace already stored
    public const KIND_CANCEL = 'cancel';           // CANCELLED / EXPIRED: status CANCELLED, grace_until = paid_through
    public const KIND_REVOKE = 'revoke';           // REFUNDED / REVERSED: revoke that period immediately
    public const KIND_IGNORE = 'ignore';

    // ---- 30-day money-back guarantee (2026-11-15). Pure, no I/O. Times are DB-clock 'Y-m-d H:i:s'.
    // Eligible when the first payment ever made for this product (by this person or for this site) belongs to
    // the subscription being cancelled, and now is no later than that payment + $days days.
    public static function guaranteeDecision(?string $firstPaymentAt, bool $firstIsThisSubscription, string $now, int $days = 30): array
    {
        if ($firstPaymentAt === null || $firstPaymentAt === '') {
            return ['eligible' => false, 'reason' => 'no_payment', 'window_ends' => null];
        }
        $end = (new DateTimeImmutable($firstPaymentAt))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
        if (!$firstIsThisSubscription) {
            return ['eligible' => false, 'reason' => 'guarantee_already_used', 'window_ends' => null];
        }
        if (strcmp($now, $end) > 0) {
            return ['eligible' => false, 'reason' => 'window_passed', 'window_ends' => $end];
        }
        return ['eligible' => true, 'reason' => 'within_window', 'window_ends' => $end];
    }

    // Map a PayPal sale-refund answer. 'already' = PayPal says it is already fully refunded (safe to record).
    public static function refundOutcome(int $http, ?array $body): array
    {
        $b = is_array($body) ? $body : [];
        $amt = is_array($b['amount'] ?? null) ? (string)($b['amount']['total'] ?? $b['amount']['value'] ?? '') : null;
        if (($http === 200 || $http === 201) && in_array(strtolower((string)($b['state'] ?? $b['status'] ?? '')), ['completed', 'pending'], true)) {
            return ['outcome' => 'refunded', 'refund_id' => (string)($b['id'] ?? ''), 'amount' => $amt, 'http' => $http, 'error' => null,
                'pending' => strtolower((string)($b['state'] ?? $b['status'] ?? '')) === 'pending'];
        }
        $name = strtoupper((string)($b['name'] ?? ''));
        $msg = strtolower((string)($b['message'] ?? ''));
        if ($name === 'TRANSACTION_ALREADY_REFUNDED' || ($name === 'TRANSACTION_REFUSED' && str_contains($msg, 'already') && str_contains($msg, 'refund'))) {
            return ['outcome' => 'already', 'refund_id' => null, 'amount' => null, 'http' => $http, 'error' => null];
        }
        return ['outcome' => 'failed', 'refund_id' => null, 'amount' => null, 'http' => $http, 'error' => $name !== '' ? $name : 'http ' . $http];
    }

    // A failed refund worth retrying with the SAME PayPal-Request-Id: no answer, 429 or 5xx. Anything else is final.
    public static function refundTransient(array $r): bool
    {
        if (($r['outcome'] ?? '') !== 'failed') {
            return false;
        }
        $h = (int)($r['http'] ?? 0);
        $e = strtoupper((string)($r['error'] ?? ''));
        if ($e === 'NO PAYPAL SALE ID FOR THIS PAYMENT') {
            return false;
        }
        return $h === 0 || $h === 429 || $h >= 500 || in_array($e, ['INTERNAL_SERVER_ERROR', 'SERVICE_UNAVAILABLE', 'RATE_LIMIT_REACHED'], true);
    }

    // Runs $attempt(n) up to $max times while the result is transient; the caller reuses one request id for every try.
    public static function refundWithRetries(callable $attempt, int $max = 3, ?callable $sleep = null): array
    {
        $sleep ??= function (int $s): void { sleep($s); };
        for ($n = 1; ; $n++) {
            $r = $attempt($n);
            $r['attempts'] = $n;
            if (!self::refundTransient($r) || $n >= $max) {
                return $r;
            }
            $sleep($n);
        }
    }

    public static function parseCustomId(?string $customId): array
    {
        $out = ['invoice_id' => null, 'site' => null];
        $s = trim((string)$customId);
        if ($s === '') {
            return $out;
        }
        foreach (explode(';', $s) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $k = strtolower(trim(substr($part, 0, $eq)));
            $v = trim(substr($part, $eq + 1));
            if ($k === 'inv' && $v !== '') {
                $out['invoice_id'] = $v;
            } elseif ($k === 'site' && $v !== '') {
                $out['site'] = $v;
            }
        }
        return $out;
    }

    public static function customId(string $invoiceId, string $bareDomain): string
    {
        $s = 'inv=' . $invoiceId . ';site=' . $bareDomain;
        return mb_substr($s, 0, 127);
    }

    public static function subscriptionIdFromEvent(array $event): ?string
    {
        $res = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        foreach (['id', 'billing_agreement_id'] as $k) {
            $v = (string)($res[$k] ?? '');
            if (preg_match('/^I-[A-Z0-9]+$/', $v)) {
                return $v;
            }
        }
        $sid = (string)($res['billing_agreement_id'] ?? $res['subscription_id'] ?? '');
        if (preg_match('/^I-[A-Z0-9]+$/', $sid)) {
            return $sid;
        }
        // PAYMENT.SALE.* often puts the subscription id in billing_agreement_id.
        $ba = (string)($res['billing_agreement_id'] ?? '');
        return preg_match('/^I-[A-Z0-9]+$/', $ba) ? $ba : null;
    }

    public static function saleIdFromEvent(array $event): ?string
    {
        $res = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $type = (string)($event['event_type'] ?? '');
        if (str_starts_with($type, 'PAYMENT.SALE.')) {
            $id = (string)($res['id'] ?? '');
            return $id !== '' ? $id : null;
        }
        // SALE sometimes nests under billing_info / last payment; not used as sale id.
        return null;
    }

    public static function customIdFromEvent(array $event): ?string
    {
        $res = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $c = $res['custom_id'] ?? $res['custom'] ?? null;
        if (is_string($c) && $c !== '') {
            return $c;
        }
        return null;
    }

    public static function eventId(array $event): ?string
    {
        $id = (string)($event['id'] ?? '');
        return $id !== '' ? $id : null;
    }

    public static function eventType(array $event): string
    {
        return (string)($event['event_type'] ?? '');
    }

    public static function kind(string $eventType): string
    {
        return match ($eventType) {
            'BILLING.SUBSCRIPTION.CREATED' => self::KIND_ATTACH,
            'BILLING.SUBSCRIPTION.UPDATED' => self::KIND_SYNC,
            'BILLING.SUBSCRIPTION.ACTIVATED' => self::KIND_GRANT_OR_WAIT,
            'BILLING.SUBSCRIPTION.SUSPENDED',
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => self::KIND_NOTE_GRACE,
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED' => self::KIND_CANCEL,
            'PAYMENT.SALE.COMPLETED' => self::KIND_SALE,
            'PAYMENT.SALE.REFUNDED',
            'PAYMENT.SALE.REVERSED' => self::KIND_REVOKE,
            default => self::KIND_IGNORE,
        };
    }

    public static function known(string $eventType): bool
    {
        return in_array($eventType, self::EVENTS, true);
    }

    // PayPal ISO time → UTC DATETIME string, or null.
    public static function toUtcDatetime(?string $iso): ?string
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($iso);
        } catch (Exception $e) {
            return null;
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function statusFromEvent(array $event): ?string
    {
        $res = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $st = strtoupper((string)($res['status'] ?? ''));
        $ok = ['APPROVAL_PENDING', 'APPROVED', 'ACTIVE', 'SUSPENDED', 'CANCELLED', 'EXPIRED', 'PENDING'];
        return in_array($st, $ok, true) ? $st : null;
    }

    public static function nextBillingFromEvent(array $event): ?string
    {
        $res = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $bi = is_array($res['billing_info'] ?? null) ? $res['billing_info'] : [];
        return self::toUtcDatetime(isset($bi['next_billing_time']) ? (string)$bi['next_billing_time'] : null);
    }

    public static function saleAmount(array $event): ?string
    {
        $res = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $amt = $res['amount'] ?? null;
        if (is_array($amt) && isset($amt['total'])) {
            return (string)$amt['total'];
        }
        if (is_array($amt) && isset($amt['value'])) {
            return (string)$amt['value'];
        }
        return null;
    }

    public static function saleCurrency(array $event): string
    {
        $res = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $amt = $res['amount'] ?? null;
        if (is_array($amt) && !empty($amt['currency'])) {
            return (string)$amt['currency'];
        }
        if (is_array($amt) && !empty($amt['currency_code'])) {
            return (string)$amt['currency_code'];
        }
        return 'USD';
    }

    // For a given event, which existing sale_id should be refunded/reversed (the parent sale).
    public static function parentSaleId(array $event): ?string
    {
        $res = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $sid = (string)($res['sale_id'] ?? $res['parent_payment'] ?? '');
        if ($sid !== '') {
            return $sid;
        }
        // Refund resource often uses id as the refund id; sale_id is the original sale.
        return null;
    }

    /**
     * Decide the handler action for an already-verified, already-idempotency-checked event.
     * Pure: no I/O. $ctx is a snapshot the store fills in:
     *   invoice_found, invoice_status, payment_exists (sale already recorded),
     *   entitlement_active, first_payment_done (any COMPLETED payment on this sub).
     */
    public static function decide(string $eventType, array $ctx = []): array
    {
        $kind = self::kind($eventType);
        if ($kind === self::KIND_IGNORE) {
            return ['kind' => $kind, 'action' => 'ignore', 'reason' => 'unhandled_event'];
        }
        if ($kind === self::KIND_ATTACH) {
            return ['kind' => $kind, 'action' => 'attach_subscription', 'reason' => 'created'];
        }
        if ($kind === self::KIND_SYNC) {
            return ['kind' => $kind, 'action' => 'sync_status', 'reason' => 'updated'];
        }
        if ($kind === self::KIND_GRANT_OR_WAIT) {
            if (!empty($ctx['first_payment_done'])) {
                return ['kind' => $kind, 'action' => 'grant_if_needed', 'reason' => 'activated_payment_known'];
            }
            return ['kind' => $kind, 'action' => 'attach_and_wait', 'reason' => 'activated_await_sale'];
        }
        if ($kind === self::KIND_SALE) {
            if (!empty($ctx['payment_exists'])) {
                return ['kind' => $kind, 'action' => 'noop_duplicate_sale', 'reason' => 'sale_already_recorded'];
            }
            if (!empty($ctx['first_payment_done'])) {
                return ['kind' => $kind, 'action' => 'renew', 'reason' => 'subsequent_sale'];
            }
            return ['kind' => $kind, 'action' => 'settle_first', 'reason' => 'first_sale'];
        }
        if ($kind === self::KIND_NOTE_GRACE) {
            return ['kind' => $kind, 'action' => 'note_grace', 'reason' => 'failure_or_suspend'];
        }
        if ($kind === self::KIND_CANCEL) {
            return ['kind' => $kind, 'action' => 'cancel_entitlement', 'reason' => 'cancelled_or_expired'];
        }
        if ($kind === self::KIND_REVOKE) {
            return ['kind' => $kind, 'action' => 'revoke_period', 'reason' => 'refund_or_reversal'];
        }
        return ['kind' => $kind, 'action' => 'ignore', 'reason' => 'fallback'];
    }
}
