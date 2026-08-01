<?php

namespace App\Services\Ai;

use App\Support\Ai\OpenRouterSettings;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class OpenRouterSpeechClient
{
    private const MAX_AUDIO_RESPONSE_BYTES = 33554432;

    private ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client;
    }

    /** @return array{stt: bool, tts: bool} */
    public function capabilities(): array
    {
        $settings = OpenRouterSettings::all();

        try {
            $this->endpoint($settings, '/api/v1/audio/transcriptions');
        } catch (AssistantSpeechException) {
            return ['stt' => false, 'tts' => false];
        }

        $connectionReady = trim((string) ($settings['api_key'] ?? '')) !== '';

        return [
            'stt' => $connectionReady
                && trim((string) ($settings['speech_to_text_model'] ?? '')) !== '',
            'tts' => $connectionReady
                && trim((string) ($settings['text_to_speech_model'] ?? '')) !== ''
                && trim((string) ($settings['tts_voice'] ?? '')) !== '',
        ];
    }

    public function isSttConfigured(): bool
    {
        return $this->capabilities()['stt'];
    }

    public function isTtsConfigured(): bool
    {
        return $this->capabilities()['tts'];
    }

    /** @return array{text: string, request_id: string|null} */
    public function transcribe(UploadedFile $audio, ?string $language = null): array
    {
        $settings = OpenRouterSettings::all(uncached: true);
        $endpoint = $this->endpoint($settings, '/api/v1/audio/transcriptions');
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = trim((string) ($settings['speech_to_text_model'] ?? ''));

        if ($apiKey === '' || $model === '') {
            throw new AssistantSpeechException(
                'not_configured',
                AssistantSpeechRouter::PROVIDER_OPENROUTER,
            );
        }

        $path = $audio->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            throw new AssistantSpeechException(
                'audio_unreadable',
                AssistantSpeechRouter::PROVIDER_OPENROUTER,
            );
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new AssistantSpeechException(
                'audio_unreadable',
                AssistantSpeechRouter::PROVIDER_OPENROUTER,
            );
        }

        $requestId = (string) Str::uuid();
        $response = $this->request($endpoint, $settings, $apiKey, $requestId, [
            'Accept' => 'application/json',
        ], [
            'model' => $model,
            'input_audio' => [
                'data' => base64_encode($bytes),
                'format' => $this->audioFormat($audio),
            ],
            'language' => $language ?: (string) config('assistant.speech.language', 'de'),
        ]);

        try {
            $payload = json_decode((string) $response->getBody(), true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new AssistantSpeechException(
                'invalid_json',
                AssistantSpeechRouter::PROVIDER_OPENROUTER,
                requestId: $this->responseRequestId($response, $requestId),
                previous: $exception,
            );
        }

        $text = is_array($payload) ? trim((string) ($payload['text'] ?? '')) : '';
        if ($text === '') {
            throw new AssistantSpeechException(
                'empty_transcription',
                AssistantSpeechRouter::PROVIDER_OPENROUTER,
                requestId: $this->responseRequestId($response, $requestId),
            );
        }

        return [
            'text' => $text,
            'request_id' => $this->responseRequestId($response, $requestId),
        ];
    }

    /** @return array{audio: string, content_type: string, request_id: string|null} */
    public function synthesize(string $text, float $speed = 1.0): array
    {
        $settings = OpenRouterSettings::all(uncached: true);
        $endpoint = $this->endpoint($settings, '/api/v1/audio/speech');
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = trim((string) ($settings['text_to_speech_model'] ?? ''));
        $voice = trim((string) ($settings['tts_voice'] ?? ''));

        if ($apiKey === '' || $model === '' || $voice === '') {
            throw new AssistantSpeechException(
                'not_configured',
                AssistantSpeechRouter::PROVIDER_OPENROUTER,
            );
        }

        $requestId = (string) Str::uuid();
        $response = $this->request($endpoint, $settings, $apiKey, $requestId, [
            'Accept' => 'audio/mpeg',
        ], [
            'model' => $model,
            'input' => $text,
            'voice' => $voice,
            'response_format' => 'mp3',
            'speed' => $speed,
        ]);

        $audio = (string) $response->getBody();
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        if (
            $contentType !== 'audio/mpeg'
            || $audio === ''
            || strlen($audio) > self::MAX_AUDIO_RESPONSE_BYTES
        ) {
            throw new AssistantSpeechException(
                'invalid_audio_response',
                AssistantSpeechRouter::PROVIDER_OPENROUTER,
                requestId: $this->responseRequestId($response, $requestId),
            );
        }

        return [
            'audio' => $audio,
            'content_type' => 'audio/mpeg',
            'request_id' => $this->responseRequestId($response, $requestId),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     */
    private function request(
        string $endpoint,
        array $settings,
        string $apiKey,
        string $requestId,
        array $headers,
        array $payload,
    ): ResponseInterface {
        try {
            $response = $this->http->request('POST', $endpoint, [
                'allow_redirects' => false,
                'connect_timeout' => min(10.0, (float) ($settings['timeout'] ?? 120)),
                'timeout' => (float) ($settings['timeout'] ?? 120),
                'http_errors' => false,
                'headers' => array_merge(
                    $this->headers($settings, $apiKey, $requestId),
                    $headers,
                ),
                'json' => $payload,
            ]);
        } catch (GuzzleException $exception) {
            throw new AssistantSpeechException(
                'transport_error',
                AssistantSpeechRouter::PROVIDER_OPENROUTER,
                requestId: $requestId,
                previous: $exception,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new AssistantSpeechException(
                'upstream_http_error',
                AssistantSpeechRouter::PROVIDER_OPENROUTER,
                $status,
                $this->responseRequestId($response, $requestId),
            );
        }

        return $response;
    }

    /** @param array<string, mixed> $settings */
    private function headers(array $settings, string $apiKey, string $requestId): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => 'RailTime-Assistant/1.0',
            'X-Request-ID' => $requestId,
        ];

        $referer = trim((string) ($settings['referer_url'] ?? ''));
        if ($referer !== '' && in_array(parse_url($referer, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $headers['HTTP-Referer'] = $referer;
        }

        $title = trim((string) ($settings['model_title'] ?? ''));
        if ($title !== '') {
            $headers['X-Title'] = mb_substr($title, 0, 255);
        }

        return $headers;
    }

    /** @param array<string, mixed> $settings */
    private function endpoint(array $settings, string $path): string
    {
        $configuredEndpoint = trim((string) ($settings['api_url'] ?? ''));
        $parts = parse_url($configuredEndpoint);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = array_map(
            static fn (mixed $allowed): string => strtolower(trim((string) $allowed)),
            (array) config('assistant.openrouter.allowed_hosts', ['openrouter.ai']),
        );

        if (
            $configuredEndpoint === ''
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || ! in_array($host, $allowedHosts, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new AssistantSpeechException(
                'endpoint_not_allowed',
                AssistantSpeechRouter::PROVIDER_OPENROUTER,
            );
        }

        return 'https://'.$host.$path;
    }

    private function audioFormat(UploadedFile $audio): string
    {
        $mimeType = strtolower(trim(explode(';', (string) ($audio->getMimeType() ?: ''))[0]));
        $format = match ($mimeType) {
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/webm', 'video/webm' => 'webm',
            'audio/mp4', 'video/mp4' => 'm4a',
            'audio/aac' => 'aac',
            'audio/flac' => 'flac',
            default => '',
        };

        if ($format === '') {
            $extension = strtolower($audio->getClientOriginalExtension());
            $format = match ($extension) {
                'wav', 'mp3', 'ogg', 'webm', 'aac', 'flac' => $extension,
                'm4a', 'mp4' => 'm4a',
                default => '',
            };
        }

        if ($format === '') {
            throw new AssistantSpeechException(
                'unsupported_audio_format',
                AssistantSpeechRouter::PROVIDER_OPENROUTER,
            );
        }

        return $format;
    }

    private function responseRequestId(ResponseInterface $response, string $fallback): string
    {
        return trim($response->getHeaderLine('X-Generation-Id')) ?: $fallback;
    }
}
