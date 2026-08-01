<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

class OpenRouterChatException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly ?int $upstreamStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('The assistant provider request failed.', 0, $previous);
    }
}
