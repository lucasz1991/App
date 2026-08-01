<?php

namespace App\Jobs;

use App\Enums\RoomRecordingStatus;
use App\Models\RoomRecording;
use App\Services\Calls\RoomEventRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PurgeExpiredCallRecordings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600];

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue((string) config('call_recording.queue', 'calls'));
    }

    public function uniqueId(): string
    {
        return 'call-recordings-retention';
    }

    public function handle(RoomEventRecorder $events): void
    {
        RoomRecording::query()
            ->where('status', RoomRecordingStatus::Complete->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->eachById(function (RoomRecording $recording) use ($events): void {
                $disk = Storage::disk($recording->storage_disk);

                if ($recording->storage_key && $disk->exists($recording->storage_key)) {
                    $deleted = $disk->delete($recording->storage_key);

                    if (! $deleted || $disk->exists($recording->storage_key)) {
                        throw new \RuntimeException('S3 bestaetigte die Loeschung der Call-Aufzeichnung nicht.');
                    }
                }

                $recording->forceFill([
                    'status' => RoomRecordingStatus::Expired,
                    'storage_key' => null,
                ])->save();

                $events->record(
                    $recording->room,
                    'recording_expired',
                    metadata: [
                        'recording_uuid' => $recording->uuid,
                        'status' => RoomRecordingStatus::Expired->value,
                    ],
                    externalId: 'recording:'.$recording->uuid.':expired',
                );
            }, 50);
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical('Abgelaufene Call-Aufzeichnungen konnten nach allen Versuchen nicht geloescht werden.', [
            'error_class' => $exception ? $exception::class : null,
        ]);
    }
}
