<?php

namespace App\Services\Agent;

use RuntimeException;

/**
 * A failed MCP exchange with a site — transport failure, a JSON-RPC error
 * object, or a malformed response. Carries the JSON-RPC error code when the
 * server supplied one.
 */
class McpError extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $rpcCode = null)
    {
        parent::__construct($message);
    }
}
