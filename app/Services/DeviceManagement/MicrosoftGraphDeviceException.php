<?php

namespace App\Services\DeviceManagement;

use RuntimeException;

final class MicrosoftGraphDeviceException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        // Never retain an HTTP exception, request, token or Graph response in
        // the exception chain persisted by a failed queue job.
        parent::__construct('Microsoft device discovery: '.$reason);
    }
}
