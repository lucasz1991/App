<?php

namespace App\Enums;

enum DeviceCommandStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Queued = 'queued';
    case Dispatched = 'dispatched';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
