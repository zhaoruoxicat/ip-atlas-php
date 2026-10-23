<?php
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }

require __DIR__ . '/bootstrap.php';
$raw = ['ip' => '8.8.8.8', 'isp' => 'Provider', 'organization' => '', 'extra' => ['nested' => true], 'latitude' => 0];
check(ApiResponse::select($raw, 'full', null) === $raw, 'full preserves unknown fields');
check(ApiResponse::select($raw, 'network', null) === ['ip'=>'8.8.8.8','asn'=>null,'asn_organization'=>null,'isp'=>'Provider','organization'=>''], 'missing null versus empty');
check(ApiResponse::select($raw, 'bad', 'latitude,longitude') === ['latitude'=>0,'longitude'=>null], 'fields override mode');
foreach (['bad', '', 'ip,,isp', 'raw_json', 'country;DROP TABLE'] as $fields) { expectError(fn() => ApiResponse::validate('full', $fields), 'INVALID_FIELDS'); }
expectError(fn() => ApiResponse::validate('unknown', null), 'INVALID_MODE');
$ip = IpValidator::parse('2606:4700:4700::1111');
$valid = IpSbProvider::decode('{"ip":"2606:4700:4700:0:0:0:0:1111","latitude":1,"future":{"x":1}}', $ip);
check(isset($valid['future']), 'provider unknown field preserved');
foreach (['{}', '[]', 'null', '{broken', '{"ip":"8.8.8.8"}', '{"ip":[]}', '{"ip":"2606:4700:4700::1111","latitude":100}', '{"ip":"2606:4700:4700::1111","isp":[]}'] as $body) { expectError(fn() => IpSbProvider::decode($body, $ip), 'UPSTREAM_INVALID_RESPONSE'); }
$objects = IpSbProvider::decode('{"ip":"2606:4700:4700::1111","empty":{},"list":[]}', $ip);
check($objects['empty'] instanceof stdClass && $objects['list'] === [], 'preserves empty object versus array');
$largeJson = '{"ip":"8.8.8.8","large":18446744073709551615,"empty":{}}';
$encoded = ApiResponse::encodeResult(['success'=>true,'data'=>json_decode($largeJson,true),'meta'=>['ip_version'=>4],'_raw_json'=>$largeJson], 'full', null);
check(str_contains($encoded,'18446744073709551615') && !str_contains($encoded,'_raw_json'), 'full preserves original numbers without internal field');
$projected = json_decode(ApiResponse::encodeResult(['success'=>true,'data'=>$raw,'meta'=>[],'_raw_json'=>json_encode($raw)], 'network', null),true);
check(!isset($projected['data']['extra']) && !isset($projected['_raw_json']), 'projected response has no raw internal data');
echo "PASS response and provider\n";
