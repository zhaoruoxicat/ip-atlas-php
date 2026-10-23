<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
/** 可向调用方公开的业务异常；不包含数据库或凭据详情。 */
final class AppException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 400, public readonly ?int $retryAfter = null)
    { parent::__construct($message); }
}
