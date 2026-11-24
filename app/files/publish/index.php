<?php
declare(strict_types=1);
// ChatGPT editor (refs #67): POST /files/publish = POST /files/ with action publish (one OpenAPI operation per path).
$j = json_decode((string)file_get_contents('php://input'), true);
$sentAction = is_array($j) && isset($j['action']) ? $j['action'] : ($_POST['action'] ?? null);
if ($sentAction !== null && strtolower(trim((string)$sentAction)) !== 'publish') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'this path is action publish; leave action out or send publish']);
    exit;
}
$_GET['action'] = 'publish';
require '/var/www/html/files/index.php';
