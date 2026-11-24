<?php
declare(strict_types=1);

require_once '/var/www/html/lib/DwDomain.php';

// ChatGPT as the DW site editor (refs #67, staging 2026-11-21): safe-edit actions for /files.
// Loaded only by files/index.php (after its session/Bearer and FILE-right checks) and by files/preview/.
// Uses files/index.php's helpers (fail, fileBytes, textField, typeFor, archive, watermarkGate,
// bumpHtmlCacheIfNeeded, cacheRelFromRow, utcOffset, and refuseStorage where that file has it).
//
//   draft    FILE CREATE  new bytes as a PRIVATE copy; replaces = live file id (found by name+path if omitted)
//                         -> id, replaces, preview_url (works without sign-in until preview_expires)
//   preview  FILE READ    a fresh preview_url for a draft
//   publish  FILE UPDATE  draft -> live: the replaced file gets the draft's bytes and becomes PUBLIC (old bytes
//                         stay in files_history), or a new draft itself becomes PUBLIC. -> live_id, live_url
//   discard  FILE UPDATE  delete a draft (kept in files_history)
//   versions FILE READ    the file's earlier versions from files_history (metadata + md5)
//   restore  FILE CREATE  an earlier version (history_id) as a new draft of that file, to preview then publish
final class DwFilesEditor
{
    const ACTIONS = ['draft', 'preview', 'publish', 'discard', 'versions', 'restore'];
    const PREVIEW_TTL_DAYS = 7;
    const TEXT_TYPES = ['HTML', 'CSS', 'JS', 'JSON', 'TXT', 'SVG'];
    const MIME = ['HTML' => 'text/html; charset=utf-8', 'CSS' => 'text/css; charset=utf-8', 'JS' => 'text/javascript; charset=utf-8',
        'JSON' => 'application/json; charset=utf-8', 'TXT' => 'text/plain; charset=utf-8', 'SVG' => 'image/svg+xml', 'JPG' => 'image/jpeg',
        'PNG' => 'image/png', 'GIF' => 'image/gif', 'WEBP' => 'image/webp', 'ICO' => 'image/x-icon', 'PDF' => 'application/pdf',
        'WOFF' => 'font/woff', 'WOFF2' => 'font/woff2', 'MP4' => 'video/mp4'];

    /** The site domain links should use: the OAuth grant's domain, the request host, else the brand's first domain. */
    public static function siteDomain(PDO $db, array $session): ?string
    {
        if (!empty($session['oauth_domain'])) {
            return (string)$session['oauth_domain'];
        }
        $host = DwDomain::requestHost();
        if ($host !== '' && !DwDomain::isAppHost($host) && DwDomain::brandForHost($host, [Dw::class, 'pdo']) === (string)$session['brand']) {
            return $host;
        }
        $q = $db->prepare("SELECT domain_name FROM domain WHERE code = ? ORDER BY domain_name LIKE 'www.%', domain_name LIMIT 1");
        $q->execute([$session['brand']]);
        $d = $q->fetchColumn();
        return is_string($d) && $d !== '' ? $d : null;
    }

    public static function liveUrl(?string $domain, array $row): ?string
    {
        if ($domain === null) {
            return null;
        }
        $rel = cacheRelFromRow($row) ?? '';
        if (strcasecmp($rel, 'index.html') === 0) {
            $rel = '';
        }
        return 'https://' . $domain . '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
    }

    private static function newPreview(PDO $db, string $draftId): array
    {
        $t = bin2hex(random_bytes(24));
        $db->prepare('UPDATE file_draft SET preview_hash = ?, preview_expires = NOW() + INTERVAL ' . self::PREVIEW_TTL_DAYS . ' DAY WHERE id = ?')->execute([hash('sha256', $t), $draftId]);
        Dw::history($db, 'file_draft', $draftId, 'UPDATE');
        $e = $db->prepare('SELECT preview_expires, domain FROM file_draft WHERE id = ?');
        $e->execute([$draftId]);
        $r = $e->fetch(PDO::FETCH_ASSOC) ?: [];
        $dom = (string)($r['domain'] ?? '');
        return ['preview_url' => $dom !== '' ? 'https://' . $dom . '/files/preview/?t=' . $t : null, 'preview_expires' => (string)($r['preview_expires'] ?? '')];
    }

