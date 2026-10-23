<?php
declare(strict_types=1);
define('IP_ATLAS_ENTRY', true);
require __DIR__ . '/app/bootstrap.php';
$id = Auth::requireAdmin(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); throw new AppException('METHOD_NOT_ALLOWED','只支持 POST。',405); }
Auth::verifyCsrf(input($_POST,'csrf'));
$ip = IpValidator::parse(input($_POST,'ip','')); $ip->assertPublic();
$refresh = input($_POST,'refresh','0');
if (!in_array($refresh,['0','1'],true)) { throw new AppException('INVALID_PARAMETER','refresh 必须为 0 或 1。'); }
session_write_close(); // 不持有 Session 锁等待上游。
(new RateLimiter(db()))->consume('admin:' . $id,[1=>$config['rate_limit']['admin_per_second']]);
if ($refresh === '1') { Logger::write('admin_refresh',['admin_id'=>$id,'ip_version'=>$ip->version]); }
$result = ipService()->lookup($ip,$refresh === '1');
// 管理界面用原始文本显示/复制，避免 JavaScript Number 改变未知大整数。
$result['raw_json'] = $result['_raw_json'];
unset($result['_raw_json']);
ApiResponse::send($result);
