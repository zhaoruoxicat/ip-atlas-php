<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
final class IpSbProvider implements IpProviderInterface
{
    public function __construct(private readonly array $config, private readonly RateLimiter $limiter)
    {
        if (($config['base_url'] ?? '') !== 'https://api.ip.sb/geoip/') { throw new RuntimeException('Unsupported upstream host'); }
    }

    /** 有限重试，每次真实回源均消耗共享配额，禁重定向、保持 TLS 校验。 */
    public function fetch(IpValidator $ip): IpPayload
    {
        $ip->assertPublic();
        if (!extension_loaded('curl')) { throw new AppException('UPSTREAM_UNAVAILABLE', '上游请求服务未就绪。', 503); }
        $attempts = 1 + max(0, min(1, (int)($this->config['retries'] ?? 1)));
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $this->limiter->consume('upstream:ip.sb', [1=>$this->config['max_requests_per_second'],60=>$this->config['max_requests_per_minute']]);
            $handle = curl_init($this->config['base_url'] . rawurlencode($ip->canonical));
            $body = ''; $overflow = false;
            curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_CONNECTTIMEOUT=>max(1, min(3, (int)$this->config['connect_timeout_seconds'])), CURLOPT_TIMEOUT=>max(1, min(8, (int)$this->config['timeout_seconds'])), CURLOPT_USERAGENT=>$this->config['user_agent'], CURLOPT_HTTPHEADER=>['Accept: application/json'], CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$body, &$overflow): int {
                if (strlen($body) + strlen($chunk) > 1048576) { $overflow = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
            $ok = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            unset($handle);
            if ($overflow) { throw new AppException('UPSTREAM_INVALID_RESPONSE', '上游响应超过长度限制。', 502); }
            if ($ok !== false && $status === 200) { return new IpPayload(self::decode($body, $ip), $body); }
            if ($status === 429) { throw new AppException('UPSTREAM_UNAVAILABLE', '上游暂时限流，请稍后重试。', 503, 60); }
            if (($ok === false || $status >= 500) && $attempt + 1 < $attempts) { usleep(200000); continue; }
            if ($ok === false || $status >= 500) { throw new AppException('UPSTREAM_UNAVAILABLE', '暂时无法连接上游服务。', 503); }
            throw new AppException('UPSTREAM_ERROR', '上游服务未能返回查询结果。', 502);
        }
        throw new AppException('UPSTREAM_UNAVAILABLE', '上游服务不可用。', 503);
    }

    /** 仅接受 JSON 对象、合法已知类型和与请求一致的 IP。 */
    public static function decode(string $body, IpValidator $requested): array
    {
        try {
            $object = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            if (!$object instanceof stdClass || !isset($object->ip) || !is_string($object->ip)) { throw new RuntimeException('Invalid object'); }
            $actual = IpValidator::parse($object->ip);
            if ($actual->binary !== $requested->binary || $actual->version !== $requested->version) { throw new RuntimeException('IP mismatch'); }
            $raw = get_object_vars($object);
            $strings = ['country_code'=>8,'country'=>128,'region_code'=>32,'region'=>128,'city'=>128,'postal_code'=>32,'continent_code'=>8,'timezone'=>80,'asn_organization'=>255,'isp'=>255,'organization'=>255];
            foreach ($strings as $field=>$max) {
                if (isset($raw[$field]) && (!is_string($raw[$field]) || preg_match_all('/./us', $raw[$field]) > $max)) { throw new RuntimeException('Invalid string'); }
            }
            foreach (['latitude'=>90,'longitude'=>180] as $field=>$range) {
                if (isset($raw[$field]) && ((!is_float($raw[$field]) && !is_int($raw[$field])) || !is_finite((float)$raw[$field]) || abs($raw[$field]) > $range)) { throw new RuntimeException('Invalid coordinate'); }
            }
            if (isset($raw['offset']) && (!is_int($raw['offset']) || abs($raw['offset']) > 86400)) { throw new RuntimeException('Invalid offset'); }
            if (isset($raw['asn']) && (!is_int($raw['asn']) || $raw['asn'] < 0 || $raw['asn'] > 4294967295)) { throw new RuntimeException('Invalid ASN'); }
            return $raw;
        } catch (Throwable) { throw new AppException('UPSTREAM_INVALID_RESPONSE', '上游响应无效或 IP 不匹配。', 502); }
    }
}
