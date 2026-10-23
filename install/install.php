<?php
declare(strict_types=1);
define('IP_ATLAS_ENTRY',true);
define('IP_ATLAS_INSTALLER',true);
require dirname(__DIR__) . '/app/bootstrap.php';
$installer = new Installer(dirname(__DIR__));
$locked = $installer->locked() || ($config['db']['user'] ?? '') !== '';
$error = null; $success = false;
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
// 首次安装在 HTTP 开发环境也必须能够保存 CSRF Session。
$GLOBALS['config']['security']['session_secure'] = $https;
$values = ['db_host'=>'127.0.0.1','db_port'=>'3306','db_name'=>'ip_library','db_user'=>'','db_socket'=>'','admin_username'=>'','session_secure'=>$https ? '1' : '0'];
if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) { header('Allow: GET, POST'); throw new AppException('METHOD_NOT_ALLOWED','只支持 GET 和 POST。',405); }
if ($locked) { http_response_code(403); }
else {
    Auth::start();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            Auth::verifyCsrf(input($_POST,'csrf'));
            foreach (array_keys($values) as $key) { $values[$key] = input($_POST,$key,$key === 'session_secure' ? '0' : ''); }
            $posted = $values;
            foreach (['db_password','admin_password','admin_confirm','create_database'] as $key) { $posted[$key] = input($_POST,$key,$key === 'session_secure' ? '0' : ''); }
            $installer->install($posted);
            $_SESSION = []; session_regenerate_id(true);
            $success = true; $locked = true;
        } catch (AppException $exception) { $error = $exception->getMessage(); http_response_code($exception->status); }
    }
}
$checks = $installer->checks();
?><!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>系统安装 · IP Atlas</title><link rel="stylesheet" href="/style/tabler.min.css"><link rel="stylesheet" href="/assets/app.css"><script src="/style/tabler.min.js" defer></script></head>
<body><main class="container-xl py-5"><div class="page-heading"><div><div class="eyebrow text-secondary">IP ATLAS SETUP</div><h1>安装 IP 归属地系统</h1><p>填写数据库连接和管理员账号，完成系统初始化。</p></div><span class="local-badge">PHP 8.4 + MySQL</span></div>
<?php if ($locked): ?><section class="card"><div class="card-body py-5 text-center"><div class="alert <?=$success ? 'alert-success' : 'alert-info'?>"><?=$success ? '安装成功！配置和管理员账号已保存，安装程序已锁定。' : '系统已安装或已配置，安装程序已锁定，不能重复安装。'?></div><a class="btn btn-primary" href="/login.php">前往管理员登录</a></div></section>
<?php else: ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif; ?>
<div class="row g-3"><div class="col-lg-4"><section class="card"><div class="card-header"><h2 class="card-title">环境检查</h2></div><div class="card-body"><dl class="detail-list"><?php foreach ($checks as $label=>$passed): ?><div class="detail-row"><dt class="flex-fill"><?=e($label)?></dt><dd><span class="badge <?=$passed ? 'bg-green-lt' : 'bg-red-lt'?>"><?=$passed ? '通过' : '未通过'?></span></dd></div><?php endforeach; ?></dl><p class="detail-note mt-4 mb-0">网站根目录就是项目根目录。Apache 请启用随项目提供的 .htaccess；Nginx 请使用 deploy 中的私有目录保护规则。</p></div></section></div><div class="col-lg-8"><section class="card"><form method="post" action="/install/install.php"><?=csrfField()?><div class="card-header"><h2 class="card-title">数据库信息</h2></div><div class="card-body"><div class="row g-3"><div class="col-md-8"><label class="form-label" for="db_host">数据库主机</label><input class="form-control" id="db_host" name="db_host" value="<?=e($values['db_host'])?>" maxlength="253" required></div><div class="col-md-4"><label class="form-label" for="db_port">端口</label><input class="form-control" id="db_port" name="db_port" type="number" min="1" max="65535" value="<?=e($values['db_port'])?>" required></div><div class="col-md-6"><label class="form-label" for="db_name">数据库名称</label><input class="form-control" id="db_name" name="db_name" value="<?=e($values['db_name'])?>" maxlength="64" pattern="[A-Za-z0-9_]+" required></div><div class="col-md-6"><label class="form-label" for="db_user">数据库用户名</label><input class="form-control" id="db_user" name="db_user" value="<?=e($values['db_user'])?>" maxlength="128" autocomplete="off" required></div><div class="col-md-6"><label class="form-label" for="db_password">数据库密码</label><input class="form-control" id="db_password" name="db_password" type="password" autocomplete="new-password" maxlength="1024"></div><div class="col-md-6"><label class="form-label" for="db_socket">Unix Socket <span class="text-secondary">（可选）</span></label><input class="form-control" id="db_socket" name="db_socket" value="<?=e($values['db_socket'])?>" placeholder="留空则使用主机和端口" maxlength="255"></div></div><label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="create_database" value="1"><span class="form-check-label">数据库不存在时自动创建</span><span class="form-check-description">需要该账号具有建库权限；已有数据库必须为空，不覆盖现有数据。</span></label></div><div class="card-header"><h2 class="card-title">管理员账号</h2></div><div class="card-body"><div class="mb-3"><label class="form-label" for="admin_username">管理员用户名</label><input class="form-control" id="admin_username" name="admin_username" value="<?=e($values['admin_username'])?>" pattern="[A-Za-z0-9_.-]{3,64}" maxlength="64" autocomplete="username" required><div class="form-hint">3–64 位字母、数字、下划线、点或短横线。</div></div><div class="row g-3"><div class="col-md-6"><label class="form-label" for="admin_password">管理员密码</label><input class="form-control" id="admin_password" name="admin_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></div><div class="col-md-6"><label class="form-label" for="admin_confirm">确认密码</label><input class="form-control" id="admin_confirm" name="admin_confirm" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></div></div><div class="form-hint mt-2">密码长度须为 12–72 字节；请不要与数据库密码相同。</div><label class="form-check mt-4"><input class="form-check-input" type="checkbox" name="session_secure" value="1" <?=$values['session_secure'] === '1' ? 'checked' : ''?>><span class="form-check-label">管理员 Cookie 仅通过 HTTPS 发送</span><span class="form-check-description">HTTPS 网站请勾选；本机 HTTP 调试请取消，否则安装后无法保持登录。</span></label></div><div class="card-footer"><button class="btn btn-primary w-100" type="submit" <?=in_array(false,$checks,true) ? 'disabled' : ''?>>安装系统</button></div></form></section></div></div>
<?php endif; ?></main></body></html>
