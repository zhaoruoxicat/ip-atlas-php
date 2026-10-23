<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
final class View
{
    public static function icon(string $name, string $class = 'icon'): string
    {
        $paths = [
            'globe'=>'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18c-5-5-5-13 0-18"/>',
            'search'=>'<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>',
            'key'=>'<circle cx="8" cy="8" r="5"/><path d="m12 12 9 9m-4-4 3-3m-6 0 3-3"/>',
            'logout'=>'<path d="M10 4H4v16h6m4-16 8 8-8 8m-6-8h14"/>',
            'copy'=>'<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V4H4v12h4"/>',
            'refresh'=>'<path d="M20 7v5h-5M4 17v-5h5M6 6a8 8 0 0 1 14 6M4 12a8 8 0 0 0 14 6"/>',
            'pin'=>'<path d="M19 10c0 6-7 11-7 11S5 16 5 10a7 7 0 0 1 14 0"/><circle cx="12" cy="10" r="2"/>',
            'network'=>'<rect x="9" y="3" width="6" height="6" rx="1"/><rect x="3" y="15" width="6" height="6" rx="1"/><rect x="15" y="15" width="6" height="6" rx="1"/><path d="M12 9v3H6v3m6-3h6v3"/>',
            'code'=>'<path d="m8 6-6 6 6 6m8-12 6 6-6 6m-3-14-2 16"/>',
            'shield'=>'<path d="m12 3 8 4v5c0 5-8 9-8 9s-8-4-8-9V7l8-4"/><path d="m8 12 3 3 5-6"/>',
        ];
        return '<svg class="' . e($class) . '" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['globe']) . '</svg>';
    }
    public static function start(string $title, string $active): void
    {
        ?><!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title><?=e($title)?> · IP Atlas</title><link rel="stylesheet" href="/style/tabler.min.css"><link rel="stylesheet" href="/assets/app.css"><script src="/style/tabler.min.js" defer></script><script src="/assets/app.js" defer></script></head>
<body><div class="page"><header class="navbar navbar-expand-md atlas-header"><div class="container-xl"><a class="navbar-brand atlas-brand" href="/index.php"><span class="brand-mark"><?=self::icon('globe')?></span>IP Atlas<span class="brand-caption">归属地数据库</span></a><div class="header-tools"><span class="admin-name"><?=e($_SESSION['username'] ?? '')?></span><form action="/logout.php" method="post"><?=csrfField()?><button class="btn btn-ghost-secondary btn-sm" type="submit"><?=self::icon('logout')?>退出</button></form></div></div></header>
<nav class="atlas-nav" aria-label="主导航"><div class="container-xl"><a class="nav-item <?=$active === 'query' ? 'active' : ''?>" href="/index.php"><?=self::icon('search')?>IP 检索</a><a class="nav-item <?=$active === 'tokens' ? 'active' : ''?>" href="/token.php"><?=self::icon('key')?>API Token</a><span class="nav-note">IPv4 / IPv6 · MySQL 缓存</span></div></nav>
<main class="page-wrapper"><div class="container-xl py-4">
<?php
    }
    public static function end(): void
    {
        ?></div><footer class="atlas-footer"><div class="container-xl"><span>IP Atlas · 自建 IP 信息服务</span><span>数据来源 IP.SB · 所有时间均为 UTC</span></div></footer></main></div><div id="toast" class="atlas-toast" role="status" hidden></div></body></html><?php
    }
}
