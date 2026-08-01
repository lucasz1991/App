<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

final class AssistantSpeechException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $provider,
        public readonly ?int $upstreamStatus = null,
        public readonly ?string $requestId = null,
        public readonly bool $fallbackAttempted = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct('The assistant speech request failed.', 0, $previous);
    }

    public static function fromLocal(
        SpeechServiceException $exception,
        bool $fallbackAttempted = false,
    ): self {
        return new self(
            $exception->reasonCode,
            AssistantSpeechRouter::PROVIDER_LOCAL,
            $exception->upstreamStatus,
            $exception->requestId,
            $fallbackAttempted,
            $exception,
        );
    }

    public function afterFallback(): self
    {
        return new self(
            $this->reasonCode,
            $this->provider,
            $this->upstreamStatus,
            $this->requestId,
            true,
            $this,
        );
    }
}
