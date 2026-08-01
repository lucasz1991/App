<?php

namespace App\Services\Ai;

final class OpenRouterToolDecision
{
    /**
     * @param  array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>  $toolCalls
     * @param  array<int, array<string, mixed>>  $fileAnnotations
     */
    public function __construct(
        public readonly ?string $content,
        array $toolCalls,
        public readonly array $fileAnnotations = [],
    ) {
        // Provider responses remain untrusted even when parallel tool calls
        // were disabled. A knowledge request may execute at most one lookup.
        $this->toolCalls = array_slice($toolCalls, 0, 1);
    }

    /**
     * @var array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>
     */
    public readonly array $toolCalls;

    public function requestsTool(): bool
    {
        return $this->toolCalls !== [];
    }

    /** @return array<string, mixed> */
    public function assistantMessage(): array
    {
        $message = [
            'role' => 'assistant',
            'content' => $this->content,
            'tool_calls' => $this->toolCalls,
        ];

        if ($this->fileAnnotations !== []) {
            $message['annotations'] = $this->fileAnnotations;
        }

        return $message;
    }
}
