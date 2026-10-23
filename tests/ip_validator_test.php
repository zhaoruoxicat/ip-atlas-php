<?php
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }

require __DIR__ . '/bootstrap.php';
$v4 = IpValidator::parse(' 8.8.8.8 ');
check($v4->version === 4 && strlen($v4->binary) === 4 && $v4->canonical === '8.8.8.8', 'IPv4 normalization');
$a = IpValidator::parse('2606:4700:4700::1111');
$b = IpValidator::parse('2606:4700:4700:0000:0000:0000:0000:1111');
check($a->binary === $b->binary && $a->version === 6, 'IPv6 equivalence');
check(IpValidator::parse('::ffff:8.8.8.8')->version === 6, 'mapped IPv6 stays IPv6');
check(strlen(IpValidator::parse('8.0.0.8')->binary) === 4, 'zero bytes retained');
foreach (['', 'example.com', 'https://8.8.8.8', '8.8.8.8/32', '1.2.3.999', '01.2.3.4', '::gg', '8.8.8.8,1.1.1.1'] as $ip) {
    expectError(fn() => IpValidator::parse($ip), 'INVALID_IP');
}
foreach (['0.0.0.0', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.1.1', '172.16.0.1', '192.168.0.1', '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '240.0.0.1', '::', '::1', 'fc00::1', 'fe80::1', 'ff02::1', '2001:db8::1', '2001:2::1', '2001:10::1', '2002::1', '64:ff9b::808:808', '::ffff:192.168.1.1'] as $ip) {
    expectError(fn() => IpValidator::parse($ip)->assertPublic(), 'NON_PUBLIC_IP');
}
foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2001:4860:4860::8888', '::ffff:8.8.8.8'] as $ip) {
    IpValidator::parse($ip)->assertPublic();
}
echo "PASS IP validator\n";
