<?php

namespace App\Enums;

enum DeviceEnrollmentStatus: string
{
    case Invited = 'invited';
    case Claimed = 'claimed';
    case Completed = 'completed';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
