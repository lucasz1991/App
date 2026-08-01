<?php

namespace App\Services\Ai;

use JsonException;

class AssistantKnowledgeToolRunner
{
    public function __construct(
        private readonly AssistantKnowledgePool $knowledge,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  callable(string): void  $onDelta
     */
    public function answer(
        OpenRouterChatClient $client,
        array $messages,
        callable $onDelta,
    ): string {
        $tools = $this->knowledge->toolDefinitions();
        $decision = $client->completeToolDecision($messages, $tools);

        if (! $decision->requestsTool()) {
            $answer = trim((string) $decision->content);
            $onDelta($answer);

            return $answer;
        }

        $messages[] = $decision->assistantMessage();

        foreach ($decision->toolCalls as $toolCall) {
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'],
                'name' => $toolCall['function']['name'],
                'content' => $this->execute($toolCall['function']['name'], $toolCall['function']['arguments']),
            ];
        }

        // OpenRouter requires the same tool schema on the follow-up request.
        // tool_choice=none guarantees that this bounded loop cannot recurse.
        return $client->stream($messages, $onDelta, $tools, 'none');
    }

    private function execute(string $name, string $rawArguments): string
    {
        if ($name !== AssistantKnowledgePool::TOOL_NAME) {
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

        if (! is_array($arguments) || ! is_string($arguments['query'] ?? null)) {
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