    private static function draftRow(PDO $db, string $id, string $brand, bool $lock = false): ?array
    {
        $q = $db->prepare("SELECT d.*, f.file_name, f.file_path, f.file_type FROM file_draft d JOIN files f ON f.id = d.id WHERE d.id = ? AND d.brand = ? AND f.brand = d.brand AND d.status = 'DRAFT'" . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$id, $brand]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private static function brandFile(PDO $db, string $id, string $brand): ?array
    {
        $q = $db->prepare('SELECT id, file_name, file_path, file_type, public_private, watermark_mode, created, brand FROM files WHERE id = ? AND brand = ?');
        $q->execute([$id, $brand]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Write a PRIVATE draft row + file_draft row. Returns the answer payload. */
    private static function makeDraft(PDO $db, array $sent, array $session, string $bytes, ?string $name, ?string $path, string $type, ?string $replaces): array
    {
        $brand = (string)$session['brand'];
        if (function_exists('refuseStorage')) {
            refuseStorage($db, $sent, $brand, $bytes);
        }
        $id = Dw::randId();
        $domain = self::siteDomain($db, $session);
        $db->beginTransaction();
        $db->prepare("INSERT INTO files (id, file_data, file_name, file_path, file_type, created, brand, owner_id, owner_type, public_private, watermark_mode) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, 'PRSN', 'PRIVATE', 'default')")
            ->execute([$id, $bytes, $name, $path, $type, $brand, $session['person_id']]);
        archive($db, $id, 'INSERT');
        $db->prepare('INSERT INTO file_draft (id, brand, replaces_id, created_by, via, grant_id, domain, status, created) VALUES (?, ?, ?, ?, ?, ?, ?, \'DRAFT\', NOW())')
            ->execute([$id, $brand, $replaces, $session['person_id'], !empty($session['oauth_grant']) ? 'oauth' : 'session', $session['oauth_grant'] ?? null, $domain]);
        Dw::history($db, 'file_draft', $id, 'INSERT');
        require_once '/var/www/html/lib/DwLinkTree.php';
        DwLinkTree::onSaved($db, $brand, null, ['file_name' => $name, 'file_path' => $path], (string)($session['person_id'] ?? 'files'));
        $pv = self::newPreview($db, $id);
        $db->commit();
        return ['ok' => true, 'message' => 'Draft saved. Nothing is live yet. Show the person preview_url; publish only when they say so.',
            'id' => $id, 'replaces' => $replaces, 'file_name' => $name, 'file_path' => $path, 'file_type' => $type] + $pv
            + ['live_url_after_publish' => self::liveUrl($domain, ['file_name' => $name, 'file_path' => $path])];
    }

    public static function handle(string $action, string $id, array $sent, array $session): void
    {
        $db = Dw::pdo();
        $brand = (string)$session['brand'];

        if ($action === 'draft') {
            $bytes = fileBytes($sent);
            if ($bytes === null) {
                fail($sent, 'draft needs the new content: file_text (text files), file_data (base64) or multipart file');
            }
            $replaces = trim((string)($sent['replaces'] ?? ''));
            $target = null;
            if ($replaces !== '') {
                $target = self::brandFile($db, $replaces, $brand);
                if ($target === null) {
                    fail($sent, 'replaces: file not found', 404);
                }
            }
            $name = textField($sent, 'file_name', 100);
            $path = textField($sent, 'file_path', 200);
            if ($target !== null) {
                $name = ($name === null || $name === '') ? $target['file_name'] : $name;
                $path = $path === null ? $target['file_path'] : $path;
            }
            if ($name === null || $name === '') {
                fail($sent, 'file_name is required for a draft of a new file (or send replaces)');
            }
            $path = $path === null ? '' : trim($path, '/');
            if ($target === null) {
                // Same name and folder as a file already on the site: this draft replaces it.
                $q = $db->prepare("SELECT id FROM files f WHERE brand = ? AND file_name = ? AND TRIM(BOTH '/' FROM COALESCE(file_path, '')) = ?
                    AND NOT EXISTS (SELECT 1 FROM file_draft d WHERE d.id = f.id AND d.status = 'DRAFT')
                    ORDER BY public_private = 'PUBLIC' DESC, created DESC, id DESC LIMIT 1");
                $q->execute([$brand, $name, $path]);
                $found = $q->fetchColumn();
                if (is_string($found) && $found !== '') {
                    $target = self::brandFile($db, $found, $brand);
                }
            }
            $type = typeFor($sent, $name) ?? ($target['file_type'] ?? 'TXT');
            Dw::jsonOut(200, self::makeDraft($db, $sent, $session, $bytes, $name, $path, (string)$type, $target['id'] ?? null));
            return;
        }

        if ($action === 'restore') {
            $hid = trim((string)($sent['history_id'] ?? ''));
            $live = self::brandFile($db, $id, $brand);
            $q = $db->prepare('SELECT file_data, file_name, file_path, file_type FROM files_history WHERE history_id = ? AND id = ? AND brand = ?');
            $q->execute([$hid, $id, $brand]);
            $old = $q->fetch(PDO::FETCH_ASSOC);
            if ($live === null || !$old) {
                fail($sent, 'restore needs id (a current file) and history_id (from versions)', 404);
            }
            $out = self::makeDraft($db, $sent, $session, (string)$old['file_data'], $old['file_name'], $old['file_path'], (string)$old['file_type'], $id);
            $out['message'] = 'The earlier version is saved as a draft. Show the person preview_url; publish it only when they say so.';
            Dw::jsonOut(200, $out);
            return;
        }

        if ($action === 'versions') {
            $live = self::brandFile($db, $id, $brand);
            if ($live === null) {
                fail($sent, 'file not found', 404);
            }
            $q = $db->prepare('SELECT history_id, action, archived_at, file_name, file_path, file_type, public_private, OCTET_LENGTH(file_data) AS file_size, MD5(file_data) AS md5 FROM files_history WHERE id = ? AND brand = ? ORDER BY archived_at DESC, history_id LIMIT 50');
            $q->execute([$id, $brand]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['file_size'] = (int)$r['file_size'];
            }
            unset($r);
            Dw::jsonOut(200, ['ok' => true, 'id' => $id, 'created_utc_offset' => utcOffset($db),
                'note' => 'Each row is how the file looked at archived_at (INSERT = first upload; UPDATE = the version just before a change). Use restore with history_id to bring one back as a draft.',
                'versions' => $rows]);
            return;
        }

        $d = self::draftRow($db, $id, $brand);
        if ($d === null) {
            fail($sent, 'draft not found (it may already be published or discarded)', 404);
        }

        if ($action === 'preview') {
            $db->beginTransaction();
            $pv = self::newPreview($db, $id);
            $db->commit();
            Dw::jsonOut(200, ['ok' => true, 'id' => $id] + $pv);
            return;
        }

        if ($action === 'discard') {
            $db->beginTransaction();
            $d = self::draftRow($db, $id, $brand, true);
            if ($d === null) {
                $db->rollBack();
                fail($sent, 'draft not found', 404);
            }
            archive($db, $id, 'DELETE');
            $db->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
            $db->prepare("UPDATE file_draft SET status = 'DISCARDED', preview_hash = NULL WHERE id = ?")->execute([$id]);
            Dw::history($db, 'file_draft', $id, 'UPDATE');
            require_once '/var/www/html/lib/DwLinkTree.php';
            DwLinkTree::onSaved($db, $brand, ['id' => $id, 'file_name' => $d['file_name'] ?? '', 'file_path' => $d['file_path'] ?? ''], null, (string)($session['person_id'] ?? 'files'));
            $db->commit();
            Dw::jsonOut(200, ['ok' => true, 'message' => 'Draft discarded. The live site did not change.', 'id' => $id]);
            return;
        }

        // publish
        $target = $d['replaces_id'] !== null ? self::brandFile($db, (string)$d['replaces_id'], $brand) : null;
        $liveId = $target !== null ? (string)$target['id'] : $id;
        $after = $target !== null ? $target : self::brandFile($db, $id, $brand);
        $after['public_private'] = 'PUBLIC';
        $after['file_type'] = $d['file_type'];
        watermarkGate($db, $sent, $brand, $after, false);
        $db->beginTransaction();
        $d = self::draftRow($db, $id, $brand, true);
        if ($d === null) {
            $db->rollBack();
            fail($sent, 'draft not found (it may already be published)', 404);
        }
        $prev = $db->prepare('SELECT file_type, public_private, brand, file_name, file_path FROM files WHERE id = ? FOR UPDATE');
        $prev->execute([$liveId]);
        $prevRow = $prev->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($target !== null) {
            archive($db, $liveId, 'UPDATE');
            $db->prepare("UPDATE files t JOIN files s ON s.id = ? SET t.file_data = s.file_data, t.file_type = s.file_type, t.file_name = s.file_name, t.file_path = s.file_path, t.public_private = 'PUBLIC' WHERE t.id = ? AND t.brand = s.brand")
                ->execute([$id, $liveId]);
            archive($db, $id, 'DELETE');
            $db->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
        } else {
            archive($db, $id, 'UPDATE');
            $db->prepare("UPDATE files SET public_private = 'PUBLIC' WHERE id = ?")->execute([$id]);
        }
        $db->prepare("UPDATE file_draft SET status = 'PUBLISHED', published_at = NOW(), published_file_id = ?, preview_hash = NULL WHERE id = ?")->execute([$liveId, $id]);
        Dw::history($db, 'file_draft', $id, 'UPDATE');
        $now = $db->prepare('SELECT file_type, public_private, brand, file_name, file_path FROM files WHERE id = ?');
        $now->execute([$liveId]);
        $afterRow = $now->fetch(PDO::FETCH_ASSOC) ?: $prevRow;
        $by = (string)$session['person_id'];
        if ($target !== null && is_array($prevRow)) {
            bumpHtmlCacheIfNeeded($db, $prevRow, $by, $liveId, cacheRelFromRow($prevRow));
        }
        bumpHtmlCacheIfNeeded($db, is_array($afterRow) ? $afterRow : null, $by, $liveId, is_array($afterRow) ? cacheRelFromRow($afterRow) : null);
        require_once '/var/www/html/lib/DwLinkTree.php';
        if ($target !== null) {
            // Live file may have been renamed to the draft's path; drop the draft path if that file is gone.
            DwLinkTree::onSaved($db, $brand, is_array($prevRow) ? $prevRow : null, is_array($afterRow) ? $afterRow : null, $by);
            $draftPath = ['id' => $id, 'file_name' => $d['file_name'] ?? '', 'file_path' => $d['file_path'] ?? ''];
            DwLinkTree::onSaved($db, $brand, $draftPath, null, $by);
        } else {
            DwLinkTree::onSaved($db, $brand, is_array($prevRow) ? $prevRow : null, is_array($afterRow) ? $afterRow : null, $by);
        }
        $db->commit();
        Dw::jsonOut(200, ['ok' => true, 'message' => 'Published. The live site shows it within about a minute (page cache). The earlier version is kept in version history.', 'id' => $id, 'live_id' => $liveId,
            'live_url' => self::liveUrl(self::siteDomain($db, $session), is_array($afterRow) ? $afterRow : [])]);
    }

    // ------------------------------------------------------------------ GET /files/preview/?t=
    public static function previewRoute(): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        $t = (string)($_GET['t'] ?? '');
        $nf = function (): void {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Preview expired</title><p style="font:16px sans-serif;margin:40px">This preview link has expired or the draft was published or discarded. Ask for a new preview.</p>';
            exit;
        };
        if (!preg_match('/^[0-9a-f]{48}$/', $t)) {
            $nf();
        }
        require_once '/var/www/html/lib/DwOAuth.php';
        $host = DwOAuth::host();
        $brand = DwDomain::isAppHost($host) ? null : DwDomain::brandForHost($host, [Dw::class, 'pdo']);
        if ($brand === null) {
            $nf();
        }
        $q = Dw::pdo()->prepare("SELECT f.file_data, f.file_type, f.file_path FROM file_draft d JOIN files f ON f.id = d.id
            WHERE d.preview_hash = ? AND d.status = 'DRAFT' AND d.preview_expires > NOW() AND d.brand = ? AND f.brand = d.brand LIMIT 1");
        $q->execute([hash('sha256', $t), $brand]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            $nf();
        }
        $type = (string)$r['file_type'];
        header('Content-Type: ' . (self::MIME[$type] ?? 'application/octet-stream'));
        $body = (string)$r['file_data'];
        if ($type === 'HTML') {
            // Relative links resolve against the live site folder; a banner says this is not live yet.
            $dir = trim((string)$r['file_path'], '/');
            $base = '<base href="/' . htmlspecialchars($dir === '' ? '' : implode('/', array_map('rawurlencode', explode('/', $dir))) . '/', ENT_QUOTES) . '">';
            $meta = '<meta name="robots" content="noindex,nofollow">';
            $banner = '<div style="position:fixed;z-index:2147483647;left:0;right:0;bottom:0;background:#0b1f3a;color:#fff;font:600 14px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;text-align:center;padding:8px 12px;opacity:.94">DRAFT PREVIEW &middot; not live yet &middot; publish from ChatGPT when it looks right</div>';
            $head = (stripos($body, '<base') === false ? $base : '') . $meta;
            $body = preg_match('/<head[^>]*>/i', $body) ? (string)preg_replace('/<head[^>]*>/i', '$0' . $head, $body, 1) : $head . $body;
            $body = stripos($body, '</body>') !== false ? (string)preg_replace('#</body>#i', $banner . '</body>', $body, 1) : $body . $banner;
        }
        echo $body;
        exit;
    }
}
