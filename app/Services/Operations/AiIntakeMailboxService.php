<?php

namespace App\Services\Operations;

use App\Jobs\PollAiIntakeMailbox;
use App\Jobs\ProbeAiDispositionWorker;
use App\Models\Setting;
use App\Models\User;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AiIntakeMailboxService
{
    private const RUNTIME_KEY = 'ai_disposition_mailbox_runtime';

    public function __construct(private readonly AiIntakeImapClient $imap, private readonly AiDispositionSmtpTransport $smtp) {}

    private function authorize(User $actor): void
    {
        $fresh = User::find($actor->id);
        abort_unless($fresh?->isActive() && $fresh->isSuperAdmin(), 403);
    }

    public function probe(User $actor): array
    {
        $this->authorize($actor);
        $settings = AiDispositionSettings::all(true);
        $result = [];
        try {
            $info = $this->imap->snapshot($settings);
            $result['imap'] = ['state' => 'authenticated', 'uid_validity' => $info['uid_validity'], 'messages' => $info['messages']];
        } catch (\Throwable) {
            $result['imap'] = ['state' => 'unavailable'];
        }
        try {
            $this->authorize($actor);
            $proof = $this->smtp->probe($settings);
            $result['smtp'] = ['state' => $proof['authenticated'] && $proof['tls'] ? 'authenticated' : 'unavailable'];
        } catch (\Throwable) {
            $result['smtp'] = ['state' => 'unavailable'];
        }

        return $result;
    }

    public function status(): array
    {
        $runtime = (array) (Setting::getValueUncached(AiDispositionSettings::GROUP, self::RUNTIME_KEY) ?? []);
        $worker = Cache::get('ai-disposition:worker', []);

        return $runtime + ['activation_pending' => AiDispositionSettings::enabled() && (! isset($runtime['uid_validity']) || ($runtime['mailbox_id'] ?? '') !== AiDispositionSettings::mailboxId()),
            'worker' => $worker, 'worker_pending' => Cache::has('ai-disposition:worker-probe'),
            'cache_persistent' => ! in_array(config('cache.stores.'.config('cache.default').'.driver'), ['array', 'null'], true)];
    }

    public function probeWorker(User $actor): void
    {
        $this->authorize($actor);
        $nonce = (string) Str::uuid();
        Cache::put('ai-disposition:worker-probe', $nonce, 120);
        ProbeAiDispositionWorker::dispatch($nonce);
    }

    public function pollNow(User $actor): void
    {
        $this->authorize($actor);
        abort_unless(AiDispositionSettings::enabled(), 409);
        PollAiIntakeMailbox::dispatch();
    }

    public function previewRecent(User $actor, int $limit = 20): array
    {
        $this->authorize($actor);
        $settings = AiDispositionSettings::all(true);
        $result = $this->imap->recent($settings, max(1, min(50, $limit)));
        $token = (string) Str::uuid();
        Cache::put('ai-disposition:preview:'.$token, ['actor_id' => $actor->id, 'mailbox_id' => AiDispositionSettings::mailboxId($settings),
            'settings_revision' => $settings['revision'], 'uid_validity' => $result['uid_validity'], 'uids' => array_column($result['messages'], 'uid')], 600);

        return $result + ['token' => $token, 'mailbox_id' => AiDispositionSettings::mailboxId($settings)];
    }

    public function importPreview(User $actor, string $token, array $uids): array
    {
        $this->authorize($actor);
        $preview = Cache::pull('ai-disposition:preview:'.$token);
        $settings = AiDispositionSettings::all(true);
        $selected = array_values(array_unique(array_map('intval', $uids)));
        abort_unless($settings['enabled'] && is_array($preview) && $preview['actor_id'] === $actor->id && $preview['mailbox_id'] === AiDispositionSettings::mailboxId($settings) && $preview['settings_revision'] === $settings['revision'] && count($selected) > 0 && count($selected) <= 50 && array_diff($selected, $preview['uids']) === [], 409, 'Importvorschau ist nicht mehr aktuell.');
        PollAiIntakeMailbox::dispatch($selected, (int) $preview['uid_validity'], $preview['mailbox_id'], (int) $settings['revision']);

        return ['queued' => count($selected)];
    }

    public function poll(array $historicalUids = [], ?int $historicalValidity = null, ?string $expectedMailbox = null, ?int $expectedSettingsRevision = null): array
    {
        $lock = Cache::lock('ai-disposition:mailbox-poll', 660);
        if (! $lock->get()) {
            return ['state' => 'busy'];
        }
        try {
            $settings = AiDispositionSettings::all(true);
            if (! $settings['enabled'] || ! AiDispositionSettings::supervisor($settings) || ! AiIntakeSchema::ready()) {
                return ['state' => 'disabled'];
            }
            $mailbox = AiDispositionSettings::mailboxId($settings);
            if ($expectedMailbox !== null && ($expectedMailbox !== $mailbox || $expectedSettingsRevision !== (int) $settings['revision'])) {
                return ['state' => 'stale'];
            }
            $snapshot = $this->imap->snapshot($settings);
            abort_unless($snapshot['uid_validity'] > 0 && $snapshot['uid_next'] > 0, 503);
            $runtime = (array) (Setting::getValueUncached(AiDispositionSettings::GROUP, self::RUNTIME_KEY) ?? []);
            if ($historicalUids === [] && (($runtime['mailbox_id'] ?? null) !== $mailbox || ! isset($runtime['uid_validity']))) {
                $runtime = ['mailbox_id' => $mailbox, 'uid_validity' => $snapshot['uid_validity'], 'cursor_uid' => $snapshot['uid_next'] - 1,
                    'activated_at' => now()->utc()->toIso8601String(), 'last_poll_at' => now()->utc()->toIso8601String(), 'state' => 'active', 'imported' => 0];
                $this->saveRuntime($runtime, (int) $settings['revision']);

                return $runtime;
            }
            if (($historicalValidity ?? ($runtime['uid_validity'] ?? null)) !== $snapshot['uid_validity']) {
                $runtime['state'] = 'uid_validity_changed';
                $runtime['failure_code'] = 'mailbox_uid_validity_changed';
                $this->saveRuntime($runtime, (int) $settings['revision']);

                return $runtime;
            }
            $scan = $historicalUids === [] ? $this->imap->uidsAfter($settings, (int) $runtime['cursor_uid'], (int) $settings['max_messages_per_poll']) : null;
            $uids = $historicalUids ?: $scan['uids'];
            sort($uids, SORT_NUMERIC);
            $imported = 0;
            $completedScan = true;
            foreach ($uids as $uid) {
                $freshSettings = AiDispositionSettings::all(true);
                if (! $freshSettings['enabled'] || $freshSettings['revision'] !== $settings['revision'] || ! AiDispositionSettings::supervisor($freshSettings)) {
                    $completedScan = false;
                    break;
                }
                $message = $this->imap->fetch($settings, (int) $uid, (int) $snapshot['uid_validity']);
                try {
                    app(AiIntakeService::class)->receiveEmail($message);
                    $imported++;
                    if ($historicalUids === []) {
                        $runtime['cursor_uid'] = (int) $uid;
                        $this->saveRuntime($runtime, (int) $settings['revision']);
                    }
                } finally {
                    Storage::disk('local')->delete($message['_cleanup'] ?? []);
                }
            }
            if ($historicalUids === [] && $completedScan) {
                $runtime['cursor_uid'] = max((int) $runtime['cursor_uid'], (int) $scan['scan_through']);
            }
            $runtime['last_poll_at'] = now()->utc()->toIso8601String();
            $runtime['state'] = 'active';
            $runtime['failure_code'] = null;
            $runtime['imported'] = $imported;
            $this->saveRuntime($runtime, (int) $settings['revision']);

            return $runtime;
        } catch (\Throwable) {
            $runtime = (array) (Setting::getValueUncached(AiDispositionSettings::GROUP, self::RUNTIME_KEY) ?? []);
            $runtime['state'] = 'failed';
            $runtime['failure_code'] = 'mailbox_poll_failed';
            $runtime['last_attempt_at'] = now()->utc()->toIso8601String();
            $this->saveRuntime($runtime, isset($settings) ? (int) $settings['revision'] : null);

            return $runtime;
        } finally {
            $lock->release();
        }
    }

    private function saveRuntime(array $runtime, ?int $settingsRevision = null): void
    {
        DB::transaction(function () use ($runtime, $settingsRevision): void {
            $setting = Setting::where('type', AiDispositionSettings::GROUP)->where('key', AiDispositionSettings::KEY)->lockForUpdate()->first();
            if ($settingsRevision !== null && (int) ($setting?->value['revision'] ?? 1) !== $settingsRevision) {
                return;
            }
            Setting::setValue(AiDispositionSettings::GROUP, self::RUNTIME_KEY, $runtime);
        });
    }
}
