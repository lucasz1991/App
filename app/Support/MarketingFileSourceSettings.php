<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

final class MarketingFileSourceSettings
{
    public const GROUP = 'marketing';

    public const KEY = 'file_source';

    public static function selectedFolderId(bool $uncached = false): ?int
    {
        $row = DB::table('settings')
            ->select('value')
            ->where('type', self::GROUP)
            ->where('key', self::KEY)
            ->first();

        return self::folderIdFromStoredRaw($row?->value, $row !== null);
    }

    public static function folderIdFromStoredRaw(mixed $rawValue, bool $rowExists = true): ?int
    {
        if (! $rowExists) {
            return null;
        }

        if (! is_string($rawValue) || trim($rawValue) === '') {
            return 0;
        }

        try {
            $value = json_decode($rawValue, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 0;
        }

        if (! is_array($value)) {
            return 0;
        }

        return self::folderIdFromValue($value);
    }

    public static function folderIdFromValue(mixed $value): ?int
    {

        // A missing row is handled by folderIdFromStoredRaw(). Within a valid
        // setting object, selected_folder_id=null means the company-pool root.
        // Every malformed shape becomes the fail-closed sentinel 0.
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

    public static function fingerprintForValue(mixed $value): string
    {
        $folderId = self::folderIdFromValue($value);

        return hash('sha256', $folderId === null ? 'root' : 'folder:'.$folderId);
    }

    public static function fingerprintForFolderId(?int $folderId): string
    {
        return hash('sha256', $folderId === null ? 'root' : 'folder:'.$folderId);
    }

    public static function setSelectedFolderId(?int $folderId): void
    {
        Setting::setValue(self::GROUP, self::KEY, [
            'selected_folder_id' => $folderId,
        ]);
    }
}
