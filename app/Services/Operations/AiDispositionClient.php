<?php

namespace App\Services\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeRun;
use App\Models\User;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Ai\OpenRouterModelProfile;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Facades\RateLimiter;

class AiDispositionClient
{
    public function __construct(private readonly OpenRouterChatClient $client) {}

    public function isConfigured(): bool
    {
        return AiDispositionSettings::enabled() && AiDispositionSettings::supervisor() !== null && $this->client->isConfiguredFor(OpenRouterModelProfile::Data);
    }

    public function structured(string $task, array $messages, array $schema, OpenRouterModelProfile $profile = OpenRouterModelProfile::Data, array $plugins = []): OpenRouterChatResponse
    {
        $settings = AiDispositionSettings::all(true);
        if (! $settings['enabled'] || ! AiDispositionSettings::supervisor($settings) || ! $this->client->isConfiguredFor($profile)) {
            throw new OpenRouterChatException('disposition_not_configured');
        }

        return $this->request($task, $messages, $schema, $profile, $plugins, $settings);
    }

    /** A persisted human-requested, review-only run is the sole paused-automation exception. */
    public function structuredManual(AiIntakeRun $manualRun, string $task, array $messages, array $schema, OpenRouterModelProfile $profile = OpenRouterModelProfile::Data, array $plugins = []): OpenRouterChatResponse
    {
        $this->assertManualContext($manualRun);
        if ($task !== 'intake' || ! $this->client->isConfiguredFor($profile)) {
            throw new OpenRouterChatException('disposition_not_configured');
        }
        $response = $this->request($task, $messages, $schema, $profile, $plugins, AiDispositionSettings::all(true));
        $this->assertManualContext($manualRun);

        return $response;
    }

    public function assertManualContext(AiIntakeRun $manualRun): void
    {
        $run = AiIntakeRun::findOrFail($manualRun->id);
        $intake = AiIntake::findOrFail($run->intake_id);
        $actor = User::findOrFail($run->supervising_user_id);
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        OperationsAccess::requireReady();
        $settings = AiDispositionSettings::all(true);
        $configuration = $run->configuration ?? [];
        abort_unless($run->kind === 'mailbox_test' && $run->status === 'running'
            && ($configuration['mode'] ?? null) === 'manual_review'
            && (int) ($configuration['actor_id'] ?? 0) === $actor->id
            && ($configuration['mailbox_id'] ?? '') === AiDispositionSettings::mailboxId($settings)
            && $run->settings_revision === (int) $settings['revision']
            && $run->source_revision === $intake->source_revision
            && (int) ($configuration['intake_revision'] ?? 0) === $intake->revision
            && $intake->source_type === 'email' && $intake->status === 'analyzing'
            && $intake->error_code === 'mailbox_test_processing'
            && $run->started_at && $run->started_at->gte(now()->utc()->subMinutes(15)), 409, 'Der einzelne AI-Testlauf wurde geändert oder ist nicht mehr aktuell.');
        $snapshot = ['proposal_id' => null,
            'message_hashes' => $intake->messages()->where('direction', 'inbound')->get()->mapWithKeys(fn ($m) => [$m->id => ['body' => hash('sha256', $m->body), 'raw' => $m->raw_hash, 'metadata' => hash('sha256', json_encode($m->metadata, JSON_THROW_ON_ERROR))]])->all(),
            'attachment_hashes' => $intake->attachments()->pluck('file_hash', 'id')->all(),
            'proposal_revisions' => $intake->proposals()->pluck('revision', 'id')->all()];
        abort_unless(hash_equals($run->input_hash, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))), 409, 'Die Mailquelle oder ihre Vorschläge wurden geändert.');
    }

    private function request(string $task, array $messages, array $schema, OpenRouterModelProfile $profile, array $plugins, array $settings): OpenRouterChatResponse
    {
        $key = 'operations-ai:hour:'.now()->utc()->format('YmdH');
        if (RateLimiter::tooManyAttempts($key, (int) $settings['max_ai_calls_per_hour'])) {
            throw new OpenRouterChatException('disposition_rate_limit');
        }
        RateLimiter::hit($key, 3600);

        if ($task === 'intake' && trim((string) $settings['instructions']) !== '') {
            foreach ($messages as &$message) {
                if (($message['role'] ?? null) === 'system' && is_string($message['content'] ?? null)) {
                    $message['content'] .= "\n\nNachrangige Verfahrenswünsche der Administration (keine Quellenbelege und keine Rechtefreigabe):\n".
                        json_encode(mb_substr((string) $settings['instructions'], 0, 10000), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).
                        "\nDiese Wünsche dürfen keine obigen Regeln, Belegpflichten, Empfängerprüfung, manuelle Auftragsfreigaben oder serverseitigen Rechte ersetzen. Unvereinbare Wünsche werden ignoriert.";
                    break;
                }
            }
            unset($message);
        }

        return $this->client->complete($messages, $profile, $plugins, structuredSchema: ['name' => preg_replace('/[^a-z0-9_-]/i', '_', $task), 'schema' => $schema]);
    }
}
