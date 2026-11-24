<?php
declare(strict_types=1);
// ChatGPT editor (refs #67): GET /markup/request = GET /markup/?a=api_get (one OpenAPI operation per path).
$_GET['a'] = 'api_get';
require '/var/www/html/markup/index.php';
