<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
final class RateLimiter
{
    public function __construct(private readonly PDO $pdo) {}
    /** 一次原子检查所有窗口，采用 MySQL 服务端时间避免 Worker 时间偏差。 */
    public function consume(string $bucket, array $windows): void
    {
        $hash = hash('sha256', $bucket);
        $lock = 'ipl:rate:' . substr($hash, 0, 50);
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 1)'); $stmt->execute([$lock]);
        if ((int)$stmt->fetchColumn() !== 1) { $this->deny(1); }
        try {
            foreach ($windows as $seconds => $limit) {
                $seconds = max(1, (int)$seconds); $limit = max(1, (int)$limit);
                $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rate_limit_events WHERE bucket_hash = ? AND occurred_at > TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6))');
                $stmt->execute([$hash, -$seconds]);
                if ((int)$stmt->fetchColumn() >= $limit) { $this->deny($seconds); }
            }
            $stmt = $this->pdo->prepare('INSERT INTO rate_limit_events (bucket_hash, occurred_at) VALUES (?, UTC_TIMESTAMP(6))'); $stmt->execute([$hash]);
            // 每个 bucket 及时清理窗口外的数据；闲置 bucket 由维护脚本清理。
            $stmt = $this->pdo->prepare('DELETE FROM rate_limit_events WHERE bucket_hash = ? AND occurred_at < TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6))');
            $stmt->execute([$hash, -max(3600, ...array_map('intval', array_keys($windows)))]);
        } finally {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$lock]);
        }
    }
    private function deny(int $retry): never
    {
        Logger::write('rate_limited', ['code'=>'RATE_LIMITED']);
        throw new AppException('RATE_LIMITED', '请求过于频繁，请稍后重试。', 429, $retry);
    }
}
