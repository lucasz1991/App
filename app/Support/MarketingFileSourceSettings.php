<?php

namespace App\Support;

use App\Models\Setting;

final class MarketingFileSourceSettings
{
    public const GROUP = 'marketing';

    public const KEY = 'file_source';

    public static function selectedFolderId(bool $uncached = false): ?int
    {
        $value = $uncached
            ? Setting::getValueUncached(self::GROUP, self::KEY)
            : Setting::getValue(self::GROUP, self::KEY);

        // A missing setting and an explicitly stored null both mean the
        // company pool root. Every malformed value is represented by the
        // fail-closed sentinel 0 and must never broaden access to the root.
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ! array_key_exists('selected_folder_id', $value)) {
            return 0;
        }

        $raw = $value['selected_folder_id'];
        if ($raw === null) {
            return null;
        }

        if (! is_int($raw) && ! (is_string($raw) && ctype_digit($raw))) {
            return 0;
        }

        $folderId = (int) $raw;

        return $folderId > 0 ? $folderId : 0;
    }

    public static function setSelectedFolderId(?int $folderId): void
    {
        Setting::setValue(self::GROUP, self::KEY, [
            'selected_folder_id' => $folderId,
        ]);
    }
}
