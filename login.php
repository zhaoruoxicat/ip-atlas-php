<?php
declare(strict_types=1);
define('IP_ATLAS_ENTRY', true);
require __DIR__ . '/app/bootstrap.php';
Auth::start();
if (isset($_SESSION['admin_id'])) { header('Location: /index.php',true,303); exit; }
$error = null; $username = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Auth::verifyCsrf(input($_POST,'csrf'));
        $username = input($_POST,'username',''); $password = input($_POST,'password','');
        if (strlen($username) > 64 || strlen($password) > 72 || !Auth::login($username,$password)) { $error = '用户名或密码错误，请重试。'; }
        else { header('Location: /index.php',true,303); exit; }
    } catch (AppException $exception) { http_response_code($exception->status); $error = $exception->getMessage(); }
}
?><!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>管理员登录 · IP Atlas</title><link rel="stylesheet" href="/style/tabler.min.css"><link rel="stylesheet" href="/assets/app.css"><script src="/style/tabler.min.js" defer></script><script src="/assets/app.js" defer></script></head>
<body class="login-page"><main class="login-layout"><section class="login-story"><a class="atlas-brand login-brand" href="/login.php"><span class="brand-mark"><?=View::icon('globe')?></span>IP Atlas</a><div class="story-content"><span class="eyebrow">YOUR PRIVATE IP LIBRARY</span><h1>每一个地址，<br>都有它的来处。</h1><p>将地理位置与网络信息汇聚到自己的数据库，<br>为你的应用提供统一、可靠的 IP 查询服务。</p><div class="story-tags"><span>IPv4 & IPv6</span><span>缓存优先</span><span>Token 鉴权</span></div></div><div class="orbital" aria-hidden="true"><div></div><div></div><div></div><span>IPv4</span><span>IPv6</span></div><div class="story-footer">PHP 8.4 + MySQL · IP.SB 数据源</div></section><section class="login-form-panel"><div class="login-box"><div class="login-shield"><?=View::icon('shield')?></div><div class="text-secondary mb-2">管理控制台</div><h2>欢迎回来</h2><p class="text-secondary mb-4">登录以检索地址和管理 API 凭据。</p><?php if ($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif; ?><form method="post" action="/login.php"><?=csrfField()?><div class="mb-3"><label class="form-label" for="username">用户名</label><input class="form-control form-control-lg" id="username" name="username" autocomplete="username" maxlength="64" required value="<?=e($username)?>"></div><div class="mb-4"><label class="form-label" for="password">密码</label><input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required maxlength="72"></div><button class="btn btn-primary btn-lg w-100" type="submit">登录控制台</button></form><p class="login-help">仅管理员可访问。首次部署请访问安装程序创建管理员。</p></div></section></main></body></html>
