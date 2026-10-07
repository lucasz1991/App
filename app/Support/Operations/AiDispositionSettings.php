<?php

namespace App\Support\Operations;

use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterModelProfile;
use App\Support\Ai\OpenRouterSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

final class AiDispositionSettings
{
    public const GROUP = 'operations';

    public const KEY = 'ai_disposition';

    public const SECRET_MASK = OpenRouterSettings::SECRET_MASK;

    public const DEFAULTS = [
        'enabled' => false, 'automation_mode' => 'automatic', 'revision' => 1, 'supervisor_id' => null,
        'imap_host' => '', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_username' => '', 'imap_password' => '', 'imap_folder' => 'INBOX',
        'smtp_same_credentials' => true, 'smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => '', 'smtp_password' => '',
        'from_address' => '', 'from_name' => 'RailTime Disposition', 'max_rounds' => 2, 'reply_timeout_hours' => 48, 'poll_interval_minutes' => 1,
        'max_messages_per_poll' => 25, 'max_attachment_count' => 3, 'max_total_kilobytes' => 15360, 'max_audio_kilobytes' => 8192,
        'max_ai_calls_per_hour' => 100, 'instructions' => '',
    ];

    public static function all(bool $uncached = false): array
    {
        try {
            $stored = (array) (Setting::getValueUncached(self::GROUP, self::KEY) ?? []);
            $values = array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
            foreach (['imap_password', 'smtp_password'] as $field) {
                $secret = (string) $values[$field];
                $values[$field] = str_starts_with($secret, 'enc:v1:') ? Crypt::decryptString(substr($secret, 7)) : '';
            }

            return $values;
        } catch (Throwable) {
            return self::DEFAULTS;
        }
    }

    public static function forForm(): array
    {
        $values = self::all(true);
        foreach (['imap_password', 'smtp_password'] as $field) {
            $values[$field] = $values[$field] === '' ? '' : self::SECRET_MASK;
        }
        $values['expected_revision'] = $values['revision'];

        return $values;
    }

    public static function save(array $values, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        $actor = $actor instanceof User ? User::find($actor->id) : null;
        abort_unless($actor?->isActive() && $actor->isSuperAdmin(), 403);
        $current = self::all(true);
        $clean = array_replace($current, array_intersect_key($values, self::DEFAULTS));
        foreach (['imap_password', 'smtp_password'] as $field) {
            if (trim((string) $clean[$field]) === '' || $clean[$field] === self::SECRET_MASK) {
                $clean[$field] = $current[$field];
            }
        }
        foreach (['imap_host', 'imap_username', 'imap_folder', 'smtp_host', 'smtp_username', 'from_address', 'from_name', 'instructions'] as $field) {
            $clean[$field] = trim((string) $clean[$field]);
        }
        $rules = [
            'enabled' => ['required', 'boolean'], 'automation_mode' => ['required', Rule::in(['automatic', 'assisted'])],
            'supervisor_id' => ['nullable', 'integer'], 'smtp_same_credentials' => ['required', 'boolean'],
            'imap_host' => ['nullable', 'string', 'max:253'], 'smtp_host' => ['nullable', 'string', 'max:253'],
            'imap_port' => ['required', 'integer', 'between:1,65535'], 'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', Rule::in(['ssl', 'tls'])], 'smtp_encryption' => ['required', Rule::in(['ssl', 'tls'])],
            'imap_username' => ['nullable', 'string', 'max:255'], 'smtp_username' => ['nullable', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:2048'], 'smtp_password' => ['nullable', 'string', 'max:2048'],
            'imap_folder' => ['required', 'string', 'max:255'], 'from_address' => ['nullable', 'email:rfc', 'max:254'],
            'from_name' => ['required', 'string', 'max:180'], 'instructions' => ['nullable', 'string', 'max:10000'],
            'max_rounds' => ['required', 'integer', 'between:1,2'], 'reply_timeout_hours' => ['required', 'integer', 'between:1,720'],
            'poll_interval_minutes' => ['required', 'integer', 'between:1,60'], 'max_messages_per_poll' => ['required', 'integer', 'between:1,100'],
            'max_attachment_count' => ['required', 'integer', 'between:1,3'], 'max_total_kilobytes' => ['required', 'integer', 'between:1,15360'],
            'max_audio_kilobytes' => ['required', 'integer', 'between:1,8192'], 'max_ai_calls_per_hour' => ['required', 'integer', 'between:1,1000'],
        ];
        Validator::make($clean, $rules)->validate();
        foreach (['imap_host', 'smtp_host'] as $field) {
            abort_if($clean[$field] !== '' && (! preg_match('/^[a-z0-9.-]+$/iD', $clean[$field]) || str_contains($clean[$field], '..')), 422, 'Ungültiger Mailserver.');
        }
        foreach (['imap_username', 'smtp_username', 'imap_folder', 'from_name'] as $field) {
            abort_if(preg_match('/[\r\n\x00]/', $clean[$field]), 422, 'Ungültige Eingabe.');
        }
        if ($clean['enabled']) {
            abort_unless(AiIntakeSchema::ready() && app(OpenRouterChatClient::class)->isConfiguredFor(OpenRouterModelProfile::Data), 422, 'AI-Eingang und gemeinsame AI-Verbindung zuerst einrichten.');
            abort_unless(self::supervisor($clean) && trim($clean['imap_host']) !== '' && trim($clean['imap_username']) !== '' && $clean['imap_password'] !== '' && $clean['smtp_host'] !== '' && $clean['from_address'] !== '' && self::smtpCredentials($clean)['password'] !== '' && self::smtpCredentials($clean)['username'] !== '', 422, 'Postfach und verantwortliche Disposition vollständig einrichten.');
        }
        DB::transaction(function () use ($clean, $values, $current): void {
            $row = Setting::query()->where('type', self::GROUP)->where('key', self::KEY)->lockForUpdate()->first();
            $revision = (int) (($row?->value ?? [])['revision'] ?? 1);
            abort_unless((int) ($values['expected_revision'] ?? $current['revision']) === $revision, 409, 'Die Einstellungen wurden inzwischen geändert.');
            $clean['revision'] = $revision + 1;
            foreach (['imap_password', 'smtp_password'] as $field) {
                $clean[$field] = $clean[$field] === '' ? '' : 'enc:v1:'.Crypt::encryptString($clean[$field]);
            }
            Setting::setValue(self::GROUP, self::KEY, $clean);
            if (($clean['enabled'] && ! $current['enabled']) || self::mailboxId($clean) !== self::mailboxId($current)) {
                Setting::setValue(self::GROUP, 'ai_disposition_mailbox_runtime', ['state' => 'activation_pending', 'requested_at' => now()->utc()->toIso8601String()]);
            }
        });
    }

