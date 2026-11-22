<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

final class OAuth_Failure extends \RuntimeException {
 public function __construct(public readonly string $reason='invalid_request',public readonly int $status=400) { parent::__construct('The MCP connection request could not be authorized.'); }
}
