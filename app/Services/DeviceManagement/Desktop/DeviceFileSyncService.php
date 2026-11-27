<?php

namespace App\Services\DeviceManagement\Desktop;

use App\Models\Device;
use App\Models\File;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentVersion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Verbindliche Dateien (z. B. die Wagenliste) legt die RailTime-App auf Firmen-PCs mit Office-Vertrauen
 * von selbst in den Ordner RailTime\Listen. Weil Excel dort Makros ohne Sperre ausführt, gilt:
 * - verteilt wird nur die Version, die ein Superadmin ausdrücklich freigegeben hat;
 * - freigeben lässt sich nur eine Version, die an ein aktives Herausgeber-Zertifikat gekoppelt ist;
 * - eine neue Version erreicht die Geräte erst nach erneuter Freigabe;
 * - die App prüft Größe und SHA-256 und überschreibt nie eine Datei, die der Nutzer verändert hat.
 */
final class DeviceFileSyncService
{
    public const CAPABILITY = 'file_sync';

    public const EXTENSIONS = ['xlsm', 'xlsx', 'xltm', 'xltx', 'pdf'];

    public const MAX_FILES = 10;

    public const MAX_BYTES = 50 * 1024 * 1024;

    /** Dateiname auf dem PC: keine Pfade, keine Steuerzeichen, kein führender Punkt. */
    public const NAME_PATTERN = '/\A(?!\.)[\p{L}\p{N} _.,()+\-]{1,116}\.(xlsm|xlsx|xltm|xltx|pdf)\z/u';

    public const KEY_PATTERN = '/\A[a-z0-9][a-z0-9-]{0,190}\z/';

    public function ready(): bool
    {
        try {
            return Schema::hasTable('managed_documents') && Schema::hasColumn('managed_documents', 'device_release_version_id');
        } catch (\Throwable) {
            return false;
        }
    }

    public function canRelease(?User $user): bool
    {
        return app(DeviceTrustService::class)->canManage($user);
    }

    /** Warum sich die aktuelle Version (noch) nicht freigeben lässt; null = freigebbar. */
    public function blocker(ManagedDocument $document): ?string
    {
        $version = $document->currentVersion()->with(['file', 'trustCertificates'])->first();
        if (! $version?->file) {
            return 'Die Datei hat noch keine Version.';
        }
        if (! in_array($this->extension($version->file), self::EXTENSIONS, true)) {
            return 'Nur Excel-Listen und PDFs lassen sich auf die PCs verteilen.';
        }
        if (! $version->trustCertificates->contains(fn ($certificate) => $certificate->active)) {
            return 'Die aktuelle Version ist mit keinem aktiven Zertifikat gekoppelt. Erst signieren und mit Zertifikat hochladen.';
        }
        if ((int) $version->file->size > self::MAX_BYTES) {
            return 'Die Datei ist größer als 50 MB.';
        }

        return null;
    }

    public function release(ManagedDocument $document, User $actor): ManagedDocumentVersion
    {
        abort_unless($this->ready() && $this->canRelease($actor), 403);
        if ($reason = $this->blocker($document)) {
            throw ValidationException::withMessages(['deviceRelease' => $reason]);
        }

        return DB::transaction(function () use ($document, $actor): ManagedDocumentVersion {
            $locked = ManagedDocument::query()->lockForUpdate()->findOrFail($document->id);
            $version = $locked->currentVersion()->with('file')->firstOrFail();
            $this->sha256($version->file);
            $locked->forceFill(['device_release_version_id' => $version->id, 'device_released_by' => $actor->id, 'device_released_at' => now()])->save();
            activity('device-management')->causedBy($actor)->performedOn($locked)->event('device-desktop.file-released')
                ->withProperties(['document' => $locked->slug, 'version' => $version->version_number])
                ->log('Verbindliche Datei für Firmen-PCs freigegeben');

            return $version;
        }, 3);
    }

