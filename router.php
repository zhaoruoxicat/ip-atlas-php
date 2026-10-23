<?php
declare(strict_types=1);
/** 仅供 php -S 使用；仅放行公开入口与 CSS/JS 资源，不公开整个源码目录。 */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$entries = ['/','/index.php','/login.php','/logout.php','/token.php','/ip.php','/query.php','/install/install.php'];
if (in_array($path,$entries,true)) {
    $file = $path === '/' ? __DIR__ . '/index.php' : __DIR__ . $path;
    if (is_file($file)) { require $file; return true; }
}
if (preg_match('~^/(?:style|assets)/[a-zA-Z0-9_.-]+\.(?:css|js)$~D',$path) && is_file(__DIR__ . $path)) { return false; }
http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Not Found'; return true;
