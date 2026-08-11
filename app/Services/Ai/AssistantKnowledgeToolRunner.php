<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Support\Str;
use JsonException;

class AssistantKnowledgeToolRunner
{
    private const MARKETING_REDESIGN_TOOL = 'redesign_pagebuilder_document';

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
        array $pageBuilderContext = [],
    ): string {
        $tools = $this->toolDefinitions($user, $currentRoute);
        if ($tools === []) {
            return $client->stream($messages, $onDelta);
        }

        if ($this->requestsCompleteMarketingRedesign($messages, $tools, $currentRoute)) {
            $answer = $this->executeCompleteMarketingRedesign(
                $user,
                $currentRoute,
                $pageBuilderContext,
                $onEffect,
            );
            $onDelta($answer);

            return $answer;
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
            $pageBuilderContext,
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
        array $pageBuilderContext = [],
    ): OpenRouterChatResponse {
        $tools = $this->toolDefinitions($user, $currentRoute);
        if ($tools === []) {
            return $client->complete($messages, $profile, $plugins);
        }

        if ($this->requestsCompleteMarketingRedesign($messages, $tools, $currentRoute)) {
            return new OpenRouterChatResponse(
                $this->executeCompleteMarketingRedesign(
                    $user,
                    $currentRoute,
                    $pageBuilderContext,
                    $onEffect,
                ),
                [],
            );
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
            $pageBuilderContext,
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
        array $pageBuilderContext,
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
                $pageBuilderContext,
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
        array $pageBuilderContext,
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
                $pageBuilderContext,
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

    /**
     * A complete redesign is intentionally one deterministic, allowlisted
     * document action. It must never depend on whether a provider happens to
     * choose a tool or falls back to coaching prose.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     */
    private function requestsCompleteMarketingRedesign(array $messages, array $tools, string $currentRoute): bool
    {
        if ($currentRoute !== 'admin.marketing.creatives.editor'
            || ! in_array(self::MARKETING_REDESIGN_TOOL, $this->toolNames($tools), true)) {
            return false;
        }

        $conversation = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? null;
            if (! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $text = $this->messageText($message['content'] ?? '');
            if (trim($text) === '') {
                continue;
            }

            $conversation[] = ['role' => $role, 'text' => $text];
        }

        $latestIndex = array_key_last($conversation);
        if ($latestIndex === null || $conversation[$latestIndex]['role'] !== 'user') {
            return false;
        }

        $latestIntent = $this->normalizeIntentText($conversation[$latestIndex]['text']);
        if ($this->isDirectCompleteRedesignCommand($latestIntent)) {
            return true;
        }

        if (! $this->isAffirmativeImplementationFollowUp($latestIntent) || $latestIndex < 2) {
            return false;
        }

        $previousAssistant = $conversation[$latestIndex - 1];
        $previousUser = $conversation[$latestIndex - 2];
        if ($previousAssistant['role'] !== 'assistant'
            || $previousUser['role'] !== 'user'
            || ! $this->assistantOffersImplementation($this->normalizeIntentText($previousAssistant['text']))) {
            return false;
        }

        $lookbackStart = max(0, $latestIndex - 8);
        for ($index = $latestIndex - 2; $index >= $lookbackStart; $index--) {
            $entry = $conversation[$index];
            $intent = $this->normalizeIntentText($entry['text']);

            if ($entry['role'] === 'user' && $this->isDirectCompleteRedesignCommand($intent)) {
                return true;
            }

            if ($entry['role'] === 'assistant'
                && $this->hasWholeDocumentLanguage($intent)
                && $this->hasRedesignLanguage($intent)
                && ! $this->hasExplicitRedesignNegation($intent)) {
                return true;
            }
        }

        return false;
    }

    private function messageText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return implode(' ', $parts);
    }

    private function normalizeIntentText(string $text): string
    {
        $withoutQuotes = preg_replace([
            '/^\s*>.*$/mu',
            '/"[^"\r\n]*"/u',
            '/„[^“”\r\n]*[“”]/u',
            '/“[^”\r\n]*”/u',
            '/‘[^’\r\n]*’/u',
            '/`[^`\r\n]*`/u',
        ], ' ', mb_substr($text, 0, 1200));

        return mb_strtolower(Str::ascii(Str::squish($withoutQuotes ?? $text)));
    }

    private function isDirectCompleteRedesignCommand(string $intent): bool
    {
        if ($intent === ''
            || $this->hasExplicitRedesignNegation($intent)
            || $this->asksForAdviceOrDiscussion($intent)) {
            return false;
        }

        return $this->hasWholeDocumentLanguage($intent)
            && $this->hasRedesignLanguage($intent)
            && $this->hasImplementationLanguage($intent);
    }

    private function isAffirmativeImplementationFollowUp(string $intent): bool
    {
        if ($intent === ''
            || mb_strlen($intent) > 320
            || $this->hasExplicitRedesignNegation($intent)
            || $this->asksForAdviceOrDiscussion($intent)) {
            return false;
        }

        $affirmative = preg_match('/\b(?:ja|jawohl|okay|ok|gerne|bitte|jetzt|yes|please|sure|go\s+ahead)\b/u', $intent) === 1;

        return $affirmative && $this->hasImplementationLanguage($intent);
    }

    private function assistantOffersImplementation(string $intent): bool
    {
        if ($intent === '' || $this->hasExplicitRedesignNegation($intent)) {
            return false;
        }

        $offer = preg_match('/\b(?:ich\s+kann|ich\s+werde|ich\s+setze|soll\s+ich|mochtest\s+du|wenn\s+du\s+(?:willst|mochtest)|i\s+can|i\s+will|shall\s+i|would\s+you\s+like)\b/u', $intent) === 1;
        $editorSubject = preg_match('/\b(?:re-?design|design|layout|aufbau|motiv|entwurf|seite|bereich(?:e|en)?|editor|story|post|web)\b/u', $intent) === 1;

        return $offer && $editorSubject && $this->hasImplementationLanguage($intent);
    }

    private function hasWholeDocumentLanguage(string $intent): bool
    {
        return preg_match('/\b(?:komplett(?:e[snmr]?)?|vollstandig(?:e[snmr]?)?|gesamt(?:e[snmr]?)?|ganz(?:e[snmr]?)?|full|complete|entire|whole)\b/u', $intent) === 1;
    }

    private function hasRedesignLanguage(string $intent): bool
    {
        return preg_match('/\b(?:re-?design(?:en|e|t)?|neu\s+gestalten|neugestalten|neu\s+machen|umgestalten|layout\s+neu|design\s+neu)\b/u', $intent) === 1;
    }

    private function hasImplementationLanguage(string $intent): bool
    {
        return preg_match('/\b(?:mach(?:e|en|t)?|umsetz(?:en|e|t)?|gestalt(?:e|en|et)?|redesign(?:en|e|t)?|re-design(?:en|e|t)?|umgestalt(?:e|en|et)?|anwend(?:en|e|et)?|ubernehm(?:en|e|t)?|implement(?:ieren|iere|iert)?|apply|make|do)\b/u', $intent) === 1
            || preg_match('/\bsetz(?:e|en|t)?\b.{0,180}\bum\b/u', $intent) === 1
            || preg_match('/\b(?:mach|do|apply|implement)\s+(?:es|das|it)\b/u', $intent) === 1;
    }

    private function hasExplicitRedesignNegation(string $intent): bool
    {
        return preg_match('/\b(?:kein(?:e|en|em|er|es)?|no)\b(?:\s+\S+){0,4}\s+\b(?:re-?design\w*|design|layout|entwurf|motiv|seite)\b/u', $intent) === 1
            || preg_match('/\b(?:nicht|nie|niemals|don\'t|dont|do\s+not|never|not)\b(?:\s+\S+){0,4}\s+\b(?:umsetz\w*|anwend\w*|ubernehm\w*|mach\w*|gestalt\w*|re-?design\w*|implement\w*|apply|do)\b/u', $intent) === 1
            || preg_match('/\b(?:re-?design\w*|umsetz\w*|implement\w*|apply)\b(?:\s+\S+){0,4}\s+\b(?:nicht|nie|niemals|not|never)\b/u', $intent) === 1;
    }

    private function asksForAdviceOrDiscussion(string $intent): bool
    {
        return preg_match('/\b(?:wie|welche|welcher|welches|was|warum|wieso|risiko|risiken|erklar\w*|beschreib\w*|empfehl\w*|vorschlag\w*|idee\w*|konzept\w*|anleitung\w*|schritt\w*|how|what|why|risk|risks|explain\w*|describe\w*|recommend\w*|suggest\w*|advice)\b/u', $intent) === 1;
    }

    /**
     * @param  array<string, mixed>  $pageBuilderContext
     */
    private function executeCompleteMarketingRedesign(
        ?User $user,
        string $currentRoute,
        array $pageBuilderContext,
        ?callable $onEffect,
    ): string {
        $german = app()->getLocale() === 'de';
        if ($user === null) {
            return $german
                ? 'Das komplette Redesign konnte nicht vorbereitet werden, weil kein sicherer Benutzerkontext vorhanden ist.'
                : 'The complete redesign could not be prepared because no secure user context is available.';
        }

        $result = $this->applicationTools->execute(
            self::MARKETING_REDESIGN_TOOL,
            ['preset' => 'railtime_modern'],
            $user,
            $currentRoute,
            [],
            $pageBuilderContext,
        );
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $effect = is_array($result['effect'] ?? null) ? $result['effect'] : null;

        if (($payload['state'] ?? null) === 'scheduled' && $effect !== null && $onEffect !== null) {
            $onEffect($effect);

            return $german
                ? 'Ich habe das komplette RailTime-Modern-Redesign für Story, Post und Web vorbereitet. Deine redaktionellen Inhalte bleiben erhalten; eine bestehende Freigabe wird auf Entwurf zurückgesetzt. Prüfe die vollständige Änderung und bestätige sie mit „Übernehmen“.'
                : 'I prepared the complete RailTime Modern redesign for story, post and web. Your editorial content is preserved; any existing approval returns to draft. Review the complete change and confirm it with “Apply”.';
        }

        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            $message = $german
                ? 'Der sichere Editor hat das komplette Redesign nicht angenommen.'
                : 'The secure editor did not accept the complete redesign.';
        }

        return $german
            ? 'Das komplette Redesign konnte nicht vorbereitet werden: '.$message
            : 'The complete redesign could not be prepared: '.$message;
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
