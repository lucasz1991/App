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
        return $this->isConfiguredFor(OpenRouterModelProfile::Text);
    }

    public function isConfiguredFor(OpenRouterModelProfile $profile): bool
    {
        $settings = OpenRouterSettings::all();

        if (trim((string) $settings['api_key']) === '' || $this->modelFor($settings, $profile) === '') {
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
     * @param  array<int, array<string, mixed>>  $tools
     */
    public function stream(
        array $messages,
        callable $onDelta,
        array $tools = [],
        string|array|null $toolChoice = null,
    ): string {
        $settings = OpenRouterSettings::all(uncached: true);
        $endpoint = $this->validatedEndpoint((string) ($settings['api_url'] ?? ''));
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = trim((string) ($settings['text_model'] ?? ''));

        if ($apiKey === '' || $model === '') {
            throw new OpenRouterChatException('not_configured');
        }

        $stream = (bool) ($settings['stream_enabled'] ?? true);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float) $settings['temperature'],
            'max_completion_tokens' => min(
                (int) $settings['max_completion_tokens'],
                max(1, (int) config('assistant.openrouter.max_completion_tokens', 4000)),
            ),
            'stream' => $stream,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $toolChoice ?? 'auto';
            $payload['parallel_tool_calls'] = false;
        }

        try {
            $response = $this->http->request('POST', $endpoint, [
                'allow_redirects' => false,
                'connect_timeout' => min(10.0, (float) $settings['timeout']),
                'timeout' => (float) $settings['timeout'],
                'stream' => $stream,
                'headers' => $this->headers($settings, $apiKey, $stream),
                'json' => $payload,
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

    /**
     * First, non-streaming step of the knowledge-tool loop. The returned tool
     * call is only a model proposal; execution remains entirely server-side.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<int, array<string, mixed>>  $plugins
     */
    public function completeToolDecision(
        array $messages,
        array $tools,
        OpenRouterModelProfile $profile = OpenRouterModelProfile::Text,
        array $plugins = [],
    ): OpenRouterToolDecision {
        $settings = OpenRouterSettings::all(uncached: true);
        $endpoint = $this->validatedEndpoint((string) ($settings['api_url'] ?? ''));
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = $this->modelFor($settings, $profile);

        if ($apiKey === '' || $model === '' || $tools === []) {
            throw new OpenRouterChatException('not_configured');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float) $settings['temperature'],
            'max_completion_tokens' => min(
                (int) $settings['max_completion_tokens'],
                max(1, (int) config('assistant.openrouter.max_completion_tokens', 4000)),
            ),
            'stream' => false,
            'tools' => $tools,
            'tool_choice' => 'auto',
            'parallel_tool_calls' => false,
        ];

        if ($plugins !== []) {
            $payload['plugins'] = $plugins;
        }

        try {
            $response = $this->http->request('POST', $endpoint, [
                'allow_redirects' => false,
                'connect_timeout' => min(10.0, (float) $settings['timeout']),
                'timeout' => (float) $settings['timeout'],
                'headers' => $this->headers($settings, $apiKey, false),
                'json' => $payload,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $exception) {
            throw new OpenRouterChatException('transport_error', previous: $exception);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new OpenRouterChatException('upstream_http_error', $response->getStatusCode());
        }

        $payload = $this->decodeJsonResponse($response);
        $message = $payload['choices'][0]['message'] ?? null;
        if (! is_array($message)) {
            throw new OpenRouterChatException('invalid_tool_response');
        }

        $content = is_string($message['content'] ?? null)
            ? trim($message['content'])
            : null;
        $toolCalls = $this->toolCalls($message['tool_calls'] ?? []);

        if (($content === null || $content === '') && $toolCalls === []) {
            throw new OpenRouterChatException('empty_response');
        }

        return new OpenRouterToolDecision(
            $content ?: null,
            $toolCalls,
            $this->fileAnnotations($message['annotations'] ?? []),
        );
    }

    /**
     * Non-streaming completion for locally extracted documents and multimodal
     * message content. The existing stream() contract remains unchanged.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $plugins
     * @param  array<int, array<string, mixed>>  $tools
     */
    public function complete(
        array $messages,
        OpenRouterModelProfile $profile,
        array $plugins = [],
        array $tools = [],
        string|array|null $toolChoice = null,
    ): OpenRouterChatResponse {
        $settings = OpenRouterSettings::all(uncached: true);
        $endpoint = $this->validatedEndpoint((string) ($settings['api_url'] ?? ''));
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = $this->modelFor($settings, $profile);

        if ($apiKey === '' || $model === '') {
            throw new OpenRouterChatException('not_configured');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float) $settings['temperature'],
            'max_completion_tokens' => min(
                (int) $settings['max_completion_tokens'],
                max(1, (int) config('assistant.openrouter.max_completion_tokens', 4000)),
            ),
            'stream' => false,
        ];

        if ($plugins !== []) {
            $payload['plugins'] = $plugins;
        }

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $toolChoice ?? 'auto';
            $payload['parallel_tool_calls'] = false;
        }

        try {
            $response = $this->http->request('POST', $endpoint, [
                'allow_redirects' => false,
                'connect_timeout' => min(10.0, (float) $settings['timeout']),
                'timeout' => (float) $settings['timeout'],
                'headers' => $this->headers($settings, $apiKey, false),
                'json' => $payload,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $exception) {
            throw new OpenRouterChatException('transport_error', previous: $exception);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new OpenRouterChatException('upstream_http_error', $status);
        }

        $payload = $this->decodeJsonResponse($response);
        $content = $payload['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new OpenRouterChatException(isset($payload['error']) ? 'upstream_payload_error' : 'empty_response');
        }

        return new OpenRouterChatResponse(
            trim($content),
            $this->fileAnnotations($payload['choices'][0]['message']['annotations'] ?? []),
        );
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
        $payload = $this->decodeJsonResponse($response);

        $content = $payload['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new OpenRouterChatException(isset($payload['error']) ? 'upstream_payload_error' : 'empty_response');
        }

        $onDelta($content);

        return $content;
    }

    /** @param array<string, mixed> $settings */
    private function modelFor(array $settings, OpenRouterModelProfile $profile): string
    {
        return match ($profile) {
            OpenRouterModelProfile::Text => trim((string) ($settings['text_model'] ?? '')),
            OpenRouterModelProfile::Data => trim((string) ($settings['data_model'] ?? ''))
                ?: trim((string) ($settings['text_model'] ?? '')),
            OpenRouterModelProfile::ImageUnderstanding => trim((string) ($settings['image_understanding_model'] ?? '')),
        };
    }

    /** @return array<string, mixed> */
    private function decodeJsonResponse(ResponseInterface $response): array
    {
        $maxBytes = max(1024, (int) config('assistant.openrouter.max_response_bytes', 8 * 1024 * 1024));
        $declaredLength = (int) $response->getHeaderLine('Content-Length');

        if ($declaredLength > $maxBytes) {
            throw new OpenRouterChatException('response_too_large');
        }

        $body = (string) $response->getBody();
        if (strlen($body) > $maxBytes) {
            throw new OpenRouterChatException('response_too_large');
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new OpenRouterChatException('invalid_json', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new OpenRouterChatException('invalid_json');
        }

        return $payload;
    }

    /**
     * Keep only the documented file-annotation shape. The caller applies its
     * own tighter session budget before persisting parsed PDF content.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fileAnnotations(mixed $annotations): array
    {
        if (! is_array($annotations)) {
            return [];
        }

        $result = [];
        $seen = [];

        foreach ($annotations as $annotation) {
            if (! is_array($annotation) || ($annotation['type'] ?? null) !== 'file' || ! is_array($annotation['file'] ?? null)) {
                continue;
            }

            $hash = mb_substr(trim((string) ($annotation['file']['hash'] ?? '')), 0, 256);
            $content = $annotation['file']['content'] ?? null;

            if ($hash === '' || isset($seen[$hash]) || ! is_array($content)) {
                continue;
            }

            $parts = [];
            foreach ($content as $part) {
                if (! is_array($part) || ! in_array($part['type'] ?? null, ['text', 'image_url'], true)) {
                    continue;
                }

                if ($part['type'] === 'text' && is_string($part['text'] ?? null)) {
                    $parts[] = ['type' => 'text', 'text' => $part['text']];
                } elseif (
                    $part['type'] === 'image_url'
                    && is_array($part['image_url'] ?? null)
                    && is_string($part['image_url']['url'] ?? null)
                ) {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $part['image_url']['url']]];
                }
            }

            if ($parts === []) {
                continue;
            }

            $seen[$hash] = true;
            $result[] = [
                'type' => 'file',
                'file' => [
                    'hash' => $hash,
                    'name' => mb_substr(trim((string) ($annotation['file']['name'] ?? '')), 0, 180),
                    'content' => $parts,
                ],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>
     */
    private function toolCalls(mixed $toolCalls): array
    {
        if (! is_array($toolCalls)) {
            return [];
        }

        $result = [];
        foreach (array_slice($toolCalls, 0, 1) as $toolCall) {
            if (! is_array($toolCall) || ! is_array($toolCall['function'] ?? null)) {
                continue;
            }

            $id = trim((string) ($toolCall['id'] ?? ''));
            $name = trim((string) ($toolCall['function']['name'] ?? ''));
            $arguments = (string) ($toolCall['function']['arguments'] ?? '');

            if (
                $id === ''
                || mb_strlen($id) > 255
                || preg_match('/[\x00-\x20\x7F]/', $id) === 1
                || ! preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $name)
                || strlen($arguments) > 20000
            ) {
                continue;
            }

            $result[] = [
                'id' => $id,
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => $arguments,
                ],
            ];
        }

        return $result;
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
