<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// Affiliate program for AI assistants (refs #74, staging 2026-11-22).
// OAuth Bearer access to /affiliate/join/ (self-service) and /affiliate/ (staff), with the same checks a person has.
//
//   affiliate:join      the signed-in person: join, channels, own status/referrals/commissions, referral link BY EMAIL
//   affiliate:manage    brand SUPER_ADMIN or AFFILIATE staff (live data_access): read, default rate, program
//                       settings (not the client discount = pricing), per-affiliate overrides, transfer REQUESTS
//   affiliate:transfer  staff only: approve or reject a pending transfer request (approved_by = that person)
//
// Brand: the request Host via DwAffiliate::requestBrand(); a token issued for another brand is refused (403).
// On the app host (app.dwalliance.com), which has no brand, the token's own brand is used, as /files does.
// Codes, tokens, secrets, session ids and brand codes are never returned in JSON: the referral code and link go
// to the affiliate's own email address only.
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';
require_once '/var/www/html/lib/DwAffiliate.php';
require_once '/var/www/html/lib/DwOAuth.php';

final class DwAffiliateApi
{
    const SCOPE_JOIN = 'affiliate:join';
    const SCOPE_MANAGE = 'affiliate:manage';
    const SCOPE_TRANSFER = 'affiliate:transfer';
    const STAFF_SCOPES = [self::SCOPE_MANAGE, self::SCOPE_TRANSFER];
    /** Program settings an API caller may change. Client discount (what referred clients pay) is pricing: dashboard only. */
    const API_SETTINGS = ['program_enabled', 'default_commission_percent', 'percent', 'terms'];
    const PRICING_KEYS = ['client_discount_percent', 'client_discount_months'];
    /** Never in API JSON. */
    const SECRET_KEYS = ['code', 'token', 'secret', 'secret_hash', 'visitor_token', 'ip_hash', 'session', 'session_id', 'brand', 'cookie', 'v', 'link'];

    private static ?array $ctx = null;

    public static function hasBearer(): bool
    {
        return DwOAuth::bearer() !== null;
    }

    public static function deny(int $code, string $err, string $desc): never
    {
        if ($code === 401 || $err === 'insufficient_scope') {
            header('WWW-Authenticate: Bearer realm="dwalliance", error="' . $err . '"');
        }
        Dw::jsonOut($code, ['ok' => false, 'error' => $desc, 'oauth_error' => $err]);
        exit;
    }

    /**
     * Bearer context for $scope, or null when no Authorization header was sent (old session rules apply).
     * Bad/expired token 401 and missing scope 403 answer and exit inside DwOAuth::session().
     * @return array{person_id:string,brand:string,via:string,scopes:list<string>}|null
     */
    public static function context(PDO $db, string $scope): ?array
    {
        if (!self::hasBearer()) {
            return null;
        }
        $s = DwOAuth::session($scope);
        if ($s === null) {
            return null;
        }
        $grantBrand = (string)$s['brand'];
        $host = DwOAuth::host();
        if (!DwDomain::isAppHost($host)) {
            $hostBrand = DwAffiliate::requestBrand($db, $host);
            if ($hostBrand === null || !hash_equals($grantBrand, $hostBrand)) {
                self::deny(403, 'wrong_site', 'this connection belongs to a different website; connect again on this website');
            }
        }
        $scopes = preg_split('/\s+/', trim((string)($s['oauth_scope'] ?? ''))) ?: [];
        if (in_array($scope, self::STAFF_SCOPES, true) && !DwAffiliate::canManage($db, (string)$s['person_id'], $grantBrand)) {
            self::deny(403, 'not_staff', 'this account needs SUPER_ADMIN or the affiliate permission on this website');
        }
        // The rest of this request sees the token's brand (saveProfile, findAffiliateByEmail, ...).
        DwAffiliate::pinRequestBrand($grantBrand);
        return self::$ctx = [
            'person_id' => (string)$s['person_id'],
            'brand' => $grantBrand,
            'via' => 'oauth:' . (string)($s['oauth_client'] ?? ''),
            'scopes' => array_values($scopes),
        ];
    }

