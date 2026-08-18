<?php

namespace App\Services\DeviceManagement\Data;

final readonly class EnrollmentResult
{
    /** @param list<string> $steps */
    public function __construct(
        public string $status,
        public array $steps,
        public ?string $enrollmentUrl = null,
        public ?string $expiresAt = null,
        public bool $limitedManagement = false,
        public ?string $message = null,
    ) {}
}
