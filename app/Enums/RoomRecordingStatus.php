<?php

namespace App\Enums;

enum RoomRecordingStatus: string
{
    case Requested = 'requested';
    case Starting = 'starting';
    case Active = 'active';
    case Ending = 'ending';
    case Complete = 'complete';
    case Failed = 'failed';
    case Aborted = 'aborted';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Complete, self::Failed, self::Aborted, self::Expired], true);
    }

    public function permitsPublishing(): bool
    {
        return $this === self::Active;
    }
}
