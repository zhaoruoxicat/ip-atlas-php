# IP 归属地系统实现计划

依据：`IP数据库服务_LLM开发文档.md`；目标 PHP 8.4、MySQL 8.0+。

1. 基础工程：原生 PHP 类自动加载、私有配置、PDO、错误处理、SQL。按用户后续要求，入口文件与 assets 放在根目录，直接使用 style 原文件；网页安装与根目录保护见 root-install-plan.md。
2. 地址与响应：先测试 IPv4/IPv6 二进制规范化、mapped IPv6、非公网 CIDR 拦截、fields/mode 选择，再实现 IpValidator 和 ApiResponse。
3. Repository：固定双表映射、BINARY 参数、完整 JSON、成功后 UTC 时间更新；MySQL 集成测试验证表结构、零字节、PRIMARY 执行计划和未知字段保存。
4. 上游与缓存：固定 HTTPS 主机、cURL TLS/超时/响应限制与 IP 一致性；数据库命名锁合并请求，数据库共享滑动窗口限流，失败旧缓存兜底。测试首次、命中、过期、失败和强制刷新。
5. 鉴权与接口：Token 哈希、权限、有效期、单 Token 限流；先校验输入再接数据库；Bearer 与 URL token；统一 JSON 和 HTTP 状态。
6. 管理页面：安全 Session、CSRF、登录防爆破、POST 退出；本地 Tabler 响应式布局；服务端会话查询无 Token 下发；Token 创建/轮换只显示一次，启停删除。
7. 验证与部署：CLI 管理员初始化和维护；PHP 语法检查、单元/MySQL/HTTP 集成测试；README 的 Nginx/Apache、环境变量、日志清理和 API 示例。

重点边界：数组参数、空 fields、URL/header 凭据歧义、IPv6 特殊地址、上游 JSON 错误类型/未知嵌套字段、并发限流、重复 POST、数据库不可用。开发环境没有预装 PHP/MySQL，将尽可能用临时独立运行时验证，不修改系统配置。
