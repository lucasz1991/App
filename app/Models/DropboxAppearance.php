<?php

namespace App\Models;

use App\Services\Dropbox\WorkbookReader;
use Illuminate\Database\Eloquent\Model;

class DropboxAppearance extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['locator' => 'array', 'baseline' => 'array', 'last_excel' => 'array'];

    protected static function booted(): void
    {
        static::saving(function (self $appearance) {
            if (($appearance->locator['kind'] ?? '') === 'availability_submission' && (int) ($appearance->baseline['wish_id'] ?? 0) > 0) {
                $appearance->locator = [...$appearance->locator, 'wish_id' => (int) $appearance->baseline['wish_id']];
            }
            $appearance->identity_hash = WorkbookReader::identityHash($appearance->last_excel ?? []);
        });
    }
}
