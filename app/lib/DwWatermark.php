<?php
declare(strict_types=1);

require_once __DIR__ . '/DwBrand.php';
// Watermarks on PUBLIC JPG/PNG/WEBP and PDFs (refs #51).
// Two independent marks:
//   * DW promo mark on free sites. Checkout paths and DW's own sites are skipped.
//     Only an in-force no_banner entitlement hides it. File/domain/global owner-mark
//     switches, and staff banner_mode / banner_default, cannot turn it off.
//   * Owner marks on any site. File mode, then domain mode, then app_setting
//     watermark_default; most specific wins. Style lives on domain.watermark_config.
// Originals in the files table are never modified. 404.php calls apply() on a cache
// miss and writes the result to the host-keyed html-cache (outside the docroot).
//
// Images: PHP GD (php8.2-gd, FreeType). Tiny images (max edge < MIN_EDGE px) and
// SVG/ICO/GIF are skipped. PDFs: every page is stamped with FPDI + FPDF
// (lib/vendor) using a GD-rendered transparent PNG — no Ghostscript.

require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwBanner.php';

final class DwWatermark
{
    public const TYPES = ['JPG', 'PNG', 'WEBP', 'PDF'];
    /** Skip icons/logos whose longer side is below this many pixels. */
    public const MIN_EDGE = 128;
    public const FONT = '/var/www/html/lib/fonts/DejaVuSans.ttf';
    public const FONT_BOLD = '/var/www/html/lib/fonts/DejaVuSans-Bold.ttf';
    public const FONT_FALLBACK = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    public const DW_LINE1 = 'Made with DW Alliance';
    public const DW_LINE2 = 'dwalliance.com';
    public const POSITIONS = ['bottom-right', 'bottom-left', 'top-right', 'top-left', 'center', 'diagonal', 'tile'];
    public const KINDS = ['text', 'image'];
    public const JPEG_QUALITY = 90;
    public const WEBP_QUALITY = 85;

    /** @var array<string,mixed>|null CLI test hook. */
    public static ?array $testPlan = null;
    public static ?string $testFont = null;

    public static function isType(string $fileType): bool
    {
        return in_array(strtoupper($fileType), self::TYPES, true);
    }

    public static function font(): string
    {
        if (PHP_SAPI === 'cli' && is_string(self::$testFont) && self::$testFont !== '' && is_file(self::$testFont)) {
            return self::$testFont;
        }
        foreach ([self::FONT, self::FONT_BOLD, self::FONT_FALLBACK] as $f) {
            if (is_file($f) && is_readable($f)) {
                return $f === self::FONT_BOLD ? self::FONT : $f;
            }
        }
        return self::FONT;
    }

    /**
     * Owner-mark precedence: file on/off wins; file default follows the domain;
     * domain default follows the global default (missing global = off).
     */
    public static function ownerApplies(string $fileMode, string $domainMode, string $globalDefault): bool
    {
        $file = in_array($fileMode, ['on', 'off', 'default'], true) ? $fileMode : 'default';
        $dom = in_array($domainMode, ['on', 'off', 'default'], true) ? $domainMode : 'default';
        $g = $globalDefault === 'on' ? 'on' : 'off';
        if ($file === 'on' || $file === 'off') {
            return $file === 'on';
        }
        if ($dom === 'on' || $dom === 'off') {
            return $dom === 'on';
        }
        return $g === 'on';
    }

    /**
     * Should this response carry the DW promo mark? Independent of banner_mode
     * and of owner watermark_mode. $path is the request path.
     */
    public static function dwApplies(string $host, string $brand, bool $appHost, string $path): bool
    {
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($m !== 'GET' && $m !== 'HEAD') {
            return false;
        }
        $c = DwBanner::cfg();
        $re = (string)$c['skip_path_regex'];
        if ($re !== '' && @preg_match($re, $path) === 1) {
            return false;
        }
        if (!$appHost && in_array(DwBanner::bare($host), array_map([DwBanner::class, 'bare'], (array)$c['exempt_hosts']), true)) {
            return false;
        }
        try {
            $seller = self::sellerBrand();
            if ($seller !== null && hash_equals($seller, $brand)) {
                return false;
            }
            $db = Dw::pdo();
            return !DwBanner::hasNoBanner($db, $brand, $appHost ? null : DwBanner::bare($host));
        } catch (Throwable $e) {
            error_log('DwWatermark::dwApplies failed, hiding DW mark: ' . get_class($e));
            return false;
        }
    }

