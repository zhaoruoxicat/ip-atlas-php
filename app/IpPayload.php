<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
/** 原始 JSON 文本和经过校验的常用字段一起传递，防止重编码丢失未来字段的类型或精度。 */
final class IpPayload
{
    public readonly string $json;
    public function __construct(public readonly array $data, ?string $originalJson = null)
    {
        $this->json = $originalJson ?? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
