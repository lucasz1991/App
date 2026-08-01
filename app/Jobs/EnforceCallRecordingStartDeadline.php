<?php

namespace App\Jobs;

use App\Models\RoomRecording;
use App\Services\Calls\CallRecordingService;
use App\Services\Calls\RoomLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnforceCallRecordingStartDeadline implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 15;

    public int $uniqueFor = 90;

    public function __construct(public readonly int $recordingId)
    {
    }

    public function uniqueId(): string
    {
        return 'room-recording-deadline:'.$this->recordingId;
    }

    public function handle(CallRecordingService $recordings, RoomLifecycleService $rooms): void
    {
        $recording = RoomRecording::find($this->recordingId);

        if (! $recording) {
            return;
        }

        $roomToEnd = $recordings->enforceStartDeadline($recording);

        if ($roomToEnd?->isActive()) {
            $rooms->endCall($roomToEnd, 'recording_start_timeout');
        }
    }
}
