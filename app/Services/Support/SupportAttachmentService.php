<?php

namespace App\Services\Support;

use App\Models\Setting;
use App\Models\SupportCase;
use App\Models\SupportCaseAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SupportAttachmentService
{
    public function store(SupportCase $case, User $actor, UploadedFile $file, bool $confirmed): SupportCaseAttachment
    {
        app(SupportCaseService::class)->authorize($case, $actor);
        abort_unless($confirmed, 422, 'Datei vor dem Hochladen ausdrücklich freigeben.');
        validator(['attachment' => $file], ['attachment' => ['required', 'file', 'max:5120', 'mimetypes:image/png,image/jpeg,application/pdf']])->validate();
        $path = 'device-support/'.$case->public_id.'/'.Str::uuid().'.enc';
        try {
            return DB::transaction(function () use ($case, $actor, $file, $path): SupportCaseAttachment {
                $case = SupportCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
                app(SupportCaseService::class)->authorize($case, $actor);
                abort_if($case->attachments()->where('expires_at', '>', now())->count() >= 3, 422, 'Maximal drei aktive Anhänge pro Fall.');
                $bytes = file_get_contents($file->getRealPath());
                abort_unless(is_string($bytes) && strlen($bytes) <= 5242880, 422);
                abort_unless(Storage::disk('private')->put($path, Crypt::encryptString($bytes)), 500);
                $days = max(1, min(90, (int) (((array) Setting::getValueUncached('device_management', 'support'))['retention_days'] ?? 30)));

                return $case->attachments()->create(['user_id' => $actor->id, 'disk' => 'private', 'path' => $path,
                    'name' => preg_replace('/[^\pL\pN ._-]/u', '_', mb_substr($file->getClientOriginalName(), 0, 160)), 'mime_type' => $file->getMimeType(), 'size' => strlen($bytes), 'expires_at' => now()->addDays($days)]);
            }, 3);
        } catch (\Throwable $error) {
            Storage::disk('private')->delete($path);
            throw $error;
        }
    }

    public function contents(SupportCaseAttachment $attachment, User $actor): string
    {
        $case = SupportCase::query()->findOrFail($attachment->support_case_id);
        app(SupportCaseService::class)->authorize($case, $actor);
        abort_unless($attachment->expires_at->isFuture() && $attachment->disk === 'private'
            && preg_match('~^device-support/[a-f0-9-]{36}/[a-f0-9-]{36}\.enc$~', $attachment->path), 404);

        return Crypt::decryptString(Storage::disk('private')->get($attachment->path));
    }
}
