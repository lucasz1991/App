<?php

namespace App\Models\Concerns;

use App\Services\Operations\SiteSnapshotService;

trait HasRoutingSite
{
    public static function bootHasRoutingSite(): void
    {
        static::saving(fn ($record) => app(SiteSnapshotService::class)->prepare($record));
    }
}
