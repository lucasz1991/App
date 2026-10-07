<?php

namespace App\Services\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeAttachment;
use App\Models\AiIntakeMessage;
use App\Services\Ai\Attachments\AssistantAttachmentProcessor;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiIntakeAttachmentService
{
    private const AUDIO = ['wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'], 'mp3' => ['audio/mpeg', 'audio/mp3'], 'ogg' => ['audio/ogg', 'video/ogg'], 'webm' => ['audio/webm', 'video/webm'], 'm4a' => ['audio/mp4', 'video/mp4', 'audio/x-m4a'], 'mp4' => ['audio/mp4', 'video/mp4'], 'aac' => ['audio/aac', 'audio/x-aac', 'audio/x-hx-aac-adts'], 'flac' => ['audio/flac', 'audio/x-flac']];

    /** Persist only validated bounded bytes; callers own transaction rollback cleanup. */
    public function store(AiIntake $intake, AiIntakeMessage $message, array $uploads, array &$paths): void
    {
        $settings = AiDispositionSettings::all();
        $maxCount = max(1, min(3, (int) $settings['max_attachment_count']));
        $maxTotal = max(1, min(15360, (int) $settings['max_total_kilobytes'])) * 1024;
        $maxAudio = max(1, min(8192, (int) $settings['max_audio_kilobytes'])) * 1024;
        abort_if(count($uploads) > $maxCount, 422, 'Die Anzahl der Anlagen überschreitet die konfigurierte Grenze.');
        $total = 0;
        foreach ($uploads as $upload) {
            [$name, $bytes] = $this->incoming($upload);
            $size = strlen($bytes);
            $total += $size;
            abort_if($size < 1 || $total > $maxTotal, 422, 'Anlagen sind leer oder überschreiten die konfigurierte Gesamtgröße.');
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $audio = isset(self::AUDIO[$extension]);
            abort_if($size > ($audio ? $maxAudio : (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? 5 : 10) * 1024 * 1024), 422, 'Anlage überschreitet die zulässige Größe.');
            $hash = hash('sha256', $bytes);
            if ($message->attachments()->where('file_hash', $hash)->exists()) {
                continue;
            }
            $path = $this->put($intake, $bytes, $paths);
            $file = new UploadedFile(Storage::disk('local')->path($path), $name, null, UPLOAD_ERR_OK, true);
            $mime = (string) $file->getMimeType();
            if ($audio) {
                if (! in_array($mime, self::AUDIO[$extension], true)) {
                    throw ValidationException::withMessages(['uploads' => 'Audioformat und Dateiinhalt stimmen nicht überein.']);
                }
            } else {
                app(AssistantAttachmentProcessor::class)->validate([$file]);
            }
            $kind = $audio ? 'audio' : match ($extension) {
                'jpg', 'jpeg', 'png', 'webp' => 'image', 'pdf' => 'pdf', 'docx', 'xlsx', 'pptx' => 'office', default => 'text',
            };
            $message->attachments()->create(['intake_id' => $intake->id, 'file_name' => $name, 'file_mime' => $mime, 'file_size' => $size, 'file_hash' => $hash, 'file_path' => $path, 'kind' => $kind, 'status' => 'stored', 'metadata' => ['extension' => $extension]]);
        }
    }

    public function put(AiIntake $intake, string $bytes, array &$paths): string
    {
        $path = 'ai-intake/'.$intake->public_id.'/'.Str::uuid();
        abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'Original konnte nicht gespeichert werden.');
        $paths[] = $path;

        return $path;
    }

    public function bytes(AiIntake $intake, string $path, string $hash): string
    {
        abort_unless(preg_match('#^ai-intake/'.preg_quote($intake->public_id, '#').'/[a-f0-9-]{36}$#D', $path) && Storage::disk('local')->exists($path), 404);
        $bytes = Storage::disk('local')->get($path);
        abort_unless(hash_equals($hash, hash('sha256', $bytes)), 409, 'Originaldatei wurde verändert.');

        return $bytes;
    }

    public function uploaded(AiIntakeAttachment $attachment): UploadedFile
    {
        $this->bytes($attachment->intake, $attachment->file_path, $attachment->file_hash);

        return new UploadedFile(Storage::disk('local')->path($attachment->file_path), $attachment->file_name, $attachment->file_mime, UPLOAD_ERR_OK, true);
    }

    public function discard(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            if (preg_match('#^ai-intake/[a-f0-9-]{36}/[a-f0-9-]{36}$#D', $path) && ! AiIntakeAttachment::where('file_path', $path)->exists() && ! AiIntakeMessage::where('raw_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function incoming(mixed $upload): array
    {
        if ($upload instanceof UploadedFile && $upload->isValid()) {
            abort_if((int) $upload->getSize() > 10 * 1024 * 1024, 422);
            $name = $upload->getClientOriginalName();
            $bytes = file_get_contents($upload->getRealPath());
        } elseif (is_array($upload) && is_string($upload['name'] ?? null) && is_string($upload['bytes'] ?? null)) {
            $name = $upload['name'];
            $bytes = $upload['bytes'];
        } else {
            throw ValidationException::withMessages(['uploads' => 'Anlage konnte nicht sicher übernommen werden.']);
        }
        $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name)))), 0, 180);
        abort_unless($name !== '' && is_string($bytes), 422);

        return [$name, $bytes];
    }
}
