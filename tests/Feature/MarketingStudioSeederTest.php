<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Models\MarketingCreative;
use App\Models\User;
use App\Services\Marketing\MarketingStudioService;
use Database\Seeders\MarketingStudioSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use RuntimeException;
use Tests\TestCase;

class MarketingStudioSeederTest extends TestCase
{
    use DatabaseMigrations;

    public function test_it_creates_both_start_motives_with_all_variants_for_the_preferred_admin(): void
    {
        User::factory()->create(['role' => 'admin', 'email' => 'first-admin@rail-time.test']);
        $preferred = User::factory()->create(['role' => 'admin', 'email' => 'marketing-admin@rail-time.test']);
        config()->set('marketing.seed_admin_email', $preferred->email);

        $this->seed(MarketingStudioSeeder::class);

        $creatives = MarketingCreative::query()->with('variants')->orderBy('id')->get();
        $this->assertCount(2, $creatives);
        $this->assertSame(
            ['railtime_info_wagenmeister', 'railtime_job_wagenmeister'],
            $creatives->pluck('shared_content.template_key')->sort()->values()->all(),
        );
        $this->assertSame(6, $creatives->sum(fn (MarketingCreative $creative): int => $creative->variants->count()));

        foreach ($creatives as $creative) {
            $this->assertSame(MarketingCreativeStatus::Draft, $creative->status);
            $this->assertSame($preferred->id, $creative->created_by);
            $this->assertSame($preferred->id, $creative->updated_by);
            $this->assertCount(3, $creative->variants);

            foreach ($creative->variants as $variant) {
                $this->assertSame(2, data_get($variant->builder_data, 'railtime.schema'));
                $this->assertMatchesRegularExpression(
                    '#src="/rt-brand/img/logo-horizontal(?:-darkbg)?\.png"#',
                    $variant->html,
                );
            }
        }
    }

    public function test_a_second_run_is_idempotent(): void
    {
        User::factory()->create(['role' => 'admin']);

        $this->seed(MarketingStudioSeeder::class);
        $creativeIds = MarketingCreative::query()->orderBy('id')->pluck('id')->all();
        $variantIds = MarketingCreative::query()
            ->with('variants')
            ->orderBy('id')
            ->get()
            ->flatMap->variants
            ->pluck('id')
            ->all();

        $this->seed(MarketingStudioSeeder::class);

        $this->assertSame($creativeIds, MarketingCreative::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(
            $variantIds,
            MarketingCreative::query()->with('variants')->orderBy('id')->get()->flatMap->variants->pluck('id')->all(),
        );
        $this->assertDatabaseCount('marketing_creatives', 2);
        $this->assertDatabaseCount('marketing_creative_variants', 6);
    }

    public function test_an_existing_start_record_is_left_unchanged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $existing = app(MarketingStudioService::class)->createFromTemplate(MarketingCreativeType::Job, $admin);
        $existing->forceFill([
            'title' => 'Individuell gepflegtes Startmotiv',
            'shared_content' => array_replace($existing->shared_content, ['intro' => 'Nicht überschreiben']),
        ])->save();
        $originalVariantHashes = $existing->variants()->orderBy('id')->pluck('content_hash')->all();

        $this->seed(MarketingStudioSeeder::class);

        $existing->refresh();
        $this->assertSame('Individuell gepflegtes Startmotiv', $existing->title);
        $this->assertSame('Nicht überschreiben', $existing->shared_content['intro']);
        $this->assertSame($originalVariantHashes, $existing->variants()->orderBy('id')->pluck('content_hash')->all());
        $this->assertDatabaseCount('marketing_creatives', 2);
        $this->assertDatabaseHas('marketing_creatives', [
            'type' => MarketingCreativeType::Info->value,
            'status' => MarketingCreativeStatus::Draft->value,
        ]);
    }

    public function test_a_soft_deleted_start_record_is_neither_recreated_nor_restored(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $deleted = app(MarketingStudioService::class)->createFromTemplate(MarketingCreativeType::Job, $admin);
        $deleted->delete();

        $this->seed(MarketingStudioSeeder::class);

        $this->assertSame(1, MarketingCreative::withTrashed()
            ->where('shared_content->template_key', 'railtime_job_wagenmeister')
            ->count());
        $this->assertSoftDeleted('marketing_creatives', ['id' => $deleted->id]);
        $this->assertDatabaseCount('marketing_creatives', 2);
    }

    public function test_it_fails_clearly_when_no_admin_exists(): void
    {
        User::factory()->create(['role' => 'staff']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kein Administrator');

        $this->seed(MarketingStudioSeeder::class);
    }
}
