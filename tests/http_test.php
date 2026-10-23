<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
// 只在本地独立测试实例运行；可选 TEST_ADMIN_USER/TEST_ADMIN_PASSWORD。
$base = rtrim(getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8080','/');
$cookie = tempnam(sys_get_temp_dir(),'ip-http-');
function request(string $path, ?array $post = null, array $headers = []): array
{
    global $base, $cookie;
    $curl = curl_init($base . $path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>$headers]);
    if ($post !== null) { curl_setopt($curl,CURLOPT_POST,true); curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post)); }
    $body = curl_exec($curl); check($body !== false,'HTTP connection');
    $size = curl_getinfo($curl,CURLINFO_HEADER_SIZE); $status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    return ['status'=>$status,'headers'=>substr($body,0,$size),'body'=>substr($body,$size)];
}
function csrf(array $response): string
{
    check(preg_match('/name="csrf" value="([a-f0-9]{64})"/',$response['body'],$matches) === 1,'CSRF rendered'); return $matches[1];
}
function apiCheck(string $path, int $expected, string $code, ?array $post = null): void
{
    $response = request($path,$post);
    check($response['status'] === $expected,'HTTP status for ' . $code);
    check(stripos($response['headers'],'application/json') !== false,'JSON content type');
    check(stripos($response['headers'],'no-store') !== false,'no-store header');
    $json = json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);
    check($json['success'] === false && $json['error']['code'] === $code,'error ' . $code);
}
try {
    foreach (['/index.php','/token.php'] as $path) { $response = request($path); check($response['status'] === 303 && str_contains($response['headers'],'/login.php'),'login redirect'); }
    apiCheck('/ip.php?ip=not-an-ip',400,'INVALID_IP');
    apiCheck('/ip.php?ip=127.0.0.1',400,'NON_PUBLIC_IP');
    apiCheck('/ip.php?ip=2001%3Adb8%3A%3A1',400,'NON_PUBLIC_IP');
    apiCheck('/ip.php?ip=8.8.8.8&mode=bad',400,'INVALID_MODE');
    apiCheck('/ip.php?ip=8.8.8.8&fields=',400,'INVALID_FIELDS');
    apiCheck('/ip.php?ip%5B%5D=8.8.8.8',400,'INVALID_PARAMETER');
    apiCheck('/ip.php?ip=8.8.8.8',401,'TOKEN_MISSING');
    apiCheck('/ip.php',405,'METHOD_NOT_ALLOWED',[]);
    apiCheck('/query.php',401,'AUTH_REQUIRED',['ip'=>'8.8.8.8']);
    $user = getenv('TEST_ADMIN_USER'); $password = getenv('TEST_ADMIN_PASSWORD');
    if ($user && $password) {
        $login = request('/login.php');
        $response = request('/login.php',['username'=>$user,'password'=>$password,'csrf'=>csrf($login)]);
        check($response['status'] === 303,'admin login');
        $page = request('/index.php'); check($page['status'] === 200,'logged-in query page');
        $adminCsrf = csrf($page);
        apiCheck('/query.php',403,'CSRF_INVALID',['ip'=>'8.8.8.8','csrf'=>'bad']);
        $tokenPage = request('/token.php');
        $created = request('/token.php',['csrf'=>csrf($tokenPage),'action'=>'create','name'=>'HTTP test','refresh'=>'1']);
        check(preg_match('/id="once-token">([a-f0-9]{64})</',$created['body'],$matches) === 1,'one-time token created');
        $plain = $matches[1];
        $again = request('/token.php'); check(!str_contains($again['body'],$plain),'token not redisplayed');
        $api = request('/ip.php?ip=8.8.8.8&mode=coordinates',null,['Authorization: Bearer ' . $plain]);
        // 缓存预置则成功；真实上游故障允许 502/503，不把外网可用性冒充功能结果。
        check(in_array($api['status'],[200,502,503],true),'Bearer accepted');
        if ($api['status'] === 200) {
            $json = json_decode($api['body'],true,64,JSON_THROW_ON_ERROR);
            check(array_keys($json['data']) === ['ip','latitude','longitude'],'coordinates response');
            $query = request('/query.php',['ip'=>'8.8.8.8','csrf'=>csrf(request('/index.php'))]);
            check($query['status'] === 200,'session query');
        }
        // 获取该测试 Token ID，只操作当前刚创建的测试记录。
        check(preg_match('/ID #(\d+)/',$created['body'],$ids) === 1,'created token ID');
        $deleted = request('/token.php',['csrf'=>csrf($again),'action'=>'delete','id'=>$ids[1]]);
        check($deleted['status'] === 303,'delete token');
        $revoked = request('/ip.php?ip=8.8.8.8',null,['Authorization: Bearer ' . $plain]);
        check($revoked['status'] === 401,'deleted token revoked');
        $logoutPage = request('/index.php');
        $logout = request('/logout.php',['csrf'=>csrf($logoutPage)]); check($logout['status'] === 303,'logout');
        check(request('/index.php')['status'] === 303,'logout clears session');
        echo "PASS admin, CSRF, token creation/deletion, Bearer and logout HTTP flow\n";
    } else { echo "SKIP optional authenticated flow: set TEST_ADMIN_USER/TEST_ADMIN_PASSWORD\n"; }
    echo "PASS HTTP API guards and authentication redirects\n";
} finally { @unlink($cookie); }
