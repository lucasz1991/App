<?php

namespace App\Services\Ai;

final class OpenRouterToolDecision
{
    /**
     * @param  array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>  $toolCalls
     */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls,
    ) {}

    public function requestsTool(): bool
    {
        return $this->toolCalls !== [];
    }

    /** @return array<string, mixed> */
    public function assistantMessage(): array
    {
        return [
            'role' => 'assistant',
            'content' => $this->content,
            'tool_calls' => $this->toolCalls,
        ];
    }
}
