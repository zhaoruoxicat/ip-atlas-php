<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
if (getenv('RUN_INSTALL_HTTP_TESTS') !== '1') { echo "SKIP install HTTP: requires a fresh disposable site and database\n"; exit; }
$base = rtrim(getenv('TEST_INSTALL_BASE_URL') ?: 'http://127.0.0.1:8080','/');
$name = getenv('TEST_INSTALL_DB_NAME') ?: '';
check(preg_match('/^ip_install_[a-z0-9_]+$/D',$name) === 1,'dedicated installation database name required');
$cookie = tempnam(sys_get_temp_dir(),'ip-install-http-');
function installRequest(string $path, ?array $post = null): array {
    global $base,$cookie;
    $curl = curl_init($base . $path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>30]);
    if ($post !== null) { curl_setopt($curl,CURLOPT_POST,true); curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post)); }
    $body = curl_exec($curl); check($body !== false,'installation HTTP connection');
    $size = curl_getinfo($curl,CURLINFO_HEADER_SIZE);
    return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'headers'=>substr($body,0,$size),'body'=>substr($body,$size)];
}
function installCsrf(array $response): string {
    check(preg_match('/name="csrf" value="([a-f0-9]{64})"/',$response['body'],$matches) === 1,'install CSRF'); return $matches[1];
}
try {
    $first = installRequest('/index.php');
    check($first['status'] === 303 && str_contains($first['headers'],'/install/install.php'),'first-run redirect');
    $api = installRequest('/ip.php?ip=8.8.8.8');
    check($api['status'] === 503 && json_decode($api['body'],true)['error']['code'] === 'INSTALL_REQUIRED','first-run JSON error');
    $page = installRequest('/install/install.php'); check($page['status'] === 200,'installer shown');
    $csrf = installCsrf($page);
    check(installRequest('/install/install.php',['csrf'=>'bad'])['status'] === 403,'install CSRF rejected');
    $password = getenv('TEST_INSTALL_ADMIN_PASSWORD') ?: bin2hex(random_bytes(16));
    $user = getenv('TEST_INSTALL_ADMIN_USER') ?: 'web_installer_admin';
    $values = ['csrf'=>$csrf,'db_host'=>getenv('DB_HOST') ?: '127.0.0.1','db_port'=>getenv('DB_PORT') ?: '3306','db_name'=>$name,'db_user'=>getenv('DB_USER') ?: 'root','db_password'=>getenv('DB_PASSWORD') ?: '','db_socket'=>getenv('DB_SOCKET') ?: '','admin_username'=>$user,'admin_password'=>$password,'admin_confirm'=>$password,'create_database'=>'1'];
    // 不提交 session_secure，模拟 HTTP 表单的未选中 checkbox。
    $installed = installRequest('/install/install.php',$values);
    check($installed['status'] === 200 && str_contains($installed['body'],'安装成功'),'web installation success');
    check(!str_contains($installed['body'],$password),'admin password not reflected');
    check(installRequest('/install/install.php')['status'] === 403,'installer locked');
    check(installRequest('/install/install.php',$values)['status'] === 403,'reinstall blocked');
    foreach (['/config/config.php','/database/schema.sql','/app/bootstrap.php','/storage/install.mutex','/install/install.lock','/README.md','/tests/bootstrap.php','/.git/config'] as $path) { check(in_array(installRequest($path)['status'],[403,404],true),'private resource blocked ' . $path); }
    $login = installRequest('/login.php');
    $response = installRequest('/login.php',['csrf'=>installCsrf($login),'username'=>$user,'password'=>$password]);
    check($response['status'] === 303,'installed admin login');
    check(installRequest('/index.php')['status'] === 200,'root index accessible after login');
    check(installRequest('/token.php')['status'] === 200,'root token page accessible');
    echo "PASS web install, CSRF, root routes, private-resource blocks, lock and admin login\n";
} finally { unlink($cookie); }
