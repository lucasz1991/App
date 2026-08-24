<?php

use App\Models\MarketingCreative;
use App\Models\User;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_creatives')
            || ! Schema::hasTable('marketing_creative_variants')
            || ! Schema::hasTable('users')) {
            return;
        }

        $keys = [
            MarketingTemplateFactory::CAREER_JOB_WAGENMEISTER,
            MarketingTemplateFactory::CAREER_JOB_TRIEBFAHRZEUGFUEHRER,
            MarketingTemplateFactory::CAREER_JOB_ARBEITSZUGFUEHRER,
        ];

        DB::transaction(function () use ($keys): void {
            $missingKeys = [];
            foreach ($keys as $key) {
                $exists = MarketingCreative::withTrashed()
                    ->where('shared_content->template_key', $key)
                    ->lockForUpdate()
                    ->exists();

                if (! $exists) {
                    $missingKeys[] = $key;
                }
            }

            if ($missingKeys === []) {
                return;
            }

            $actor = User::query()
                ->where('role', 'admin')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            // Frische Datenbanken besitzen während der Migration noch keinen
            // Administrator. Der DatabaseSeeder ruft nach dem Admin-Seeding
            // den idempotenten MarketingStudioSeeder auf.
            if (! $actor instanceof User) {
                return;
            }

            $templates = app(MarketingTemplateFactory::class);
            $studio = app(MarketingStudioService::class);

            foreach ($missingKeys as $key) {
                // Recheck after the administrator row lock. This closes the
                // race with another deploy process that may have installed
                // the same key while this transaction was waiting.
                if (MarketingCreative::withTrashed()
                    ->where('shared_content->template_key', $key)
                    ->lockForUpdate()
                    ->exists()) {
                    continue;
                }

                $studio->createFromTemplate(
                    $templates->typeForKey($key),
                    $actor,
                    $key,
                );
            }
        });
    }

    public function down(): void
    {
        // Bearbeitete, freigegebene oder gelöschte Motive werden nie automatisch entfernt.
    }
};
