<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
try {
    // 保留过期 IP，供上游故障时兜底；只清理限流事件和到期日志。
    $deleted = db()->exec('DELETE FROM rate_limit_events WHERE occurred_at < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 50000');
    $cutoff = time() - max(1,(int)$config['logging']['retention_days']) * 86400;
    $logs = 0;
    foreach (glob($config['logging']['path'] . '/*.jsonl') ?: [] as $file) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}\.jsonl$/D',basename($file)) && filemtime($file) < $cutoff && unlink($file)) { $logs++; }
    }
    echo "清理限流记录 {$deleted} 条，日志 {$logs} 个。\n";
} catch (Throwable) { fwrite(STDERR,"维护失败，请检查配置及权限。\n"); exit(1); }
