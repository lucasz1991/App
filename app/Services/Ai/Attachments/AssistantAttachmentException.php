<?php

namespace App\Services\Ai\Attachments;

use RuntimeException;
use Throwable;

class AssistantAttachmentException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $userMessage,
        public readonly string $validationKey = 'attachments',
        ?Throwable $previous = null,
    ) {
        parent::__construct('The assistant attachment could not be processed.', 0, $previous);
    }
}
