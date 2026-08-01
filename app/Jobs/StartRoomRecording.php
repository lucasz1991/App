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
use Illuminate\Support\Facades\Log;
use Throwable;

class StartRoomRecording implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 25;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $recordingId)
    {
    }

    public function uniqueId(): string
    {
        return 'room-recording-start:'.$this->recordingId;
    }

    public function handle(CallRecordingService $recordings, RoomLifecycleService $rooms): void
    {
        if (! $recordings->enabled()) {
            return;
        }

        $recording = RoomRecording::find($this->recordingId);

        if (! $recording) {
            return;
        }

        $roomToEnd = $recordings->start($recording);

        if ($roomToEnd?->isActive()) {
            $rooms->endCall($roomToEnd, 'recording_failed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical('Queue-Job zum Start der verpflichtenden Aufnahme ist fehlgeschlagen.', [
            'recording_id' => $this->recordingId,
            'error_class' => $exception ? $exception::class : null,
        ]);
    }
}
