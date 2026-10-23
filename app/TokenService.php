<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
final class TokenService
{
    public function __construct(private readonly PDO $pdo) {}
    public function authenticate(string $plain): array
    {
        if ($plain === '') { throw new AppException('TOKEN_MISSING', '请提供 API Token。', 401); }
        if (!preg_match('/^[a-f0-9]{64}$/D', $plain)) { throw new AppException('TOKEN_INVALID', 'API Token 无效。', 401); }
        $stmt = $this->pdo->prepare('SELECT * FROM api_tokens WHERE token_hash = ?'); $stmt->execute([hash('sha256', $plain)]);
        $row = $stmt->fetch();
        if (!$row) { throw new AppException('TOKEN_INVALID', 'API Token 无效。', 401); }
        if (!(bool)$row['is_enabled']) { throw new AppException('TOKEN_DISABLED', 'API Token 已禁用。', 403); }
        if ($row['expires_at'] !== null && strtotime($row['expires_at'] . ' UTC') <= time()) { throw new AppException('TOKEN_EXPIRED', 'API Token 已过期。', 401); }
        $row['scopes'] = json_decode($row['scopes'], true, 16, JSON_THROW_ON_ERROR);
        unset($row['token_hash']);
        return $row;
    }
    public function requireScope(array $token, string $scope): void
    {
        if (!in_array($scope, $token['scopes'], true)) { throw new AppException('SCOPE_DENIED', 'API Token 缺少 ' . $scope . ' 权限。', 403); }
    }
    public function markUsed(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?'); $stmt->execute([$id]);
    }
    public function all(): array
    {
        return $this->pdo->query('SELECT id,name,scopes,is_enabled,expires_at,created_at,last_used_at FROM api_tokens ORDER BY id DESC')->fetchAll();
    }
    /** 完整凭据仅返回创建者，不写 Session、数据库或日志。 */
    public function create(string $name, array $scopes, ?string $expires): array
    {
        $name = trim($name);
        if ($name === '' || preg_match_all('/./us', $name) > 100) { throw new AppException('INVALID_TOKEN_NAME', '名称不能为空，最多 100 个字符。'); }
        if (!in_array('lookup', $scopes, true) || array_diff($scopes, ['lookup','refresh'])) { throw new AppException('INVALID_SCOPES', '必须包含 lookup 权限，仅支持 lookup 和 refresh。'); }
        $plain = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('INSERT INTO api_tokens (name,token_hash,scopes,expires_at) VALUES (?,?,?,?)');
        $stmt->execute([$name,hash('sha256',$plain),json_encode(array_values(array_unique($scopes)),JSON_THROW_ON_ERROR),$expires]);
        $id = (int)$this->pdo->lastInsertId(); Logger::write('token_created',['token_id'=>$id]);
        return ['id'=>$id,'token'=>$plain];
    }
    public function rotate(int $id): string
    {
        $plain = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('UPDATE api_tokens SET token_hash = ?, last_used_at = NULL WHERE id = ?'); $stmt->execute([hash('sha256',$plain),$id]);
        $this->changed($stmt); Logger::write('token_rotated',['token_id'=>$id]); return $plain;
    }
    public function setEnabled(int $id, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM api_tokens WHERE id = ?'); $stmt->execute([$id]);
        if (!$stmt->fetch()) { throw new AppException('TOKEN_NOT_FOUND', 'Token 不存在。', 404); }
        $stmt = $this->pdo->prepare('UPDATE api_tokens SET is_enabled = ? WHERE id = ?'); $stmt->execute([(int)$enabled,$id]);
        Logger::write('token_status_changed',['token_id'=>$id,'action'=>$enabled ? 'enable' : 'disable']);
    }
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM api_tokens WHERE id = ?'); $stmt->execute([$id]);
        $this->changed($stmt); Logger::write('token_deleted',['token_id'=>$id]);
    }
    private function changed(PDOStatement $stmt): void { if ($stmt->rowCount() !== 1) { throw new AppException('TOKEN_NOT_FOUND', 'Token 不存在。', 404); } }
}
