<?php
declare(strict_types=1);
// ChatGPT editor (refs #67): GET /markup/requests = GET /markup/?a=api_list (one OpenAPI operation per path).
$_GET['a'] = 'api_list';
require '/var/www/html/markup/index.php';
