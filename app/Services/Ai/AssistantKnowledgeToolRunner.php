<?php

namespace App\Services\Ai;

use App\Models\User;
use JsonException;

class AssistantKnowledgeToolRunner
{
    public function __construct(
        private readonly AssistantKnowledgePool $knowledge,
        private readonly AssistantApplicationTools $applicationTools,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  callable(string): void  $onDelta
     */
    public function answer(
        OpenRouterChatClient $client,
        array $messages,
        callable $onDelta,
        ?User $user = null,
        string $currentRoute = 'unknown',
        array $wagonContext = [],
        ?callable $onEffect = null,
    ): string {
        $tools = $this->toolDefinitions($user, $currentRoute);
        if ($tools === []) {
            return $client->stream($messages, $onDelta);
        }

        $decision = $client->completeToolDecision($messages, $tools);

        if (! $decision->requestsTool()) {
            $answer = trim((string) $decision->content);
            $onDelta($answer);

            return $answer;
        }

        $messages = $this->appendToolResult(
            $messages,
            $decision,
            $user,
            $currentRoute,
            $wagonContext,
            $onEffect,
            $tools,
        );

        // OpenRouter requires the same tool schema on the follow-up request.
        // tool_choice=none guarantees that this bounded loop cannot recurse.
        return $client->stream($messages, $onDelta, $tools, 'none');
    }

    /**
     * Attachment-aware knowledge round. The first completion both parses the
     * attachment and decides whether one local lookup is needed. PDF
     * annotations are replayed on the assistant message and retained for the
     * encrypted follow-up context.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $plugins
     */
    public function complete(
        OpenRouterChatClient $client,
        array $messages,
        OpenRouterModelProfile $profile,
        array $plugins = [],
        ?User $user = null,
        string $currentRoute = 'unknown',
        array $wagonContext = [],
        ?callable $onEffect = null,
    ): OpenRouterChatResponse {
        $tools = $this->toolDefinitions($user, $currentRoute);
        if ($tools === []) {
            return $client->complete($messages, $profile, $plugins);
        }

        $decision = $client->completeToolDecision($messages, $tools, $profile, $plugins);

        if (! $decision->requestsTool()) {
            return new OpenRouterChatResponse(
                trim((string) $decision->content),
                $decision->fileAnnotations,
            );
        }

        $messages = $this->appendToolResult(
            $messages,
            $decision,
            $user,
            $currentRoute,
            $wagonContext,
            $onEffect,
            $tools,
        );
        $response = $client->complete($messages, $profile, $plugins, $tools, 'none');

        return new OpenRouterChatResponse(
            $response->content,
            $this->mergeFileAnnotations($decision->fileAnnotations, $response->fileAnnotations),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function appendToolResult(
        array $messages,
        OpenRouterToolDecision $decision,
        ?User $user,
        string $currentRoute,
        array $wagonContext,
        ?callable $onEffect,
        array $tools,
    ): array {
        $toolCall = $decision->toolCalls[0];
        $messages[] = $decision->assistantMessage();
        $messages[] = [
            'role' => 'tool',
            'tool_call_id' => $toolCall['id'],
            'name' => $toolCall['function']['name'],
            'content' => $this->execute(
                $toolCall['function']['name'],
                $toolCall['function']['arguments'],
                $user,
                $currentRoute,
                $wagonContext,
                $onEffect,
                $this->toolNames($tools),
            ),
        ];

        return $messages;
    }

    /**
     * @param  array<int, array<string, mixed>>  $first
     * @param  array<int, array<string, mixed>>  $second
     * @return array<int, array<string, mixed>>
     */
    private function mergeFileAnnotations(array $first, array $second): array
    {
        $merged = [];
        $seen = [];

        foreach ([...$first, ...$second] as $annotation) {
            if (! is_array($annotation) || ! is_array($annotation['file'] ?? null)) {
                continue;
            }

            $hash = trim((string) ($annotation['file']['hash'] ?? ''));
            if ($hash === '' || isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $merged[] = $annotation;
        }

        return $merged;
    }

    private function execute(
        string $name,
        string $rawArguments,
        ?User $user,
        string $currentRoute,
        array $wagonContext,
        ?callable $onEffect,
        array $allowedToolNames,
    ): string {
        if (! in_array($name, $allowedToolNames, true)) {
            return $this->encode([
                'error' => 'unknown_tool',
                'message' => 'Dieses Tool ist nicht freigegeben.',
            ]);
        }

        try {
            $arguments = json_decode($rawArguments, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->encode([
                'error' => 'invalid_arguments',
                'message' => 'Die Suchparameter sind kein gültiges JSON-Objekt.',
            ]);
        }

        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return $this->encode([
                'error' => 'invalid_arguments',
                'message' => 'Die Tool-Parameter müssen ein JSON-Objekt sein.',
            ]);
        }

        if ($name !== AssistantKnowledgePool::TOOL_NAME) {
            if ($user === null) {
                return $this->encode([
                    'error' => 'unknown_tool',
                    'message' => 'Dieses Tool ist nicht freigegeben.',
                ]);
            }

            $result = $this->applicationTools->execute(
                $name,
                $arguments,
                $user,
                $currentRoute,
                $wagonContext,
            );

            if (is_array($result['effect']) && $onEffect !== null) {
                $onEffect($result['effect']);
            }

            return $this->encode($result['payload']);
        }

        if (! is_string($arguments['query'] ?? null)) {
            return $this->encode([
                'error' => 'invalid_arguments',
                'message' => 'Für die Wissenssuche ist eine konkrete query erforderlich.',
            ]);
        }

        $topic = isset($arguments['topic']) && is_string($arguments['topic'])
            ? $arguments['topic']
            : null;
        $limit = is_numeric($arguments['limit'] ?? null)
            ? (int) $arguments['limit']
            : 4;

        return $this->encode($this->knowledge->search(
            $arguments['query'],
            $topic,
            $limit,
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function toolDefinitions(?User $user, string $currentRoute): array
    {
        $tools = $this->knowledge->hasSearchableKnowledge()
            ? $this->knowledge->toolDefinitions()
            : [];

        if ($user !== null) {
            $tools = [...$tools, ...$this->applicationTools->toolDefinitions($user, $currentRoute)];
        }

        return $tools;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, string>
     */
    private function toolNames(array $tools): array
    {
        $names = [];

        foreach ($tools as $tool) {
            $name = $tool['function']['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return '{"error":"encoding_failed"}';
        }
    }
}
