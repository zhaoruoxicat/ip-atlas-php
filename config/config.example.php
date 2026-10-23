<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'ip_library',
        'user' => getenv('DB_USER') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
        'socket' => getenv('DB_SOCKET') ?: null,
    ],
    'upstream' => [
        'base_url' => 'https://api.ip.sb/geoip/',
        'user_agent' => getenv('UPSTREAM_USER_AGENT') ?: 'PrivateIPLibrary/1.0',
        'timeout_seconds' => 7,
        'connect_timeout_seconds' => 3,
        'max_requests_per_minute' => 80,
        'max_requests_per_second' => 4,
        'retries' => 1,
    ],
    'cache' => ['ttl_seconds'=>7 * 86400, 'allow_stale_on_upstream_failure'=>true, 'lock_wait_seconds'=>2],
    'rate_limit' => ['token_per_second'=>300, 'admin_per_second'=>300, 'login_per_15_minutes'=>10],
    'security' => [
        // 生产环境必须为 true 并启用 HTTPS；本地 HTTP 调试可在私有 config.php 中设为 false。
        'session_secure' => getenv('SESSION_SECURE') !== '0',
        'session_ttl_seconds' => 7200,
        'trusted_proxies' => [], // 例如 ['127.0.0.1/32']；默认仅使用 REMOTE_ADDR。
    ],
    'logging' => ['path'=>dirname(__DIR__) . '/storage/logs', 'retention_days'=>30],
];
