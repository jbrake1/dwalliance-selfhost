<?php
declare(strict_types=1);
// Serves a few static text files with the configured brand (refs #79). The hosted service does not use
// this: its nginx serves the files as they are. A self-host nginx routes the paths below here, so the
// published API specs and the JS client name the operator's own hosts (lib/DwBrand.php).
require_once __DIR__ . '/DwBrand.php';

$files = [
    '/openapi/files.json' => ['openapi/files.json', 'application/json'],
    '/openapi/affiliate.json' => ['openapi/affiliate.json', 'application/json'],
    '/js/dw/dw.js' => ['js/dw/dw.js', 'text/javascript'],
];
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$f = $files[$path] ?? null;
$file = $f !== null ? dirname(__DIR__) . '/' . $f[0] : '';
if ($f === null || !is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "not found\n";
    exit;
}
header('Content-Type: ' . $f[1] . '; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
echo DwBrand::t((string)file_get_contents($file));
