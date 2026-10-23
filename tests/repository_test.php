<?php
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/app/bootstrap.php';
if (getenv('RUN_MYSQL_TESTS') !== '1') { echo "SKIP MySQL integration: set RUN_MYSQL_TESTS=1 on a dedicated test database\n"; exit; }
$pdo = db(); $repo = new IpRepository($pdo);
$ips = [IpValidator::parse('8.0.0.8'), IpValidator::parse('2606:4700:4700::1111'), IpValidator::parse('::ffff:8.8.8.8')];
foreach ($ips as $ip) { $repo->delete($ip); }
try {
    foreach ($ips as $ip) {
        $raw = ['ip'=>$ip->canonical, 'isp'=>'Provider', 'latitude'=>12.1,'longitude'=>-45.2,'offset'=>3600,'future'=>['nested'=>true]];
        $row = $repo->save($ip, $raw, 60);
        check(json_decode(json_encode($row['raw'])) == json_decode(json_encode($raw)), 'raw JSON round trip');
        check($row['city'] === null && $row['utc_offset'] === 3600, 'missing null and offset');
        check($row['ip_bin'] === $ip->binary, 'binary including nulls');
        $table = $ip->version === 4 ? 'ip_ipv4' : 'ip_ipv6';
        $stmt = $pdo->prepare('EXPLAIN SELECT * FROM ' . $table . ' WHERE ip_bin = ?');
        $stmt->bindValue(1, $ip->binary, PDO::PARAM_LOB); $stmt->execute();
        check($stmt->fetch()['key'] === 'PRIMARY', 'uses PRIMARY');
        $stmt = $pdo->prepare('SELECT CHARACTER_OCTET_LENGTH FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table,'ip_bin']); check((int)$stmt->fetchColumn() === strlen($ip->binary), 'schema binary width');
    }
    foreach (['ip_ipv4'=>[4,15],'ip_ipv6'=>[16,39]] as $table=>$widths) {
        $stmt = $pdo->prepare('SELECT column_name,character_maximum_length FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name IN (?,?)');
        $stmt->execute([$table,'ip_bin','ip_text']); $columns = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        check((int)$columns['ip_bin'] === $widths[0] && (int)$columns['ip_text'] === $widths[1], 'binary and text schema widths');
        $indexes = array_unique(array_column($pdo->query('SHOW INDEX FROM ' . $table)->fetchAll(),'Key_name'));
        check(!array_diff(['PRIMARY','uk_ip_text','idx_expires','idx_country_region_city','idx_asn','idx_isp'],$indexes),'inherited indexes');
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ip_ipv6 WHERE ip_bin = ?');
    $stmt->bindValue(1,str_pad($ips[0]->binary,16,chr(0)),PDO::PARAM_LOB); $stmt->execute();
    check((int)$stmt->fetchColumn() === 0,'IPv4 not saved to IPv6 table');
    $original = '{"ip":"8.0.0.8","empty":{},"list":[],"large_number":18446744073709551615,"future":{"flag":true}}';
    $saved = $repo->save($ips[0],IpSbProvider::decode($original,$ips[0]),60,$original);
    check(str_contains($saved['raw_json'],'18446744073709551615'),'original JSON numeric precision preserved in storage');
    check($saved['raw']['empty'] instanceof stdClass && $saved['raw']['list'] === [],'object and array storage preserved');
    $equivalent = IpValidator::parse('2606:4700:4700:0000:0000:0000:0000:1111');
    check($repo->find($equivalent) !== null, 'IPv6 equivalent lookup');
    $calls = 0;
    $provider = new class($calls) implements IpProviderInterface {
        private int $calls = 0;
        public bool $fail = false;
        public function __construct(private int &$counter) {}
        public function fetch(IpValidator $ip): IpPayload {
            $this->counter++;
            if ($this->fail) { throw new AppException('UPSTREAM_UNAVAILABLE','test failure',503); }
            return new IpPayload(['ip'=>$ip->canonical,'extra'=>['future'=>1]]);
        }
    };
    $ip = $ips[0]; $repo->delete($ip);
    $service = new IpService($repo,$provider,['ttl_seconds'=>60,'lock_wait_seconds'=>0,'allow_stale_on_upstream_failure'=>true]);
    check($service->lookup($ip)['meta']['cache_status'] === 'miss' && $calls === 1, 'first lookup');
    check($service->lookup($ip)['meta']['cache_status'] === 'hit' && $calls === 1, 'second lookup no upstream');
    check($service->lookup($ip,true)['meta']['cache_status'] === 'fresh' && $calls === 2, 'forced refresh');
    $stmt = $pdo->prepare('UPDATE ip_ipv4 SET expires_at = ? WHERE ip_bin = ?');
    $stmt->bindValue(1,'2000-01-01 00:00:00'); $stmt->bindValue(2,$ip->binary,PDO::PARAM_LOB); $stmt->execute();
    $before = $repo->find($ip); $provider->fail = true;
    check($service->lookup($ip)['meta']['stale'] === true, 'stale on provider failure');
    check($repo->find($ip)['fetched_at'] === $before['fetched_at'], 'failure preserves dates');
    $strict = new IpService($repo,$provider,['ttl_seconds'=>60,'lock_wait_seconds'=>0,'allow_stale_on_upstream_failure'=>false]);
    expectError(fn() => $strict->lookup($ip),'UPSTREAM_UNAVAILABLE');
    $provider->fail = false;
    check($service->lookup($ip)['meta']['cache_status'] === 'fresh', 'expired refresh');
    $tokenService = new TokenService($pdo);
    $created = $tokenService->create('integration-test', ['lookup'], null);
    check(strlen($created['token']) === 64, 'random token length');
    $token = $tokenService->authenticate($created['token']);
    check(in_array('lookup',$token['scopes'],true), 'scope persisted');
    expectError(fn() => $tokenService->requireScope($token,'refresh'), 'SCOPE_DENIED');
    $stmt = $pdo->prepare('SELECT token_hash FROM api_tokens WHERE id = ?'); $stmt->execute([$created['id']]);
    check($stmt->fetchColumn() === hash('sha256',$created['token']), 'only hash stored');
    $rotated = $tokenService->rotate($created['id']);
    expectError(fn() => $tokenService->authenticate($created['token']), 'TOKEN_INVALID');
    $tokenService->authenticate($rotated);
    $tokenService->setEnabled($created['id'], false);
    expectError(fn() => $tokenService->authenticate($rotated), 'TOKEN_DISABLED');
    $tokenService->setEnabled($created['id'], true);
    $tokenService->authenticate($rotated);
    $tokenService->delete($created['id']);
    $expired = $tokenService->create('expired', ['lookup'], '2000-01-01 00:00:00');
    expectError(fn() => $tokenService->authenticate($expired['token']), 'TOKEN_EXPIRED');
    $tokenService->delete($expired['id']);
    $limiter = new RateLimiter($pdo); $bucket = 'integration:' . bin2hex(random_bytes(8));
    $limiter->consume($bucket,[60=>1]);
    expectError(fn() => (new RateLimiter(Database::connect($GLOBALS['config']['db'])))->consume($bucket,[60=>1]), 'RATE_LIMITED');
    echo "PASS repository, cache, token, cross-connection limiter\n";
} finally { foreach ($ips as $ip) { $repo->delete($ip); } }
