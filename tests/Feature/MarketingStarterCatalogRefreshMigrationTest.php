<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use ReflectionMethod;
use Tests\TestCase;

class MarketingStarterCatalogRefreshMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_it_refreshes_only_complete_legacy_baselines_and_creates_the_network_starter_idempotently(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->migration();
        $job = $this->legacyStarter($admin, MarketingTemplateFactory::JOB_WAGENMEISTER, $migration);
        $info = $this->legacyStarter(
            $admin,
            MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE,
            $migration,
            1,
        );

        $migration->up();

        foreach ([$job, $info] as $creative) {
            $creative->refresh()->load('variants');
            $definition = app(MarketingTemplateFactory::class)->definitionByKey(
                (string) data_get($creative->shared_content, 'template_key'),
            );

            $this->assertSame($definition['title'], $creative->title);
            $this->assertSame($definition['shared_content'], $creative->shared_content);
            $this->assertSame(MarketingTemplateFactory::SEED_VERSION, $creative->shared_content['seed_version']);
            $this->assertSame(MarketingCreativeStatus::Draft, $creative->status);
            $this->assertCount(3, $creative->variants);
            foreach ($creative->variants as $variant) {
                $this->assertSame($creative->is($job) ? 3 : 2, $variant->version);
                $this->assertSame(3, data_get($variant->builder_data, 'railtime.schema'));
                $this->assertSame(
                    app(MarketingStudioService::class)->contentHash($variant->builder_data, $variant->html, $variant->css),
                    $variant->content_hash,
                );
            }
        }

        $network = MarketingCreative::query()
            ->where('shared_content->template_key', MarketingTemplateFactory::INFO_GERMANY_NETWORK)
            ->with('variants')
            ->sole();
        $this->assertSame($admin->id, $network->created_by);
        $this->assertCount(3, $network->variants);

        $creativeIds = MarketingCreative::query()->orderBy('id')->pluck('id')->all();
        $variantState = $this->variantState();
        $migration->up();

        $this->assertSame($creativeIds, MarketingCreative::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($variantState, $this->variantState());
        $this->assertSame(1, MarketingCreative::withTrashed()
            ->where('shared_content->template_key', MarketingTemplateFactory::INFO_GERMANY_NETWORK)
            ->count());
    }

    public function test_it_leaves_modified_approved_incomplete_and_soft_deleted_starters_untouched(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->migration();

        $contentEdited = $this->legacyStarter($admin, MarketingTemplateFactory::JOB_WAGENMEISTER, $migration);
        $contentEdited->forceFill([
            'shared_content' => array_replace($contentEdited->shared_content, ['intro' => 'Individuell gepflegt']),
        ])->save();

        $layoutEdited = $this->legacyStarter($admin, MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE, $migration);
        $layoutVariant = $layoutEdited->variants()->where('format', MarketingCreativeFormat::Story->value)->firstOrFail();
        $layoutVariant->forceFill(['version' => 3])->save();

        $approved = $this->legacyStarter($admin, MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE, $migration);
        $approved->forceFill([
            'status' => MarketingCreativeStatus::Approved,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'approval_dependency_hash' => str_repeat('a', 64),
        ])->save();

        $incomplete = $this->legacyStarter($admin, MarketingTemplateFactory::JOB_WAGENMEISTER, $migration);
        $incomplete->variants()->where('format', MarketingCreativeFormat::Web->value)->firstOrFail()->delete();

        $deleted = $this->legacyStarter($admin, MarketingTemplateFactory::JOB_WAGENMEISTER, $migration);
        $deleted->delete();

        $before = collect([$contentEdited, $layoutEdited, $approved, $incomplete])
            ->mapWithKeys(fn (MarketingCreative $creative): array => [
                $creative->id => [
                    'title' => $creative->title,
                    'content' => $creative->shared_content,
                    'variants' => $creative->variants()->withTrashed()->orderBy('id')->pluck('version', 'id')->all(),
                ],
            ]);

        $migration->up();

        foreach ($before as $creativeId => $state) {
            $creative = MarketingCreative::withTrashed()->findOrFail($creativeId);
            $this->assertSame($state['title'], $creative->title);
            $this->assertSame($state['content'], $creative->shared_content);
            $this->assertSame(
                $state['variants'],
                $creative->variants()->withTrashed()->orderBy('id')->pluck('version', 'id')->all(),
            );
        }
        $this->assertSoftDeleted('marketing_creatives', ['id' => $deleted->id]);
        $this->assertNull(data_get($deleted->fresh()->shared_content, 'seed_version'));
    }

    public function test_a_soft_deleted_network_starter_is_not_recreated_or_restored(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $network = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Info,
            $admin,
            MarketingTemplateFactory::INFO_GERMANY_NETWORK,
        );
        $network->delete();

        $this->migration()->up();

        $this->assertSame(1, MarketingCreative::withTrashed()
            ->where('shared_content->template_key', MarketingTemplateFactory::INFO_GERMANY_NETWORK)
            ->count());
        $this->assertSoftDeleted('marketing_creatives', ['id' => $network->id]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_12_020000_refresh_untouched_marketing_starter_catalog.php');
    }

    private function legacyStarter(
        User $admin,
        string $templateKey,
        Migration $migration,
        int $variantVersion = 2,
    ): MarketingCreative
    {
        $type = $templateKey === MarketingTemplateFactory::JOB_WAGENMEISTER
            ? MarketingCreativeType::Job
            : MarketingCreativeType::Info;
        $title = $type === MarketingCreativeType::Job
            ? 'Wagenmeister (m/w/d) – Entwurf'
            : 'Wagenmeister-Service – 24/7';
        $contentMethod = new ReflectionMethod($migration, 'legacySharedContent');
        /** @var array<string, mixed> $sharedContent */
        $sharedContent = $contentMethod->invoke($migration, $templateKey);

        $creative = MarketingCreative::query()->create([
            'type' => $type,
            'status' => MarketingCreativeStatus::Draft,
            'title' => $title,
            'shared_content' => $sharedContent,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        foreach (MarketingCreativeFormat::cases() as $format) {
            [$html, $css] = $this->legacyVariantDocument($type, $format);
            $builderData = [
                'pages' => [['name' => ucfirst($format->value), 'component' => $html]],
                'styles' => [],
                'railtime' => [
                    'template' => $type->value,
                    'format' => $format->value,
                    'schema' => 2,
                ],
            ];
            $creative->variants()->create([
                'format' => $format,
                'builder_data' => $builderData,
                'html' => $html,
                'css' => $css,
                'content_hash' => app(MarketingStudioService::class)->contentHash($builderData, $html, $css),
                'version' => $variantVersion,
            ]);
        }

        return $creative->load('variants');
    }

    /** @return array{string,string} */
    private function legacyVariantDocument(MarketingCreativeType $type, MarketingCreativeFormat $format): array
    {
        $dimensions = $format->dimensions();
        $family = $type === MarketingCreativeType::Job ? 'job' : 'info';
        $structure = match ([$family, $format]) {
            ['job', MarketingCreativeFormat::Story] => 'rt-hero',
            ['job', MarketingCreativeFormat::Post] => 'rt-post-panel',
            ['job', MarketingCreativeFormat::Web] => 'rt-web-copy',
            ['info', MarketingCreativeFormat::Story] => 'rt-info-hero',
            ['info', MarketingCreativeFormat::Post] => 'rt-info-post-lead',
            ['info', MarketingCreativeFormat::Web] => 'rt-info-web-lead',
        };
        $cssMarker = match ([$family, $format]) {
            ['job', MarketingCreativeFormat::Story] => '.rt-job-story .rt-hero{position:relative;height:650px',
            ['job', MarketingCreativeFormat::Post] => '.rt-job-post .rt-post-panel{position:absolute',
            ['job', MarketingCreativeFormat::Web] => '.rt-job-web .rt-web-copy{position:absolute',
            ['info', MarketingCreativeFormat::Story] => '.rt-info-story .rt-info-hero{position:relative;height:720px',
            ['info', MarketingCreativeFormat::Post] => '.rt-info-post .rt-info-post-lead{position:absolute',
            ['info', MarketingCreativeFormat::Web] => '.rt-info-web .rt-info-web-lead{position:absolute',
        };

        $html = '<main class="rt-marketing-canvas rt-'.$family.' rt-'.$family.'-'.$format->value.'">'
            .'<section class="'.$structure.'"></section>'
            .'<img src="/rt-brand/img/hero-railtime.jpg" alt="">'
            .'<img src="/rt-brand/img/logo-horizontal.png" alt="RT Rail Time GmbH">'
            .'</main>';
        $css = '*{box-sizing:border-box}.rt-marketing-canvas{width:'.$dimensions['width'].'px;height:'
            .$dimensions['height'].'px}'.$cssMarker.';color:#102237}';

        return [$html, $css];
    }

    /** @return array<int, array{version:int,hash:string}> */
    private function variantState(): array
    {
        return MarketingCreativeVariant::query()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn ($variant): array => [
                $variant->id => ['version' => $variant->version, 'hash' => $variant->content_hash],
            ])
            ->all();
    }
}
