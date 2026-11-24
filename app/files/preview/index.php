<?php
declare(strict_types=1);
// Draft preview links (refs #67). See lib/DwFilesEditor.php.
require_once '/var/www/html/lib/Dw.php';
require_once '/var/www/html/lib/DwDomain.php';
require_once '/var/www/html/lib/DwFilesEditor.php';
DwFilesEditor::previewRoute();
