<?php
declare(strict_types=1);
define('IP_ATLAS_ENTRY', true);
require __DIR__ . '/app/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); throw new AppException('METHOD_NOT_ALLOWED','只支持 GET。',405); }
// 格式、模式与字段在数据库连接前校验。
$ip = IpValidator::parse(input($_GET,'ip',''));
$ip->assertPublic();
$mode = input($_GET,'mode','full'); $fields = input($_GET,'fields');
ApiResponse::validate($mode,$fields);
$refresh = input($_GET,'refresh','0');
if (!in_array($refresh,['0','1'],true)) { throw new AppException('INVALID_PARAMETER','refresh 必须为 0 或 1。'); }
$queryToken = input($_GET,'token','');
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$plain = $queryToken;
if ($authorization !== '') {
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/iD', $authorization, $matches)) { throw new AppException('TOKEN_INVALID','Authorization 格式无效。',401); }
    $plain = $matches[1];
    if ($queryToken !== '' && !hash_equals($plain,$queryToken)) { throw new AppException('TOKEN_INVALID','请求中存在不一致的 Token。',401); }
}
if ($plain === '') { throw new AppException('TOKEN_MISSING','请提供 API Token。',401); }
$pdo = db(); $tokens = new TokenService($pdo); $token = $tokens->authenticate($plain);
$tokens->requireScope($token,'lookup');
if ($refresh === '1') { $tokens->requireScope($token,'refresh'); }
(new RateLimiter($pdo))->consume('token:' . $token['id'], [1=>$config['rate_limit']['token_per_second']]);
$tokens->markUsed((int)$token['id']);
$result = ipService()->lookup($ip,$refresh === '1');
ApiResponse::sendResult($result,$mode,$fields);
