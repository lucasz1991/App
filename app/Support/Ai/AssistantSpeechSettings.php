<?php

namespace App\Support\Ai;

use App\Models\Setting;
use InvalidArgumentException;
use Throwable;

final class AssistantSpeechSettings
{
    public const LOCAL_WITH_EXTERNAL_FALLBACK = 'local_with_external_fallback';

    public const LOCAL_ONLY = 'local_only';

    public const EXTERNAL_ONLY = 'external_only';

    public const DEFAULT_MODE = self::LOCAL_WITH_EXTERNAL_FALLBACK;

    public const GROUP = 'assistant';

    public const KEY = 'speech_routing';

    /** @return array<int, string> */
    public static function modes(): array
    {
        return [
            self::LOCAL_WITH_EXTERNAL_FALLBACK,
            self::LOCAL_ONLY,
            self::EXTERNAL_ONLY,
        ];
    }

    public static function mode(bool $uncached = false): string
    {
        try {
            $value = $uncached
                ? Setting::getValueUncached(self::GROUP, self::KEY)
                : Setting::getValue(self::GROUP, self::KEY);
        } catch (Throwable) {
            return self::DEFAULT_MODE;
        }

        $mode = is_string($value) ? trim($value) : '';

        return in_array($mode, self::modes(), true) ? $mode : self::DEFAULT_MODE;
    }

    public static function setMode(string $mode): void
    {
        $mode = trim($mode);

        if (! in_array($mode, self::modes(), true)) {
            throw new InvalidArgumentException('Unsupported assistant speech routing mode.');
        }

        Setting::setValue(self::GROUP, self::KEY, $mode);
    }
}
