<?php

namespace App\Services\Operations;

use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Ai\OpenRouterModelProfile;
use App\Support\Operations\AiDispositionSettings;
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
        $key = 'operations-ai:hour:'.now()->utc()->format('YmdH');
        if (RateLimiter::tooManyAttempts($key, (int) $settings['max_ai_calls_per_hour'])) {
            throw new OpenRouterChatException('disposition_rate_limit');
        }
        RateLimiter::hit($key, 3600);

        return $this->client->complete($messages, $profile, $plugins, structuredSchema: ['name' => preg_replace('/[^a-z0-9_-]/i', '_', $task), 'schema' => $schema]);
    }
}