    /**
     * Count of owner-watermarked files for a brand, and which ids they are (oldest first).
     *
     * A file counts when all of these hold:
     *   - it is PUBLIC
     *   - its type is one that can be stamped (JPG, PNG, WEBP, PDF)
     *   - the owner mark is on: per-file watermark_mode 'on', or 'default' while a
     *     domain of this brand (or the global watermark_default) would turn the
     *     owner mark on (see ownerApplies). Per-file 'off' never counts.
     *
     * Removing a mark (file set to off, deleting the file, or changing the domain /
     * global default so a 'default' file no longer applies) frees a slot. The
     * remaining counted files keep their order (created, then id); the next oldest
     * unmarked-by-quota file can take a freed slot.
     *
     * $opts lets callers simulate a change without writing:
     *   domain_modes: [domain_id => 'on'|'off'|'default']
     *   files: [file_id => ['watermark_mode'=>, 'public_private'=>, 'file_type'=>, 'created'=>]]
     *   extra: a not-yet-saved file row (create), must include id and the fields above.
     *
     * @return array{used:int,free_count:int,ids:list<string>}
     */
    public static function ownerUsage(PDO $db, string $brand, array $opts = []): array
    {
        require_once '/var/www/html/lib/DwGate.php';
        $free = (int)(DwGate::config('watermark')['free_count'] ?? 0);
        $ids = self::ownerMarkedIds($db, $brand, $opts);
        return ['used' => count($ids), 'free_count' => $free, 'ids' => $ids];
    }

