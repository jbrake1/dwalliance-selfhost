<?php
declare(strict_types=1);
// Affiliate API (refs #74): POST /affiliate/staff/transfer_request/ = action transfer_request (one OpenAPI operation per path). Spec: /openapi/affiliate.json
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST a JSON body']);
    exit;
}
$GLOBALS['DW_AFF_ACTION'] = 'transfer_request';
require '/var/www/html/affiliate/index.php';
