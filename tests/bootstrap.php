<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
spl_autoload_register(static function (string $name): void {
    $file = dirname(__DIR__) . '/app/' . $name . '.php';
    if (is_file($file)) { require $file; }
});
function check(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
}
function expectError(callable $fn, string $code): void {
    try { $fn(); } catch (AppException $e) { check($e->errorCode === $code, 'expected ' . $code . ', got ' . $e->errorCode); return; }
    throw new RuntimeException('FAIL: expected ' . $code);
}
/** 使用当前 PHP 与扩展启动独立 Worker，便于真实并发测试。 */
function testPhpCommand(): array
{
    $command = [PHP_BINARY];
    if (($ini = php_ini_loaded_file()) !== false) { $command[] = '-c'; $command[] = $ini; }
    else {
        $command[] = '-n';
        $command[] = '-d'; $command[] = 'extension_dir=' . ini_get('extension_dir');
        foreach (['ctype','pdo','mysqlnd','pdo_mysql','curl'] as $extension) {
            if (extension_loaded($extension)) { $command[] = '-d'; $command[] = 'extension=' . $extension; }
        }
    }
    return $command;
}