    public static function enabled(): bool
    {
        return (bool) self::all(true)['enabled'];
    }

    public static function supervisor(?array $settings = null): ?User
    {
        $settings ??= self::all(true);
        $user = User::find((int) ($settings['supervisor_id'] ?? 0));

        return $user?->isActive() && $user->can('operations.inquiries.manage') ? $user : null;
    }

    public static function smtpCredentials(?array $settings = null): array
    {
        $settings ??= self::all(true);

        return (bool) $settings['smtp_same_credentials']
            ? ['username' => $settings['imap_username'], 'password' => $settings['imap_password']]
            : ['username' => $settings['smtp_username'], 'password' => $settings['smtp_password']];
    }

    public static function status(): array
    {
        $settings = self::all(true);
        $client = app(OpenRouterChatClient::class);

        return ['enabled' => (bool) $settings['enabled'], 'revision' => (int) $settings['revision'], 'configured' => $client->isConfiguredFor(OpenRouterModelProfile::Data), 'schema_ready' => AiIntakeSchema::ready(),
            'image_configured' => $client->isConfiguredFor(OpenRouterModelProfile::ImageUnderstanding), 'supervisor_ready' => self::supervisor($settings) !== null,
            'mailbox_configured' => $settings['imap_host'] !== '' && $settings['imap_username'] !== '' && $settings['imap_password'] !== '',
            'smtp_configured' => $settings['smtp_host'] !== '' && $settings['from_address'] !== '' && self::smtpCredentials($settings)['password'] !== '',
            'cache_persistent' => ! in_array(config('cache.stores.'.config('cache.default').'.driver'), ['array', 'null'], true),
            'runtime' => (array) (Setting::getValueUncached(self::GROUP, 'ai_disposition_mailbox_runtime') ?? [])];
    }

    public static function mailboxId(?array $settings = null): string
    {
        $settings ??= self::all(true);

        return hash('sha256', strtolower($settings['imap_host']).':'.$settings['imap_port'].'|'.$settings['imap_username'].'|'.$settings['imap_folder']);
    }
}
