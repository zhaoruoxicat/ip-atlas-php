<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
$values = ['db_host'=>'127.0.0.1','db_port'=>'3306','db_name'=>'ip_install_test','db_user'=>'test','db_password'=>"quote'\\$\nsecret",'db_socket'=>'','admin_username'=>'installer_admin','admin_password'=>'secure-test-password','admin_confirm'=>'secure-test-password','session_secure'=>'0'];
$valid = Installer::validate($values);
check($valid['db']['password'] === $values['db_password'], 'password unchanged');
check($valid['security']['session_secure'] === false,'HTTP choice preserved');
foreach (['db_host'=>'localhost;dbname=other','db_port'=>'0','db_name'=>'bad; DROP DATABASE x','db_socket'=>"/tmp/x;dbname=x",'admin_username'=>'a','admin_password'=>'short','admin_confirm'=>'mismatch'] as $key=>$bad) {
    expectError(fn() => Installer::validate(array_replace($values,[$key=>$bad])),'INSTALL_INVALID_INPUT');
}
if (getenv('RUN_MYSQL_TESTS') !== '1') { echo "PASS installer validation; SKIP database installation\n"; exit; }
$root = sys_get_temp_dir() . '/ip-installer-test-' . bin2hex(random_bytes(8));
mkdir($root); mkdir($root . '/install'); mkdir($root . '/config'); mkdir($root . '/storage'); mkdir($root . '/database');
copy(dirname(__DIR__) . '/database/schema.sql',$root . '/database/schema.sql');
$values['db_host'] = getenv('DB_HOST') ?: '127.0.0.1';
$values['db_socket'] = getenv('DB_SOCKET') ?: '';
$values['db_user'] = getenv('DB_USER') ?: 'root';
$values['db_password'] = getenv('DB_PASSWORD') ?: '';
$values['db_name'] = 'ip_install_test_' . bin2hex(random_bytes(4));
$values['create_database'] = '1';
$installer = new Installer($root);
try {
    $installer->install($values);
    check(is_file($root . '/install/install.lock'),'installation lock persisted');
    $config = require $root . '/config/config.php';
    check($config['db']['name'] === $values['db_name'],'configuration written');
    $pdo = Database::connect($config['db']);
    $admin = $pdo->query('SELECT username,password_hash FROM admin_users')->fetch();
    check($admin['username'] === $values['admin_username'] && password_verify($values['admin_password'],$admin['password_hash']),'admin created with hash');
    check(count($pdo->query('SHOW TABLES')->fetchAll()) === 5,'five schema tables created');
    expectError(fn() => $installer->install($values),'INSTALL_LOCKED');
    check((int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 1,'reinstall does not change admin');
    // 配置已经存在且安装锁被删除时，也不允许重装。
    unlink($root . '/install/install.lock');
    expectError(fn() => $installer->install($values),'INSTALL_LOCKED');
    // 非空数据库不得覆盖或改动已有管理员。
    unlink($root . '/config/config.php');
    expectError(fn() => $installer->install($values),'INSTALL_DATABASE_NOT_EMPTY');
    check((int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 1,'existing tables untouched');
    // 模拟建表中途失败，只清理本次新建表，之后可以重试。
    $pdo->exec('DROP DATABASE `' . $values['db_name'] . '`');
    $originalSchema = file_get_contents($root . '/database/schema.sql');
    file_put_contents($root . '/database/schema.sql', 'CREATE TABLE ip_ipv4 (id INT PRIMARY KEY); INVALID SQL;');
    expectError(fn() => $installer->install($values),'INSTALL_DATABASE_ERROR');
    $pdo->exec('USE `' . $values['db_name'] . '`');
    check($pdo->query('SHOW TABLES')->fetchColumn() === false,'failed install cleans created table');
    check(!is_file($root . '/config/config.php') && !is_file($root . '/install/install.lock'),'failed install leaves no installed state');
    file_put_contents($root . '/database/schema.sql',$originalSchema);
    $installer->install($values);
    check((int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 1,'retry installation succeeds');
    echo "PASS installer real database, config and install lock\n";
} finally {
    if (isset($pdo)) { $pdo->exec('DROP DATABASE IF EXISTS `' . $values['db_name'] . '`'); }
    foreach ([$root.'/config/config.php',$root.'/storage/install.mutex',$root.'/install/install.lock',$root.'/database/schema.sql'] as $file) { if (is_file($file)) { unlink($file); } }
    foreach (['config','storage','database','install'] as $dir) { if (is_dir($root.'/'.$dir)) { rmdir($root.'/'.$dir); } }
    rmdir($root);
}
