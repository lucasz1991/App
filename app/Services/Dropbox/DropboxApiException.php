<?php

namespace App\Services\Dropbox;

use RuntimeException;

final class DropboxApiException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $retryAfter = 30)
    {
        parent::__construct('Dropbox: '.$reason);
    }
}
