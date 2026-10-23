<?php
declare(strict_types=1);
define('IP_ATLAS_ENTRY', true);
require __DIR__ . '/app/bootstrap.php';
Auth::requireAdmin(); $service = new TokenService(db());
$onceToken = null; $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Auth::verifyCsrf(input($_POST,'csrf'));
        $action = input($_POST,'action','');
        if ($action === 'create') {
            $expires = input($_POST,'expires_at','');
            $date = $expires === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$expires,new DateTimeZone('UTC'));
            if ($expires !== '' && (!$date || $date->format('Y-m-d\TH:i') !== $expires || $date->getTimestamp() <= time())) { throw new AppException('INVALID_EXPIRY','有效期必须为未来的 UTC 时间。'); }
            $scopes = ['lookup']; if (input($_POST,'refresh') === '1') { $scopes[] = 'refresh'; }
            $result = $service->create(input($_POST,'name',''),$scopes,$date?->format('Y-m-d H:i:s'));
            $onceToken = $result['token'];
        } else {
            $id = input($_POST,'id','');
            if (!ctype_digit($id) || (int)$id < 1) { throw new AppException('INVALID_PARAMETER','无效 Token ID。'); }
            switch ($action) {
                case 'enable': $service->setEnabled((int)$id,true); break;
                case 'disable': $service->setEnabled((int)$id,false); break;
                case 'delete': $service->delete((int)$id); break;
                case 'rotate': $onceToken = $service->rotate((int)$id); break;
                default: throw new AppException('INVALID_PARAMETER','未知管理操作。');
            }
        }
        // 成功后更换 CSRF，阻止刷新/回退意外重复创建或轮换凭据。
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        if ($onceToken === null) { header('Location: /token.php',true,303); exit; }
    } catch (AppException $exception) { http_response_code($exception->status); $error = $exception->getMessage(); }
}
$rows = $service->all();
View::start('API Token','tokens');
?>
<div class="page-heading"><div><div class="eyebrow text-secondary">ACCESS MANAGEMENT</div><h1>API Token 管理</h1><p>为不同项目创建独立凭据，控制查询与手动刷新权限。</p></div><span class="local-badge"><?=View::icon('key')?><?=count($rows)?> 个凭据</span></div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif; ?>
<?php if ($onceToken): ?><div class="card one-time-card mb-4"><div class="card-body"><h3>凭据已生成，请立即保存</h3><p>完整 Token 仅在本次页面显示。关闭页面后无法再次查看；重新生成会使旧 Token 立即失效。</p><div class="token-secret-row"><code id="once-token"><?=e($onceToken)?></code><button class="btn btn-primary" type="button" data-copy-target="once-token"><?=View::icon('copy')?>复制 Token</button></div></div></div><?php endif; ?>
<div class="row g-3"><div class="col-lg-4"><section class="card"><div class="card-header"><h2 class="card-title">新建 Token</h2></div><div class="card-body"><form action="/token.php" method="post"><?=csrfField()?><input type="hidden" name="action" value="create"><div class="mb-3"><label class="form-label" for="token-name">名称</label><input id="token-name" class="form-control" name="name" maxlength="100" placeholder="例如：个人网站 / 移动应用" required></div><div class="mb-3"><label class="form-label" for="token-expiry">有效期 <span class="text-secondary fw-normal">（可选，UTC）</span></label><input id="token-expiry" class="form-control" type="datetime-local" name="expires_at"><div class="form-hint">留空表示永久有效，仍可随时禁用或删除。</div></div><fieldset class="mb-4"><legend class="form-label">访问权限</legend><div class="scope-fixed"><span class="badge bg-blue-lt">lookup</span><span>IP 查询（默认包含）</span></div><label class="form-check mt-3"><input class="form-check-input" name="refresh" value="1" type="checkbox"><span class="form-check-label">允许手动刷新 <code>refresh</code></span><span class="form-check-description">可强制请求上游，消耗共享回源配额。</span></label></fieldset><button class="btn btn-primary w-100" type="submit">创建 Token</button></form></div></section><section class="api-tip mt-3"><h3><?=View::icon('code')?>接入方式</h3><p>推荐使用请求头传递凭据：</p><pre>Authorization: Bearer YOUR_TOKEN</pre><p>请求路径：</p><pre>/ip.php?ip=8.8.8.8&amp;mode=location</pre><p class="mb-0">支持 URL 的 <code>token</code> 参数。限额：每 Token <?=e($config['rate_limit']['token_per_second'])?> 次/秒。<br>常规过期缓存按服务端策略自动刷新。</p></section></div><div class="col-lg-8"><section class="card"><div class="card-header"><h2 class="card-title">已创建的凭据</h2><span class="card-actions text-secondary small">数据库只保存 SHA-256 哈希</span></div><?php if (!$rows): ?><div class="token-empty"><div><?=View::icon('key')?></div><h3>还没有 API Token</h3><p>创建第一个凭据，让你的应用开始查询。</p></div><?php else: ?><div class="table-responsive"><table class="table table-vcenter token-table"><thead><tr><th>名称 / 权限</th><th>状态 / 有效期</th><th>调用记录</th><th class="text-end">管理</th></tr></thead><tbody><?php foreach ($rows as $row): $expired = $row['expires_at'] && strtotime($row['expires_at'].' UTC') <= time(); ?><tr><td><div class="fw-semibold"><?=e($row['name'])?></div><div class="text-secondary small mb-2">ID #<?=e($row['id'])?></div><?php foreach (json_decode($row['scopes'],true) as $scope): ?><span class="badge bg-blue-lt me-1"><?=e($scope)?></span><?php endforeach; ?></td><td><span class="badge <?=$row['is_enabled'] && !$expired ? 'bg-green-lt' : 'bg-secondary-lt'?>"><?=$row['is_enabled'] ? ($expired ? '已过期' : '启用中') : '已禁用'?></span><div class="text-secondary small mt-2"><?=e($row['expires_at'] ?? '永久有效')?></div></td><td><div class="small">最近：<?=e($row['last_used_at'] ?? '尚未调用')?></div><div class="text-secondary small mt-1">创建：<?=e($row['created_at'])?></div></td><td><div class="token-actions"><form action="/token.php" method="post"><?=csrfField()?><input type="hidden" name="id" value="<?=e($row['id'])?>"><input type="hidden" name="action" value="<?=$row['is_enabled'] ? 'disable' : 'enable'?>"><button class="btn btn-sm" type="submit"><?=$row['is_enabled'] ? '禁用' : '启用'?></button></form><form action="/token.php" method="post" data-confirm="重新生成会立即吊销旧 Token，确定继续？"><?=csrfField()?><input type="hidden" name="id" value="<?=e($row['id'])?>"><input type="hidden" name="action" value="rotate"><button class="btn btn-sm" type="submit">重新生成</button></form><form action="/token.php" method="post" data-confirm="永久删除这个 Token？此操作无法撤销。">
<?=csrfField()?><input type="hidden" name="id" value="<?=e($row['id'])?>"><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-outline-danger" type="submit">删除</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section></div></div>
<?php View::end(); ?>
