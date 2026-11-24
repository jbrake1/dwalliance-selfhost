<?php
declare(strict_types=1);

/**
 * Path registry and redirects for /files moves and /link_tree.
 * Live rows have a null target (the path is current). A non-null target is a
 * 301/302 to another same-brand path or to an http(s) URL.
 */
class DwLinkTree
{
    public const STATUS_DEFAULT = 301;
    public const STATUSES = [301, 302];
    public const PATH_MAX = 400;
    public const TARGET_MAX = 2000;

    public static function rel(?string $filePath, ?string $fileName): string
    {
        $path = trim((string)$filePath, '/');
        $name = trim((string)$fileName, '/');
        if ($path === '') {
            return $name;
        }
        if ($name === '') {
            return $path;
        }
        return $path . '/' . $name;
    }

    /** Normalized path without a leading slash, or null if invalid. Empty string is not a usable path. */
    public static function normalize(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        if (strlen($path) > self::PATH_MAX) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $path) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..' || strpos($p, '..') !== false || $p[0] === '.' || strpbrk($p, "\\\0") !== false) {
                return null;
            }
            $parts[] = $p;
        }
        $out = implode('/', $parts);
        if (strlen($out) > self::PATH_MAX) {
            return null;
        }
        return $out;
    }

    public static function statusOf($value): int
    {
        if ($value === null || $value === '') {
            return self::STATUS_DEFAULT;
        }
        $n = is_int($value) ? $value : (is_numeric($value) ? (int)$value : 0);
        return in_array($n, self::STATUSES, true) ? $n : 0;
    }

    /** True if $target is an http(s) URL suitable for a manual redirect. */
    public static function isAbsoluteUrl(string $target): bool
    {
        if (strlen($target) > self::TARGET_MAX || preg_match('/[\r\n]/', $target)) {
            return false;
        }
        if (!preg_match('#^https?://#i', $target)) {
            return false;
        }
        $parts = parse_url($target);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        return filter_var($target, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate a redirect target: a same-brand path or an http(s) URL.
     * Returns ['kind'=>'path'|'url','value'=>string] or null.
     */
    public static function parseTarget(string $target): ?array
    {
        $target = trim($target);
        if ($target === '' || strlen($target) > self::TARGET_MAX || preg_match('/[\r\n]/', $target)) {
            return null;
        }
        if (self::isAbsoluteUrl($target)) {
            return ['kind' => 'url', 'value' => $target];
        }
        $path = self::normalize($target);
        if ($path === null || $path === '') {
            return null;
        }
        return ['kind' => 'path', 'value' => $path];
    }

    /** After a file row is created, updated, or deleted. $old/$new are files rows (file_name, file_path, brand). */
    public static function onSaved(PDO $db, string $brand, ?array $old, ?array $new, string $by): void
    {
        $by = $by !== '' ? $by : 'files';
        $oldRel = $old ? self::rel($old['file_path'] ?? '', $old['file_name'] ?? '') : '';
        $newRel = $new ? self::rel($new['file_path'] ?? '', $new['file_name'] ?? '') : '';
        $oldN = $oldRel !== '' ? self::normalize($oldRel) : '';
        $newN = $newRel !== '' ? self::normalize($newRel) : '';
        if ($oldN === null) {
            $oldN = '';
        }
        if ($newN === null) {
            $newN = '';
        }
        if ($oldN === '' && $newN === '') {
            return;
        }
        if ($newN !== '' && ($oldN === '' || $oldN === $newN)) {
            self::occupy($db, $brand, $newN, $by);
            return;
        }
        if ($oldN !== '' && $newN === '') {
            self::unregisterIfUnused($db, $brand, $oldN, $old);
            self::unlinkLegacy($brand, $old);
            return;
        }
        self::moved($db, $brand, $oldN, $newN, $by, 'auto');
        self::unlinkLegacy($brand, $old);
    }

    /** Register $path as live (target NULL). A real file wins over a redirect at the same path. */
    public static function occupy(PDO $db, string $brand, string $path, string $by, string $source = 'auto'): void
    {
        $path = self::normalize($path);
        if ($path === null || $path === '') {
            return;
        }
        $by = $by !== '' ? $by : 'files';
        $q = $db->prepare('SELECT id, target FROM link_tree WHERE brand = ? AND path = ? LIMIT 1');
        $q->execute([$brand, $path]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['target'] === null || $row['target'] === '') {
                return;
            }
            $db->prepare('UPDATE link_tree SET target = NULL, status = ?, source = ?, updated = NOW(), updated_by = ? WHERE id = ?')
                ->execute([self::STATUS_DEFAULT, $source, $by, $row['id']]);
            return;
        }
        $db->prepare('INSERT INTO link_tree (id, brand, path, target, status, source, created, created_by, updated, updated_by) VALUES (?, ?, ?, NULL, ?, ?, NOW(), ?, NOW(), ?)')
            ->execute([Dw::randId(), $brand, $path, self::STATUS_DEFAULT, $source, $by, $by]);
    }

    /** Drop a live registration when no file remains at $path. Redirects are left alone. */
    public static function unregisterIfUnused(PDO $db, string $brand, string $path, ?array $deletedRow = null): void
    {
        $path = self::normalize($path);
        if ($path === null || $path === '') {
            return;
        }
        if (self::fileExistsAt($db, $brand, $path, $deletedRow['id'] ?? null)) {
            return;
        }
        $db->prepare('DELETE FROM link_tree WHERE brand = ? AND path = ? AND (target IS NULL OR target = \'\')')
            ->execute([$brand, $path]);
    }

    /**
     * Point $old at $new, register $new as live, and collapse anyone who already targeted $old.
     * Status is 301 for automatic /files moves.
     */
    public static function moved(PDO $db, string $brand, string $old, string $new, string $by, string $source = 'auto', int $status = self::STATUS_DEFAULT): void
    {
        $old = self::normalize($old);
        $new = self::normalize($new);
        if ($old === null || $old === '' || $new === null || $new === '' || $old === $new) {
            if ($new !== null && $new !== '') {
                self::occupy($db, $brand, $new, $by, $source);
            }
            return;
        }
        $status = in_array($status, self::STATUSES, true) ? $status : self::STATUS_DEFAULT;
        $by = $by !== '' ? $by : 'files';
        self::occupy($db, $brand, $new, $by, $source);
        $final = self::collapseTo($db, $brand, $new, $old);
        if ($final === null || $final === $old) {
            $final = $new;
        }
        self::upsertRedirect($db, $brand, $old, $final, $status, $source, $by);
        $db->prepare('UPDATE link_tree SET target = ?, updated = NOW(), updated_by = ? WHERE brand = ? AND target = ? AND path <> ?')
            ->execute([$final, $by, $brand, $old, $final]);
        // Drop any self-redirects that collapse created.
        $db->prepare('DELETE FROM link_tree WHERE brand = ? AND target IS NOT NULL AND target <> \'\' AND path = target')
            ->execute([$brand]);
    }

    /** Folder-level redirects so /old/ and /old/index.html follow the move. */
    public static function movedPrefix(PDO $db, string $brand, string $from, string $to, string $by): void
    {
        $from = self::normalize($from);
        $to = self::normalize($to);
        if ($from === null || $from === '' || $to === null || $to === '' || $from === $to) {
            return;
        }
        self::moved($db, $brand, $from, $to, $by, 'auto');
        self::moved($db, $brand, $from . '/index.html', $to . '/index.html', $by, 'auto');
    }

    public static function fileExistsAt(PDO $db, string $brand, string $path, ?string $exceptId = null): bool
    {
        $path = self::normalize($path);
        if ($path === null || $path === '') {
            return false;
        }
        $slash = strrpos($path, '/');
        $name = $slash === false ? $path : substr($path, $slash + 1);
        $dir = $slash === false ? '' : substr($path, 0, $slash);
        $sql = "SELECT 1 FROM files WHERE brand = ? AND file_name = ? AND TRIM(BOTH '/' FROM COALESCE(file_path, '')) = ?";
        $args = [$brand, $name, $dir];
        if (is_string($exceptId) && $exceptId !== '') {
            $sql .= ' AND id <> ?';
            $args[] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $q = $db->prepare($sql);
        $q->execute($args);
        return (bool)$q->fetchColumn();
    }

    /** 404.php: one indexed lookup. Returns ['location'=>string,'status'=>int] or null. Failures throw; caller fails open. */
    public static function redirectFor(PDO $db, string $brand, string $lookupPath, array $ctx): ?array
    {
        $lookup = self::normalize($lookupPath);
        if ($lookup === null || $lookup === '') {
            return null;
        }
        $q = $db->prepare('SELECT target, status FROM link_tree WHERE brand = ? AND path = ? AND target IS NOT NULL AND target <> \'\' LIMIT 1');
        $q->execute([$brand, $lookup]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $target = (string)$row['target'];
        $status = self::statusOf($row['status']);
        if ($status === 0) {
            $status = self::STATUS_DEFAULT;
        }
        $parsed = self::parseTarget($target);
        if ($parsed === null) {
            return null;
        }
        if ($parsed['kind'] === 'path' && $parsed['value'] === $lookup) {
            return null;
        }
        $location = self::locationFor($parsed, $ctx, $brand);
        if ($location === null || $location === '') {
            return null;
        }
        $reqPath = (string)($ctx['request_path'] ?? '');
        $locPath = (string)(parse_url($location, PHP_URL_PATH) ?: $location);
        if ($reqPath !== '' && rtrim($reqPath, '/') === rtrim($locPath, '/')) {
            $reqQuery = (string)parse_url((string)($ctx['request_uri'] ?? ''), PHP_URL_QUERY);
            $locQuery = (string)(parse_url($location, PHP_URL_QUERY) ?? '');
            if ($reqQuery === $locQuery) {
                return null;
            }
        }
        return ['location' => $location, 'status' => $status];
    }

    public static function rowById(PDO $db, string $brand, string $id): ?array
    {
        $q = $db->prepare('SELECT id, path, target, status, source, created, updated FROM link_tree WHERE id = ? AND brand = ? LIMIT 1');
        $q->execute([$id, $brand]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function rowByPath(PDO $db, string $brand, string $path): ?array
    {
        $path = self::normalize($path);
        if ($path === null || $path === '') {
            return null;
        }
        $q = $db->prepare('SELECT id, path, target, status, source, created, updated FROM link_tree WHERE brand = ? AND path = ? LIMIT 1');
        $q->execute([$brand, $path]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function publicRow(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        return [
            'id' => (string)$row['id'],
            'path' => (string)$row['path'],
            'target' => $row['target'] !== null && $row['target'] !== '' ? (string)$row['target'] : null,
            'status' => self::statusOf($row['status']) ?: self::STATUS_DEFAULT,
            'source' => (string)$row['source'],
            'created' => (string)$row['created'],
            'updated' => (string)$row['updated'],
        ];
    }

    /**
     * Write a redirect (manual or auto). $target already parsed. Collapses one hop.
     * Returns the public row. Throws InvalidArgumentException on loop or conflict with a live file.
     */
    public static function setRedirect(PDO $db, string $brand, string $path, string $target, int $status, string $source, string $by): array
    {
        $pathN = self::normalize($path);
        if ($pathN === null || $pathN === '') {
            throw new InvalidArgumentException('path is not a valid file path');
        }
        $parsed = self::parseTarget($target);
        if ($parsed === null) {
            throw new InvalidArgumentException('target must be a same-brand path or an http(s) URL');
        }
        $status = in_array($status, self::STATUSES, true) ? $status : 0;
        if ($status === 0) {
            throw new InvalidArgumentException('status must be 301 or 302');
        }
        $by = $by !== '' ? $by : 'link_tree';
        if ($parsed['kind'] === 'path') {
            if ($parsed['value'] === $pathN) {
                throw new InvalidArgumentException('target cannot be the same as path');
            }
            if (self::fileExistsAt($db, $brand, $pathN)) {
                throw new InvalidArgumentException('a file already exists at this path; the real file wins');
            }
            $final = self::collapseTo($db, $brand, $parsed['value'], $pathN);
            if ($final === null || $final === $pathN) {
                throw new InvalidArgumentException('target would create a redirect loop');
            }
            self::upsertRedirect($db, $brand, $pathN, $final, $status, $source, $by);
            $db->prepare('UPDATE link_tree SET target = ?, updated = NOW(), updated_by = ? WHERE brand = ? AND target = ? AND path <> ?')
                ->execute([$final, $by, $brand, $pathN, $final]);
            $db->prepare('DELETE FROM link_tree WHERE brand = ? AND target IS NOT NULL AND target <> \'\' AND path = target')
                ->execute([$brand]);
        } else {
            if (self::fileExistsAt($db, $brand, $pathN)) {
                throw new InvalidArgumentException('a file already exists at this path; the real file wins');
            }
            self::upsertRedirect($db, $brand, $pathN, $parsed['value'], $status, $source, $by);
        }
        $row = self::rowByPath($db, $brand, $pathN);
        if ($row === null) {
            throw new RuntimeException('could not save the redirect');
        }
        return self::publicRow($row);
    }

    public static function deleteRow(PDO $db, string $brand, string $id): bool
    {
        $st = $db->prepare('DELETE FROM link_tree WHERE id = ? AND brand = ?');
        $st->execute([$id, $brand]);
        return $st->rowCount() > 0;
    }

    private static function upsertRedirect(PDO $db, string $brand, string $path, string $target, int $status, string $source, string $by): void
    {
        $q = $db->prepare('SELECT id FROM link_tree WHERE brand = ? AND path = ? LIMIT 1');
        $q->execute([$brand, $path]);
        $id = $q->fetchColumn();
        if ($id) {
            $db->prepare('UPDATE link_tree SET target = ?, status = ?, source = ?, updated = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$target, $status, $source, $by, $id]);
            return;
        }
        $db->prepare('INSERT INTO link_tree (id, brand, path, target, status, source, created, created_by, updated, updated_by) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), ?)')
            ->execute([Dw::randId(), $brand, $path, $target, $status, $source, $by, $by]);
    }

    /** Follow at most one existing redirect so chains stay flat. $avoid is a path we must not land on. */
    private static function collapseTo(PDO $db, string $brand, string $target, string $avoid): ?string
    {
        if ($target === $avoid) {
            return null;
        }
        $q = $db->prepare('SELECT target FROM link_tree WHERE brand = ? AND path = ? AND target IS NOT NULL AND target <> \'\' LIMIT 1');
        $q->execute([$brand, $target]);
        $next = $q->fetchColumn();
        if (!is_string($next) || $next === '') {
            return $target;
        }
        if (self::isAbsoluteUrl($next)) {
            return $next;
        }
        $n = self::normalize($next);
        if ($n === null || $n === '' || $n === $avoid || $n === $target) {
            return null;
        }
        return $n;
    }

    private static function locationFor(array $parsed, array $ctx, string $brand): ?string
    {
        if ($parsed['kind'] === 'url') {
            $location = $parsed['value'];
            return self::withQuery($location, $ctx);
        }
        $targetPath = $parsed['value'];
        $site = isset($ctx['site_prefix']) && is_string($ctx['site_prefix']) ? $ctx['site_prefix'] : '';
        if ($site !== '') {
            if ($targetPath === $site) {
                $targetPath = '';
            } elseif (str_starts_with($targetPath, $site . '/')) {
                $targetPath = substr($targetPath, strlen($site) + 1);
            }
        }
        $encoded = self::encodePath($targetPath);
        if (!empty($ctx['is_app_host'])) {
            $location = '/' . rawurlencode($brand) . ($encoded !== '' ? '/' . $encoded : '');
        } else {
            $location = $encoded !== '' ? '/' . $encoded : '/';
        }
        return self::withQuery($location, $ctx);
    }

    private static function encodePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $parts = explode('/', $path);
        return implode('/', array_map('rawurlencode', $parts));
    }

    private static function withQuery(string $location, array $ctx): string
    {
        if (strpos($location, '?') !== false) {
            return $location;
        }
        $query = (string)parse_url((string)($ctx['request_uri'] ?? ''), PHP_URL_QUERY);
        if ($query === '') {
            return $location;
        }
        return $location . '?' . $query;
    }

    private static function unlinkLegacy(string $brand, ?array $row): void
    {
        if (!$row) {
            return;
        }
        try {
            if (!class_exists('DwHtmlCache')) {
                require_once '/var/www/html/lib/DwHtmlCache.php';
            }
            DwHtmlCache::unlinkLegacy(
                $brand,
                trim((string)($row['file_path'] ?? ''), '/'),
                (string)($row['file_name'] ?? '')
            );
        } catch (Throwable $e) {
            // Serving may still hit 404.php on customer domains.
        }
    }
}
