<?php
declare(strict_types=1);
// Affiliate API (refs #74): POST /affiliate/me/ = action me (one OpenAPI operation per path). Spec: /openapi/affiliate.json
$GLOBALS['DW_AFF_ACTION'] = 'me';
require '/var/www/html/affiliate/join/index.php';
