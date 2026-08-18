<?php

namespace App\Enums;

enum DeviceManagementStatus: string
{
    case Unmanaged = 'unmanaged';
    case Pending = 'pending';
    case Managed = 'managed';
    case Limited = 'limited';
    case Error = 'error';
}
