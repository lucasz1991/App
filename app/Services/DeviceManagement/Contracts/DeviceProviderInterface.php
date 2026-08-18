<?php

namespace App\Services\DeviceManagement\Contracts;

use App\Enums\DeviceCommandType;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Services\DeviceManagement\Data\CommandResult;
use App\Services\DeviceManagement\Data\EnrollmentResult;
use App\Services\DeviceManagement\Data\HealthResult;

interface DeviceProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function enabled(): bool;

    /** @return array<string, mixed> */
    public function capabilities(): array;

    public function supportsPlatform(string $platform): bool;

    public function supportsCommand(DeviceCommandType $type): bool;

    public function health(): HealthResult;

    public function enrollment(DeviceEnrollment $enrollment, Device $device): EnrollmentResult;

    public function dispatch(DeviceCommand $command, Device $device): CommandResult;

    public function remoteSupportUrl(Device $device): ?string;
}
