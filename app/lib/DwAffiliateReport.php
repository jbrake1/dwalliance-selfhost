<?php
declare(strict_types=1);

/**
 * Read-only affiliate reporting for the API (refs #74, 2026-11-22).
 *
 * On-demand reports as stable, machine-readable JSON for the caller's AI or financial systems:
 * snake_case fields, ISO-8601 UTC timestamps, amounts as decimal strings with a currency field, stable ids,
 * cursor pagination, and incremental sync (updated_after + next_cursor, ordered by updated then id).
 *
 * Scoping: a self report is one affiliate on one brand; a staff report is one brand (optionally one affiliate
 * of that brand). Never returns referral/channel/transfer codes, tokens, visitor tokens, IP hashes, user agents,
 * invoice or payment ids, or client names beyond initials.
 */
final class DwAffiliateReport
{
    public const SCHEMA_VERSION = 'affiliate-report/1';
    public const CURRENCY = 'USD';
    public const STATUSES = ['pending', 'approved', 'paid', 'reversed'];
    public const SELF_VIEWS = ['summary', 'referrals', 'conversions', 'commissions', 'ownership'];
    public const STAFF_VIEWS = ['summary', 'affiliate', 'referrals', 'conversions', 'commissions', 'transfers'];
    public const API_MAX_LIMIT = 100;
    public const EMAIL_MAX_ROWS = 5000;
    public const EMAIL_MAX_BYTES = 1048576;   // 1 MiB of JSON per emailed report

    // ------------------------------------------------------------------ public entry points

    /** Report for the caller's own affiliate. */
    public static function self(PDO $db, string $brand, string $affiliateId, array $in, int $maxLimit = self::API_MAX_LIMIT): array
    {
        $view = (string)($in['view'] ?? 'summary');
        if (!in_array($view, self::SELF_VIEWS, true)) {
            return self::err('view must be one of: ' . implode(', ', self::SELF_VIEWS));
        }
        if (!self::affiliateInBrand($db, $affiliateId, $brand)) {
            return self::err('join the affiliate program first', 404);
        }
        $scope = ['brand' => $brand, 'affiliate_id' => $affiliateId, 'self' => true];
        return self::run($db, $scope, $view === 'ownership' ? 'transfers' : $view, $in, $maxLimit);
    }

    /** Report for staff: the whole brand, or one affiliate of it (view affiliate, or any view with `affiliate`). */
    public static function staff(PDO $db, string $brand, array $in, int $maxLimit = self::API_MAX_LIMIT): array
    {
        $view = (string)($in['view'] ?? 'summary');
        if (!in_array($view, self::STAFF_VIEWS, true)) {
            return self::err('view must be one of: ' . implode(', ', self::STAFF_VIEWS));
        }
        $scope = ['brand' => $brand, 'self' => false];
        $ref = trim((string)($in['affiliate'] ?? $in['affiliate_id'] ?? ''));
        if ($ref !== '') {
            $r = DwAffiliate::resolve($db, $ref, $brand);
            if ($r === null || !self::affiliateInBrand($db, (string)$r['affiliate_id'], $brand)) {
                return self::err('affiliate not found', 404);
            }
            $scope['affiliate_id'] = (string)$r['affiliate_id'];
        } elseif ($view === 'affiliate') {
            return self::err('affiliate is required for view affiliate');
        }
        $out = self::run($db, $scope, $view === 'affiliate' ? 'summary' : $view, $in, $maxLimit);
        if (!empty($out['ok']) && $view === 'affiliate') {
            $out['view'] = 'affiliate';
            $out['affiliate'] = self::affiliateHeader($db, $scope['affiliate_id']);
        }
        return $out;
    }

