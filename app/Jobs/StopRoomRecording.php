<?php

namespace App\Jobs;

use App\Models\RoomRecording;
use App\Services\Calls\CallRecordingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StopRoomRecording implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 25;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $uniqueFor = 120;

    public function __construct(public readonly int $recordingId) {}

    public function uniqueId(): string
    {
        return 'room-recording-stop:'.$this->recordingId;
    }

    public function handle(CallRecordingService $recordings): void
    {
        $recording = RoomRecording::find($this->recordingId);

        if ($recording) {
            $recordings->stop($recording);
        }
    }
}
