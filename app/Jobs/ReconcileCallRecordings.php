<?php

namespace App\Jobs;

use App\Enums\RoomRecordingStatus;
use App\Models\RoomRecording;
use App\Services\Calls\CallRecordingService;
use App\Services\Calls\RoomLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileCallRecordings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 55;

    public function __construct()
    {
        $this->onQueue((string) config('call_recording.queue', 'calls'));
    }

    public function uniqueId(): string
    {
        return 'call-recordings-reconcile';
    }

    public function handle(CallRecordingService $recordings, RoomLifecycleService $rooms): void
    {
        if (! $recordings->infrastructureReady()) {
            return;
        }

        RoomRecording::query()
            ->whereIn('status', [
                RoomRecordingStatus::Requested->value,
                RoomRecordingStatus::Starting->value,
                RoomRecordingStatus::Active->value,
                RoomRecordingStatus::Ending->value,
            ])
            ->orderBy('id')
            ->eachById(function (RoomRecording $recording) use ($recordings, $rooms): void {
                $roomToEnd = $recordings->reconcile($recording);

                if ($roomToEnd?->isActive()) {
                    $rooms->endCall($roomToEnd, 'recording_failed');
                }
            }, 50);
    }
}
