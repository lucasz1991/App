<?php

namespace App\Support\OutlookAddin;

use RuntimeException;

final class OutlookAddinException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 500,
        public readonly string $errorCode = 'outlook_addin_error',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
