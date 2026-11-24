<?php
declare(strict_types=1);
// ChatGPT editor (refs #67): POST /files/restore = POST /files/ with action restore (one OpenAPI operation per path).
$j = json_decode((string)file_get_contents('php://input'), true);
$sentAction = is_array($j) && isset($j['action']) ? $j['action'] : ($_POST['action'] ?? null);
if ($sentAction !== null && strtolower(trim((string)$sentAction)) !== 'restore') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'this path is action restore; leave action out or send restore']);
    exit;
}
$_GET['action'] = 'restore';
require '/var/www/html/files/index.php';