    /** @return list<string> */
    public static function ownerMarkedIds(PDO $db, string $brand, array $opts = []): array
    {
        $global = 'off';
        try {
            $gq = $db->prepare("SELECT setting_value FROM app_setting WHERE setting_name = 'watermark_default' LIMIT 1");
            $gq->execute();
            $gv = $gq->fetchColumn();
            if (is_string($gv) && ($gv === 'on' || $gv === 'off')) {
                $global = $gv;
            }
        } catch (Throwable $e) {
            $global = 'off';
        }
        $domains = [];
        try {
            $dq = $db->prepare('SELECT id, watermark_mode FROM domain WHERE code = ?');
            $dq->execute([$brand]);
            $domains = $dq->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $domains = [];
        }
        $overrides = (array)($opts['domain_modes'] ?? []);
        $defaultApplies = $domains === [] && $global === 'on';
        foreach ($domains as $d) {
            $id = (string)$d['id'];
            $mode = strtolower((string)($overrides[$id] ?? $d['watermark_mode'] ?? 'default'));
            if (self::ownerApplies('default', $mode, $global)) {
                $defaultApplies = true;
                break;
            }
        }
        $fileOverrides = (array)($opts['files'] ?? []);
        $rows = [];
        try {
            $q = $db->prepare('SELECT id, watermark_mode, public_private, file_type, created FROM files WHERE brand = ?');
            $q->execute([$brand]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }
        $byId = [];
        foreach ($rows as $r) {
            $byId[(string)$r['id']] = $r;
        }
        foreach ($fileOverrides as $id => $patch) {
            $id = (string)$id;
            $byId[$id] = array_merge($byId[$id] ?? ['id' => $id, 'watermark_mode' => 'default', 'public_private' => 'PRIVATE', 'file_type' => '', 'created' => '9999-12-31 23:59:59'], $patch, ['id' => $id]);
        }
        if (is_array($opts['extra'] ?? null) && (string)($opts['extra']['id'] ?? '') !== '') {
            $ex = $opts['extra'];
            $id = (string)$ex['id'];
            $byId[$id] = array_merge($byId[$id] ?? ['id' => $id, 'watermark_mode' => 'default', 'public_private' => 'PRIVATE', 'file_type' => '', 'created' => '9999-12-31 23:59:59'], $ex, ['id' => $id]);
        }
        $marked = [];
        foreach ($byId as $id => $r) {
            if (strtoupper((string)($r['public_private'] ?? '')) !== 'PUBLIC') {
                continue;
            }
            if (!self::isType((string)($r['file_type'] ?? ''))) {
                continue;
            }
            $mode = strtolower((string)($r['watermark_mode'] ?? 'default'));
            if ($mode === 'on' || ($mode === 'default' && $defaultApplies)) {
                $marked[] = ['id' => (string)$id, 'created' => (string)($r['created'] ?? ''), 'idkey' => (string)$id];
            }
        }
        usort($marked, fn($a, $b) => [$a['created'], $a['idkey']] <=> [$b['created'], $b['idkey']]);
        return array_column($marked, 'id');
    }

    // This file may carry an owner mark: paid grant / seller / gate off / grace, or it is among the first free_count marked files.
    public static function ownerMarkAllowed(PDO $db, string $brand, string $fileId, array $opts = []): bool
    {
        try {
            require_once '/var/www/html/lib/DwGate.php';
            if (DwGate::check($db, $brand, 'watermark')['allowed']) {
                return true;
            }
            $u = self::ownerUsage($db, $brand, $opts);
            $rank = array_search($fileId, $u['ids'], true);
            return $rank !== false && $rank < $u['free_count'];
        } catch (Throwable $e) {
            error_log('DwWatermark gate check failed: ' . get_class($e));
            return true;
        }
    }

    // Null = the change is allowed. Otherwise the DwGate::check() body to return as HTTP 402.
    // Already-marked files keep their slots; only a change that adds marks past free_count is refused.
    public static function ownerMarkRefuseChange(PDO $db, string $brand, array $afterOpts): ?array
    {
        try {
            require_once '/var/www/html/lib/DwGate.php';
            $c = DwGate::check($db, $brand, 'watermark');
            $before = self::ownerUsage($db, $brand);
            $after = self::ownerUsage($db, $brand, $afterOpts);
            $c['usage'] = $after;
            if ($c['allowed']) {
                return null;
            }
            if ($after['used'] <= $after['free_count'] || $after['used'] <= $before['used']) {
                return null;
            }
            return $c;
        } catch (Throwable $e) {
            error_log('DwWatermark gate check failed: ' . get_class($e));
            return null;
        }
    }


    /**
     * @param array<string,mixed> $fileRow must include id, file_type, watermark_mode (optional)
     * @return array{dw:bool, owner:?array, skip:?string, file_id:string}
     */
    public static function plan(PDO $db, string $host, string $brand, bool $appHost, string $path, array $fileRow, ?string $bareHost = null): array
    {
        if (PHP_SAPI === 'cli' && is_array(self::$testPlan)) {
            return self::$testPlan;
        }
        $fileId = (string)($fileRow['id'] ?? '');
        $out = ['dw' => false, 'owner' => null, 'skip' => null, 'file_id' => $fileId];
        $type = strtoupper((string)($fileRow['file_type'] ?? ''));
        if (!self::isType($type)) {
            $out['skip'] = 'type';
            return $out;
        }
        $bare = $bareHost ?? ($appHost ? null : DwBanner::bare($host));
        $out['dw'] = self::dwApplies($host, $brand, $appHost, $path);
        $fileMode = strtolower((string)($fileRow['watermark_mode'] ?? 'default'));
        $domainMode = 'default';
        $cfgJson = null;
        try {
            if ($bare !== null) {
                $q = $db->prepare("SELECT watermark_mode, watermark_config FROM domain
                    WHERE code = ? AND domain_name IN (?, ?) ORDER BY domain_name = ? DESC LIMIT 1");
                $q->execute([$brand, $bare, 'www.' . $bare, $bare]);
            } else {
                $q = $db->prepare("SELECT watermark_mode, watermark_config FROM domain
                    WHERE code = ? ORDER BY domain_name LIMIT 1");
                $q->execute([$brand]);
            }
            $drow = $q->fetch(PDO::FETCH_ASSOC);
            if (is_array($drow)) {
                $domainMode = strtolower((string)($drow['watermark_mode'] ?? 'default'));
                $cfgJson = $drow['watermark_config'] ?? null;
            }
            $g = 'off';
            $gq = $db->prepare("SELECT setting_value FROM app_setting WHERE setting_name = 'watermark_default' LIMIT 1");
            $gq->execute();
            $gv = $gq->fetchColumn();
            if (is_string($gv) && ($gv === 'on' || $gv === 'off')) {
                $g = $gv;
            }
        } catch (Throwable $e) {
            error_log('DwWatermark::plan owner lookup failed: ' . get_class($e));
            $g = 'off';
        }
        if (self::ownerApplies($fileMode, $domainMode, $g ?? 'off')) {
            $opts = $fileId !== ''
                ? ['extra' => ['id' => $fileId, 'watermark_mode' => $fileMode, 'public_private' => 'PUBLIC', 'file_type' => $type, 'created' => (string)($fileRow['created'] ?? '9999-12-31 23:59:59')]]
                : [];
            if (self::ownerMarkAllowed($db, $brand, $fileId, $opts)) {
                $defaultText = $bare ?? $brand;
                $out['owner'] = self::normalizeConfig(is_string($cfgJson) ? $cfgJson : (is_array($cfgJson) ? json_encode($cfgJson) : null), $defaultText);
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $plan
     */
    public static function apply(string $bytes, string $fileType, array $plan, ?PDO $db = null, ?string $brand = null): string
    {
        $type = strtoupper($fileType);
        if ($bytes === '' || !self::isType($type)) {
            return $bytes;
        }
        $dw = !empty($plan['dw']);
        $owner = is_array($plan['owner'] ?? null) ? $plan['owner'] : null;
        if (!$dw && $owner === null) {
            return $bytes;
        }
        try {
            if ($type === 'PDF') {
                return self::stampPdf($bytes, $dw, $owner, $db, $brand);
            }
            return self::stampImage($bytes, $type, $dw, $owner, $db, $brand);
        } catch (Throwable $e) {
            error_log('DwWatermark::apply failed, serving original: ' . $e->getMessage());
            return $bytes;
        }
    }

    /**
     * @param array<string,mixed>|null $json
     * @return array{kind:string,text:string,image_file_id:?string,position:string,size:float,opacity:float,color:string,angle:float}
     */
    public static function normalizeConfig(?string $json, string $defaultText): array
    {
        $j = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($j)) {
            $j = [];
        }
        $kind = strtolower(trim((string)($j['kind'] ?? 'text')));
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'text';
        }
        $text = trim((string)($j['text'] ?? ''));
        if ($text === '') {
            $text = $defaultText;
        }
        if (strlen($text) > 80) {
            $text = substr($text, 0, 80);
        }
        $imgId = trim((string)($j['image_file_id'] ?? ''));
        $pos = strtolower(trim((string)($j['position'] ?? 'diagonal')));
        if (!in_array($pos, self::POSITIONS, true)) {
            $pos = 'diagonal';
        }
        $size = isset($j['size']) ? (float)$j['size'] : 12.0;
        $size = max(4.0, min(40.0, $size));
        $op = isset($j['opacity']) ? (float)$j['opacity'] : 0.22;
        $op = max(0.05, min(1.0, $op));
        $color = strtoupper(trim((string)($j['color'] ?? '#FFFFFF')));
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            $color = '#FFFFFF';
        }
        $angle = isset($j['angle']) ? (float)$j['angle'] : 45.0;
        if ($angle < 0 || $angle > 360) {
            $angle = 45.0;
        }
        return [
            'kind' => $kind,
            'text' => $text,
            'image_file_id' => $imgId !== '' ? $imgId : null,
            'position' => $pos,
            'size' => $size,
            'opacity' => $op,
            'color' => $color,
            'angle' => $angle,
        ];
    }

    /** @param array<string,mixed> $cfg */
    public static function encodeConfig(array $cfg): string
    {
        $n = self::normalizeConfig(json_encode($cfg), (string)($cfg['text'] ?? ''));
        $j = json_encode($n, JSON_UNESCAPED_SLASHES);
        return is_string($j) ? $j : '{}';
    }

    // ----- images (GD) -----

    /**
     * @param array<string,mixed>|null $owner
     */
    private static function stampImage(string $bytes, string $type, bool $dw, ?array $owner, ?PDO $db, ?string $brand): string
    {
        $im = @imagecreatefromstring($bytes);
        if (!$im instanceof GdImage) {
            return $bytes;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 1 || $h < 1) {
            imagedestroy($im);
            return $bytes;
        }
        if (max($w, $h) < self::MIN_EDGE) {
            imagedestroy($im);
            return $bytes;
        }
        if (!imageistruecolor($im)) {
            $tc = imagecreatetruecolor($w, $h);
            imagealphablending($tc, false);
            imagesavealpha($tc, true);
            $clear = imagecolorallocatealpha($tc, 0, 0, 0, 127);
            imagefilledrectangle($tc, 0, 0, $w, $h, $clear);
            imagealphablending($tc, true);
            imagecopy($tc, $im, 0, 0, 0, 0, $w, $h);
            imagedestroy($im);
            $im = $tc;
        }
        imagealphablending($im, true);
        imagesavealpha($im, true);

        if ($owner !== null) {
            self::paintOwner($im, $w, $h, $owner, $db, $brand);
        }
        if ($dw) {
            self::paintDw($im, $w, $h);
        }

        ob_start();
        $ok = false;
        if ($type === 'JPG') {
            $ok = imagejpeg($im, null, self::JPEG_QUALITY);
        } elseif ($type === 'PNG') {
            $ok = imagepng($im, null, 6);
        } elseif ($type === 'WEBP') {
            $ok = imagewebp($im, null, self::WEBP_QUALITY);
        }
        $out = ob_get_clean();
        imagedestroy($im);
        return ($ok && is_string($out) && $out !== '') ? $out : $bytes;
    }

    private static function paintDw(GdImage $im, int $w, int $h): void
    {
        $font = self::font();
        $bold = is_file(self::FONT_BOLD) ? self::FONT_BOLD : $font;
        if (!is_file($font)) {
            return;
        }
        $min = min($w, $h);
        $size = max(8.0, min(28.0, $min * 0.028));
        $pad = (int)max(8, $min * 0.02);
        $angle = 0.0;
        $line1 = DwBrand::t(self::DW_LINE1);
        $line2 = DwBrand::t(self::DW_LINE2);
        $b1 = imagettfbbox($size, $angle, $bold, $line1);
        $b2 = imagettfbbox($size * 0.85, $angle, $font, $line2);
        if ($b1 === false || $b2 === false) {
            return;
        }
        $tw = max(self::bboxWidth($b1), self::bboxWidth($b2));
        $th = self::bboxHeight($b1) + self::bboxHeight($b2) + (int)($size * 0.35);
        $x = $w - $pad - $tw;
        $y2 = $h - $pad;
        $y1 = $y2 - self::bboxHeight($b2) - (int)($size * 0.35);
        $x = max($pad, $x);
        $shadow = imagecolorallocatealpha($im, 0, 0, 0, 70);
        $fg = imagecolorallocatealpha($im, 255, 255, 255, 55); // ~57% opaque
        if ($shadow === false || $fg === false) {
            return;
        }
        imagettftext($im, $size, $angle, $x + 1, $y1 + 1, $shadow, $bold, $line1);
        imagettftext($im, $size, $angle, $x, $y1, $fg, $bold, $line1);
        imagettftext($im, $size * 0.85, $angle, $x + 1, $y2 + 1, $shadow, $font, $line2);
        imagettftext($im, $size * 0.85, $angle, $x, $y2, $fg, $font, $line2);
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private static function paintOwner(GdImage $im, int $w, int $h, array $cfg, ?PDO $db, ?string $brand): void
    {
        $kind = (string)$cfg['kind'];
        if ($kind === 'image' && $db instanceof PDO && is_string($cfg['image_file_id'] ?? null) && $brand !== null) {
            if (self::paintOwnerImage($im, $w, $h, $cfg, $db, $brand)) {
                return;
            }
            // Fall through to text if the logo cannot be loaded.
        }
        self::paintOwnerText($im, $w, $h, $cfg);
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private static function paintOwnerText(GdImage $im, int $w, int $h, array $cfg): void
    {
        $font = self::font();
        if (!is_file($font)) {
            return;
        }
        $text = (string)$cfg['text'];
        if ($text === '') {
            return;
        }
        $min = min($w, $h);
        $size = max(8.0, $min * ((float)$cfg['size'] / 100.0));
        $angle = (float)$cfg['angle'];
        $pos = (string)$cfg['position'];
        [$r, $g, $b] = self::hexRgb((string)$cfg['color']);
        $alpha = (int)round((1.0 - (float)$cfg['opacity']) * 127);
        $alpha = max(0, min(127, $alpha));
        $col = imagecolorallocatealpha($im, $r, $g, $b, $alpha);
        $shadow = imagecolorallocatealpha($im, 0, 0, 0, min(127, $alpha + 20));
        if ($col === false) {
            return;
        }
        if ($pos === 'tile' || $pos === 'diagonal') {
            $useAngle = $pos === 'tile' ? $angle : $angle;
            $bbox = imagettfbbox($size, $useAngle, $font, $text);
            if ($bbox === false) {
                return;
            }
            $tw = self::bboxWidth($bbox);
            $th = self::bboxHeight($bbox);
            if ($pos === 'diagonal') {
                $x = (int)(($w - $tw) / 2);
                $y = (int)(($h + $th) / 2);
                if ($shadow !== false) {
                    imagettftext($im, $size, $useAngle, $x + 1, $y + 1, $shadow, $font, $text);
                }
                imagettftext($im, $size, $useAngle, $x, $y, $col, $font, $text);
                return;
            }
            $stepX = max($tw + (int)($min * 0.12), (int)($min * 0.25));
            $stepY = max($th + (int)($min * 0.12), (int)($min * 0.25));
            for ($yy = -$stepY; $yy < $h + $stepY; $yy += $stepY) {
                for ($xx = -$stepX; $xx < $w + $stepX; $xx += $stepX) {
                    imagettftext($im, $size, $useAngle, $xx, $yy + $th, $col, $font, $text);
                }
            }
            return;
        }
        $bbox = imagettfbbox($size, 0, $font, $text);
        if ($bbox === false) {
            return;
        }
        $tw = self::bboxWidth($bbox);
        $th = self::bboxHeight($bbox);
        $pad = (int)max(8, $min * 0.03);
        [$x, $y] = self::anchor($pos, $w, $h, $tw, $th, $pad);
        if ($shadow !== false) {
            imagettftext($im, $size, 0, $x + 1, $y + 1, $shadow, $font, $text);
        }
        imagettftext($im, $size, 0, $x, $y, $col, $font, $text);
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private static function paintOwnerImage(GdImage $im, int $w, int $h, array $cfg, PDO $db, string $brand): bool
    {
        $logo = self::loadLogo($db, $brand, (string)$cfg['image_file_id']);
        if ($logo === null) {
            return false;
        }
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        if ($lw < 1 || $lh < 1) {
            imagedestroy($logo);
            return false;
        }
        $min = min($w, $h);
        $target = max(8, (int)round($min * ((float)$cfg['size'] / 100.0)));
        $scale = $target / max($lw, $lh);
        $nw = max(1, (int)round($lw * $scale));
        $nh = max(1, (int)round($lh * $scale));
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $clear = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $nw, $nh, $clear);
        imagealphablending($resized, true);
        imagecopyresampled($resized, $logo, 0, 0, 0, 0, $nw, $nh, $lw, $lh);
        imagedestroy($logo);
        self::multiplyAlpha($resized, (float)$cfg['opacity']);
        $pos = (string)$cfg['position'];
        $pad = (int)max(8, $min * 0.03);
        if ($pos === 'tile') {
            $stepX = $nw + $pad;
            $stepY = $nh + $pad;
            for ($yy = 0; $yy < $h; $yy += $stepY) {
                for ($xx = 0; $xx < $w; $xx += $stepX) {
                    imagecopy($im, $resized, $xx, $yy, 0, 0, $nw, $nh);
                }
            }
            imagedestroy($resized);
            return true;
        }
        if ($pos === 'diagonal' || $pos === 'center') {
            $x = (int)(($w - $nw) / 2);
            $y = (int)(($h - $nh) / 2);
        } else {
            [$x, $yTop] = self::anchor($pos, $w, $h, $nw, $nh, $pad);
            // anchor() returns baseline y for text; for images we want top-left.
            $y = in_array($pos, ['bottom-right', 'bottom-left'], true) ? ($h - $pad - $nh) : $pad;
            if (in_array($pos, ['top-right', 'bottom-right'], true)) {
                $x = $w - $pad - $nw;
            } elseif (in_array($pos, ['top-left', 'bottom-left'], true)) {
                $x = $pad;
            }
        }
        imagecopy($im, $resized, max(0, $x), max(0, $y), 0, 0, $nw, $nh);
        imagedestroy($resized);
        return true;
    }

    private static function loadLogo(PDO $db, string $brand, string $fileId): ?GdImage
    {
        if ($fileId === '' || strlen($fileId) > 50) {
            return null;
        }
        try {
            $q = $db->prepare("SELECT file_data, file_type FROM files WHERE id = ? AND brand = ? AND file_type IN ('JPG','PNG','WEBP') LIMIT 1");
            $q->execute([$fileId, $brand]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !is_string($row['file_data']) || $row['file_data'] === '') {
                return null;
            }
            $im = @imagecreatefromstring($row['file_data']);
            return $im instanceof GdImage ? $im : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function multiplyAlpha(GdImage $im, float $opacity): void
    {
        $opacity = max(0.0, min(1.0, $opacity));
        if ($opacity >= 0.999) {
            return;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($im, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                $na = (int)round(127 - (127 - $a) * $opacity);
                $na = max(0, min(127, $na));
                $col = imagecolorallocatealpha($im, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF, $na);
                if ($col !== false) {
                    imagesetpixel($im, $x, $y, $col);
                }
            }
        }
        imagealphablending($im, true);
    }

    // ----- PDFs (FPDI + FPDF, stamp every page) -----

    /**
     * @param array<string,mixed>|null $owner
     */
    private static function stampPdf(string $bytes, bool $dw, ?array $owner, ?PDO $db, ?string $brand): string
    {
        $fpdf = '/var/www/html/lib/vendor/fpdf/fpdf.php';
        $auto = '/var/www/html/lib/vendor/fpdi/src/autoload.php';
        if (!is_file($fpdf) || !is_file($auto)) {
            throw new RuntimeException('FPDF/FPDI is not installed');
        }
        require_once $fpdf;
        require_once $auto;
        $reader = \setasign\Fpdi\PdfParser\StreamReader::createByString($bytes);
        $pdf = new \setasign\Fpdi\Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pageCount = $pdf->setSourceFile($reader);
        if ($pageCount < 1) {
            return $bytes;
        }
        $tmpDir = sys_get_temp_dir();
        for ($i = 1; $i <= $pageCount; $i++) {
            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);
            $pw = (float)$size['width'];
            $ph = (float)$size['height'];
            $pdf->AddPage($size['orientation'], [$pw, $ph]);
            $pdf->useTemplate($tpl, 0, 0, $pw, $ph);
            $stamp = self::pageStampPng($pw, $ph, $dw, $owner, $db, $brand);
            if ($stamp !== null) {
                $path = $tmpDir . '/dw-wm-' . bin2hex(random_bytes(4)) . '.png';
                if (file_put_contents($path, $stamp) !== false) {
                    $pdf->Image($path, 0, 0, $pw, $ph, 'PNG');
                    @unlink($path);
                }
            }
        }
        $out = $pdf->Output('S');
        return is_string($out) && $out !== '' ? $out : $bytes;
    }

    /**
     * Build a transparent PNG the size of one PDF page (points ≈ pixels at 1:1 for placement).
     * @param array<string,mixed>|null $owner
     */
    private static function pageStampPng(float $pw, float $ph, bool $dw, ?array $owner, ?PDO $db, ?string $brand): ?string
    {
        // Render at 2x for sharper type, then FPDF scales back to the page.
        $scale = 2.0;
        $w = max(32, (int)round($pw * $scale));
        $h = max(32, (int)round($ph * $scale));
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $clear = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, $w, $h, $clear);
        imagealphablending($im, true);
        if ($owner !== null) {
            self::paintOwner($im, $w, $h, $owner, $db, $brand);
        }
        if ($dw) {
            self::paintDw($im, $w, $h);
        }
        ob_start();
        $ok = imagepng($im, null, 6);
        $out = ob_get_clean();
        imagedestroy($im);
        return ($ok && is_string($out) && $out !== '') ? $out : null;
    }

    // ----- helpers -----

    /** @return array{0:int,1:int,2:int} */
    private static function hexRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** @param array<int,int> $b */
    private static function bboxWidth(array $b): int
    {
        return (int)(max($b[0], $b[2], $b[4], $b[6]) - min($b[0], $b[2], $b[4], $b[6]));
    }

    /** @param array<int,int> $b */
    private static function bboxHeight(array $b): int
    {
        return (int)(max($b[1], $b[3], $b[5], $b[7]) - min($b[1], $b[3], $b[5], $b[7]));
    }

    /**
     * Text baseline origin for a named corner/center.
     * @return array{0:int,1:int}
     */
    private static function anchor(string $pos, int $w, int $h, int $tw, int $th, int $pad): array
    {
        return match ($pos) {
            'top-left' => [$pad, $pad + $th],
            'top-right' => [$w - $pad - $tw, $pad + $th],
            'bottom-left' => [$pad, $h - $pad],
            'center' => [(int)(($w - $tw) / 2), (int)(($h + $th) / 2)],
            default => [$w - $pad - $tw, $h - $pad], // bottom-right
        };
    }

    private static function sellerBrand(): ?string
    {
        return DwBanner::sellerBrand();
    }
}
