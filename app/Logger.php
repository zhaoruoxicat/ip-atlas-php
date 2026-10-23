<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
final class Logger
{
    private const KEYS = ['admin_id','token_id','ip_version','code','action'];
    /** 只记录固定字段及错误类别，不记录 URL、请求正文、密码或 Token。 */
    public static function write(string $event, array $context = []): void
    {
        $config = $GLOBALS['config']['logging'] ?? null;
        if (!$config) { return; }
        if (isset($_SESSION['admin_id'])) { $context += ['admin_id'=>(int)$_SESSION['admin_id']]; }
        $line = json_encode(['time'=>gmdate('c'),'event'=>$event] + array_intersect_key($context, array_flip(self::KEYS)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = $config['path'];
        if (!is_dir($path)) { @mkdir($path, 0700, true); }
        $file = $path . '/' . gmdate('Y-m-d') . '.jsonl';
        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) !== false) { @chmod($file, 0600); }
    }
}
