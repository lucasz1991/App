<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomRecording;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CallRecordingPlaybackController extends Controller
{
    public function __invoke(
        Request $request,
        Room $room,
        RoomRecording $recording,
    ): RedirectResponse {
        // Absichtlich kein Gate: Der globale Admin-Bypass darf hier niemals
        // greifen. Nur der in rooms.owner_id festgehaltene Organisator sieht die Datei.
        abort_unless((int) $room->owner_id === (int) $request->user()->id, 403);
        abort_unless((int) $recording->room_id === (int) $room->id, 404);
        abort_unless($recording->isPlayable(), 410);

        try {
            $disk = Storage::disk($recording->storage_disk);
            $exists = $disk->exists($recording->storage_key);
        } catch (Throwable $exception) {
            Log::warning('Call-Aufzeichnungsdatei konnte nicht geprueft werden.', [
                'recording_uuid' => $recording->uuid,
                'error_class' => $exception::class,
            ]);

            abort(503, 'Die Aufzeichnung ist momentan nicht verfuegbar.');
        }

        abort_unless($exists, 410);

        try {
            $url = $disk->temporaryUrl(
                $recording->storage_key,
                now()->addMinutes(5),
                [
                    'ResponseContentType' => 'video/mp4',
                    'ResponseContentDisposition' => 'inline; filename="railtime-call-'.$room->uuid.'.mp4"',
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Temporäre Wiedergabe-URL fuer Call-Aufzeichnung konnte nicht erstellt werden.', [
                'recording_uuid' => $recording->uuid,
                'error_class' => $exception::class,
            ]);

            abort(503, 'Die Aufzeichnung ist momentan nicht verfuegbar.');
        }

        return redirect()->away($url)
            ->withHeaders([
                'Cache-Control' => 'private, no-store, max-age=0',
                'Referrer-Policy' => 'no-referrer',
            ]);
    }
}
