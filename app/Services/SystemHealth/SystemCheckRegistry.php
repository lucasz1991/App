<?php

namespace App\Services\SystemHealth;

use App\Models\Setting;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use InvalidArgumentException;
use Throwable;

/** Only these code-owned IDs can reach a handler. No browser-supplied transport input. */
class SystemCheckRegistry
{
    public const VERSION = '2026-09-06.1';

    public function all(): array
    {
        $groups = [
            'Anwendung und Daten' => [
                'application' => 'Anwendung und PHP', 'database' => 'Datenbank und Migrationen',
                'session' => 'Sitzungen', 'assets' => 'Build-Assets',
            ],
            'Cache und Dateien' => ['cache' => 'Anwendungscache', 'storage' => 'Dateien und Speicher', 'backups' => 'Backups'],
            'Hintergrundverarbeitung' => [
                'scheduler' => 'Scheduler-Nachweis', 'queue_default' => 'Standard-Worker',
                'queue_devices' => 'Geräte-Worker', 'queue_microsoft' => 'Microsoft-Worker',
                'queue_calls' => 'Aufzeichnungs-Worker', 'queue_push' => 'Push-Worker', 'queue_marketing' => 'Marketing-Worker',
            ],
            'Microsoft und Geräte' => [
                'microsoft' => 'Microsoft Graph', 'microsoft_runtime' => 'Microsoft-Synchronisierung',
                'device_openuem' => 'OpenUEM', 'device_meshcentral' => 'MeshCentral',
                'device_headwind' => 'Headwind MDM', 'device_nanomdm' => 'NanoMDM',
                'device_identity' => 'Konten-Connector', 'device_simulation' => 'Gerätesimulation',
            ],
            'Kommunikation' => ['mail' => 'E-Mail / SMTP', 'realtime' => 'Realtime', 'livekit' => 'LiveKit', 'push' => 'Web Push'],
            'Weitere Integrationen' => [
                'speech' => 'Lokaler Sprachdienst', 'ai' => 'KI-Konfiguration', 'outlook' => 'Outlook-Add-in',
                'recordings' => 'Aufzeichnungen', 'marketing' => 'Marketing-Rendering',
            ],
        ];
        $rows = [];
        foreach ($groups as $group => $checks) {
            foreach ($checks as $id => $label) {
                $device = str_starts_with($id, 'device_') || str_contains($id, 'microsoft') || $id === 'queue_devices';
                $calls = in_array($id, ['livekit', 'recordings', 'queue_calls'], true);
                $ai = in_array($id, ['ai', 'speech'], true);
                $rows[$id] = [
                    'id' => $id, 'label' => $label, 'group' => $group,
                    'settings_tab' => $device ? 'device-management' : (($ai || $calls) ? 'superadmin' : 'system'),
                    'settings_section' => match (true) {
                        str_contains($id, 'microsoft') => 'device-microsoft',
                        $id === 'device_identity' => 'device-identities',
                        $id === 'device_simulation' => 'device-safety',
                        $device => 'device-providers', $ai => 'assistant-runtime',
                        $calls => 'calls', $id === 'mail' => 'mails', default => 'system',
                    },
                ];
            }
        }

        return $rows;
    }

    public function get(string $id): array
    {
        return $this->all()[$id] ?? throw new InvalidArgumentException('Unknown system check.');
    }

    /** Credentials affect freshness, but neither configuration nor hash leaves the server. */
    public function fingerprint(string $id): string
    {
        $this->get($id);
        $roots = match (true) {
            str_starts_with($id, 'queue_'), $id === 'scheduler' => ['queue', 'database', 'device_management', 'call_recording', 'webpush', 'marketing'],
            str_starts_with($id, 'device_'), str_starts_with($id, 'microsoft') => ['device_management', 'queue'],
            in_array($id, ['application', 'database', 'cache', 'session', 'storage', 'assets'], true) => ['app', 'database', 'cache', 'session', 'filesystems', 'jetstream', 'marketing', 'outlook_addin', 'device_management', 'livewire', 'call_recording'],
            default => ['mail', 'broadcasting', 'reverb', 'services', 'assistant', 'livekit', 'webpush', 'call_recording', 'outlook_addin', 'marketing'],
        };
        $values = ['version' => self::VERSION, 'id' => $id, 'app_key' => config('app.key'), 'environment' => app()->environment()];
        foreach ($roots as $root) {
            $values[$root] = config($root);
        }
        foreach (array_filter([
            $id === 'speech' ? config('assistant.speech.token_file') : null,
            $id === 'push' ? config('webpush.auto_provision_path', storage_path('app/private/webpush-vapid.json')) : null,
        ], 'is_string') as $credentialFile) {
            // Existing small credential files influence freshness without hydration or key generation.
            $values['credential_file_fingerprint'] = is_file($credentialFile) && is_readable($credentialFile)
                && filesize($credentialFile) <= 16384 ? hash_file('sha256', $credentialFile) : 'unavailable';
        }
        try {
            if (str_starts_with($id, 'queue_')) {
                $values['queue_target'] = app(QueueChecks::class)->target($id);
            }
            if (str_starts_with($id, 'microsoft') || $id === 'queue_microsoft') {
                $values['microsoft'] = app(MicrosoftDeviceSettings::class)->fingerprint();
            } elseif (str_starts_with($id, 'device_') && $id !== 'device_simulation') {
                $values['provider'] = app(DeviceManagementSettings::class)->providerRuntime(substr($id, 7), fresh: true);
            } else {
                // Operational result summaries are excluded: they must not invalidate themselves.
                $values['settings'] = Setting::query()->whereIn('type', ['assistant', 'services', 'calls', 'webpush', 'system'])
                    ->orderBy('type')->orderBy('key')->get(['type', 'key', 'value'])->toArray();
            }
        } catch (Throwable) {
            $values['settings'] = 'unavailable';
        }

        return hash('sha256', serialize($values));
    }
}
