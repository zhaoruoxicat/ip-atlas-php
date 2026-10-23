<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
/** 一次性安装；配置根路径由代码指定，不接受 HTTP 参数。 */
final class Installer
{
    public function __construct(private readonly string $root) {}

    public function locked(): bool
    {
        return is_file($this->root . '/config/config.php') || is_file($this->root . '/install/install.lock');
    }

    public static function validate(array $values): array
    {
        foreach ($values as $value) { if (!is_string($value)) { self::invalid('表单参数必须为字符串。'); } }
        $host = trim($values['db_host'] ?? ''); $name = trim($values['db_name'] ?? '');
        $port = $values['db_port'] ?? '3306'; $socket = trim($values['db_socket'] ?? '');
        $user = trim($values['db_user'] ?? ''); $password = $values['db_password'] ?? '';
        $admin = trim($values['admin_username'] ?? ''); $adminPassword = $values['admin_password'] ?? '';
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,253}$/D',$host)) { self::invalid('数据库主机格式无效，请填写 IP、localhost 或主机名。'); }
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) { self::invalid('数据库端口必须为 1–65535。'); }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)) { self::invalid('数据库名称仅允许 1–64 位字母、数字、下划线。'); }
        if ($user === '' || strlen($user) > 128 || strlen($password) > 1024 || str_contains($user,"\0")) { self::invalid('请填写有效数据库账号。'); }
        if ($socket !== '' && ($socket[0] !== '/' || strlen($socket) > 255 || strpbrk($socket,";\r\n\0") !== false)) { self::invalid('Unix Socket 必须为合法绝对路径，不能包含 DSN 分隔符。'); }
        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/D',$admin)) { self::invalid('管理员用户名须为 3–64 位字母、数字、_.-。'); }
        if (strlen($adminPassword) < 12 || strlen($adminPassword) > 72 || str_contains($adminPassword,"\0")) { self::invalid('管理员密码长度须为 12–72 字节。'); }
        if (!hash_equals($adminPassword,$values['admin_confirm'] ?? '')) { self::invalid('两次管理员密码不一致。'); }
        $secure = $values['session_secure'] ?? '0';
        if (!in_array($secure,['0','1'],true)) { self::invalid('无效的 Cookie 安全设置。'); }
        return ['db'=>['host'=>$host,'port'=>(int)$port,'name'=>$name,'user'=>$user,'password'=>$password,'socket'=>$socket === '' ? null : $socket], 'security'=>['session_secure'=>$secure === '1']];
    }

    public function checks(): array
    {
        $checks = ['PHP 8.4 或更高版本'=>PHP_VERSION_ID >= 80400];
        foreach (['pdo_mysql','curl','ctype','session','json','filter','hash'] as $extension) { $checks['PHP ' . $extension . ' 扩展'] = extension_loaded($extension); }
        foreach (['config','storage','install'] as $directory) { $checks[$directory . ' 目录可写'] = is_dir($this->root . '/' . $directory) && is_writable($this->root . '/' . $directory); }
        $checks['SQL 初始化文件可读'] = is_readable($this->root . '/database/schema.sql');
        return $checks;
    }

    /** 双锁保护检查、建表和发布配置，禁止覆盖已安装实例或非空数据库。 */
    public function install(array $values): void
    {
        if ($this->locked()) { throw new AppException('INSTALL_LOCKED','系统已经安装，不能重复安装或重置管理员。',403); }
        $config = self::validate($values);
        if (in_array(false,$this->checks(),true)) { throw new AppException('INSTALL_ENVIRONMENT','环境检查未通过，请修复扩展或目录权限。'); }
        $mutex = @fopen($this->root . '/storage/install.mutex','c');
        if ($mutex === false) { throw new AppException('INSTALL_WRITE_ERROR','无法创建安装互斥文件，请检查 storage 权限。'); }
        @chmod($this->root . '/storage/install.mutex',0600);
        if (!flock($mutex,LOCK_EX | LOCK_NB)) { fclose($mutex); throw new AppException('INSTALL_BUSY','另一个安装请求正在执行，请稍后再试。',409); }
        $pdo = null; $dbLock = null; $created = []; $temp = null; $ownLock = false; $published = false;
        try {
            if ($this->locked()) { throw new AppException('INSTALL_LOCKED','系统已经安装。',403); }
            $db = $config['db'];
            $dsn = $db['socket'] ? 'mysql:unix_socket=' . $db['socket'] . ';charset=utf8mb4' : 'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';charset=utf8mb4';
            $pdo = new PDO($dsn,$db['user'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
            if (version_compare(preg_replace('/[^0-9.].*$/','',(string)$pdo->query('SELECT VERSION()')->fetchColumn()),'8.0','<')) { throw new AppException('INSTALL_DATABASE_VERSION','需要 MySQL 8.0 或更高版本。'); }
            $dbLock = 'ip-atlas-install:' . substr(hash('sha256',$db['name']),0,40);
            $stmt = $pdo->prepare('SELECT GET_LOCK(?,0)'); $stmt->execute([$dbLock]);
            if ((int)$stmt->fetchColumn() !== 1) { $dbLock = null; throw new AppException('INSTALL_BUSY','该数据库正在被其他安装请求使用。',409); }
            if (($values['create_database'] ?? '') === '1') { $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $db['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); }
            $pdo->exec('USE `' . $db['name'] . '`');
            if ($pdo->query('SHOW TABLES')->fetchColumn() !== false) { throw new AppException('INSTALL_DATABASE_NOT_EMPTY','数据库不为空，请使用一个专用于本系统的空数据库。'); }
            $sql = preg_replace('/^\s*--[^\n]*(?:\n|$)/m','',(string)file_get_contents($this->root . '/database/schema.sql'));
            foreach (explode(';',$sql) as $statement) {
                $statement = trim($statement); if ($statement === '') { continue; }
                $pdo->exec($statement);
                if (preg_match('/^CREATE TABLE (ip_ipv4|ip_ipv6|admin_users|api_tokens|rate_limit_events)\b/i',$statement,$match)) { $created[] = $match[1]; }
            }
            $stmt = $pdo->prepare('INSERT INTO admin_users (username,password_hash) VALUES (?,?)');
            $stmt->execute([trim($values['admin_username']),password_hash($values['admin_password'],PASSWORD_DEFAULT)]);
            $content = "<?php\ndeclare(strict_types=1);\nif (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }\nreturn " . var_export($config,true) . ";\n";
            $temp = tempnam($this->root . '/config','.install-');
            if ($temp === false || !chmod($temp,0600) || file_put_contents($temp,$content,LOCK_EX) !== strlen($content)) { throw new AppException('INSTALL_WRITE_ERROR','无法写入配置，请检查 config 目录权限。'); }
            $lock = @fopen($this->root . '/install/install.lock','x');
            if ($lock === false) { throw new AppException('INSTALL_WRITE_ERROR','无法创建安装锁，请检查 install 目录权限。'); }
            $ownLock = true;
            try { if (fwrite($lock,gmdate('c') . "\n") === false) { throw new AppException('INSTALL_WRITE_ERROR','无法保存安装锁。'); } }
            finally { fclose($lock); }
            @chmod($this->root . '/install/install.lock',0600);
            // link 原子发布完整配置且绝不覆盖现有文件，秘密不会以半写入形式出现。
            if (!@link($temp,$this->root . '/config/config.php')) { throw new AppException('INSTALL_WRITE_ERROR','配置发布失败，请确认 config 目录支持创建文件且不存在已有配置。'); }
            $published = true;
        } catch (Throwable $error) {
            if (!$published) {
                if ($pdo) { foreach (array_reverse($created) as $table) { try { $pdo->exec('DROP TABLE `' . $table . '`'); } catch (Throwable) {} } }
                if ($ownLock) { @unlink($this->root . '/install/install.lock'); }
            }
            if ($error instanceof AppException) { throw $error; }
            throw new AppException('INSTALL_DATABASE_ERROR','数据库连接或初始化失败。请检查连接信息、数据库是否存在及建库/建表权限。');
        } finally {
            if ($pdo && $dbLock) { try { $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$dbLock]); } catch (Throwable) {} }
            if (is_string($temp) && is_file($temp)) { @unlink($temp); }
            flock($mutex,LOCK_UN); fclose($mutex);
        }
    }
    private static function invalid(string $message): never { throw new AppException('INSTALL_INVALID_INPUT',$message); }
}
