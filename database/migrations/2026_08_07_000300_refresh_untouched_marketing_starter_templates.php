<?php

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Services\Marketing\MarketingContentBinder;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_creatives') || ! Schema::hasTable('marketing_creative_variants')) {
            return;
        }

        $templates = app(MarketingTemplateFactory::class);
        $binder = app(MarketingContentBinder::class);
        $sanitizer = app(MarketingHtmlSanitizer::class);
        $studio = app(MarketingStudioService::class);

        MarketingCreative::query()
            ->with('variants')
            ->orderBy('id')
            ->chunkById(100, function ($creatives) use ($templates, $binder, $sanitizer, $studio): void {
                foreach ($creatives as $creative) {
                    DB::transaction(function () use ($creative, $templates, $binder, $sanitizer, $studio): void {
                        $locked = MarketingCreative::query()
                            ->with('variants')
                            ->lockForUpdate()
                            ->find($creative->id);

                        if (! $locked instanceof MarketingCreative) {
                            return;
                        }

                        $definition = $templates->definition($locked->type);
                        $changed = false;

                        foreach ($locked->variants as $variant) {
                            if (! $variant instanceof MarketingCreativeVariant
                                || $variant->version !== 1
                                || (int) data_get($variant->builder_data, 'railtime.schema', 0) !== 1
                                || ! $variant->format instanceof MarketingCreativeFormat) {
                                continue;
                            }

                            $template = $definition['variants'][$variant->format->value] ?? null;
                            if (! is_array($template)) {
                                continue;
                            }

                            $html = $sanitizer->html($binder->bindHtml(
                                (string) $template['html'],
                                $locked->shared_content ?? [],
                            ));
                            $css = $sanitizer->css((string) $template['css']);
                            $builderData = $binder->syncBuilderData((array) $template['builder_data'], $html);

                            $variant->forceFill([
                                'builder_data' => $builderData,
                                'html' => $html,
                                'css' => $css,
                                'content_hash' => $studio->contentHash($builderData, $html, $css),
                                'version' => 2,
                            ])->save();
                            $changed = true;
                        }

                        if (! $changed) {
                            return;
                        }

                        $locked->forceFill([
                            'status' => MarketingCreativeStatus::Draft,
                            'approved_by' => null,
                            'approved_at' => null,
                        ])->save();
                    });
                }
            });
    }

    public function down(): void
    {
        // Bereits bearbeitete oder freigegebene Marketinginhalte werden nicht automatisch zurückgesetzt.
    }
};
