<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
final class IpService
{
    public function __construct(private readonly IpRepository $repository, private readonly IpProviderInterface $provider, private readonly array $config) {}

    public function lookup(IpValidator $ip, bool $refresh = false): array
    {
        $ip->assertPublic();
        $cached = $this->repository->find($ip);
        if (!$refresh && $cached && $this->isFresh($cached)) { return $this->result($ip, $cached, 'hit'); }
        if (!$this->repository->acquire($ip, (int)$this->config['lock_wait_seconds'])) {
            $latest = $this->repository->find($ip);
            if ($latest && $this->isFresh($latest)) { return $this->result($ip, $latest, 'hit'); }
            if ($latest && $this->config['allow_stale_on_upstream_failure']) { return $this->result($ip, $latest, 'stale'); }
            throw new AppException('UPSTREAM_UNAVAILABLE', '该 IP 正在刷新，请稍后重试。', 503, 2);
        }
        try {
            $latest = $this->repository->find($ip);
            // 等待锁期间其他 Worker 完成回源，可复用其结果；显式刷新尊重刷新请求。
            if (!$refresh && $latest && $this->isFresh($latest)) { return $this->result($ip, $latest, 'hit'); }
            if ($refresh && $latest && (!$cached || $latest['fetch_version'] !== $cached['fetch_version']) && $this->isFresh($latest)) { return $this->result($ip, $latest, 'fresh'); }
            try { $payload = $this->provider->fetch($ip); }
            catch (AppException $error) {
                Logger::write('upstream_failure', ['code'=>$error->errorCode,'ip_version'=>$ip->version]);
                if ($latest && $this->config['allow_stale_on_upstream_failure']) { return $this->result($ip, $latest, 'stale'); }
                throw $error;
            }
            $row = $this->repository->save($ip, $payload->data, (int)$this->config['ttl_seconds'], $payload->json);
            return $this->result($ip, $row, $latest ? 'fresh' : 'miss');
        } finally { $this->repository->release($ip); }
    }
    private function isFresh(array $row): bool { return strtotime($row['expires_at'] . ' UTC') > time(); }
    private function result(IpValidator $ip, array $row, string $status): array
    {
        return ['success'=>true,'data'=>$row['raw'],'_raw_json'=>$row['raw_json'],'meta'=>['ip_version'=>$ip->version,'source'=>$row['source'],'cache_status'=>$status,'fetched_at'=>gmdate('Y-m-d\TH:i:s\Z', strtotime($row['fetched_at'] . ' UTC')),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z', strtotime($row['expires_at'] . ' UTC')),'stale'=>$status === 'stale']];
    }
}