    /**
     * Email the same report as raw JSON (body + .json attachment) only to $to, the caller's verified address on file.
     * Pages through the view up to EMAIL_MAX_ROWS rows / EMAIL_MAX_BYTES; says so when truncated.
     */
    public static function email(PDO $db, callable $build, string $to, string $siteName, array $in): array
    {
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return self::err('there is no verified email address on file for you');
        }
        $in['limit'] = 1000;
        $first = $build($in, 1000);
        if (empty($first['ok'])) {
            return $first;
        }
        $report = $first;
        $listKey = self::listKey($report);
        $truncated = false;
        if ($listKey !== null) {
            $rows = $report[$listKey];
            $cursor = $report['next_cursor'] ?? null;
            while ($cursor !== null && count($rows) < self::EMAIL_MAX_ROWS) {
                $page = $build(['cursor' => $cursor] + $in, 1000);
                if (empty($page['ok'])) {
                    break;
                }
                $rows = array_merge($rows, $page[$listKey]);
                $cursor = $page['next_cursor'] ?? null;
            }
            if (count($rows) > self::EMAIL_MAX_ROWS) {
                $rows = array_slice($rows, 0, self::EMAIL_MAX_ROWS);
                $truncated = true;
            }
            $report[$listKey] = $rows;
            $report['next_cursor'] = $cursor;
            if ($cursor !== null) {
                $truncated = true;
            }
        }
        $json = self::encode($report);
        while (strlen($json) > self::EMAIL_MAX_BYTES && $listKey !== null && $report[$listKey] !== []) {
            $keep = max(0, (int)floor(count($report[$listKey]) * 0.8));
            $report[$listKey] = array_slice($report[$listKey], 0, $keep);
            $truncated = true;
            $json = self::encode($report);
        }
        $report['truncated'] = $truncated;
        $report['truncation_note'] = $truncated
            ? 'This email holds the first ' . ($listKey !== null ? count($report[$listKey]) : 0) . ' rows (cap ' . self::EMAIL_MAX_ROWS . ' rows / 1 MiB). Pull the rest through the API with a narrower date range or the updated_after cursor.'
            : null;
        $json = self::encode($report);
        $rowCount = $listKey !== null ? count($report[$listKey]) : null;
        $fname = 'affiliate-report-' . (string)$report['view'] . '-' . gmdate('Ymd\THis\Z') . '.json';
        $subject = $siteName . ' affiliate report (' . (string)$report['view'] . ')';
        $text = "Your {$siteName} affiliate report, as you asked. Raw JSON follows and is attached as {$fname}.\n"
            . "Schema: " . self::SCHEMA_VERSION . ". Amounts are decimal strings in " . self::CURRENCY . "; times are ISO-8601 UTC.\n"
            . ($truncated ? "TRUNCATED: " . $report['truncation_note'] . "\n" : '')
            . "\n" . $json . "\n";
        $b = 'b_' . bin2hex(random_bytes(12));
        $mime = "MIME-Version: 1.0\r\nAuto-Submitted: auto-generated\r\nContent-Type: multipart/mixed; boundary=\"{$b}\"\r\n\r\n"
            . "--{$b}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . "--{$b}\r\nContent-Type: application/json; charset=utf-8; name=\"{$fname}\"\r\nContent-Disposition: attachment; filename=\"{$fname}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($json), 76, "\r\n")
            . "--{$b}--\r\n";
        $sent = Dw::sendMime($to, $subject, $mime);
        if (!$sent) {
            return self::err('could not send the email; try again later', 502);
        }
        return ['ok' => true, 'schema_version' => self::SCHEMA_VERSION, 'emailed' => true, 'status' => 'sent',
            'view' => (string)$report['view'], 'rows' => $rowCount, 'bytes' => strlen($json), 'truncated' => $truncated,
            'note' => 'The report was emailed as JSON (body and attachment) to your verified email address on file.'];
    }

    // ------------------------------------------------------------------ dispatcher

    private static function run(PDO $db, array $scope, string $view, array $in, int $maxLimit): array
    {
        $f = self::filters($in, $maxLimit, $view);
        if (is_string($f)) {
            return self::err($f);
        }
        $out = match ($view) {
            'summary' => self::summary($db, $scope, $f),
            'referrals' => self::referrals($db, $scope, $f, false),
            'conversions' => self::referrals($db, $scope, $f, true),
            'commissions' => self::commissions($db, $scope, $f),
            'transfers' => self::transfers($db, $scope, $f),
        };
        if (empty($out['ok'])) {
            return $out;
        }
        $view = ($scope['self'] && $view === 'transfers') ? 'ownership' : $view;
        $head = [
            'ok' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'currency' => self::CURRENCY,
            'view' => $view,
            'scope' => ['kind' => isset($scope['affiliate_id']) ? 'affiliate' : 'brand', 'affiliate_id' => $scope['affiliate_id'] ?? null],
            'filters' => self::publicFilters($f),
        ];
        unset($out['ok']);
        return $head + $out;
    }

    // ------------------------------------------------------------------ filters

    /** @return array<string,mixed>|string */
    public static function filters(array $in, int $maxLimit, string $view = 'summary'): array|string
    {
        $f = ['from' => null, 'to' => null, 'group_by' => 'period', 'period' => 'month', 'limit' => 50, 'cursor' => null,
              'updated_after' => null, 'channel_id' => null, 'status' => null, 'converted' => null];
        foreach (['from', 'to'] as $k) {
            $v = trim((string)($in[$k] ?? ''));
            if ($v === '') {
                continue;
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));
            if ($d === false || $d->format('Y-m-d') !== $v) {
                return "$k must be a UTC date like 2026-11-01";
            }
            $f[$k] = $v;
        }
        if ($f['from'] !== null && $f['to'] !== null && $f['from'] > $f['to']) {
            return 'from is after to';
        }
        $g = (string)($in['group_by'] ?? 'period');
        $allowedGroups = ['period', 'code', 'channel', 'affiliate', 'none'];
        if (!in_array($g, $allowedGroups, true)) {
            return 'group_by must be period, code, channel, affiliate (staff), or none';
        }
        $f['group_by'] = $g;
        $p = (string)($in['period'] ?? 'month');
        if (!in_array($p, ['day', 'week', 'month'], true)) {
            return 'period must be day, week, or month';
        }
        if ($p === 'day' && $g === 'period' && $view === 'summary') {
            if ($f['from'] === null || $f['to'] === null) {
                return 'period day needs from and to (at most 366 days)';
            }
            $days = (new DateTimeImmutable($f['to']))->diff(new DateTimeImmutable($f['from']))->days;
            if ($days > 366) {
                return 'period day covers at most 366 days; use week or month';
            }
        }
        $f['period'] = $p;
        if (isset($in['limit']) && $in['limit'] !== '') {
            if (!is_numeric($in['limit'])) {
                return 'limit must be a number';
            }
            $f['limit'] = max(1, min($maxLimit, (int)$in['limit']));
        }
        $c = trim((string)($in['cursor'] ?? ''));
        if ($c !== '') {
            $dec = self::decodeCursor($c);
            if ($dec === null) {
                return 'cursor is not valid; start again without it';
            }
            $f['cursor'] = $dec;
        }
        $u = trim((string)($in['updated_after'] ?? ''));
        if ($u !== '') {
            $t = self::parseIso($u);
            if ($t === null) {
                return 'updated_after must be an ISO-8601 UTC time like 2026-11-22T00:00:00Z';
            }
            $f['updated_after'] = $t;
        }
        $ch = trim((string)($in['channel_id'] ?? ''));
        $f['channel_id'] = $ch !== '' ? $ch : null;
        $st = trim((string)($in['status'] ?? ''));
        if ($st !== '' && !in_array($st, self::STATUSES, true)) {
            return 'status must be pending, approved, paid, or reversed';
        }
        $f['status'] = $st !== '' ? $st : null;
        if (array_key_exists('converted', $in) && $in['converted'] !== null && $in['converted'] !== '') {
            $cv = filter_var($in['converted'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($cv === null) {
                return 'converted must be true or false';
            }
            $f['converted'] = $cv;
        }
        return $f;
    }

    private static function publicFilters(array $f): array
    {
        return [
            'from' => $f['from'], 'to' => $f['to'], 'group_by' => $f['group_by'], 'period' => $f['period'],
            'limit' => $f['limit'], 'updated_after' => $f['updated_after'] !== null ? self::iso($f['updated_after']) : null,
            'channel_id' => $f['channel_id'], 'status' => $f['status'], 'converted' => $f['converted'],
            'date_basis' => 'UTC days, from and to inclusive',
        ];
    }

    // ------------------------------------------------------------------ SQL helpers

    private static function scopeSql(array $scope, array &$args, string $a = 'a'): string
    {
        $s = " AND EXISTS (SELECT 1 FROM person sp WHERE sp.id = {$a}.person_id AND sp.brand = ?)";
        $args[] = $scope['brand'];
        if (isset($scope['affiliate_id'])) {
            $s .= " AND {$a}.affiliate_id = ?";
            $args[] = $scope['affiliate_id'];
        }
        return $s;
    }

    private static function rangeSql(string $col, array $f, array &$args): string
    {
        $s = '';
        if ($f['from'] !== null) {
            $s .= " AND {$col} >= " . self::DB_ARG;
            $args[] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== null) {
            $s .= " AND {$col} < " . self::DB_ARG;
            $args[] = gmdate('Y-m-d', strtotime($f['to'] . ' 00:00:00 UTC') + 86400) . ' 00:00:00';
        }
        return $s;
    }

    private static function channelSql(string $col, array $f, array &$args): string
    {
        if ($f['channel_id'] === null) {
            return '';
        }
        if ($f['channel_id'] === 'main') {
            return " AND {$col} IS NULL";
        }
        $args[] = $f['channel_id'];
        return " AND {$col} = ?";
    }

    /** Cursor/incremental sync on (updated, id), ascending. */
    private static function syncSql(string $upd, string $id, array $f, array &$args): string
    {
        $s = '';
        if ($f['updated_after'] !== null) {
            $s .= " AND {$upd} > " . self::DB_ARG;
            $args[] = $f['updated_after'];
        }
        if ($f['cursor'] !== null && $f['cursor']['k'] === 'u') {
            $s .= " AND ({$upd} > " . self::DB_ARG . " OR ({$upd} = " . self::DB_ARG . " AND {$id} > ?))";
            $t = $f['cursor']['t'];
            array_push($args, $t, $t, $f['cursor']['i']);
        }
        return $s;
    }

    private static function money(string $expr): string
    {
        return "CAST(COALESCE(SUM({$expr}), 0) AS DECIMAL(14,2))";
    }

    private static function bucket(string $col, string $period): string
    {
        $c = self::utcSql($col);
        return match ($period) {
            'day' => "DATE_FORMAT({$c}, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(DATE_SUB(DATE({$c}), INTERVAL WEEKDAY({$c}) DAY), '%Y-%m-%d')",
            default => "DATE_FORMAT({$c}, '%Y-%m-01')",
        };
    }

    // ------------------------------------------------------------------ summary

    private static function summary(PDO $db, array $scope, array $f): array
    {
        $g = $f['group_by'];
        if ($g === 'affiliate' && !empty($scope['self'])) {
            return self::err('group_by affiliate is for staff reports');
        }
        if ($g === 'affiliate' && isset($scope['affiliate_id'])) {
            $g = 'none';
        }
        [$refKey, $comKey] = match ($g) {
            'period' => [self::bucket('r.created', $f['period']), self::bucket('c.created', $f['period'])],
            'code' => ["CONCAT(r.affiliate_id, '|', COALESCE(r.affiliate_channel_id, ''))", "CONCAT(c.affiliate_id, '|', COALESCE(c.affiliate_channel_id, ''))"],
            'channel' => ["COALESCE(rch.type, 'main_link')", "COALESCE(cch.type, 'main_link')"],
            'affiliate' => ['r.affiliate_id', 'c.affiliate_id'],
            default => ["'all'", "'all'"],
        };
        $convKey = $g === 'period' ? self::bucket('r.converted_at', $f['period']) : $refKey;

        // referrals (by created)
        $args = [];
        $w = self::scopeSql($scope, $args) . self::rangeSql('r.created', $f, $args) . self::channelSql('r.affiliate_channel_id', $f, $args);
        $q = $db->prepare("SELECT {$refKey} AS k, COUNT(*) AS n FROM affiliate_referral r JOIN affiliate a ON a.affiliate_id = r.affiliate_id
                            LEFT JOIN affiliate_channel rch ON rch.affiliate_channel_id = r.affiliate_channel_id WHERE 1=1 {$w} GROUP BY k");
        $q->execute($args);
        $groups = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $gr = &self::g($groups, (string)$r['k']);
            $gr['referrals'] = (int)$r['n'];
            unset($gr);
        }
        // conversions (by converted_at)
        $args = [];
        $w = self::scopeSql($scope, $args) . self::rangeSql('r.converted_at', $f, $args) . self::channelSql('r.affiliate_channel_id', $f, $args);
        $q = $db->prepare("SELECT {$convKey} AS k, COUNT(*) AS n FROM affiliate_referral r JOIN affiliate a ON a.affiliate_id = r.affiliate_id
                            LEFT JOIN affiliate_channel rch ON rch.affiliate_channel_id = r.affiliate_channel_id WHERE r.converted_at IS NOT NULL {$w} GROUP BY k");
        $q->execute($args);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $gr = &self::g($groups, (string)$r['k']);
            $gr['conversions'] = (int)$r['n'];
            unset($gr);
        }
        // commissions (by created, per status)
        $args = [];
        $w = self::scopeSql($scope, $args) . self::rangeSql('c.created', $f, $args) . self::channelSql('c.affiliate_channel_id', $f, $args);
        $q = $db->prepare("SELECT {$comKey} AS k, c.status, COUNT(*) AS n, " . self::money('c.commission') . " AS amt, " . self::money('c.net_paid') . " AS net
                             FROM affiliate_commission c JOIN affiliate a ON a.affiliate_id = c.affiliate_id
                             LEFT JOIN affiliate_channel cch ON cch.affiliate_channel_id = c.affiliate_channel_id WHERE 1=1 {$w} GROUP BY k, c.status");
        $q->execute($args);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $gr = &self::g($groups, (string)$r['k']);
            $gr['commission'][(string)$r['status']] = ['count' => (int)$r['n'], 'amount' => (string)$r['amt'], 'net_paid' => (string)$r['net']];
            unset($gr);
        }
        // totals straight from the ledger (not summed in PHP)
        $totals = self::totals($db, $scope, $f);

        ksort($groups, SORT_STRING);
        $keys = array_keys($groups);
        $offset = ($f['cursor'] !== null && $f['cursor']['k'] === 'o') ? (int)$f['cursor']['o'] : 0;
        $page = array_slice($keys, $offset, $f['limit']);
        $next = ($offset + $f['limit'] < count($keys)) ? self::encodeCursor(['k' => 'o', 'o' => $offset + $f['limit']]) : null;
        $labels = self::groupLabels($db, $g, $page, $scope);
        $rows = [];
        foreach ($page as $k) {
            $rows[] = $labels[$k] + self::finishGroup($groups[$k]);
        }
        $out = ['ok' => true, 'group_by' => $g, 'totals' => $totals, 'group_count' => count($keys), 'groups' => $rows, 'next_cursor' => $next];
        if (!isset($scope['affiliate_id'])) {
            $out['affiliates'] = self::affiliateCounts($db, $scope, $f);
        }
        return $out;
    }

    private static function &g(array &$groups, string $k): array
    {
        if (!isset($groups[$k])) {
            $groups[$k] = ['referrals' => 0, 'conversions' => 0, 'commission' => []];
        }
        return $groups[$k];
    }

    private static function finishGroup(array $gr): array
    {
        $by = [];
        $earned = '0.00';
        $net = '0.00';
        foreach (self::STATUSES as $s) {
            $x = $gr['commission'][$s] ?? ['count' => 0, 'amount' => '0.00', 'net_paid' => '0.00'];
            $by[$s] = ['count' => $x['count'], 'amount' => $x['amount']];
            if ($s !== 'reversed') {
                $earned = self::add($earned, $x['amount']);
                $net = self::add($net, $x['net_paid']);
            }
        }
        return ['referrals' => $gr['referrals'], 'conversions' => $gr['conversions'], 'commission_by_status' => $by,
            'commission_earned' => $earned, 'net_paid_by_clients' => $net];
    }

    private static function totals(PDO $db, array $scope, array $f): array
    {
        $args = [];
        $w = self::scopeSql($scope, $args) . self::rangeSql('r.created', $f, $args) . self::channelSql('r.affiliate_channel_id', $f, $args);
        $q = $db->prepare("SELECT COUNT(*) FROM affiliate_referral r JOIN affiliate a ON a.affiliate_id = r.affiliate_id WHERE 1=1 {$w}");
        $q->execute($args);
        $refs = (int)$q->fetchColumn();
        $args = [];
        $w = self::scopeSql($scope, $args) . self::rangeSql('r.converted_at', $f, $args) . self::channelSql('r.affiliate_channel_id', $f, $args);
        $q = $db->prepare("SELECT COUNT(*) FROM affiliate_referral r JOIN affiliate a ON a.affiliate_id = r.affiliate_id WHERE r.converted_at IS NOT NULL {$w}");
        $q->execute($args);
        $conv = (int)$q->fetchColumn();
        $args = [];
        $w = self::scopeSql($scope, $args) . self::rangeSql('c.created', $f, $args) . self::channelSql('c.affiliate_channel_id', $f, $args);
        $q = $db->prepare("SELECT c.status, COUNT(*) AS n, " . self::money('c.commission') . " AS amt FROM affiliate_commission c JOIN affiliate a ON a.affiliate_id = c.affiliate_id WHERE 1=1 {$w} GROUP BY c.status");
        $q->execute($args);
        $by = [];
        foreach (self::STATUSES as $s) {
            $by[$s] = ['count' => 0, 'amount' => '0.00'];
        }
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $by[(string)$r['status']] = ['count' => (int)$r['n'], 'amount' => (string)$r['amt']];
        }
        $args2 = [];
        $w2 = self::scopeSql($scope, $args2) . self::rangeSql('c.created', $f, $args2) . self::channelSql('c.affiliate_channel_id', $f, $args2);
        $q = $db->prepare("SELECT " . self::money('c.commission') . " AS earned, " . self::money('c.net_paid') . " AS net FROM affiliate_commission c JOIN affiliate a ON a.affiliate_id = c.affiliate_id WHERE c.status <> 'reversed' {$w2}");
        $q->execute($args2);
        $e = $q->fetch(PDO::FETCH_ASSOC) ?: ['earned' => '0.00', 'net' => '0.00'];
        return ['referrals' => $refs, 'conversions' => $conv, 'commission_by_status' => $by,
            'commission_earned' => (string)$e['earned'], 'net_paid_by_clients' => (string)$e['net'],
            'basis' => 'referrals by created, conversions by converted_at, commissions by created; commission_earned excludes reversed'];
    }

    private static function affiliateCounts(PDO $db, array $scope, array $f): array
    {
        $args = [];
        $w = self::scopeSql($scope, $args);
        $q = $db->prepare("SELECT COUNT(*) AS total, SUM(a.status = 'active') AS active FROM affiliate a WHERE 1=1 {$w}");
        $q->execute($args);
        $t = $q->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'active' => 0];
        $args = [];
        $w = self::scopeSql($scope, $args) . self::rangeSql('a.created', $f, $args);
        $q = $db->prepare("SELECT COUNT(*) FROM affiliate a WHERE 1=1 {$w}");
        $q->execute($args);
        return ['total' => (int)$t['total'], 'active' => (int)$t['active'], 'joined_in_range' => (int)$q->fetchColumn()];
    }

    private static function groupLabels(PDO $db, string $g, array $keys, array $scope): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = match ($g) {
                'period' => ['period_start' => $k, 'period' => null],
                'channel' => ['channel_type' => $k],
                'none' => ['group' => 'all'],
                default => [],
            };
        }
        if ($g === 'period') {
            foreach ($keys as $k) {
                $out[$k] = ['period_start' => $k];
            }
        }
        if ($g === 'code') {
            $chq = $db->prepare('SELECT type, handle FROM affiliate_channel WHERE affiliate_channel_id = ?');
            foreach ($keys as $k) {
                [$aid, $cid] = array_pad(explode('|', $k, 2), 2, '');
                $row = ['code_kind' => $cid === '' ? 'affiliate' : 'channel', 'code_id' => $cid === '' ? $aid : $cid,
                        'affiliate_id' => $aid, 'channel_id' => $cid === '' ? null : $cid, 'channel_type' => null, 'channel_handle' => null];
                if ($cid !== '') {
                    $chq->execute([$cid]);
                    $c = $chq->fetch(PDO::FETCH_ASSOC);
                    if (is_array($c)) {
                        $row['channel_type'] = (string)$c['type'];
                        $row['channel_handle'] = (string)$c['handle'];
                    }
                }
                $out[$k] = $row;
            }
        }
        if ($g === 'affiliate') {
            $q = $db->prepare('SELECT a.affiliate_id, a.status, p.first_name, p.last_name FROM affiliate a JOIN person p ON p.id = a.person_id WHERE a.affiliate_id = ? AND p.brand = ?');
            foreach ($keys as $k) {
                $q->execute([$k, $scope['brand']]);
                $a = $q->fetch(PDO::FETCH_ASSOC);
                $out[$k] = ['affiliate_id' => $k, 'affiliate_name' => is_array($a) ? trim($a['first_name'] . ' ' . $a['last_name']) : null,
                            'affiliate_status' => is_array($a) ? (string)$a['status'] : null];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ lists (cursor + incremental sync)

    private static function referrals(PDO $db, array $scope, array $f, bool $conversionsOnly): array
    {
        $args = [];
        $col = $conversionsOnly ? 'r.converted_at' : 'r.created';
        $w = self::scopeSql($scope, $args) . self::rangeSql($col, $f, $args) . self::channelSql('r.affiliate_channel_id', $f, $args);
        if ($conversionsOnly) {
            $w .= ' AND r.converted_at IS NOT NULL';
        } elseif ($f['converted'] !== null) {
            $w .= $f['converted'] ? ' AND r.converted_at IS NOT NULL' : ' AND r.converted_at IS NULL';
        }
        $cargs = $args;
        $tq = $db->prepare("SELECT COUNT(*) FROM affiliate_referral r JOIN affiliate a ON a.affiliate_id = r.affiliate_id WHERE 1=1 {$w}");
        $tq->execute($cargs);
        $total = (int)$tq->fetchColumn();
        $w .= self::syncSql('r.updated', 'r.id', $f, $args);
        $lim = $f['limit'] + 1;
        $q = $db->prepare(
            "SELECT r.id, r.affiliate_id, r.affiliate_channel_id, r.commission_percent, r.source_type, r.landing_url, r.referrer,
                    r.utm_source, r.utm_medium, r.utm_campaign, CONVERT_TZ(r.converted_at, @@session.time_zone, '+00:00') AS converted_at, r.converted_person_id, CONVERT_TZ(r.created, @@session.time_zone, '+00:00') AS created, CONVERT_TZ(r.updated, @@session.time_zone, '+00:00') AS updated,
                    ch.type AS channel_type, p.first_name AS aff_first, p.last_name AS aff_last
               FROM affiliate_referral r JOIN affiliate a ON a.affiliate_id = r.affiliate_id JOIN person p ON p.id = a.person_id
               LEFT JOIN affiliate_channel ch ON ch.affiliate_channel_id = r.affiliate_channel_id
              WHERE 1=1 {$w} ORDER BY r.updated, r.id LIMIT {$lim}"
        );
        $q->execute($args);
        $all = $q->fetchAll(PDO::FETCH_ASSOC);
        $more = count($all) > $f['limit'];
        $all = array_slice($all, 0, $f['limit']);
        $rows = [];
        foreach ($all as $r) {
            $row = [
                'referral_id' => (string)$r['id'],
                'affiliate_id' => (string)$r['affiliate_id'],
                'channel_id' => $r['affiliate_channel_id'] !== null ? (string)$r['affiliate_channel_id'] : null,
                'channel_type' => $r['channel_type'] !== null ? (string)$r['channel_type'] : 'main_link',
                'source_type' => (string)$r['source_type'],
                'landing_host' => self::host($r['landing_url']),
                'referrer_host' => self::host($r['referrer']),
                'utm_source' => $r['utm_source'] !== null ? (string)$r['utm_source'] : null,
                'utm_medium' => $r['utm_medium'] !== null ? (string)$r['utm_medium'] : null,
                'utm_campaign' => $r['utm_campaign'] !== null ? (string)$r['utm_campaign'] : null,
                'commission_percent' => (string)$r['commission_percent'],
                'converted' => $r['converted_at'] !== null,
                'converted_at' => self::iso($r['converted_at']),
                'created_at' => self::iso($r['created']),
                'updated_at' => self::iso($r['updated']),
            ];
            if (empty($scope['self'])) {
                $row['affiliate_name'] = trim((string)$r['aff_first'] . ' ' . (string)$r['aff_last']);
            }
            if ($conversionsOnly) {
                $row['client'] = self::initials($db, $r['converted_person_id']);
                $row['subscription'] = self::subscriptionFor($db, (string)$r['id']);
            }
            $rows[] = $row;
        }
        $next = null;
        if ($more && $all !== []) {
            $last = end($all);
            $next = self::encodeCursor(['k' => 'u', 't' => substr((string)$last['updated'], 0, 19), 'i' => (string)$last['id']]);
        }
        $key = $conversionsOnly ? 'conversions' : 'referrals';
        return ['ok' => true, 'total' => $total, 'count' => count($rows), $key => $rows, 'next_cursor' => $next,
            'order' => 'updated_at, then id (ascending)', 'sync_note' => 'For incremental sync pass updated_after = the largest updated_at you stored, then follow next_cursor until it is null.'];
    }

    private static function subscriptionFor(PDO $db, string $referralId): ?array
    {
        $q = $db->prepare(
            "SELECT s.id, s.variant_id, s.referred_plan_id, s.discount_percent, s.discount_months, s.commission_percent, CONVERT_TZ(s.starts_at, @@session.time_zone, '+00:00') AS starts_at, CONVERT_TZ(s.ends_at, @@session.time_zone, '+00:00') AS ends_at, CONVERT_TZ(s.created, @@session.time_zone, '+00:00') AS created,
                    (SELECT " . self::money('c.commission') . " FROM affiliate_commission c WHERE c.affiliate_subscription_id = s.id AND c.status <> 'reversed') AS earned,
                    (SELECT " . self::money('c.commission') . " FROM affiliate_commission c WHERE c.affiliate_subscription_id = s.id AND c.status = 'paid') AS paid
               FROM affiliate_subscription s WHERE s.referral_id = ? ORDER BY s.created DESC, s.id DESC LIMIT 1"
        );
        $q->execute([$referralId]);
        $s = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($s)) {
            return null;
        }
        $now = time();
        $start = strtotime((string)($s['starts_at'] ?? $s['created']) . ' UTC');
        $end = $s['ends_at'] !== null ? strtotime((string)$s['ends_at'] . ' UTC') : null;
        $status = $start > $now ? 'scheduled' : (($end !== null && $end <= $now) ? 'ended' : 'active');
        return [
            'subscription_id' => (string)$s['id'],
            'status' => $status,
            'plan' => self::planName($db, $s['variant_id']),
            'plan_variant_id' => $s['variant_id'] !== null ? (string)$s['variant_id'] : null,
            'discount_percent' => (string)$s['discount_percent'],
            'discount_months' => (int)$s['discount_months'],
            'commission_percent' => (string)$s['commission_percent'],
            'started_at' => self::iso($s['starts_at'] ?? $s['created']),
            'ends_at' => self::iso($s['ends_at']),
            'commission_earned' => (string)$s['earned'],
            'commission_paid' => (string)$s['paid'],
        ];
    }

    private static function commissions(PDO $db, array $scope, array $f): array
    {
        $args = [];
        $w = self::scopeSql($scope, $args) . self::rangeSql('c.created', $f, $args) . self::channelSql('c.affiliate_channel_id', $f, $args);
        if ($f['status'] !== null) {
            $w .= ' AND c.status = ?';
            $args[] = $f['status'];
        }
        $targs = $args;
        $tq = $db->prepare("SELECT c.status, COUNT(*) AS n, " . self::money('c.commission') . " AS amt FROM affiliate_commission c JOIN affiliate a ON a.affiliate_id = c.affiliate_id WHERE 1=1 {$w} GROUP BY c.status");
        $tq->execute($targs);
        $by = [];
        foreach (self::STATUSES as $s) {
            $by[$s] = ['count' => 0, 'amount' => '0.00'];
        }
        $total = 0;
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $by[(string)$r['status']] = ['count' => (int)$r['n'], 'amount' => (string)$r['amt']];
            $total += (int)$r['n'];
        }
        $w .= self::syncSql('c.updated', 'c.id', $f, $args);
        $lim = $f['limit'] + 1;
        $q = $db->prepare(
            "SELECT c.id, c.affiliate_id, c.affiliate_channel_id, c.affiliate_subscription_id, c.owner_person_id, c.gross, c.discount, c.net_paid,
                    c.rate, c.commission, c.status, CONVERT_TZ(c.reversed_at, @@session.time_zone, '+00:00') AS reversed_at, CONVERT_TZ(c.created, @@session.time_zone, '+00:00') AS created, CONVERT_TZ(c.updated, @@session.time_zone, '+00:00') AS updated, a.person_id AS aff_person, ch.type AS channel_type,
                    op.first_name AS owner_first, op.last_name AS owner_last
               FROM affiliate_commission c JOIN affiliate a ON a.affiliate_id = c.affiliate_id
               LEFT JOIN affiliate_channel ch ON ch.affiliate_channel_id = c.affiliate_channel_id
               LEFT JOIN person op ON op.id = c.owner_person_id
              WHERE 1=1 {$w} ORDER BY c.updated, c.id LIMIT {$lim}"
        );
        $q->execute($args);
        $all = $q->fetchAll(PDO::FETCH_ASSOC);
        $more = count($all) > $f['limit'];
        $all = array_slice($all, 0, $f['limit']);
        $rows = [];
        foreach ($all as $r) {
            $row = [
                'commission_id' => (string)$r['id'],
                'affiliate_id' => (string)$r['affiliate_id'],
                'channel_id' => $r['affiliate_channel_id'] !== null ? (string)$r['affiliate_channel_id'] : null,
                'channel_type' => $r['channel_type'] !== null ? (string)$r['channel_type'] : 'main_link',
                'subscription_id' => $r['affiliate_subscription_id'] !== null ? (string)$r['affiliate_subscription_id'] : null,
                'status' => (string)$r['status'],
                'gross' => (string)$r['gross'],
                'discount' => (string)$r['discount'],
                'net_paid' => (string)$r['net_paid'],
                'rate_percent' => (string)$r['rate'],
                'commission' => (string)$r['commission'],
                'currency' => self::CURRENCY,
                'payee' => (string)$r['owner_person_id'] === (string)$r['aff_person'] ? 'affiliate' : 'transferred_owner',
                'reversed_at' => self::iso($r['reversed_at']),
                'created_at' => self::iso($r['created']),
                'updated_at' => self::iso($r['updated']),
            ];
            if (empty($scope['self'])) {
                $row['payee_name'] = trim((string)$r['owner_first'] . ' ' . (string)$r['owner_last']);
            }
            $rows[] = $row;
        }
        $next = null;
        if ($more && $all !== []) {
            $last = end($all);
            $next = self::encodeCursor(['k' => 'u', 't' => substr((string)$last['updated'], 0, 19), 'i' => (string)$last['id']]);
        }
        return ['ok' => true, 'total' => $total, 'totals_by_status' => $by, 'count' => count($rows), 'commissions' => $rows, 'next_cursor' => $next,
            'order' => 'updated_at, then id (ascending)', 'sync_note' => 'For incremental sync pass updated_after = the largest updated_at you stored, then follow next_cursor until it is null.'];
    }

    private static function transfers(PDO $db, array $scope, array $f): array
    {
        $args = [];
        $w = self::scopeSql($scope, $args) . self::rangeSql('t.effective_at', $f, $args);
        $tq = $db->prepare("SELECT COUNT(*) FROM affiliate_ownership_transfer t JOIN affiliate a ON a.affiliate_id = t.affiliate_id WHERE 1=1 {$w}");
        $tq->execute($args);
        $total = (int)$tq->fetchColumn();
        $w .= self::syncSql('t.updated', 't.id', $f, $args);
        $lim = $f['limit'] + 1;
        $q = $db->prepare(
            "SELECT t.id, t.affiliate_id, t.from_owner_person_id, t.to_owner_person_id, CONVERT_TZ(t.effective_at, @@session.time_zone, '+00:00') AS effective_at, t.reason, t.notes, CONVERT_TZ(t.approved_at, @@session.time_zone, '+00:00') AS approved_at,
                    t.requested_by, t.approved_by, CONVERT_TZ(t.created, @@session.time_zone, '+00:00') AS created, CONVERT_TZ(t.updated, @@session.time_zone, '+00:00') AS updated, a.person_id AS aff_person,
                    pf.first_name AS f_first, pf.last_name AS f_last, pt.first_name AS t_first, pt.last_name AS t_last,
                    pr.first_name AS r_first, pr.last_name AS r_last, pa.first_name AS a_first, pa.last_name AS a_last
               FROM affiliate_ownership_transfer t JOIN affiliate a ON a.affiliate_id = t.affiliate_id
               LEFT JOIN person pf ON pf.id = t.from_owner_person_id LEFT JOIN person pt ON pt.id = t.to_owner_person_id
               LEFT JOIN person pr ON pr.id = t.requested_by LEFT JOIN person pa ON pa.id = t.approved_by
              WHERE 1=1 {$w} ORDER BY t.updated, t.id LIMIT {$lim}"
        );
        $q->execute($args);
        $all = $q->fetchAll(PDO::FETCH_ASSOC);
        $more = count($all) > $f['limit'];
        $all = array_slice($all, 0, $f['limit']);
        $rows = [];
        $self = !empty($scope['self']);
        foreach ($all as $r) {
            $me = (string)$r['aff_person'];
            $row = [
                'transfer_id' => (string)$r['id'],
                'affiliate_id' => (string)$r['affiliate_id'],
                'reason' => (string)$r['reason'],
                'effective_at' => self::iso($r['effective_at']),
                'approved_at' => self::iso($r['approved_at']),
                'created_at' => self::iso($r['created']),
                'updated_at' => self::iso($r['updated']),
            ];
            if ($self) {
                $row['from_owner'] = (string)$r['from_owner_person_id'] === $me ? 'you' : self::short($r['f_first'], $r['f_last']);
                $row['to_owner'] = (string)$r['to_owner_person_id'] === $me ? 'you' : self::short($r['t_first'], $r['t_last']);
            } else {
                $row['from_owner_name'] = trim((string)$r['f_first'] . ' ' . (string)$r['f_last']);
                $row['to_owner_name'] = trim((string)$r['t_first'] . ' ' . (string)$r['t_last']);
                $row['requested_by_name'] = trim((string)$r['r_first'] . ' ' . (string)$r['r_last']);
                $row['approved_by_name'] = trim((string)$r['a_first'] . ' ' . (string)$r['a_last']);
                $row['notes'] = $r['notes'] !== null ? (string)$r['notes'] : null;
            }
            $rows[] = $row;
        }
        $next = null;
        if ($more && $all !== []) {
            $last = end($all);
            $next = self::encodeCursor(['k' => 'u', 't' => substr((string)$last['updated'], 0, 19), 'i' => (string)$last['id']]);
        }
        $out = ['ok' => true, 'total' => $total, 'count' => count($rows), 'transfers' => $rows, 'next_cursor' => $next, 'order' => 'updated_at, then id (ascending)'];
        if ($self) {
            $rq = $db->prepare("SELECT id, reason, status, CONVERT_TZ(effective_at, @@session.time_zone, '+00:00') AS effective_at, CONVERT_TZ(created, @@session.time_zone, '+00:00') AS created_utc, CONVERT_TZ(decided_at, @@session.time_zone, '+00:00') AS decided_at FROM affiliate_transfer_request WHERE affiliate_id = ? AND brand = ? ORDER BY created, id");
            $rq->execute([$scope['affiliate_id'], $scope['brand']]);
            $out['requests'] = array_map(fn($r) => ['request_id' => (string)$r['id'], 'reason' => (string)$r['reason'], 'status' => (string)$r['status'],
                'effective_at' => self::iso($r['effective_at']), 'created_at' => self::iso($r['created_utc']), 'decided_at' => self::iso($r['decided_at'])], $rq->fetchAll(PDO::FETCH_ASSOC));
        }
        return $out;
    }

    // ------------------------------------------------------------------ small helpers

    public static function listKey(array $report): ?string
    {
        foreach (['referrals', 'conversions', 'commissions', 'transfers', 'groups'] as $k) {
            if (isset($report[$k]) && is_array($report[$k]) && array_is_list($report[$k])) {
                return $k;
            }
        }
        return null;
    }

    private static function affiliateInBrand(PDO $db, string $affiliateId, string $brand): bool
    {
        $q = $db->prepare('SELECT 1 FROM affiliate a JOIN person p ON p.id = a.person_id WHERE a.affiliate_id = ? AND p.brand = ?');
        $q->execute([$affiliateId, $brand]);
        return (bool)$q->fetchColumn();
    }

    private static function affiliateHeader(PDO $db, string $affiliateId): array
    {
        $q = $db->prepare("SELECT a.affiliate_id, a.status, CONVERT_TZ(a.created, @@session.time_zone, '+00:00') AS created, CONVERT_TZ(a.updated, @@session.time_zone, '+00:00') AS updated, a.commission_rate_override, a.owner_person_id, a.person_id, p.first_name, p.last_name,
                                  op.first_name AS own_first, op.last_name AS own_last
                             FROM affiliate a JOIN person p ON p.id = a.person_id LEFT JOIN person op ON op.id = a.owner_person_id WHERE a.affiliate_id = ?");
        $q->execute([$affiliateId]);
        $a = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'affiliate_id' => $affiliateId,
            'name' => trim((string)($a['first_name'] ?? '') . ' ' . (string)($a['last_name'] ?? '')),
            'status' => (string)($a['status'] ?? ''),
            'owner_name' => trim((string)($a['own_first'] ?? '') . ' ' . (string)($a['own_last'] ?? '')),
            'owned_by_someone_else' => (string)($a['owner_person_id'] ?? '') !== (string)($a['person_id'] ?? ''),
            'commission_rate_override' => isset($a['commission_rate_override']) ? (string)$a['commission_rate_override'] : null,
            'commission_percent_for_new_referrals' => DwAffiliate::resolvedCommissionPercent($db, $affiliateId),
            'created_at' => self::iso($a['created'] ?? null),
            'updated_at' => self::iso($a['updated'] ?? null),
        ];
    }

    private static function planName(PDO $db, mixed $variantId): ?string
    {
        if ($variantId === null || $variantId === '') {
            return null;
        }
        try {
            $q = $db->prepare('SELECT p.name AS pn, v.name AS vn FROM product_variant v JOIN product p ON p.id = v.product_id WHERE v.id = ?');
            $q->execute([(string)$variantId]);
            $n = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($n)) {
                return null;
            }
            $pn = trim((string)$n['pn']);
            $vn = trim((string)$n['vn']);
            return $pn . ($vn !== '' && $vn !== $pn ? ' (' . $vn . ')' : '') ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Client shown as initials only ("A. B."): no names, emails or companies of referred clients. */
    private static function initials(PDO $db, mixed $personId): ?string
    {
        if ($personId === null || $personId === '') {
            return null;
        }
        $q = $db->prepare('SELECT first_name, last_name FROM person WHERE id = ?');
        $q->execute([(string)$personId]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($p)) {
            return null;
        }
        $i = '';
        foreach ([$p['first_name'], $p['last_name']] as $n) {
            $n = trim((string)$n);
            if ($n !== '') {
                $i .= mb_strtoupper(mb_substr($n, 0, 1)) . '. ';
            }
        }
        return $i !== '' ? trim($i) : 'Client';
    }

    private static function short(mixed $first, mixed $last): string
    {
        $f = trim((string)$first);
        $l = trim((string)$last);
        return trim($f . ($l !== '' ? ' ' . mb_strtoupper(mb_substr($l, 0, 1)) . '.' : '')) ?: 'another person';
    }

    private static function host(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $h = parse_url($url, PHP_URL_HOST);
        return is_string($h) ? strtolower($h) : null;
    }

    private static function add(string $a, string $b): string
    {
        // exact 2-decimal addition without floats
        $ca = (int)round((float)$a * 100);
        $cb = (int)round((float)$b * 100);
        $c = $ca + $cb;
        return ($c < 0 ? '-' : '') . intdiv(abs($c), 100) . '.' . str_pad((string)(abs($c) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** UTC view of a DATETIME column. The DB clock may be local time (production: SYSTEM = US Pacific with DST). */
    private static function utcSql(string $col): string
    {
        return "CONVERT_TZ({$col}, @@session.time_zone, '+00:00')";
    }

    /** Placeholder for a UTC 'Y-m-d H:i:s' value compared against a DB-clock DATETIME column. */
    private const DB_ARG = "CONVERT_TZ(?, '+00:00', @@session.time_zone)";

    public static function iso(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return str_replace(' ', 'T', substr((string)$v, 0, 19)) . 'Z';
    }

    private static function parseIso(string $v): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $v)) {
            return null;
        }
        $t = strtotime($v . (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v) ? '' : ' UTC'));
        return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
    }

    private static function encodeCursor(array $c): string
    {
        return rtrim(strtr(base64_encode(json_encode($c)), '+/', '-_'), '=');
    }

    private static function decodeCursor(string $s): ?array
    {
        $raw = base64_decode(strtr($s, '-_', '+/'), true);
        $c = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($c) || !isset($c['k'])) {
            return null;
        }
        if ($c['k'] === 'o' && isset($c['o']) && is_int($c['o']) && $c['o'] >= 0) {
            return $c;
        }
        if ($c['k'] === 'u' && isset($c['t'], $c['i']) && is_string($c['t']) && is_string($c['i'])
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $c['t'])) {
            return $c;
        }
        return null;
    }

    private static function encode(array $r): string
    {
        return json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function err(string $m, int $http = 400): array
    {
        return ['ok' => false, 'http' => $http, 'error' => $m];
    }
}
