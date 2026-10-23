<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
$files = ['ip_validator_test.php','api_test.php','installer_test.php','repository_test.php','concurrency_test.php'];
foreach ($files as $file) {
    $command = implode(' ', array_map('escapeshellarg',testPhpCommand()));
    passthru($command . ' ' . escapeshellarg(__DIR__ . '/' . $file), $status);
    if ($status !== 0) { exit($status); }
}
echo "Test suite finished.\n";
