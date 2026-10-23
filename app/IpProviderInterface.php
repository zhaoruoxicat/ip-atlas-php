<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('IP_ATLAS_ENTRY')) { http_response_code(404); exit; }
interface IpProviderInterface { public function fetch(IpValidator $ip): IpPayload; }
