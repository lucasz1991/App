<?php

namespace App\Services\DeviceManagement;

use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DeviceArtifact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeviceArtifactService
{
    public function __construct(private readonly DeviceManagementSettings $settings) {}

    /** @var array<string, string> */
    private const KINDS_BY_EXTENSION = [
        'ps1' => 'script', 'bat' => 'script', 'cmd' => 'script', 'sh' => 'script',
        'msi' => 'software', 'exe' => 'software', 'pkg' => 'software', 'dmg' => 'software', 'apk' => 'software',
        'mobileconfig' => 'profile',
        'pdf' => 'file', 'txt' => 'file', 'csv' => 'file', 'xlsx' => 'file', 'xls' => 'file',
        'docx' => 'file', 'pptx' => 'file', 'zip' => 'file',
    ];

    /**
     * @throws AuthorizationException
     */
    public function store(Device $device, UploadedFile $file, User $actor): DeviceArtifact
    {
        Gate::forUser($actor)->authorize('devices.commands.execute');

        $maxKilobytes = $this->settings->artifactMaxKilobytes();
        if (! $file->isValid() || $file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'artifactUpload' => 'Die Datei ist ungültig oder überschreitet die erlaubte Größe.',
            ]);
        }

        $extension = mb_strtolower($file->getClientOriginalExtension());
        $kind = self::KINDS_BY_EXTENSION[$extension] ?? null;
        if (! $kind) {
            throw ValidationException::withMessages([
                'artifactUpload' => 'Dieser Dateityp ist für Geräteaktionen nicht freigegeben.',
            ]);
        }

        $disk = $this->settings->artifactDisk();
        if (config("filesystems.disks.{$disk}.visibility") !== 'private') {
            throw ValidationException::withMessages([
                'artifactUpload' => 'Der Geräte-Artefaktspeicher muss privat konfiguriert sein.',
            ]);
        }

        $originalName = Str::limit(basename($file->getClientOriginalName()), 191, '');
        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $safeName = ($safeName !== '' ? $safeName : 'artifact').'.'.$extension;
        $storageName = Str::uuid().'-'.$safeName;
        $path = $file->storeAs('device-management/artifacts/'.$device->public_id, $storageName, $disk);
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'artifactUpload' => 'Die Datei konnte nicht im privaten Speicher abgelegt werden.',
            ]);
        }

        try {
            $stream = Storage::disk($disk)->readStream($path);
            if (! is_resource($stream)) {
                throw new \RuntimeException('Artifact stream unavailable.');
            }

            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            fclose($stream);

            $artifact = DeviceArtifact::query()->create([
                'device_id' => $device->id,
                'kind' => $kind,
                'platform' => $device->platform instanceof DevicePlatform
                    ? $device->platform
                    : DevicePlatform::from((string) $device->platform),
                'name' => $originalName,
                'original_name' => $originalName,
                'disk' => $disk,
                'storage_path' => $path,
                'mime_type' => Storage::disk($disk)->mimeType($path) ?: $file->getClientMimeType(),
                'size_bytes' => Storage::disk($disk)->size($path),
                'sha256' => hash_final($hash),
                'metadata' => [
                    'extension' => $extension,
                    'execution_requested' => false,
                ],
                'uploaded_by' => $actor->id,
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        activity('device-management')
            ->causedBy($actor)
            ->performedOn($device)
            ->withProperties([
                'device_public_id' => $device->public_id,
                'artifact_public_id' => $artifact->public_id,
                'kind' => $artifact->kind,
                'sha256' => $artifact->sha256,
                'size_bytes' => $artifact->size_bytes,
            ])
            ->log('device_artifact_uploaded');

        return $artifact;
    }

    /** @throws AuthorizationException */
    public function approve(DeviceArtifact $artifact, User $actor): void
    {
        Gate::forUser($actor)->authorize('devices.commands.execute');

        $artifact->update([
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        activity('device-management')
            ->causedBy($actor)
            ->performedOn($artifact->device)
            ->withProperties([
                'artifact_public_id' => $artifact->public_id,
                'sha256' => $artifact->sha256,
            ])
            ->log('device_artifact_approved');
    }
}
