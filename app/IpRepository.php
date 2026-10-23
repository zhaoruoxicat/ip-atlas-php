<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
final class IpRepository
{
    public function __construct(private readonly PDO $pdo) {}
    private function table(IpValidator $ip): string { return $ip->version === 4 ? 'ip_ipv4' : 'ip_ipv6'; }

    /** 使用 PRIMARY 二进制精确检索，不对索引套 SQL 函数。 */
    public function find(IpValidator $ip): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->table($ip) . ' WHERE ip_bin = :ip_bin');
        $stmt->bindValue(':ip_bin', $ip->binary, PDO::PARAM_LOB);
        $stmt->execute(); $row = $stmt->fetch();
        if (!$row) { return null; }
        $row['raw'] = get_object_vars(json_decode($row['raw_json'], false, 64, JSON_THROW_ON_ERROR));
        return $row;
    }

    /** 只有成功响应写入缓存；未知字段也保留到原始 JSON。 */
    public function save(IpValidator $ip, array $raw, int $ttl, ?string $originalJson = null): array
    {
        $fields = ['country_code','country','region_code','region','city','postal_code','continent_code','latitude','longitude','timezone','asn','asn_organization','isp','organization'];
        $values = ['ip_text'=>$ip->canonical,'raw_json'=>$originalJson ?? json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),'source'=>'ip.sb','fetched_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>gmdate('Y-m-d H:i:s', time() + max(1, $ttl)), 'utc_offset'=>$raw['offset'] ?? null];
        foreach ($fields as $field) { $values[$field] = $raw[$field] ?? null; }
        $columns = array_keys($values);
        $sql = 'INSERT INTO ' . $this->table($ip) . ' (ip_bin,' . implode(',', $columns) . ') VALUES (:ip_bin,:' . implode(',:', $columns) . ') ON DUPLICATE KEY UPDATE ';
        $updates = [];
        foreach ($columns as $column) { $updates[] = $column . ' = :update_' . $column; }
        $stmt = $this->pdo->prepare($sql . implode(',', $updates) . ',fetch_version = fetch_version + 1');
        $stmt->bindValue(':ip_bin', $ip->binary, PDO::PARAM_LOB);
        foreach ($values as $key => $value) {
            $type = $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $type);
            $stmt->bindValue(':update_' . $key, $value, $type);
        }
        $stmt->execute();
        return $this->find($ip) ?? throw new RuntimeException('Cache write failed');
    }

    public function delete(IpValidator $ip): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->table($ip) . ' WHERE ip_bin = ?');
        $stmt->bindValue(1, $ip->binary, PDO::PARAM_LOB); $stmt->execute();
    }
    /** 短期命名锁由数据库连接持有，异常和连接断开均可释放。 */
    public function acquire(IpValidator $ip, int $seconds): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$this->lockName($ip), max(0, min($seconds, 3))]);
        return (int)$stmt->fetchColumn() === 1;
    }
    public function release(IpValidator $ip): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$this->lockName($ip)]);
    }
    private function lockName(IpValidator $ip): string { return 'ipl:ip:' . substr(hash('sha256', $ip->binary), 0, 56); }
}
