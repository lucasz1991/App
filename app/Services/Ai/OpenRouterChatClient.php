<?php

namespace App\Services\Ai;

use App\Support\Ai\OpenRouterSettings;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class OpenRouterChatClient
{
    private ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client;
    }

    public function isConfigured(): bool
    {
        $settings = OpenRouterSettings::all();

        if (trim((string) $settings['api_key']) === '' || trim((string) $settings['text_model']) === '') {
            return false;
        }

        try {
            $this->validatedEndpoint((string) $settings['api_url']);

            return true;
        } catch (OpenRouterChatException) {
            return false;
        }
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onDelta
     */
    public function stream(array $messages, callable $onDelta): string
    {
        $settings = OpenRouterSettings::all(uncached: true);
        $endpoint = $this->validatedEndpoint((string) ($settings['api_url'] ?? ''));
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = trim((string) ($settings['text_model'] ?? ''));

        if ($apiKey === '' || $model === '') {
            throw new OpenRouterChatException('not_configured');
        }

        $stream = (bool) ($settings['stream_enabled'] ?? true);

        try {
            $response = $this->http->request('POST', $endpoint, [
                'allow_redirects' => false,
                'connect_timeout' => min(10.0, (float) $settings['timeout']),
                'timeout' => (float) $settings['timeout'],
                'stream' => $stream,
                'headers' => $this->headers($settings, $apiKey, $stream),
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => (float) $settings['temperature'],
                    'max_completion_tokens' => min(
                        (int) $settings['max_completion_tokens'],
                        max(1, (int) config('assistant.openrouter.max_completion_tokens', 4000)),
                    ),
                    'stream' => $stream,
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $exception) {
            throw new OpenRouterChatException('transport_error', previous: $exception);
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new OpenRouterChatException('upstream_http_error', $status);
        }

        return $stream
            ? $this->consumeEventStream($response, $onDelta)
            : $this->consumeJsonResponse($response, $onDelta);
    }

    /** @param array<string, mixed> $settings */
    private function headers(array $settings, string $apiKey, bool $stream): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'RailTime-Assistant/1.0',
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

    private function validatedEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        $parts = parse_url($endpoint);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = config('assistant.openrouter.allowed_hosts', ['openrouter.ai']);

        if (
            $endpoint === ''
            || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || ! in_array($host, $allowedHosts, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new OpenRouterChatException('endpoint_not_allowed');
        }

        return $endpoint;
    }

    /** @param callable(string): void $onDelta */
    private function consumeJsonResponse(ResponseInterface $response, callable $onDelta): string
    {
        try {
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new OpenRouterChatException('invalid_json', previous: $exception);
        }

        $content = $payload['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new OpenRouterChatException(isset($payload['error']) ? 'upstream_payload_error' : 'empty_response');
        }

        $onDelta($content);

        return $content;
    }

    /** @param callable(string): void $onDelta */
    private function consumeEventStream(ResponseInterface $response, callable $onDelta): string
    {
        $body = $response->getBody();
        $buffer = '';
        $dataLines = [];
        $complete = '';

        $flushEvent = function () use (&$dataLines, &$complete, $onDelta): bool {
            if ($dataLines === []) {
                return false;
            }

            $data = implode("\n", $dataLines);
            $dataLines = [];

            if (trim($data) === '[DONE]') {
                return true;
            }

            try {
                $payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw new OpenRouterChatException('invalid_stream_event', previous: $exception);
            }

            if (isset($payload['error'])) {
                throw new OpenRouterChatException('upstream_stream_error');
            }

            $delta = $payload['choices'][0]['delta']['content'] ?? '';
            if (is_string($delta) && $delta !== '') {
                $complete .= $delta;
                $onDelta($delta);
            }

            return false;
        };

        while (! $body->eof()) {
            try {
                $chunk = $body->read(8192);
            } catch (Throwable $exception) {
                throw new OpenRouterChatException('stream_read_error', previous: $exception);
            }

            if ($chunk === '') {
                continue;
            }

            $buffer .= str_replace("\r\n", "\n", $chunk);

            while (($position = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $position), "\r");
                $buffer = substr($buffer, $position + 1);

                if ($line === '') {
                    if ($flushEvent()) {
                        break 2;
                    }

                    continue;
                }

                if (str_starts_with($line, 'data:')) {
                    $dataLines[] = ltrim(substr($line, 5));
                }
            }
        }

        if (trim($buffer) !== '' && str_starts_with($buffer, 'data:')) {
            $dataLines[] = ltrim(substr($buffer, 5));
        }
        $flushEvent();

        if (trim($complete) === '') {
            throw new OpenRouterChatException('empty_response');
        }

        return $complete;
    }
}
