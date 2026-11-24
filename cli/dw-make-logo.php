<?php
declare(strict_types=1);
// Make a plain email logo (a dark circle with your brand mark) so no third-party logo ships with the app.
//   php dw-make-logo.php MARK OUT.png
// Replace the file with your own 96x96 PNG at any time (/var/lib/app/data/email/dw-logo.png; the file
// name is fixed in the code, the picture is yours).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[$mark, $out] = [$argv[1] ?? '', $argv[2] ?? ''];
$mark = mb_substr((string)preg_replace('/[^\p{L}\p{N}]+/u', '', $mark), 0, 3);
if ($mark === '' || $out === '') {
    fwrite(STDERR, "usage: dw-make-logo.php MARK OUT.png\n");
    exit(2);
}
$s = 96;
$im = imagecreatetruecolor($s, $s);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledellipse($im, intdiv($s, 2), intdiv($s, 2), $s - 2, $s - 2, imagecolorallocate($im, 28, 25, 23));
$white = imagecolorallocate($im, 255, 255, 255);
$font = dirname(__DIR__) . '/app/lib/fonts/DejaVuSans-Bold.ttf';
if (function_exists('imagettftext') && is_file($font)) {
    $size = [1 => 40, 2 => 32, 3 => 24][mb_strlen($mark)];
    $b = imagettfbbox($size, 0, $font, $mark);
    $x = intdiv($s - ($b[2] - $b[0]), 2) - $b[0];
    $y = intdiv($s - ($b[1] - $b[7]), 2) - $b[7];
    imagettftext($im, $size, 0, $x, $y, $white, $font, $mark);
} else {
    $f = 5;
    imagestring($im, $f, intdiv($s - imagefontwidth($f) * strlen($mark), 2), intdiv($s - imagefontheight($f), 2), $mark, $white);
}
imagepng($im, $out);
echo "logo written: $out\n";
