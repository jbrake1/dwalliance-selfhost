<?php
declare(strict_types=1);
// ChatGPT editor (refs #67): POST /files/refresh_preview = POST /files/ with action preview (one OpenAPI operation per path).
$j = json_decode((string)file_get_contents('php://input'), true);
$sentAction = is_array($j) && isset($j['action']) ? $j['action'] : ($_POST['action'] ?? null);
if ($sentAction !== null && strtolower(trim((string)$sentAction)) !== 'preview') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'this path is action preview; leave action out or send preview']);
    exit;
}
$_GET['action'] = 'preview';
require '/var/www/html/files/index.php';
