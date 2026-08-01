<?php

namespace App\Support\Ai;

use App\Models\Setting;
use App\Services\Ai\AssistantKnowledgePool;
use Throwable;

final class AssistantKnowledgeSettings
{
    public const GROUP = 'assistant';

    public const KEY = 'knowledge_intro';

    public const DEFAULT_INTRO = 'Der RailTime-Wissenspool enth\u00e4lt redaktionell gepr\u00fcfte Hinweise zu Bedienung, Abl\u00e4ufen und internen Begriffen. Nutze Detailwissen nur, wenn es zur Frage passt.';

    public static function intro(bool $uncached = false): string
    {
        try {
            $value = $uncached
                ? Setting::getValueUncached(self::GROUP, self::KEY)
                : Setting::getValue(self::GROUP, self::KEY);
        } catch (Throwable) {
            return self::DEFAULT_INTRO;
        }

        $intro = is_string($value) ? trim($value) : '';

        return $intro !== '' ? mb_substr($intro, 0, 1200) : self::DEFAULT_INTRO;
    }

    public static function setIntro(string $intro): void
    {
        $intro = trim($intro);
        Setting::setValue(self::GROUP, self::KEY, $intro !== '' ? $intro : self::DEFAULT_INTRO);
        app(AssistantKnowledgePool::class)->forgetCache();
    }
}
