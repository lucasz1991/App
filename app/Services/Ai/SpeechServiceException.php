<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

class SpeechServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly ?int $upstreamStatus = null,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('The speech service request failed.', 0, $previous);
    }
}
