<?php

namespace App\Services\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

class SpeechServiceClient
{
    private ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client;
    }

    public function isConfigured(): bool
    {
        try {
            return (bool) config('assistant.speech.enabled')
                && $this->token() !== ''
                && trim((string) config('assistant.speech.client_id')) !== ''
                && $this->baseUrl() !== '';
        } catch (SpeechServiceException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $response = $this->request('GET', '/v1/status', [
            'timeout' => (float) config('assistant.speech.status_timeout', 5),
        ]);

        return $this->json($response['body'], $response['request_id']);
    }

    /** @return array{text: string, request_id: string|null} */
    public function transcribe(UploadedFile $audio, ?string $language = null): array
    {
        $path = $audio->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            throw new SpeechServiceException('audio_unreadable');
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new SpeechServiceException('audio_unreadable');
        }

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', basename(str_replace('\\', '/', $audio->getClientOriginalName())));
        if (! is_string($filename) || $filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'recording.webm';
        }
        $filename = mb_substr($filename, 0, 128);

        $mimeType = strtolower(trim(explode(';', (string) ($audio->getMimeType() ?: 'application/octet-stream'))[0]));
        $mimeType = match ($mimeType) {
            'video/webm' => 'audio/webm',
            'video/mp4' => 'audio/mp4',
            default => $mimeType,
        };

        $result = $this->request('POST', '/v1/transcriptions', [
            'timeout' => (float) config('assistant.speech.stt_timeout', 300),
            'json' => [
                'audio_base64' => base64_encode($bytes),
                'mime_type' => $mimeType,
                'filename' => $filename,
                'language' => $language ?: (string) config('assistant.speech.language', 'de'),
            ],
        ]);
        $payload = $this->json($result['body'], $result['request_id']);
        $text = trim((string) ($payload['text'] ?? ''));

        if ($text === '') {
            throw new SpeechServiceException('empty_transcription', requestId: $result['request_id']);
        }

        return ['text' => $text, 'request_id' => $result['request_id']];
    }

    /** @return array{audio: string, content_type: string, request_id: string|null} */
    public function synthesize(string $text, float $speed = 1.0): array
    {
        $result = $this->request('POST', '/v1/speech', [
            'timeout' => (float) config('assistant.speech.tts_timeout', 150),
            'headers' => ['Accept' => 'audio/wav'],
            'json' => ['text' => $text, 'speed' => $speed],
        ]);
        $contentType = strtolower(trim(explode(';', $result['content_type'])[0]));

        if (! in_array($contentType, ['audio/wav', 'audio/x-wav', 'audio/wave'], true) || $result['body'] === '') {
            throw new SpeechServiceException('invalid_audio_response', requestId: $result['request_id']);
        }

        return [
            'audio' => $result['body'],
            'content_type' => 'audio/wav',
            'request_id' => $result['request_id'],
        ];
    }

    /** @return array{body: string, content_type: string, request_id: string|null} */
    private function request(string $method, string $path, array $options): array
    {
        if (! (bool) config('assistant.speech.enabled')) {
            throw new SpeechServiceException('disabled');
        }

        $requestId = (string) Str::uuid();
        $options = array_replace_recursive([
            'allow_redirects' => false,
            'connect_timeout' => (float) config('assistant.speech.connect_timeout', 2),
            'http_errors' => false,
            // Ein global gesetztes http_proxy/all_proxy wuerde diesen Aufruf
            // sonst samt Bearer-Token ueber einen fremden Host schicken:
            // Guzzle zieht die Proxy-Variablen im Handler unabhaengig vom SAPI
            // aus der Prozessumgebung. Der leere Wert pinnt "kein Proxy" fest
            // und unterdrueckt dabei auch diesen Fallback. Der Kanal ist
            // ausdruecklich nur fuer Loopback gedacht und darf den Server
            // unter keinen Umstaenden verlassen.
            'proxy' => '',
            'headers' => [
                'Authorization' => 'Bearer '.$this->token(),
                'X-Client-ID' => trim((string) config('assistant.speech.client_id')),
                'X-Request-ID' => $requestId,
                'Accept' => 'application/json',
            ],
        ], $options);

        try {
            $response = $this->http->request($method, $this->baseUrl().$path, $options);
        } catch (GuzzleException $exception) {
            throw new SpeechServiceException('transport_error', requestId: $requestId, previous: $exception);
        }

        $upstreamRequestId = trim($response->getHeaderLine('X-Request-ID')) ?: $requestId;
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new SpeechServiceException('upstream_http_error', $status, $upstreamRequestId);
        }

        return [
            'body' => (string) $response->getBody(),
            'content_type' => $response->getHeaderLine('Content-Type'),
            'request_id' => $upstreamRequestId,
        ];
    }

    private function baseUrl(): string
    {
        $url = rtrim(trim((string) config('assistant.speech.url')), '/');
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $loopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
        $allowRemote = (bool) config('assistant.speech.allow_remote', false);

        if (
            $url === ''
            || ! is_array($parts)
            || ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (! $loopback && (! $allowRemote || $scheme !== 'https'))
        ) {
            throw new SpeechServiceException('endpoint_not_allowed');
        }

        return $url;
    }

    private function token(): string
    {
        $token = trim((string) config('assistant.speech.token'));
        if ($token !== '') {
            return $token;
        }

        $file = trim((string) config('assistant.speech.token_file'));
        if ($file === '' || ! is_file($file) || ! is_readable($file)) {
            throw new SpeechServiceException('credentials_missing');
        }

        $token = trim((string) @file_get_contents($file));
        if ($token === '') {
            throw new SpeechServiceException('credentials_missing');
        }

        return $token;
    }

    /** @return array<string, mixed> */
    private function json(string $body, ?string $requestId): array
    {
        try {
            $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new SpeechServiceException('invalid_json', requestId: $requestId, previous: $exception);
        }

        if (! is_array($payload)) {
            throw new SpeechServiceException('invalid_json', requestId: $requestId);
        }

        return $payload;
    }
}
