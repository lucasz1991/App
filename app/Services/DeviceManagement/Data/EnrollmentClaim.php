<?php

namespace App\Services\DeviceManagement\Data;

use App\Models\DeviceEnrollment;

final readonly class EnrollmentClaim
{
    public function __construct(
        public DeviceEnrollment $enrollment,
        public EnrollmentResult $instructions,
    ) {}
}
