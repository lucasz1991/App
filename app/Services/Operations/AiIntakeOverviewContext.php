<?php

namespace App\Services\Operations;

/** A server-created, request-local proof for readonly overview advice. */
final readonly class AiIntakeOverviewContext
{
    public function __construct(
        public int $actorId,
        public ?int $customerId,
        public int $settingsRevision,
        public string $inputHash,
        public float $startedAt,
    ) {}
}
