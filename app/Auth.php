<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string)$GLOBALS['config']['security']['session_ttl_seconds']);
        session_name('ip_library_session');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(bool)$GLOBALS['config']['security']['session_secure'],'httponly'=>true,'samesite'=>'Lax']);
        if (!session_start()) { throw new RuntimeException('Session storage unavailable'); }
        $ttl = (int)$GLOBALS['config']['security']['session_ttl_seconds'];
        if (isset($_SESSION['admin_id']) && (int)($_SESSION['last_active'] ?? 0) < time() - $ttl) { $_SESSION = []; session_regenerate_id(true); }
        if (isset($_SESSION['admin_id'])) { $_SESSION['last_active'] = time(); }
    }
    public static function csrf(): string
    {
        self::start(); return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public static function verifyCsrf(?string $provided): void
    {
        if ($provided === null || !hash_equals(self::csrf(), $provided)) { throw new AppException('CSRF_INVALID', '页面已失效，请刷新后重试。', 403); }
    }
    public static function requireAdmin(bool $json = false): int
    {
        self::start();
        if (!isset($_SESSION['admin_id'])) {
            if ($json) { throw new AppException('AUTH_REQUIRED', '登录已过期，请重新登录。', 401); }
            header('Location: /login.php', true, 303); exit;
        }
        return (int)$_SESSION['admin_id'];
    }
    public static function login(string $username, string $password): bool
    {
        $pdo = db();
        $limits = new RateLimiter($pdo);
        $max = (int)$GLOBALS['config']['rate_limit']['login_per_15_minutes'];
        $limits->consume('login:ip:' . self::clientIp(), [900=>$max]);
        $limits->consume('login:user:' . hash('sha256', strtolower(trim($username))), [900=>$max]);
        $stmt = $pdo->prepare('SELECT id,password_hash,username FROM admin_users WHERE username = ?'); $stmt->execute([trim($username)]);
        $user = $stmt->fetch();
        // 固定 dummy hash 消除不存在用户名时跳过密码校验的时序差异。
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $verified = password_verify($password, $hash);
        if (!$user || !$verified) { Logger::write('login_failed'); return false; }
        self::start(); session_regenerate_id(true);
        $_SESSION = ['admin_id'=>(int)$user['id'],'username'=>$user['username'],'csrf'=>bin2hex(random_bytes(32)),'last_active'=>time()];
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $stmt = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?'); $stmt->execute([password_hash($password,PASSWORD_DEFAULT),$user['id']]);
        }
        $stmt = $pdo->prepare('UPDATE admin_users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?'); $stmt->execute([$user['id']]);
        Logger::write('login_success',['admin_id'=>(int)$user['id']]); return true;
    }
    public static function logout(): void
    {
        self::start(); Logger::write('logout',['admin_id'=>(int)($_SESSION['admin_id'] ?? 0)]);
        $_SESSION = []; $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires'=>time()-42000,'path'=>$params['path'],'secure'=>$params['secure'],'httponly'=>true,'samesite'=>'Lax']); session_destroy();
    }
    /** 只对明确可信代理接受地址头；否则使用 TCP 对端地址。 */
    public static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $bin = @inet_pton($remote);
        foreach ($GLOBALS['config']['security']['trusted_proxies'] as $cidr) {
            if ($bin !== false && IpValidator::inCidr($bin, $cidr)) {
                $candidate = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
                if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP)) { return inet_ntop(inet_pton($candidate)); }
                // XFF 从右向左跳过可信代理，选首个非可信节点。
                $chain = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
                foreach (array_reverse(array_slice($chain,-20)) as $candidate) {
                    $candidate = trim($candidate); $candidateBin = @inet_pton($candidate);
                    if ($candidateBin === false) { return $remote; }
                    $trusted = false;
                    foreach ($GLOBALS['config']['security']['trusted_proxies'] as $range) { if (IpValidator::inCidr($candidateBin,$range)) { $trusted = true; break; } }
                    if (!$trusted) { return inet_ntop($candidateBin); }
                }
                return $remote;
            }
        }
        return $remote;
    }
}
