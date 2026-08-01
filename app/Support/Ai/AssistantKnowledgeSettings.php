<?php

namespace App\Support\Ai;

use App\Models\Setting;
use App\Services\Ai\AssistantKnowledgePool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AssistantKnowledgeSettings
{
    public const GROUP = 'assistant';

    public const KEY = 'knowledge_intro';

    public const DEFAULT_PROMPT_KEY = 'default_prompt';

    public const BINDING_RULES_KEY = 'binding_rules';

    public const INTRO_MAX_CHARACTERS = 1200;

    public const DEFAULT_PROMPT_MAX_CHARACTERS = 3000;

    public const RULES_MAX_CHARACTERS = 4000;

    public const DEFAULT_INTRO = 'Der RailTime-Wissenspool enthält redaktionell geprüfte Hinweise zu Bedienung, Abläufen und internen Begriffen. Nutze Detailwissen nur, wenn es zur Frage passt.';

    public const DEFAULT_PROMPT = 'Du bist RailTime Assist, der digitale Assistent innerhalb der RailTime-Anwendung. Hilf Mitarbeitenden und Administratoren verständlich, knapp und lösungsorientiert bei Bedienung, Navigation und allgemeinen RailTime-Abläufen.';

    public const DEFAULT_RULES = "Antworte nachvollziehbar und unterscheide bestätigte Informationen von Annahmen.\nErfinde keine internen Abläufe, Zuständigkeiten oder Live-Daten.\nNutze den Wissenspool nur, wenn die vorhandenen Kurzinfos nicht ausreichen.\nVerweise bei fehlendem oder unsicherem Wissen auf das passende RailTime-Modul oder den Support.";

    public static function intro(bool $uncached = false): string
    {
        return self::textSetting(
            self::KEY,
            self::DEFAULT_INTRO,
            self::INTRO_MAX_CHARACTERS,
            $uncached,
        );
    }

    public static function defaultPrompt(bool $uncached = false): string
    {
        return self::textSetting(
            self::DEFAULT_PROMPT_KEY,
            self::DEFAULT_PROMPT,
            self::DEFAULT_PROMPT_MAX_CHARACTERS,
            $uncached,
        );
    }

    public static function rules(bool $uncached = false): string
    {
        return self::textSetting(
            self::BINDING_RULES_KEY,
            self::DEFAULT_RULES,
            self::RULES_MAX_CHARACTERS,
            $uncached,
        );
    }

    public static function setIntro(string $intro): void
    {
        $intro = self::normalizeText($intro, self::INTRO_MAX_CHARACTERS);
        Setting::setValue(self::GROUP, self::KEY, $intro !== '' ? $intro : self::DEFAULT_INTRO);
        app(AssistantKnowledgePool::class)->forgetCache();
    }

    public static function setPromptConfiguration(string $defaultPrompt, string $rules): void
    {
        $defaultPrompt = self::normalizeText($defaultPrompt, self::DEFAULT_PROMPT_MAX_CHARACTERS);
        $rules = self::normalizeText($rules, self::RULES_MAX_CHARACTERS);

        DB::transaction(static function () use ($defaultPrompt, $rules): void {
            Setting::query()->updateOrCreate(
                ['type' => self::GROUP, 'key' => self::DEFAULT_PROMPT_KEY],
                ['value' => $defaultPrompt !== '' ? $defaultPrompt : self::DEFAULT_PROMPT],
            );
            Setting::query()->updateOrCreate(
                ['type' => self::GROUP, 'key' => self::BINDING_RULES_KEY],
                ['value' => $rules !== '' ? $rules : self::DEFAULT_RULES],
            );
        });

        // Keep the previous pair visible until both rows are committed, then
        // invalidate both keys together. This prevents a concurrent request
        // from repopulating one cache key with an uncommitted/stale value.
        Cache::forget('settings.'.self::GROUP.'.'.self::DEFAULT_PROMPT_KEY);
        Cache::forget('settings.'.self::GROUP.'.'.self::BINDING_RULES_KEY);
    }

    private static function textSetting(
        string $key,
        string $default,
        int $maxCharacters,
        bool $uncached,
    ): string {
        try {
            $value = $uncached
                ? Setting::getValueUncached(self::GROUP, $key)
                : Setting::getValue(self::GROUP, $key);
        } catch (Throwable) {
            return $default;
        }

        $text = is_string($value) ? self::normalizeText($value, $maxCharacters) : '';

        return $text !== '' ? $text : $default;
    }

    private static function normalizeText(string $text, int $maxCharacters): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));

        return mb_substr($text, 0, $maxCharacters);
    }
}
