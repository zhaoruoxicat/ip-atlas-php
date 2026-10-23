<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
/** 安全地从 TTY 读取口令，避免 shell history/ps 泄露。 */
function readPassword(string $prompt): string
{
    if (!function_exists('shell_exec') || !stream_isatty(STDIN)) { throw new RuntimeException('请在交互式终端运行，或通过标准输入管道提供两行口令。'); }
    $mode = trim((string)shell_exec('stty -g'));
    if ($mode === '') { throw new RuntimeException('无法控制终端回显。'); }
    fwrite(STDOUT, $prompt);
    shell_exec('stty -echo');
    try { $line = fgets(STDIN); return $line === false ? '' : rtrim($line,"\r\n"); }
    finally { shell_exec('stty ' . escapeshellarg($mode)); fwrite(STDOUT,"\n"); }
}
try {
    $username = trim($argv[1] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D',$username)) { throw new RuntimeException('用法：php scripts/create_admin.php 用户名（3–64 位字母、数字、_.-）；不接受命令行密码。'); }
    if (stream_isatty(STDIN)) { $password = readPassword('密码（至少 12 字节）：'); $confirmation = readPassword('再次输入密码：'); }
    else { $password = rtrim(fgets(STDIN) ?: '',"\r\n"); $confirmation = rtrim(fgets(STDIN) ?: '',"\r\n"); }
    if (strlen($password) < 12 || strlen($password) > 72) { throw new RuntimeException('密码长度须为 12–72 字节。'); }
    if (!hash_equals($password,$confirmation)) { throw new RuntimeException('两次输入的密码不一致。'); }
    $stmt = db()->prepare('INSERT INTO admin_users (username,password_hash) VALUES (?,?)');
    $stmt->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
    fwrite(STDOUT,"管理员创建成功。\n");
} catch (PDOException) { fwrite(STDERR,"创建失败：检查数据库连接、表结构以及用户名是否重复。\n"); exit(1); }
catch (Throwable $error) { fwrite(STDERR,$error->getMessage() . "\n"); exit(1); }
