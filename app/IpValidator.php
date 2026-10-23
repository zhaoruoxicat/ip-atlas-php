<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
final class IpValidator
{
    private function __construct(public readonly int $version, public readonly string $binary, public readonly string $canonical) {}

    /** 输入格式决定版本，包括 IPv4-mapped IPv6。 */
    public static function parse(string $input): self
    {
        $ip = trim($input);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) { $version = 4; }
        elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) { $version = 6; }
        else { throw new AppException('INVALID_IP', '请输入单个合法的 IPv4 或 IPv6 地址。'); }
        $bin = inet_pton($ip);
        return new self($version, $bin, inet_ntop($bin));
    }

    /** 默认只回源公网单播；保守拦截特殊用途及地址转换范围。 */
    public function assertPublic(): void
    {
        $bin = $this->binary;
        if ($this->version === 6 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $bin = substr($bin, 12); // 仅用于公网性判断，不改变版本或存储键。
        }
        if (strlen($bin) === 4) {
            $blocked = ['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'];
        } else {
            if (!self::inCidr($bin, '2000::/3')) { $this->deny(); }
            $blocked = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        }
        foreach ($blocked as $range) { if (self::inCidr($bin, $range)) { $this->deny(); } }
    }

    private function deny(): never { throw new AppException('NON_PUBLIC_IP', '私网、保留或特殊用途地址不允许向上游查询。'); }

    /** 二进制前缀匹配，同样用于可信代理名单。 */
    public static function inCidr(string $binary, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        $network = @inet_pton($parts[0]);
        if ($network === false || strlen($network) !== strlen($binary)) { return false; }
        $bits = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : strlen($network) * 8;
        if ($bits < 0 || $bits > strlen($network) * 8) { return false; }
        $bytes = intdiv($bits, 8); $remainder = $bits % 8;
        return substr($binary, 0, $bytes) === substr($network, 0, $bytes)
            && ($remainder === 0 || ((ord($binary[$bytes]) ^ ord($network[$bytes])) & (0xff << (8 - $remainder))) === 0);
    }
}
