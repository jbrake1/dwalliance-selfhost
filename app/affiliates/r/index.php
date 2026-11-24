<?php
declare(strict_types=1);
// 308 /affiliates/* -> /affiliate/* (query string preserved). Prefer nginx; this is the PHP fallback.
$uri = (string)($_SERVER['REQUEST_URI'] ?? '/affiliates/');
$path = preg_replace('#^/affiliates#', '/affiliate', $uri, 1) ?? '/affiliate/';
if ($path === '' || $path[0] !== '/') {
    $path = '/affiliate/';
}
header('Location: ' . $path, true, 308);
exit;
