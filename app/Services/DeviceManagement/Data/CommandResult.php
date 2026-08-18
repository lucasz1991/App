<?php

namespace App\Services\DeviceManagement\Data;

final readonly class CommandResult
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        public bool $accepted,
        public bool $completed,
        public ?string $providerJobId = null,
        public ?string $message = null,
        public array $details = [],
    ) {}

    /** @return array<string, mixed> */
    public function toSanitizedArray(): array
    {
        return array_filter([
            'accepted' => $this->accepted,
            'completed' => $this->completed,
            'message' => $this->message,
            'details' => $this->details,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