    public static function current(): ?array
    {
        return self::$ctx;
    }

    /** Remove secrets (codes, tokens, brand codes, ...) from anything an API caller gets back. */
    public static function clean(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            if (is_string($k) && (in_array(strtolower($k), self::SECRET_KEYS, true) || str_ends_with(strtolower($k), '_code'))) {
                continue;
            }
            $out[$k] = self::clean($x);
        }
        return $out;
    }

    // ------------------------------------------------------------------ self-service (affiliate:join)

    /** Primary email of a person on a brand. */
    public static function personEmail(PDO $db, string $personId, string $brand): ?string
    {
        $q = $db->prepare("SELECT email FROM email WHERE owner_id = ? AND owner_type = 'PRSN' AND brand = ? ORDER BY created LIMIT 1");
        $q->execute([$personId, $brand]);
        $e = $q->fetchColumn();
        return is_string($e) && $e !== '' ? $e : null;
    }

    /** Join-page session shape for a Bearer person (their email was verified when they signed in to connect). */
    public static function joinSession(PDO $db, array $ctx): array
    {
        $email = self::personEmail($db, $ctx['person_id'], $ctx['brand']);
        if ($email === null) {
            self::deny(403, 'no_email', 'this account has no email on this website');
        }
        $found = DwAffiliate::findAffiliateByEmail($db, $email, $ctx['brand']);
        return ['id' => '', 'email' => $email, 'affiliate_id' => $found['affiliate_id'] ?? null];
    }

    /** @return array<string,mixed> */
    public static function me(PDO $db, ?string $affiliateId, string $brand): array
    {
        $settings = DwAffiliate::programSettings($db, $brand);
        $program = [
            'enabled' => $settings['program_enabled'],
            'client_discount_percent' => $settings['client_discount_percent'],
            'client_discount_months' => $settings['client_discount_months'],
            'terms' => $settings['terms'],
        ];
        if ($affiliateId === null) {
            return ['ok' => true, 'joined' => false, 'program' => $program,
                'next' => 'call joinAffiliateProgram with first_name and last_name (company_name optional)'];
        }
        $q = $db->prepare(
            'SELECT a.affiliate_id, a.status, a.created, a.commission_rate_override, a.owner_person_id, a.person_id,
                    p.first_name, p.last_name, c.company_name
               FROM affiliate a JOIN person p ON p.id = a.person_id LEFT JOIN company c ON c.id = a.company_id
              WHERE a.affiliate_id = ? AND p.brand = ?'
        );
        $q->execute([$affiliateId, $brand]);
        $a = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($a)) {
            return ['ok' => true, 'joined' => false, 'program' => $program];
        }
        $ch = $db->prepare('SELECT affiliate_channel_id, type, handle, created FROM affiliate_channel WHERE affiliate_id = ? ORDER BY created, affiliate_channel_id');
        $ch->execute([$affiliateId]);
        $cnt = $db->prepare('SELECT COUNT(*) AS total, COUNT(converted_at) AS converted FROM affiliate_referral WHERE affiliate_id = ?');
        $cnt->execute([$affiliateId]);
        $c = $cnt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'converted' => 0];
        return [
            'ok' => true,
            'joined' => true,
            'affiliate' => [
                'affiliate_id' => (string)$a['affiliate_id'],
                'status' => (string)$a['status'],
                'created' => (string)$a['created'],
                'first_name' => (string)$a['first_name'],
                'last_name' => (string)$a['last_name'],
                'company_name' => (string)($a['company_name'] ?? ''),
                'owned_by_someone_else' => (string)$a['owner_person_id'] !== (string)$a['person_id'],
                'commission_percent_for_new_referrals' => DwAffiliate::resolvedCommissionPercent($db, $affiliateId),
                'channels' => $ch->fetchAll(PDO::FETCH_ASSOC) ?: [],
                'referrals' => ['total' => (int)$c['total'], 'converted' => (int)$c['converted']],
                'referral_link' => 'sent by email only: call emailMyReferralLink',
            ],
            'program' => $program,
        ];
    }

    /** @return array<string,mixed> */
    public static function referrals(PDO $db, string $affiliateId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $q = $db->prepare(
            "SELECT r.created, r.source_type, r.landing_url, r.commission_percent, r.converted_at, ch.type AS channel_type
               FROM affiliate_referral r LEFT JOIN affiliate_channel ch ON ch.affiliate_channel_id = r.affiliate_channel_id
              WHERE r.affiliate_id = ? ORDER BY r.created DESC LIMIT $limit OFFSET $offset"
        );
        $q->execute([$affiliateId]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $host = parse_url((string)($r['landing_url'] ?? ''), PHP_URL_HOST);
            $rows[] = [
                'created' => (string)$r['created'],
                'source_type' => (string)$r['source_type'],
                'channel_type' => $r['channel_type'] !== null ? (string)$r['channel_type'] : null,
                'landing_host' => is_string($host) ? strtolower($host) : null,
                'commission_percent' => (string)$r['commission_percent'],
                'converted' => $r['converted_at'] !== null,
                'converted_at' => $r['converted_at'] !== null ? (string)$r['converted_at'] : null,
            ];
        }
        $t = $db->prepare('SELECT COUNT(*) FROM affiliate_referral WHERE affiliate_id = ?');
        $t->execute([$affiliateId]);
        return ['ok' => true, 'total' => (int)$t->fetchColumn(), 'limit' => $limit, 'offset' => $offset, 'referrals' => $rows];
    }

    /** @return array<string,mixed> */
    public static function commissionSummary(PDO $db, string $affiliateId): array
    {
        $q = $db->prepare('SELECT status, COUNT(*) AS n, COALESCE(SUM(commission), 0) AS amount FROM affiliate_commission WHERE affiliate_id = ? GROUP BY status ORDER BY status');
        $q->execute([$affiliateId]);
        $by = [];
        $total = 0.0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $by[] = ['status' => (string)$r['status'], 'count' => (int)$r['n'], 'amount' => number_format((float)$r['amount'], 2, '.', '')];
            if ((string)$r['status'] !== 'reversed') {
                $total += (float)$r['amount'];
            }
        }
        return ['ok' => true, 'currency' => 'USD', 'by_status' => $by, 'total_not_reversed' => number_format($total, 2, '.', ''),
            'commission_percent_for_new_referrals' => DwAffiliate::resolvedCommissionPercent($db, $affiliateId)];
    }

    /** Email the affiliate their referral code and link (never returned in JSON). */
    public static function emailReferralLink(PDO $db, string $affiliateId, string $brand, string $context = 'app'): array
    {
        $q = $db->prepare('SELECT a.code, a.status, a.person_id FROM affiliate a JOIN person p ON p.id = a.person_id WHERE a.affiliate_id = ? AND p.brand = ?');
        $q->execute([$affiliateId, $brand]);
        $a = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($a)) {
            return ['ok' => false, 'error' => 'join the affiliate program first'];
        }
        if ((string)$a['status'] !== 'active') {
            return ['ok' => false, 'error' => 'your affiliate account is not active'];
        }
        $to = self::personEmail($db, (string)$a['person_id'], $brand);
        $host = DwAffiliate::brandPublicHost($db, $brand);
        if ($to === null || $host === null) {
            return ['ok' => false, 'error' => 'no email or website for this affiliate account'];
        }
        $code = (string)$a['code'];
        $link = 'https://' . $host . '/affiliate/r/?ref=' . rawurlencode($code);
        $name = DwAffiliate::brandDisplayName($db, $brand);
        $chq = $db->prepare('SELECT type, handle, code FROM affiliate_channel WHERE affiliate_id = ? ORDER BY created');
        $chq->execute([$affiliateId]);
        $chLines = '';
        foreach ($chq->fetchAll(PDO::FETCH_ASSOC) as $ch) {
            $chLines .= '- ' . (string)$ch['type'] . ' ' . (string)$ch['handle'] . ': ' . (string)$ch['code']
                . '  https://' . $host . '/affiliate/r/?ref=' . rawurlencode((string)$ch['code']) . "\n";
        }
        $why = match ($context) {
            'join' => "You joined the {$name} affiliate program at https://{$host}/affiliate/join/. Codes are sent only by email; keep this message.\n",
            'staff' => "A staff member of {$name} sent you this from the affiliate dashboard. Codes are sent only by email; keep this message.\n",
            'page' => "You asked for this on the affiliate join page at https://{$host}/affiliate/join/.\n",
            default => "You asked for this through a connected app. If you did not, you can disconnect apps at https://{$host}/oauth/connections/\n",
        };
        $body = "Your {$name} affiliate referral link:\n\n{$link}\n\nYour referral code: {$code}\n\n"
            . ($chLines !== '' ? "Your channel codes (one per channel, so reporting is clearer):\n{$chLines}\n" : '')
            . "Share the link. Visitors who arrive through it are credited to you.\n"
            . $why;
        $sent = Dw::sendMail($to, 'Your ' . $name . ' affiliate link', $body);
        return $sent ? ['ok' => true, 'mailed' => true, 'note' => 'The referral link and code were sent to your email address.']
            : ['ok' => false, 'error' => 'could not send the email; try again later'];
    }

    // ------------------------------------------------------------------ staff (affiliate:manage / affiliate:transfer)

    /**
     * Email a recorded ownership transfer's code to the staff member who recorded or approved it (refs #74).
     * Codes go out only by email; callers return just status and id.
     */
    public static function emailTransferCode(PDO $db, string $brand, string $staffPersonId, string $transferId): bool
    {
        $q = $db->prepare('SELECT t.code, t.reason, t.effective_at, a.affiliate_id FROM affiliate_ownership_transfer t JOIN affiliate a ON a.affiliate_id = t.affiliate_id WHERE t.id = ?');
        $q->execute([$transferId]);
        $t = $q->fetch(PDO::FETCH_ASSOC);
        $to = self::personEmail($db, $staffPersonId, $brand);
        if (!is_array($t) || $to === null) {
            return false;
        }
        $host = DwAffiliate::brandPublicHost($db, $brand) ?? DwBrand::domain();
        $name = DwAffiliate::brandDisplayName($db, $brand);
        $body = "An affiliate ownership transfer was recorded on {$name}.\n\n"
            . 'Transfer code: ' . (string)$t['code'] . "\n"
            . 'Transfer id: ' . $transferId . "\n"
            . 'Affiliate id: ' . (string)$t['affiliate_id'] . "\n"
            . 'Reason: ' . (string)$t['reason'] . "\n"
            . 'Effective: ' . (string)$t['effective_at'] . "\n\n"
            . "Transfer codes are sent only by email. The affiliate dashboard is at https://{$host}/affiliate/\n";
        return Dw::sendMail($to, $name . ' affiliate transfer recorded', $body);
    }

    /** Program settings write for an API caller: only API_SETTINGS; everything else keeps its current value. */
    public static function apiProgramSettings(PDO $db, string $brand, array $body, string $personId): array
    {
        foreach (self::PRICING_KEYS as $k) {
            if (array_key_exists($k, $body)) {
                return ['ok' => false, 'http' => 403, 'error' => 'client discount is pricing: a person changes it in the affiliate dashboard'];
            }
        }
        $cur = DwAffiliate::programSettings($db, $brand);
        $in = [
            'program_enabled' => $cur['program_enabled'] ? 1 : 0,
            'default_commission_percent' => $cur['default_commission_percent'],
            'client_discount_percent' => $cur['client_discount_percent'],
            'client_discount_months' => $cur['client_discount_months'],
            'terms' => $cur['terms_custom'] ? $cur['terms'] : '',
        ];
        $any = false;
        foreach (self::API_SETTINGS as $k) {
            if (array_key_exists($k, $body)) {
                $in[$k === 'percent' ? 'default_commission_percent' : $k] = $body[$k];
                $any = true;
            }
        }
        if (!$any) {
            return ['ok' => false, 'error' => 'send at least one of program_enabled, default_commission_percent, terms'];
        }
        return DwAffiliate::setProgramSettings($db, $brand, $in, $personId);
    }

    /** @return array<string,mixed> */
    public static function staffRead(PDO $db, string $brand): array
    {
        $settings = DwAffiliate::programSettings($db, $brand);
        $gate = DwAffiliate::gateCheck($db, $brand);
        $aq = $db->prepare(
            "SELECT a.affiliate_id, a.status, a.created, a.commission_rate_override, a.person_id, a.owner_person_id,
                    p.first_name, p.last_name, op.first_name AS owner_first, op.last_name AS owner_last,
                    (SELECT COUNT(*) FROM affiliate_referral r WHERE r.affiliate_id = a.affiliate_id) AS referrals,
                    (SELECT COUNT(*) FROM affiliate_referral r WHERE r.affiliate_id = a.affiliate_id AND r.converted_at IS NOT NULL) AS converted
               FROM affiliate a JOIN person p ON p.id = a.person_id JOIN person op ON op.id = a.owner_person_id
              WHERE p.brand = ? ORDER BY a.created DESC LIMIT 200"
        );
        $aq->execute([$brand]);
        $affs = [];
        foreach ($aq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $affs[] = [
                'affiliate_id' => (string)$r['affiliate_id'],
                'name' => trim($r['first_name'] . ' ' . $r['last_name']),
                'owner_name' => trim($r['owner_first'] . ' ' . $r['owner_last']),
                'owned_by_someone_else' => (string)$r['owner_person_id'] !== (string)$r['person_id'],
                'status' => (string)$r['status'],
                'created' => (string)$r['created'],
                'commission_rate_override' => $r['commission_rate_override'] !== null ? (string)$r['commission_rate_override'] : null,
                'commission_percent_for_new_referrals' => DwAffiliate::resolvedCommissionPercent($db, (string)$r['affiliate_id']),
                'referrals' => (int)$r['referrals'],
                'converted' => (int)$r['converted'],
            ];
        }
        return [
            'ok' => true,
            'program' => [
                'enabled' => $settings['program_enabled'],
                'default_commission_percent' => $settings['default_commission_percent'],
                'client_discount_percent' => $settings['client_discount_percent'],
                'client_discount_months' => $settings['client_discount_months'],
                'terms' => $settings['terms'],
                'terms_custom' => $settings['terms_custom'],
            ],
            'add_on' => [
                'allowed' => (bool)($gate['allowed'] ?? false),
                'can_add' => (bool)($gate['can_add'] ?? false),
                'used' => (int)($gate['used'] ?? 0),
                'free_limit' => (int)($gate['free_limit'] ?? DwAffiliate::GATE_FREE_LIMIT),
                'subscribed' => (bool)($gate['subscribed'] ?? false),
                'pricing_summary' => (string)($gate['pricing_summary'] ?? ''),
                'note' => 'Plan and pricing changes are not available through the API.',
            ],
            'affiliates' => $affs,
            'pending_transfer_requests' => self::listTransferRequests($db, $brand, 'pending')['requests'],
        ];
    }

    private static function requestRow(PDO $db, string $id): ?array
    {
        $q = $db->prepare(
            'SELECT t.id, t.affiliate_id, t.to_company, t.reason, t.notes, t.effective_at, t.status, t.requested_via, t.decided_via,
                    t.decided_at, t.decision_note, t.transfer_id, t.created,
                    rp.first_name AS req_first, rp.last_name AS req_last, dp.first_name AS dec_first, dp.last_name AS dec_last,
                    ap.first_name AS aff_first, ap.last_name AS aff_last, tp.first_name AS to_first, tp.last_name AS to_last
               FROM affiliate_transfer_request t
               JOIN affiliate a ON a.affiliate_id = t.affiliate_id JOIN person ap ON ap.id = a.person_id
               LEFT JOIN person rp ON rp.id = t.requested_by LEFT JOIN person dp ON dp.id = t.decided_by
               LEFT JOIN email te ON te.email = t.to_email AND te.owner_type = \'PRSN\' AND te.brand = t.brand
               LEFT JOIN person tp ON tp.id = te.owner_id
              WHERE t.id = ? LIMIT 1'
        );
        $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) {
            return null;
        }
        return [
            'request_id' => (string)$r['id'],
            'affiliate_id' => (string)$r['affiliate_id'],
            'affiliate_name' => trim($r['aff_first'] . ' ' . $r['aff_last']),
            'to_name' => trim(($r['to_first'] ?? '') . ' ' . ($r['to_last'] ?? '')),
            'to_company' => $r['to_company'],
            'reason' => (string)$r['reason'],
            'notes' => $r['notes'],
            'effective_at' => $r['effective_at'],
            'status' => (string)$r['status'],
            'requested_by_name' => trim(($r['req_first'] ?? '') . ' ' . ($r['req_last'] ?? '')),
            'requested_via' => str_starts_with((string)$r['requested_via'], 'oauth:') ? 'connected app' : 'dashboard',
            'decided_by_name' => $r['dec_first'] !== null ? trim($r['dec_first'] . ' ' . $r['dec_last']) : null,
            'decided_at' => $r['decided_at'],
            'decision_note' => $r['decision_note'],
            'transfer_recorded' => $r['transfer_id'] !== null,
            'created' => (string)$r['created'],
        ];
    }

    /** @return array{ok:bool,requests:list<array>} */
    public static function listTransferRequests(PDO $db, string $brand, ?string $status = 'pending'): array
    {
        $ok = ['pending', 'approved', 'rejected', 'cancelled'];
        $sql = 'SELECT id FROM affiliate_transfer_request WHERE brand = ?';
        $args = [$brand];
        if ($status !== null && $status !== '' && $status !== 'all') {
            if (!in_array($status, $ok, true)) {
                return ['ok' => false, 'requests' => [], 'error' => 'status must be pending, approved, rejected, cancelled or all'];
            }
            $sql .= ' AND status = ?';
            $args[] = $status;
        }
        $q = $db->prepare($sql . ' ORDER BY created DESC LIMIT 100');
        $q->execute($args);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $r = self::requestRow($db, (string)$id);
            if ($r !== null) {
                $out[] = $r;
            }
        }
        return ['ok' => true, 'requests' => $out];
    }

    /** File a pending transfer request (nothing changes until staff approve it). */
    public static function requestTransfer(PDO $db, string $brand, string $affiliateRaw, array $body, string $personId, string $via): array
    {
        $aff = $affiliateRaw !== '' ? DwAffiliate::resolve($db, $affiliateRaw, $brand) : null;
        if ($aff === null) {
            return ['ok' => false, 'http' => 404, 'error' => 'affiliate not found'];
        }
        $toEmail = DwAffiliate::normalizeEmail((string)($body['to_email'] ?? ''));
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'the new owner email is not valid'];
        }
        $reason = (string)($body['reason'] ?? '');
        if (!isset(DwAffiliate::TRANSFER_REASONS[$reason])) {
            return ['ok' => false, 'error' => 'reason must be sale, inheritance, corporate_transfer, or other'];
        }
        $notes = trim((string)($body['notes'] ?? ''));
        if (strlen($notes) > 2000) {
            return ['ok' => false, 'error' => 'notes are longer than 2000 characters'];
        }
        $toCompany = trim((string)($body['to_company'] ?? ''));
        if (strlen($toCompany) > 100) {
            return ['ok' => false, 'error' => 'company name is longer than 100 characters'];
        }
        $when = null;
        if (isset($body['effective_at']) && trim((string)$body['effective_at']) !== '') {
            $dt = date_create(trim((string)$body['effective_at']));
            if ($dt === false) {
                return ['ok' => false, 'error' => 'effective date is not valid'];
            }
            $when = $dt->format('Y-m-d H:i:s');
        }
        $to = DwAffiliate::personByEmail($db, $toEmail, $brand);
        if ($to === null) {
            return ['ok' => false, 'error' => 'the new owner must already be a person on this website (identified by email)'];
        }
        $own = $db->prepare('SELECT owner_person_id FROM affiliate WHERE affiliate_id = ?');
        $own->execute([$aff['affiliate_id']]);
        if ((string)$own->fetchColumn() === (string)$to['id']) {
            return ['ok' => false, 'error' => 'the new owner is already the current owner'];
        }
        $dup = $db->prepare("SELECT COUNT(*) FROM affiliate_transfer_request WHERE affiliate_id = ? AND status = 'pending'");
        $dup->execute([$aff['affiliate_id']]);
        if ((int)$dup->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'this affiliate already has a pending transfer request; approve or reject it first'];
        }
        $id = Dw::randId();
        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO affiliate_transfer_request (id, affiliate_id, brand, to_email, to_company, reason, notes, effective_at, status,
                    requested_by, requested_via, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())"
            )->execute([$id, $aff['affiliate_id'], $brand, $toEmail, $toCompany !== '' ? $toCompany : null, $reason,
                $notes !== '' ? $notes : null, $when, $personId, substr($via, 0, 80)]);
            Dw::history($db, 'affiliate_transfer_request', $id, 'INSERT');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('affiliate requestTransfer: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'could not record the transfer request'];
        }
        return ['ok' => true, 'request' => self::requestRow($db, $id),
            'note' => 'Pending. Nothing changes until staff approve it (approval needs the affiliate:transfer permission).'];
    }

    /** Approve or reject a pending request. Approval records the transfer with approved_by = $personId. */
    public static function decideTransfer(PDO $db, string $brand, string $requestId, bool $approve, string $personId, string $via, string $note = ''): array
    {
        $note = trim($note);
        if (strlen($note) > 500) {
            return ['ok' => false, 'error' => 'note is longer than 500 characters'];
        }
        if (!preg_match('/^[0-9a-f]{50}$/', $requestId)) {
            return ['ok' => false, 'http' => 404, 'error' => 'transfer request not found'];
        }
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM affiliate_transfer_request WHERE id = ? AND brand = ? FOR UPDATE');
            $q->execute([$requestId, $brand]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($r)) {
                $db->rollBack();
                return ['ok' => false, 'http' => 404, 'error' => 'transfer request not found'];
            }
            if ((string)$r['status'] !== 'pending') {
                $db->rollBack();
                return ['ok' => false, 'error' => 'this request was already ' . $r['status']];
            }
            $transferId = null;
            if ($approve) {
                $res = DwAffiliate::transferOwnership(
                    $db, (string)$r['affiliate_id'], (string)$r['to_email'], (string)$r['reason'], (string)($r['notes'] ?? ''),
                    $personId, $r['effective_at'] !== null ? (string)$r['effective_at'] : null,
                    $r['to_company'] !== null ? (string)$r['to_company'] : null, $brand, (string)$r['requested_by']
                );
                if (empty($res['ok'])) {
                    $db->rollBack();
                    return ['ok' => false, 'error' => (string)($res['error'] ?? 'could not record the transfer')];
                }
                $transferId = (string)($res['transfer']['id'] ?? '');
            }
            Dw::history($db, 'affiliate_transfer_request', $requestId, 'UPDATE');
            $db->prepare('UPDATE affiliate_transfer_request SET status = ?, decided_by = ?, decided_via = ?, decided_at = NOW(), decision_note = ?, transfer_id = ?, updated = NOW() WHERE id = ?')
                ->execute([$approve ? 'approved' : 'rejected', $personId, substr($via, 0, 80), $note !== '' ? $note : null, $transferId, $requestId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('affiliate decideTransfer: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'could not record the decision'];
        }
        $out = ['ok' => true, 'request' => self::requestRow($db, $requestId)];
        if ($approve && !empty($transferId)) {
            $out['transfer_code_emailed'] = self::emailTransferCode($db, $brand, $personId, (string)$transferId);
        }
        return $out;
    }
}
