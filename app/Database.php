<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
final class Database
{
    public static function connect(array $config): PDO
    {
        $dsn = !empty($config['socket'])
            ? 'mysql:unix_socket=' . $config['socket'] . ';dbname=' . $config['name'] . ';charset=utf8mb4'
            : 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $config['user'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_PERSISTENT=>false]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
