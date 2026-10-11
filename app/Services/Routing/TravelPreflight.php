<?php

namespace App\Services\Routing;

/** Request-local server object; never serialized to Livewire, a cache or an audit. */
final readonly class TravelPreflight
{
    public function __construct(public array $assessments, public array $context = [], public bool $preparedOutsideTransaction = false, public array $peerExceptions = []) {}
}
