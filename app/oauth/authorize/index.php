<?php
declare(strict_types=1);
// ChatGPT editor OAuth (refs #67). See lib/DwOAuth.php.
require_once '/var/www/html/lib/DwOAuth.php';
DwOAuth::authorizeRoute();
