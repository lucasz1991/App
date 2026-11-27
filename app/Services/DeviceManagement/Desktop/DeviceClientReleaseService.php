<?php

namespace App\Services\DeviceManagement\Desktop;

use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\DeviceManagementSettings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Freigegebener Installer der RailTime-App für Windows. Liegt im privaten Speicher, nie unter public/,
 * und wird nur angemeldeten Mitarbeitern über einen Controller ausgeliefert. Hochladen darf nur ein
 * Superadmin; der Server merkt sich Version, SHA-256 und ob die Datei eine Authenticode-Signatur trägt.
 */
final class DeviceClientReleaseService
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    private const SETTING_TYPE = 'device_management';

    private const SETTING_KEY = 'client_release';

    private const DIRECTORY = 'device-client/releases';

    public function __construct(private readonly DeviceManagementSettings $settings) {}

    public function canManage(?User $user): bool
    {
        return $user !== null && $user->isActive() && $user->isSuperAdmin() && $user->email_verified_at !== null
            && Gate::forUser($user)->allows('settings.manage');
    }

    /** @return array{version: string, sha256: string, size: int, signed: bool, uploaded_at: string, path: string}|null */
    public function current(): ?array
    {
        $value = Setting::getValueUncached(self::SETTING_TYPE, self::SETTING_KEY);
        $release = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : null);
        if (! is_array($release) || ! isset($release['version'], $release['sha256'], $release['path'], $release['size'])) {
            return null;
        }
        if (! preg_match(DeviceDesktopService::VERSION_PATTERN, (string) $release['version'])
            || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $release['sha256'])
            || ! str_starts_with((string) $release['path'], self::DIRECTORY.'/')) {
            return null;
        }

        return ['version' => (string) $release['version'], 'sha256' => (string) $release['sha256'], 'size' => (int) $release['size'],
            'signed' => (bool) ($release['signed'] ?? false), 'uploaded_at' => (string) ($release['uploaded_at'] ?? ''), 'path' => (string) $release['path']];
    }

    public function available(): bool
    {
        $release = $this->current();

        return $release !== null && Storage::disk($this->settings->artifactDisk())->exists($release['path']);
    }

    public function store(string $sourcePath, string $version, User $actor): array
    {
        abort_unless($this->canManage($actor), 403);
        $fail = fn (string $message) => throw ValidationException::withMessages(['releaseFile' => $message]);
        $version = trim($version);
        if (! preg_match(DeviceDesktopService::VERSION_PATTERN, $version)) {
            throw ValidationException::withMessages(['releaseVersion' => 'Bitte eine gültige Versionsnummer angeben, z. B. 0.2.0.']);
        }
        $size = @filesize($sourcePath);
        if (! is_int($size) || $size < 64 * 1024 || $size > self::MAX_BYTES) {
            $fail('Der Installer ist leer oder größer als 50 MB.');
        }
        $handle = fopen($sourcePath, 'rb');
        $head = $handle ? (string) fread($handle, 2) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($head !== 'MZ') {
            $fail('Das ist kein Windows-Programm (.exe).');
        }
        $disk = $this->settings->artifactDisk();
        if (config("filesystems.disks.{$disk}.visibility") !== 'private') {
            $fail('Der Speicher für Geräte-Dateien muss privat konfiguriert sein.');
        }
        $sha256 = hash_file('sha256', $sourcePath);
        $path = self::DIRECTORY.'/'.$sha256.'.exe';
        $stream = fopen($sourcePath, 'rb');
        Storage::disk($disk)->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        $previous = $this->current();
        $release = ['version' => $version, 'sha256' => $sha256, 'size' => $size, 'signed' => $this->hasAuthenticode($sourcePath),
            'uploaded_at' => now()->toIso8601String(), 'uploaded_by' => $actor->id, 'path' => $path];
        Setting::setValue(self::SETTING_TYPE, self::SETTING_KEY, $release);
        if ($previous !== null && $previous['path'] !== $path) {
            Storage::disk($disk)->delete($previous['path']);
        }
        activity('device-management')->causedBy($actor)->event('device-desktop.release-uploaded')
            ->withProperties(['version' => $version, 'sha256' => $sha256, 'signed' => $release['signed']])
            ->log('RailTime-App-Installer hochgeladen');

        return $release;
    }

    public function download(): StreamedResponse
    {
        $release = $this->current();
        abort_unless($release !== null, 404);
        $disk = Storage::disk($this->settings->artifactDisk());
        abort_unless($disk->exists($release['path']), 404);

        return $disk->download($release['path'], 'RailTime-Setup-'.$release['version'].'.exe', [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Ob der PE-Kopf ein Sicherheitsverzeichnis (Authenticode) enthält. Prüft nicht die Vertrauenskette. */
    public function hasAuthenticode(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return false;
        }
        try {
            fseek($handle, 0x3C);
            $offset = unpack('V', (string) fread($handle, 4))[1] ?? 0;
            if ($offset <= 0 || $offset > 4096) {
                return false;
            }
            fseek($handle, $offset);
            if (fread($handle, 4) !== "PE\0\0") {
                return false;
            }
            fseek($handle, $offset + 24);
            $magic = unpack('v', (string) fread($handle, 2))[1] ?? 0;
            $directory = match ($magic) {
                0x10B => $offset + 24 + 96 + 4 * 8,
                0x20B => $offset + 24 + 112 + 4 * 8,
                default => 0,
            };
            if ($directory === 0) {
                return false;
            }
            fseek($handle, $directory);
            $entry = unpack('Vaddress/Vsize', (string) fread($handle, 8));

            return is_array($entry) && $entry['address'] > 0 && $entry['size'] > 8;
        } finally {
            fclose($handle);
        }
    }
}
