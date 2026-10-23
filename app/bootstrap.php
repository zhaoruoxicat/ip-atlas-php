<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80400) { http_response_code(500); exit('PHP 8.4 or newer is required.'); }
date_default_timezone_set('UTC');
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
spl_autoload_register(static function (string $class): void {
    if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $class)) {
        $file = __DIR__ . '/' . $class . '.php';
        if (is_file($file)) { require $file; }
    }
});
$config = require dirname(__DIR__) . '/config/config.example.php';
$privateConfig = dirname(__DIR__) . '/config/config.php';
if (is_file($privateConfig)) { $config = array_replace_recursive($config, require $privateConfig); }
$GLOBALS['config'] = $config;
function db(): PDO { static $connection; return $connection ??= Database::connect($GLOBALS['config']['db']); }
function ipService(): IpService {
    $pdo = db(); $config = $GLOBALS['config'];
    $limiter = new RateLimiter($pdo);
    return new IpService(new IpRepository($pdo), new IpSbProvider($config['upstream'], $limiter), $config['cache']);
}
/** 拒绝数组形式的 HTTP 参数，避免 TypeError 和参数混淆。 */
function input(array $values, string $key, ?string $default = null): ?string {
    if (!array_key_exists($key, $values)) { return $default; }
    if (!is_string($values[$key])) { throw new AppException('INVALID_PARAMETER', '参数 ' . $key . ' 必须为字符串。'); }
    if (strlen($values[$key]) > 4096) { throw new AppException('INVALID_PARAMETER', '参数长度超出限制。'); }
    return $values[$key];
}
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrfField(): string { return '<input type="hidden" name="csrf" value="' . e(Auth::csrf()) . '">'; }
if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    set_exception_handler(static function (Throwable $error): void {
        $isJson = in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['ip.php','query.php'], true);
        if ($error instanceof AppException) {
            if ($isJson) { ApiResponse::error($error); }
            http_response_code($error->status); $message = $error->getMessage();
        } else {
            Logger::write('internal_error', ['code'=>get_class($error)]);
            if ($isJson) { ApiResponse::error(new AppException('INTERNAL_ERROR', '服务暂时不可用，请联系管理员。', 500)); }
            http_response_code(500); $message = '服务暂时不可用，请检查数据库配置和服务状态。';
        }
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/style/tabler.min.css"><title>服务提示</title><body><main class="container py-5"><div class="alert alert-danger">' . e($message) . '</div><a href="/index.php">返回首页</a></main></body></html>';
    });
}

// 根目录首次访问自动引导安装；外部 API 仍保持标准 JSON 错误。
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_INSTALLER') && !is_file($privateConfig) && ($config['db']['user'] ?? '') === '') {
    if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['ip.php','query.php'], true)) {
        throw new AppException('INSTALL_REQUIRED', '系统尚未安装，请先访问安装页面。', 503);
    }
    header('Location: /install/install.php', true, 303); exit;
}
