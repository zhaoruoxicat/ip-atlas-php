<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/app/bootstrap.php';
if (getenv('RUN_MYSQL_TESTS') !== '1') { echo "SKIP concurrency: enable RUN_MYSQL_TESTS on a dedicated database\n"; exit; }
if (($argv[1] ?? '') === 'rate-worker') {
    try { (new RateLimiter(db()))->consume($argv[2],[60=>2]); echo 'allowed'; }
    catch (AppException $e) { if ($e->errorCode !== 'RATE_LIMITED') { throw $e; } echo 'limited'; }
    exit;
}
if (in_array($argv[1] ?? '', ['ip-worker','refresh-worker'], true)) {
    $provider = new class($argv[2]) implements IpProviderInterface {
        public function __construct(private readonly string $callLog) {}
        public function fetch(IpValidator $ip): IpPayload {
            file_put_contents($this->callLog, "call\n", FILE_APPEND | LOCK_EX);
            usleep(300000);
            return new IpPayload(['ip'=>$ip->canonical,'future'=>(object)['new'=>true]]);
        }
    };
    $service = new IpService(new IpRepository(db()),$provider,['ttl_seconds'=>60,'lock_wait_seconds'=>2,'allow_stale_on_upstream_failure'=>true]);
    $result = $service->lookup(IpValidator::parse('8.8.4.4'), $argv[1] === 'refresh-worker');
    echo $result['meta']['cache_status']; exit;
}
function workers(string $mode, string $argument): array
{
    $processes = [];
    for ($i = 0; $i < 6; $i++) {
        $process = proc_open([...testPhpCommand(),__FILE__,$mode,$argument],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        check(is_resource($process),'worker started'); fclose($pipes[0]);
        $processes[] = [$process,$pipes];
    }
    $outputs = [];
    foreach ($processes as [$process,$pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0,'worker exit: ' . $err); $outputs[] = $out;
    }
    return array_count_values($outputs);
}
$bucket = 'concurrency-test:' . bin2hex(random_bytes(8));
$result = workers('rate-worker',$bucket);
check(($result['allowed'] ?? 0) === 2 && ($result['limited'] ?? 0) === 4,'six parallel workers share quota');
$ip = IpValidator::parse('8.8.4.4'); $repo = new IpRepository(db()); $repo->delete($ip);
$log = tempnam(sys_get_temp_dir(),'ip-calls-');
try {
    $result = workers('ip-worker',$log);
    check(($result['miss'] ?? 0) === 1 && ($result['hit'] ?? 0) === 5,'parallel cache misses merged');
    check(count(file($log,FILE_IGNORE_NEW_LINES)) === 1,'exactly one provider request');
    file_put_contents($log,'');
    $result = workers('refresh-worker',$log);
    check(($result['fresh'] ?? 0) === 6,'concurrent forced refresh responses');
    check(count(file($log,FILE_IGNORE_NEW_LINES)) === 1,'unchanged forced refresh coalesced');
    $repo->delete($ip); file_put_contents($log,'');
    workers('refresh-worker',$log);
    check(count(file($log,FILE_IGNORE_NEW_LINES)) === 1,'cold forced refresh coalesced');
    echo "PASS six-worker shared rate limit and same-IP request coalescing\n";
} finally { $repo->delete($ip); unlink($log); }
