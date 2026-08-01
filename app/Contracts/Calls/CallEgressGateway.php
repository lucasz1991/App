<?php

namespace App\Contracts\Calls;

use App\Models\RoomRecording;
use App\Support\Calls\EgressSnapshot;

interface CallEgressGateway
{
    public function isConfigured(): bool;

    public function start(RoomRecording $recording): EgressSnapshot;

    public function stop(string $egressId): EgressSnapshot;

    public function find(string $egressId): ?EgressSnapshot;
}