    public function withdraw(ManagedDocument $document, User $actor): void
    {
        abort_unless($this->ready() && $this->canRelease($actor), 403);
        $document->forceFill(['device_release_version_id' => null, 'device_released_by' => $actor->id, 'device_released_at' => now()])->save();
        activity('device-management')->causedBy($actor)->performedOn($document)->event('device-desktop.file-withdrawn')
            ->withProperties(['document' => $document->slug])->log('Verteilung auf Firmen-PCs beendet');
    }

    /**
     * Dateien für dieses Gerät. Nur Firmengeräte mit Office-Vertrauen erhalten etwas; die
     * Zielgruppe der Datei gilt wie beim Herunterladen in RailTime.
     *
     * @return Collection<int, array{key: string, version: int, name: string, sha256: string, size: int}>
     */
    public function plan(Device $device, User $employee, array $policy): Collection
    {
        if (! $this->ready() || ! ($policy['allow_office_trust'] ?? false) || $device->ownership !== 'corporate') {
            return collect();
        }

        return ManagedDocument::query()->visibleTo($employee)->where('is_active', true)
            ->whereNotNull('device_release_version_id')->orderBy('id')->limit(self::MAX_FILES)->get()
            ->map(function (ManagedDocument $document): ?array {
                $version = ManagedDocumentVersion::query()->with('file')->whereKey($document->device_release_version_id)
                    ->where('managed_document_id', $document->id)->first();
                $file = $version?->file;
                if (! $file || ! in_array($this->extension($file), self::EXTENSIONS, true) || (int) $file->size > self::MAX_BYTES
                    || ! preg_match(self::KEY_PATTERN, (string) $document->slug)) {
                    return null;
                }
                $sha256 = $this->sha256($file);
                if ($sha256 === null) {
                    return null;
                }

                return ['key' => (string) $document->slug, 'version' => (int) $version->version_number,
                    'name' => $this->fileName($document, $version, $file), 'sha256' => $sha256, 'size' => (int) $file->size];
            })->filter()->values();
    }

    /** Liefert genau die freigegebene Version, die im Plan dieses Geräts steht. */
    public function download(Device $device, User $employee, array $policy, string $key, int $version): StreamedResponse
    {
        $entry = $this->plan($device, $employee, $policy)->first(fn (array $entry) => $entry['key'] === $key && $entry['version'] === $version);
        abort_unless($entry !== null, 404);
        $document = ManagedDocument::query()->where('slug', $key)->firstOrFail();
        $file = ManagedDocumentVersion::query()->with('file')->whereKey($document->device_release_version_id)->firstOrFail()->file;
        $disk = Storage::disk($file->disk ?: 'private');
        abort_unless($disk->exists($file->path), 404);

        return $disk->download($file->path, $entry['name'], [
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'X-RailTime-Sha256' => $entry['sha256'],
        ]);
    }

    private function sha256(File $file): ?string
    {
        if (is_string($file->content_sha256) && preg_match('/\A[a-f0-9]{64}\z/', $file->content_sha256)) {
            return $file->content_sha256;
        }
        $disk = Storage::disk($file->disk ?: 'private');
        if (! $disk->exists($file->path)) {
            return null;
        }
        $stream = $disk->readStream($file->path);
        if (! is_resource($stream)) {
            return null;
        }
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);
        $hash = hash_final($context);
        $file->forceFill(['content_sha256' => $hash])->saveQuietly();

        return $hash;
    }

    private function extension(File $file): string
    {
        return strtolower(pathinfo((string) ($file->name ?: $file->path), PATHINFO_EXTENSION));
    }

    private function fileName(ManagedDocument $document, ManagedDocumentVersion $version, File $file): string
    {
        $name = trim(basename(str_replace('\\', '/', (string) $file->name)));
        if (preg_match(self::NAME_PATTERN, $name) && ! preg_match('/\A(con|prn|aux|nul|com\d|lpt\d)(\.|\z)/i', $name)) {
            return $name;
        }

        return $document->slug.'-v'.$version->version_number.'.'.$this->extension($file);
    }
}
