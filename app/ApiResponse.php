<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
final class ApiResponse
{
    public const GROUPS = [
        'location' => ['ip','country_code','country','region_code','region','city','postal_code','continent_code','timezone','offset'],
        'network' => ['ip','asn','asn_organization','isp','organization'],
        'coordinates' => ['ip','latitude','longitude'],
    ];
    public const FIELDS = ['ip','country_code','country','region_code','region','city','postal_code','continent_code','timezone','offset','asn','asn_organization','isp','organization','latitude','longitude'];

    public static function validate(string $mode, ?string $fields): ?array
    {
        if ($fields !== null) {
            $selection = array_map('trim', explode(',', $fields));
            if (count($selection) > 32 || array_diff($selection, self::FIELDS)) { throw new AppException('INVALID_FIELDS', 'fields 包含空字段或未知字段。'); }
            return array_values(array_unique($selection));
        }
        if ($mode !== 'full' && !isset(self::GROUPS[$mode])) { throw new AppException('INVALID_MODE', 'mode 必须为 full、location、network 或 coordinates。'); }
        return self::GROUPS[$mode] ?? null;
    }

    /** full 保留原始未知字段；投影中未提供字段返回 null。 */
    public static function select(array $raw, string $mode, ?string $fields): array
    {
        $selection = self::validate($mode, $fields);
        if ($selection === null) { return $raw; }
        $result = [];
        foreach ($selection as $field) { $result[$field] = $raw[$field] ?? null; }
        return $result;
    }

    /** full 直接嵌入数据库保存的 JSON，避免 PHP 解析大整数后丢失未来字段精度。 */
    public static function encodeResult(array $result, string $mode = 'full', ?string $fields = null): string
    {
        $selection = self::validate($mode, $fields);
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
        if ($selection === null && isset($result['_raw_json'])) {
            return '{"success":true,"data":' . $result['_raw_json'] . ',"meta":' . json_encode($result['meta'], $flags) . '}';
        }
        unset($result['_raw_json']);
        $result['data'] = self::select($result['data'], $mode, $fields);
        return json_encode($result, $flags);
    }

    public static function sendResult(array $result, string $mode = 'full', ?string $fields = null): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo self::encodeResult($result, $mode, $fields);
        exit;
    }

    public static function send(array $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function error(AppException $e): never
    {
        if ($e->retryAfter !== null) { header('Retry-After: ' . $e->retryAfter); }
        self::send(['success'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]], $e->status);
    }
}
