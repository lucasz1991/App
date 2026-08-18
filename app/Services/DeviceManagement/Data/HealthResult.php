<?php

namespace App\Services\DeviceManagement\Data;

final readonly class HealthResult
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        public bool $healthy,
        public string $status,
        public array $details = [],
    ) {}
}
