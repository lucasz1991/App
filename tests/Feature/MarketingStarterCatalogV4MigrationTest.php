<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeStatus;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class MarketingStarterCatalogV4MigrationTest extends TestCase
{
    use DatabaseMigrations;

    /** @var array<string, string> */
    private const RELEASES = [
        MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE => MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE,
        MarketingTemplateFactory::JOB_WAGENMEISTER => MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER,
        MarketingTemplateFactory::INFO_GERMANY_NETWORK => MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK,
    ];

    public function test_it_converts_exact_v3_starters_in_place_and_is_idempotent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $templates = app(MarketingTemplateFactory::class);
        $legacyByKey = [];

        foreach (array_keys(self::RELEASES) as $legacyKey) {
            $legacy = $this->createFromKey($admin, $legacyKey);
            $legacyByKey[$legacyKey] = [
                'id' => $legacy->id,
                'versions' => $legacy->variants
                    ->mapWithKeys(fn ($variant): array => [$variant->format->value => $variant->version])
                    ->all(),
            ];
        }

        $migration = $this->migration();
        $migration->up();

        foreach (self::RELEASES as $legacyKey => $premiumKey) {
            $premium = MarketingCreative::query()
                ->where('shared_content->template_key', $premiumKey)
                ->with('variants')
                ->sole();
            $definition = $templates->definitionByKey($premiumKey);

            $this->assertSame($legacyByKey[$legacyKey]['id'], $premium->id);
            $this->assertSame($definition['title'], $premium->title);
            $this->assertSame(MarketingTemplateFactory::PREMIUM_SEED_VERSION, $premium->shared_content['seed_version']);
            $this->assertSame(MarketingCreativeStatus::Draft, $premium->status);
            $this->assertSame(0, MarketingCreative::withTrashed()
                ->where('shared_content->template_key', $legacyKey)
                ->count());
            $this->assertCount(3, $premium->variants);

            foreach ($premium->variants as $variant) {
                $format = $variant->format->value;
                $this->assertSame($legacyByKey[$legacyKey]['versions'][$format] + 1, $variant->version);
                $this->assertSame(4, data_get($variant->builder_data, 'railtime.schema'));
                $this->assertSame($premiumKey, data_get($variant->builder_data, 'railtime.template'));
                $this->assertSame(
                    $studio->contentHash($variant->builder_data, $variant->html, $variant->css),
                    $variant->content_hash,
                );
            }
        }

        $state = $this->catalogState();
        $migration->up();

        $this->assertSame($state, $this->catalogState());
        $this->assertDatabaseCount('marketing_creatives', 3);
        $this->assertDatabaseCount('marketing_creative_variants', 9);
    }

    public function test_it_preserves_custom_approved_and_deleted_v3_records_and_installs_separate_v4_starters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $customJob = $this->createFromKey($admin, MarketingTemplateFactory::JOB_WAGENMEISTER);
        $customJob->forceFill([
            'shared_content' => array_replace($customJob->shared_content, [
                'intro' => 'Individuell gepflegte Recruiting-Kampagne',
            ]),
        ])->save();
        $customHashes = $customJob->variants()->orderBy('id')->pluck('content_hash')->all();

        $approvedInfo = $this->createFromKey($admin, MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE);
        $approvedInfo->forceFill([
            'status' => MarketingCreativeStatus::Approved,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'approval_dependency_hash' => str_repeat('a', 64),
        ])->save();

        $deletedNetwork = $this->createFromKey($admin, MarketingTemplateFactory::INFO_GERMANY_NETWORK);
        $deletedNetwork->delete();

        $this->migration()->up();

        foreach (array_values(self::RELEASES) as $premiumKey) {
            $premium = MarketingCreative::query()
                ->where('shared_content->template_key', $premiumKey)
                ->with('variants')
                ->sole();

            $this->assertSame(MarketingTemplateFactory::PREMIUM_SEED_VERSION, $premium->shared_content['seed_version']);
            $this->assertCount(3, $premium->variants);
            $this->assertNotContains($premium->id, [$customJob->id, $approvedInfo->id, $deletedNetwork->id]);
        }

        $customJob->refresh();
        $this->assertSame('Individuell gepflegte Recruiting-Kampagne', $customJob->shared_content['intro']);
        $this->assertSame($customHashes, $customJob->variants()->orderBy('id')->pluck('content_hash')->all());

        $approvedInfo->refresh();
        $this->assertSame(MarketingCreativeStatus::Approved, $approvedInfo->status);
        $this->assertSame($admin->id, $approvedInfo->approved_by);
        $this->assertSame(str_repeat('a', 64), $approvedInfo->approval_dependency_hash);

        $deletedNetwork = MarketingCreative::withTrashed()->findOrFail($deletedNetwork->id);
        $this->assertTrue($deletedNetwork->trashed());
        $this->assertSame(3, $deletedNetwork->variants()->withTrashed()->count());
        $this->assertSame(0, $deletedNetwork->variants()->count());

        $this->assertSame(6, MarketingCreative::withTrashed()->count());
        $this->assertSame(18, MarketingCreativeVariant::withTrashed()->count());
    }

    public function test_an_existing_soft_deleted_v4_key_blocks_conversion_and_recreation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $legacyInfo = $this->createFromKey($admin, MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE);
        $deletedPremium = $this->createFromKey($admin, MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE);
        $this->createFromKey($admin, MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER);
        $this->createFromKey($admin, MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK);
        $deletedPremium->delete();

        $migration = $this->migration();
        $migration->up();

        $this->assertSame(1, MarketingCreative::withTrashed()
            ->where('shared_content->template_key', MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE)
            ->count());
        $this->assertSoftDeleted('marketing_creatives', ['id' => $deletedPremium->id]);
        $this->assertDatabaseHas('marketing_creatives', [
            'id' => $legacyInfo->id,
            'deleted_at' => null,
        ]);
        $this->assertSame(
            MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE,
            data_get($legacyInfo->fresh()->shared_content, 'template_key'),
        );

        $state = $this->catalogState();
        $migration->up();
        $this->assertSame($state, $this->catalogState());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_14_000100_install_premium_marketing_catalog_v4.php');
    }

    private function createFromKey(User $admin, string $templateKey): MarketingCreative
    {
        $type = app(MarketingTemplateFactory::class)->typeForKey($templateKey);

        return app(MarketingStudioService::class)->createFromTemplate(
            $type,
            $admin,
            $templateKey,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function catalogState(): array
    {
        return MarketingCreative::withTrashed()
            ->with(['variants' => fn ($query) => $query->withTrashed()->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->map(fn (MarketingCreative $creative): array => [
                'id' => $creative->id,
                'title' => $creative->title,
                'content' => $creative->shared_content,
                'deleted_at' => $creative->deleted_at?->toISOString(),
                'variants' => $creative->variants->map(fn ($variant): array => [
                    'id' => $variant->id,
                    'version' => $variant->version,
                    'hash' => $variant->content_hash,
                    'deleted_at' => $variant->deleted_at?->toISOString(),
                ])->all(),
            ])
            ->all();
    }
}
