<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeType;
use App\Enums\MarketingRenderStatus;
use App\Livewire\Admin\Marketing\CreativesIndex;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use App\Services\Marketing\MarketingFileSourceService;
use App\Services\Marketing\MarketingRenderService;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use Database\Seeders\MarketingStudioSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class MarketingCreativeDeletionTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('private');
        config()->set('marketing.disk', 'private');
    }

    public function test_admin_can_remove_a_creative_from_the_index_without_leaving_active_variants_or_renders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $variantIds = $creative->variants->modelKeys();
        $render = app(MarketingRenderService::class)->queue(
            $creative,
            MarketingCreativeFormat::Post,
            $admin,
        );
        $creative->variants->first()->forceFill([
            'html' => '<img src="/administrator/marketing/dateien/123?v=12345678" alt="Test">',
        ])->save();
        $references = new \ReflectionMethod(MarketingFileSourceService::class, 'allReferencedFileIds');
        $this->assertSame([123], $references->invoke(app(MarketingFileSourceService::class)));
        $renderPath = 'marketing/renders/deletion-test.png';
        Storage::disk('private')->put($renderPath, 'private-render');
        $render->forceFill([
            'status' => MarketingRenderStatus::Completed,
            'path' => $renderPath,
            'mime_type' => 'image/png',
            'rendered_at' => now(),
        ])->save();

        Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->assertSee($creative->title)
            ->assertSee('Motiv entfernen')
            ->call('deleteCreative', $creative->public_id)
            ->assertDispatched('swal:toast')
            ->assertDontSee($creative->title);

        $this->assertSoftDeleted('marketing_creatives', ['id' => $creative->id]);
        foreach ($variantIds as $variantId) {
            $this->assertSoftDeleted('marketing_creative_variants', ['id' => $variantId]);
        }
        $this->assertSame(0, MarketingCreativeVariant::query()->whereIn('id', $variantIds)->count());
        $this->assertSame(
            count($variantIds),
            MarketingCreativeVariant::withTrashed()->whereIn('id', $variantIds)->count(),
        );
        $this->assertSame([], $references->invoke(app(MarketingFileSourceService::class)));

        $invalidated = $render->fresh();
        $this->assertSame(MarketingRenderStatus::Failed, $invalidated->status);
        $this->assertNull($invalidated->path);
        $this->assertNull($invalidated->mime_type);
        $this->assertNull($invalidated->rendered_at);
        $this->assertSame('Das Motiv wurde entfernt. Der Export ist nicht mehr abrufbar.', $invalidated->error);
        Storage::disk('private')->assertMissing($renderPath);

        $activity = Activity::query()
            ->where('log_name', 'marketing')
            ->where('description', 'marketing_creative_deleted')
            ->sole();
        $this->assertSame('deleted', $activity->event);
        $this->assertSame($creative->id, $activity->subject_id);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame($creative->public_id, $activity->properties->get('public_id'));
        $this->assertSame($variantIds, $activity->properties->get('variant_ids'));
        $this->assertSame([$render->id], $activity->properties->get('render_ids'));
    }

    public function test_delete_route_is_admin_only_and_every_deleted_runtime_route_fails_closed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Info,
            $admin,
        );
        $render = app(MarketingRenderService::class)->queue(
            $creative,
            MarketingCreativeFormat::Web,
            $admin,
        );

        $index = $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk();
        $index->assertSee('aus dem Marketing-Studio entfernen', escape: false);
        $this->assertFalse(Route::has('admin.marketing.creatives.restore'));

        $this->actingAs($staff)
            ->deleteJson(route('admin.marketing.creatives.destroy', $creative))
            ->assertForbidden();
        $this->assertNotSoftDeleted('marketing_creatives', ['id' => $creative->id]);

        $this->actingAs($admin)
            ->deleteJson(route('admin.marketing.creatives.destroy', $creative))
            ->assertNoContent();

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk()
            ->assertDontSee($creative->title);
        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.editor', $creative))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.preview', [$creative, 'post']))
            ->assertNotFound();
        $this->actingAs($admin)
            ->patchJson(route('admin.marketing.creatives.update', $creative), [])
            ->assertNotFound();
        $this->actingAs($admin)
            ->putJson(route('admin.marketing.variants.update', [$creative, 'story']), [])
            ->assertNotFound();
        $this->actingAs($admin)
            ->postJson(route('admin.marketing.renders.store', $creative), ['format' => 'web'])
            ->assertNotFound();
        $this->actingAs($admin)
            ->getJson(route('admin.marketing.renders.show', $render))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get(route('admin.marketing.renders.download', $render))
            ->assertNotFound();
        $this->actingAs($admin)
            ->deleteJson(route('admin.marketing.creatives.destroy', $creative))
            ->assertNotFound();
    }

    public function test_seeder_does_not_recreate_or_restore_a_removed_starter_creative(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $studio->delete($creative, $admin);

        $this->seed(MarketingStudioSeeder::class);

        $deletedStarter = MarketingCreative::withTrashed()
            ->where('shared_content->template_key', MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER)
            ->sole();
        $this->assertTrue($deletedStarter->trashed());
        $this->assertSame(0, MarketingCreative::query()
            ->where('shared_content->template_key', MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER)
            ->count());
        $this->assertSame(1, MarketingCreative::withTrashed()
            ->where('shared_content->template_key', MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER)
            ->count());
        $this->assertSame(1, MarketingCreative::query()
            ->where('shared_content->template_key', MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE)
            ->count());
        $this->assertSame(3, $deletedStarter->variants()->withTrashed()->count());
        $this->assertSame(0, $deletedStarter->variants()->count());
    }

    public function test_variant_soft_delete_migration_is_reversible_and_idempotent(): void
    {
        $migration = require database_path('migrations/2026_08_12_010000_add_soft_deletes_to_marketing_creative_variants_table.php');

        $this->assertTrue(Schema::hasColumn('marketing_creative_variants', 'deleted_at'));
        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('marketing_creative_variants', 'deleted_at'));
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('marketing_creative_variants', 'deleted_at'));
    }
}
