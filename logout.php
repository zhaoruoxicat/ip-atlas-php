<?php
declare(strict_types=1);
define('IP_ATLAS_ENTRY', true);
require __DIR__ . '/app/bootstrap.php';
Auth::requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); throw new AppException('METHOD_NOT_ALLOWED','只支持 POST。',405); }
Auth::verifyCsrf(input($_POST,'csrf')); Auth::logout(); header('Location: /login.php',true,303); exit;
