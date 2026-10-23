# 根目录部署与网页安装

按用户新要求覆盖原文档 public DocumentRoot 规定。入口 PHP 移至根目录，assets 移至根目录，本地 style 原文件直接引用。

安装页 /install/install.php：环境检查、数据库 host/port/name/user/password/socket、可选创建数据库、管理员用户名/密码确认、Secure Cookie 设置。仅空数据库允许初始化；MySQL 命名锁与本地文件锁防并发安装；保存私有 PHP 配置和 install.lock 后禁止覆盖现有配置或重置管理员。失败仅清理本次新建的项目表，不触及已有数据，错误脱敏。

项目根目录可公开，但 Web 服务器必须禁止 app/config/database/scripts/tests/docs/storage、隐藏文件及文档下载。提供 Apache .htaccess、Nginx 配置和 PHP 开发服务器路由。私有 PHP 文件额外阻止直接请求。未配置时管理页跳转安装，JSON API 返回 INSTALL_REQUIRED。

先编写 Installer 校验/安装集成测试，再实现与执行。验证根目录入口、未安装引导、安装 CSRF、真实 MySQL 建库/建表/管理员密码、锁定、配置特殊字符转义、重复安装不改变数据库、私有资源拒绝、安装后原 API/登录回归。
