<?php

namespace App\Services\Ai;

use App\Support\Ai\AssistantSpeechSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AssistantSpeechRouter
{
    public const PROVIDER_LOCAL = 'local';

    public const PROVIDER_OPENROUTER = 'openrouter';

    private const LOCAL_STATUS_CACHE_KEY = 'assistant.speech.local-status.v1';

    private const LOCAL_STATUS_CACHE_SECONDS = 15;

    public function __construct(
        private readonly SpeechServiceClient $local,
        private readonly OpenRouterSpeechClient $openRouter,
    ) {}

    /**
     * @return array{text: string, request_id: string|null, provider: string, fallback: bool}
     */
    public function transcribe(UploadedFile $audio, ?string $language = null): array
    {
        return match (AssistantSpeechSettings::mode()) {
            AssistantSpeechSettings::LOCAL_ONLY => $this->transcribeLocally($audio, $language),
            AssistantSpeechSettings::EXTERNAL_ONLY => $this->transcribeExternally($audio, $language),
            default => $this->transcribeWithFallback($audio, $language),
        };
    }

    /**
     * @return array{audio: string, content_type: string, request_id: string|null, provider: string, fallback: bool}
     */
    public function synthesize(string $text, float $speed = 1.0): array
    {
        return match (AssistantSpeechSettings::mode()) {
            AssistantSpeechSettings::LOCAL_ONLY => $this->synthesizeLocally($text, $speed),
            AssistantSpeechSettings::EXTERNAL_ONLY => $this->synthesizeExternally($text, $speed),
            default => $this->synthesizeWithFallback($text, $speed),
        };
    }

    /**
     * Normalisierter, geheimnisfreier Laufzeitstatus fuer Chatfenster und
     * Administration. Nur der lokale Daemon wird aktiv geprueft; externe
     * Bereitschaft bedeutet absichtlich Konfigurationsbereitschaft.
     *
     * @return array<string, mixed>
     */
    public function capabilities(bool $refresh = false): array
    {
        $mode = AssistantSpeechSettings::mode($refresh);
        $local = $this->localCapabilities($refresh);

        try {
            $externalCapabilities = $this->openRouter->capabilities();
        } catch (Throwable) {
            $externalCapabilities = ['stt' => false, 'tts' => false];
        }

        $openRouter = [
            'state' => $this->providerState(
                (bool) ($externalCapabilities['stt'] ?? false),
                (bool) ($externalCapabilities['tts'] ?? false),
                'not_configured',
            ),
            'configured' => (bool) ($externalCapabilities['stt'] ?? false)
                || (bool) ($externalCapabilities['tts'] ?? false),
            'stt_available' => (bool) ($externalCapabilities['stt'] ?? false),
            'tts_available' => (bool) ($externalCapabilities['tts'] ?? false),
        ];

        $sttProvider = $this->activeProvider('stt', $mode, $local, $openRouter);
        $ttsProvider = $this->activeProvider('tts', $mode, $local, $openRouter);
        $sttAvailable = $sttProvider !== null;
        $ttsAvailable = $ttsProvider !== null;
        $sttConfigured = $this->routingConfigured('stt', $mode, $local, $openRouter);
        $ttsConfigured = $this->routingConfigured('tts', $mode, $local, $openRouter);
        $preferredProvider = $mode === AssistantSpeechSettings::EXTERNAL_ONLY
            ? self::PROVIDER_OPENROUTER
            : self::PROVIDER_LOCAL;
        $fallbackAvailable = [
            'stt' => $mode === AssistantSpeechSettings::LOCAL_WITH_EXTERNAL_FALLBACK
                && $local['configured']
                && $openRouter['stt_available'],
            'tts' => $mode === AssistantSpeechSettings::LOCAL_WITH_EXTERNAL_FALLBACK
                && $local['configured']
                && $openRouter['tts_available'],
        ];
        $fallbackActive = [
            'stt' => $mode === AssistantSpeechSettings::LOCAL_WITH_EXTERNAL_FALLBACK
                && $sttProvider === self::PROVIDER_OPENROUTER,
            'tts' => $mode === AssistantSpeechSettings::LOCAL_WITH_EXTERNAL_FALLBACK
                && $ttsProvider === self::PROVIDER_OPENROUTER,
        ];

        return [
            'state' => $this->providerState($sttAvailable, $ttsAvailable, 'unavailable'),
            'mode' => $mode,
            'stt_configured' => $sttConfigured,
            'tts_configured' => $ttsConfigured,
            'stt_available' => $sttAvailable,
            'tts_available' => $ttsAvailable,
            'preferred_provider' => $preferredProvider,
            'active_provider' => [
                'stt' => $sttProvider,
                'tts' => $ttsProvider,
            ],
            'stt_provider' => $sttProvider,
            'tts_provider' => $ttsProvider,
            'fallback_available' => $fallbackAvailable,
            'fallback_active' => $fallbackActive,
            'capabilities' => [
                'stt' => [
                    'configured' => $sttConfigured,
                    'available' => $sttAvailable,
                ],
                'tts' => [
                    'configured' => $ttsConfigured,
                    'available' => $ttsAvailable,
                ],
            ],
            'providers' => [
                self::PROVIDER_LOCAL => $local,
                self::PROVIDER_OPENROUTER => $openRouter,
                'external' => $openRouter,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function status(bool $refresh = false): array
    {
        return $this->capabilities($refresh);
    }

    public function isConfigured(): bool
    {
        $capabilities = $this->capabilities();

        return $capabilities['stt_available'] || $capabilities['tts_available'];
    }

    /** @return array{text: string, request_id: string|null, provider: string, fallback: bool} */
    private function transcribeWithFallback(UploadedFile $audio, ?string $language): array
    {
        try {
            return $this->transcribeLocally($audio, $language);
        } catch (AssistantSpeechException $localFailure) {
            if (
                ! $this->fallbackEligible($localFailure)
                || ! $this->openRouter->isSttConfigured()
            ) {
                throw $localFailure;
            }

            try {
                $result = $this->transcribeExternally($audio, $language);
                $result['fallback'] = true;

                return $result;
            } catch (AssistantSpeechException $externalFailure) {
                throw $externalFailure->afterFallback();
            }
        }
    }

    /** @return array{audio: string, content_type: string, request_id: string|null, provider: string, fallback: bool} */
    private function synthesizeWithFallback(string $text, float $speed): array
    {
        try {
            return $this->synthesizeLocally($text, $speed);
        } catch (AssistantSpeechException $localFailure) {
            if (
                ! $this->fallbackEligible($localFailure)
                || ! $this->openRouter->isTtsConfigured()
            ) {
                throw $localFailure;
            }

            try {
                $result = $this->synthesizeExternally($text, $speed);
                $result['fallback'] = true;

                return $result;
            } catch (AssistantSpeechException $externalFailure) {
                throw $externalFailure->afterFallback();
            }
        }
    }

    /** @return array{text: string, request_id: string|null, provider: string, fallback: bool} */
    private function transcribeLocally(UploadedFile $audio, ?string $language): array
    {
        try {
            $result = $this->local->transcribe($audio, $language);
            Cache::forget(self::LOCAL_STATUS_CACHE_KEY);

            return $result + [
                'provider' => self::PROVIDER_LOCAL,
                'fallback' => false,
            ];
        } catch (SpeechServiceException $exception) {
            Cache::forget(self::LOCAL_STATUS_CACHE_KEY);

            throw AssistantSpeechException::fromLocal($exception);
        }
    }

    /** @return array{audio: string, content_type: string, request_id: string|null, provider: string, fallback: bool} */
    private function synthesizeLocally(string $text, float $speed): array
    {
        try {
            $result = $this->local->synthesize($text, $speed);
            Cache::forget(self::LOCAL_STATUS_CACHE_KEY);

            return $result + [
                'provider' => self::PROVIDER_LOCAL,
                'fallback' => false,
            ];
        } catch (SpeechServiceException $exception) {
            Cache::forget(self::LOCAL_STATUS_CACHE_KEY);

            throw AssistantSpeechException::fromLocal($exception);
        }
    }

    /** @return array{text: string, request_id: string|null, provider: string, fallback: bool} */
    private function transcribeExternally(UploadedFile $audio, ?string $language): array
    {
        $result = $this->openRouter->transcribe($audio, $language);

        return $result + [
            'provider' => self::PROVIDER_OPENROUTER,
            'fallback' => false,
        ];
    }

    /** @return array{audio: string, content_type: string, request_id: string|null, provider: string, fallback: bool} */
    private function synthesizeExternally(string $text, float $speed): array
    {
        $result = $this->openRouter->synthesize($text, $speed);

        return $result + [
            'provider' => self::PROVIDER_OPENROUTER,
            'fallback' => false,
        ];
    }

    private function fallbackEligible(AssistantSpeechException $exception): bool
    {
        if ($exception->provider !== self::PROVIDER_LOCAL) {
            return false;
        }

        if (in_array($exception->reasonCode, ['transport_error', 'engine_unavailable'], true)) {
            return true;
        }

        return $exception->upstreamStatus !== null
            && $exception->upstreamStatus >= 500
            && $exception->upstreamStatus <= 599;
    }

    /** @return array<string, mixed> */
    private function localCapabilities(bool $refresh): array
    {
        if ($refresh) {
            Cache::forget(self::LOCAL_STATUS_CACHE_KEY);
        }

        return Cache::remember(
            self::LOCAL_STATUS_CACHE_KEY,
            now()->addSeconds(self::LOCAL_STATUS_CACHE_SECONDS),
            fn (): array => $this->inspectLocalCapabilities(),
        );
    }

    /** @return array<string, mixed> */
    private function inspectLocalCapabilities(): array
    {
        if (! $this->local->isConfigured()) {
            return $this->localStatus(false, false, false, 'not_configured');
        }

        try {
            $status = $this->local->status();
            $engines = is_array($status['engines'] ?? null) ? $status['engines'] : [];
            $ffmpegReady = $this->engineReady($engines['ffmpeg'] ?? null);
            $whisperReady = $this->engineReady($engines['whisper'] ?? null);
            $piperReady = $this->engineReady($engines['piper'] ?? null);
            $sttReady = $ffmpegReady && $whisperReady;
            $ttsReady = $piperReady;

            return $this->localStatus(
                $sttReady,
                $ttsReady,
                true,
                $this->providerState($sttReady, $ttsReady, 'unavailable'),
                ['stt' => ! $sttReady, 'tts' => ! $ttsReady],
            );
        } catch (SpeechServiceException $exception) {
            $eligible = $this->fallbackEligible(AssistantSpeechException::fromLocal($exception));

            return $this->localStatus(
                false,
                false,
                true,
                $eligible ? 'offline' : 'unavailable',
                ['stt' => $eligible, 'tts' => $eligible],
            );
        } catch (Throwable) {
            return $this->localStatus(false, false, true, 'unavailable');
        }
    }

    /** @return array<string, mixed> */
    private function localStatus(
        bool $sttAvailable,
        bool $ttsAvailable,
        bool $configured,
        string $state,
        array $fallbackEligible = ['stt' => false, 'tts' => false],
    ): array {
        return [
            'state' => $state,
            'configured' => $configured,
            'stt_available' => $sttAvailable,
            'tts_available' => $ttsAvailable,
            'fallback_eligible' => [
                'stt' => (bool) ($fallbackEligible['stt'] ?? false),
                'tts' => (bool) ($fallbackEligible['tts'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $local
     * @param  array<string, mixed>  $openRouter
     */
    private function activeProvider(
        string $capability,
        string $mode,
        array $local,
        array $openRouter,
    ): ?string {
        $key = $capability.'_available';

        if ($mode === AssistantSpeechSettings::LOCAL_ONLY) {
            return $local[$key] ? self::PROVIDER_LOCAL : null;
        }

        if ($mode === AssistantSpeechSettings::EXTERNAL_ONLY) {
            return $openRouter[$key] ? self::PROVIDER_OPENROUTER : null;
        }

        if ($local[$key]) {
            return self::PROVIDER_LOCAL;
        }

        return ($local['fallback_eligible'][$capability] ?? false) && $openRouter[$key]
            ? self::PROVIDER_OPENROUTER
            : null;
    }

    /**
     * @param  array<string, mixed>  $local
     * @param  array<string, mixed>  $openRouter
     */
    private function routingConfigured(
        string $capability,
        string $mode,
        array $local,
        array $openRouter,
    ): bool {
        $key = $capability.'_available';

        if ($mode === AssistantSpeechSettings::EXTERNAL_ONLY) {
            return (bool) $openRouter[$key];
        }

        // Auch im Fallback-Modus ist der lokale Dienst der verbindliche
        // Primaerweg. Eine fehlende lokale Konfiguration darf nicht allein
        // durch vorhandene externe Zugangsdaten als betriebsbereit erscheinen.
        return (bool) $local['configured'];
    }

    private function providerState(bool $sttAvailable, bool $ttsAvailable, string $emptyState): string
    {
        if ($sttAvailable && $ttsAvailable) {
            return 'ready';
        }

        if ($sttAvailable || $ttsAvailable) {
            return 'degraded';
        }

        return $emptyState;
    }

    private function engineReady(mixed $state): bool
    {
        return $state === true || $state === 'ready';
    }
}
