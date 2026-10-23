# IP Atlas

基于 PHP 和 MySQL 的 IP 归属地查询系统，支持 IPv4、IPv6、本地缓存和 Token API。数据通过服务端向 IP.SB 查询，前端 CSS、JS 均使用本地文件。

## 环境要求

- PHP 8.4+，启用 PDO MySQL、cURL、ctype 和 Session。
- MySQL 8.0+。
- Apache 或 Nginx；正式部署使用 HTTPS。
- 服务器可访问 `https://api.ip.sb`，PHP 的 Session 目录可写。

无需 Composer、Node.js 或编译前端资源。

## 安装

1. 上传项目，将网站根目录设为项目目录，`index.php` 直接位于网站根目录。
2. 配置访问规则：Apache 启用 `mod_rewrite`、`AllowOverride All` 并保留 `.htaccess`；Nginx 使用 [配置示例](deploy/nginx.conf.example)，修改网站路径和 PHP-FPM socket。
3. 准备一个空数据库，安装期间允许 PHP 写入 `config/`、`install/` 和 `storage/`。
4. 访问 `/install/install.php`，填写数据库信息和管理员账号。
5. 安装完成后访问 `/login.php` 登录。

管理员密码为 12–72 字节。HTTPS 网站勾选安全 Cookie，本地 HTTP 调试取消勾选。Apache 使用 PHP-FPM 时，启用 `CGIPassAuth On` 以传递 API 的 Authorization 请求头。

配置保存于 `config/config.php`。安装后可删除 `install/install.php`，收回 `config/`、`install/` 的写权限，保留 `storage/` 可写。升级时保留配置和数据库，不要重新安装。

## 使用

- **IP 查询**：后台输入公网 IP，查看归属地、网络信息和完整 JSON，也可手动刷新。
- **Token 管理**：创建、禁用、删除或重新生成 API Token。完整 Token 只显示一次，重新生成后旧 Token 失效。

## API 调用

在后台创建 Token 后，通过 GET 请求调用 `/ip.php`：

```sh
curl -H 'Authorization: Bearer YOUR_TOKEN' \
  'https://example.com/ip.php?ip=8.8.8.8'
```

也支持 `https://example.com/ip.php?ip=8.8.8.8&token=YOUR_TOKEN`，但含 Token 的 URL 不应公开或记录到访问日志。

| 参数 | 说明 |
| --- | --- |
| `ip` | 必填，单个公网 IPv4 或 IPv6 |
| `mode` | `full`（默认）、`location`、`network`、`coordinates` |
| `fields` | 逗号分隔的返回字段，例如 `country,region,city,isp`，优先于 `mode` |
| `refresh` | 默认 `0`；设为 `1` 强制刷新，需要 Token 具备 `refresh` 权限 |

成功响应包含 `success`、`data` 和 `meta`；失败响应包含 `success: false` 和 `error`。`meta.source` 表示来源，`meta.stale` 表示是否返回过期缓存。时间统一使用 UTC。

## 缓存与限额

| 项目 | 默认值 |
| --- | --- |
| 缓存有效期 | 7 天 |
| 外部 API 调用 | 每个 Token 每秒 300 次 |
| 后台查询 | 每个管理员每秒 300 次 |
| IP.SB 回源 | 全站共享，每秒 4 次、每分钟 80 次 |
| 登录尝试 | 每个来源 IP 和用户名分别每 15 分钟 10 次 |

命中有效缓存不会请求 IP.SB；缓存不存在或过期时自动更新，上游失败时可返回旧缓存。自有调用与 IP.SB 回源分别限流；300 次/秒是限额，不代表实际处理能力。

默认设置见 [config/config.example.php](config/config.example.php)，已安装网站通过 `config/config.php` 覆盖设置。

## 维护与开发

建议每天清理过期限流记录和日志，IP 缓存会保留：

```sh
php scripts/maintenance.php
```

本地启动和基础测试：

```sh
php -S 127.0.0.1:8080 router.php
php tests/run.php
```

本地安装地址为 `http://127.0.0.1:8080/install/install.php`。数据库测试应使用独立测试库，不要连接生产数据库。

## 提交代码

`.gitignore` 已排除正式配置、日志、CSV 导出、常见备份及本机工具目录，保留 SQL 表结构和迁移文件。提交前用 `git status` 和 `git diff --cached` 检查内容；忽略规则不会移除已经提交的文件，也不会清理历史记录。不要提交数据库密码、真实 Token、私钥或用户数据。
