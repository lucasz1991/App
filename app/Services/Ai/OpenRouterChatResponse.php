<?php

namespace App\Services\Ai;

final readonly class OpenRouterChatResponse
{
    /**
     * @param  array<int, array<string, mixed>>  $fileAnnotations
     */
    public function __construct(
        public string $content,
        public array $fileAnnotations = [],
    ) {}
}
