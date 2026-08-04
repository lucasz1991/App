<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Aufnahmen der Wagenlisten-Video-Erfassung (Prototyp) entgegennehmen.
 *
 * Die Wagenliste selbst ist ein lokaler Browser-Entwurf ohne Servermodell —
 * Medien werden deshalb ueber die Entwurfs-ID zugeordnet und als File-Modell
 * am erfassenden Benutzer verankert (morph "fileable"), Ablage im privaten
 * Speicher unter wagon-lists/{user}/{draft}. Die Kompression passiert
 * clientseitig (WebM mit moderater Bitrate, JPEG 0.72) — der Server
 * speichert nur noch das kompakte Ergebnis.
 */
class WagonListMediaController extends Controller
{
    private const KIND_RULES = [
        'video' => ['mimes' => ['video/webm', 'video/mp4'], 'max_kb' => 102_400],
        'image' => ['mimes' => ['image/jpeg', 'image/webp', 'image/png'], 'max_kb' => 10_240],
        'audio' => ['mimes' => ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg'], 'max_kb' => 25_600],
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'draft_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'kind' => ['required', 'in:video,image,audio'],
            'file' => ['required', 'file'],
        ]);

        $rules = self::KIND_RULES[$validated['kind']];
        $upload = $request->file('file');
        $mime = strtolower((string) $upload->getMimeType());
        $baseMime = strtolower(explode(';', (string) $upload->getClientMimeType())[0]);

        if (! in_array($mime, $rules['mimes'], true) && ! in_array($baseMime, $rules['mimes'], true)) {
            return response()->json(['message' => __('app.wagon_video_upload_error')], 422);
        }

        if ($upload->getSize() > $rules['max_kb'] * 1024) {
            return response()->json(['message' => __('app.wagon_video_upload_error')], 422);
        }

        $user = $request->user();
        $extension = $upload->getClientOriginalExtension() ?: $upload->guessExtension() ?: 'bin';
        $safeExtension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'bin';
        $directory = sprintf('wagon-lists/%d/%s', $user->id, $validated['draft_id']);
        $storedName = Str::uuid()->toString().'.'.$safeExtension;
        $path = $upload->storeAs($directory, $storedName, 'private');

        $file = new File([
            'user_id' => $user->id,
            'name' => sprintf(
                'Wagenliste %s – %s',
                $validated['draft_id'],
                $upload->getClientOriginalName() ?: $storedName,
            ),
            'path' => $path,
            'disk' => 'private',
            'mime_type' => $mime ?: $baseMime,
            'type' => 'wagon-list-media',
            'size' => $upload->getSize(),
        ]);
        $file->fileable()->associate($user);
        $file->save();

        return response()->json([
            'id' => $file->id,
            'name' => $file->name,
            'size' => (int) $file->size,
        ], 201);
    }
}
