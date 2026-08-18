<?php

namespace App\Enums;

enum DeviceLifecycleStatus: string
{
    case Inventory = 'inventory';
    case Preparing = 'preparing';
    case Assigned = 'assigned';
    case InService = 'in_service';
    case Repair = 'repair';
    case Lost = 'lost';
    case Retired = 'retired';
}
