<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeType;
use App\Livewire\Admin\Marketing\CreativesIndex;
use App\Models\MarketingAsset;
use App\Models\User;
use App\Services\Marketing\MarketingStudioService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

class MarketingBuilderVendorIntegrityTest extends TestCase
{
    use DatabaseMigrations;

    /** @var array<string, string> */
    private const EXPECTED_SHA256 = [
        'lmz-builder.js' => 'EF398B4F114D123B35F88103E7AFFC29ABA534DA738F6ADCA0B866E3946DA53E',
        'grapesjs.js' => '1511DA9E52323A75F7329645778749FEAA11245BBADB93C8292A03C080283048',
        'lmz-builder.css' => '62C9335D4B7416F1CF3FDB4DE52A80F2F92CD888322323CDC028AF1A3C5C8E70',
        'grapesjs.css' => '2DAA348D7C55F4E9DB7A3C7FD775AE4DFD651FFF56498559E569E4AF249C954A',
    ];

    public function test_versioned_builder_runtime_matches_the_approved_joomla_245_assets(): void
    {
        $target = public_path('vendor/lmz-builder/2.4.5');

        foreach (self::EXPECTED_SHA256 as $file => $expectedHash) {
            $path = $target.DIRECTORY_SEPARATOR.$file;
            $this->assertFileExists($path);
            $this->assertSame($expectedHash, strtoupper(hash_file('sha256', $path) ?: ''), $file);
        }

        $notice = file_get_contents($target.DIRECTORY_SEPARATOR.'THIRD_PARTY_NOTICES.md');
        $this->assertIsString($notice);
        $this->assertStringContainsString('GrapesJS 0.22.14', $notice);
        $this->assertStringContainsString('media/com_lmzpagebuilder/{js,css}', $notice);
        $this->assertStringContainsString('do not depend on GrapesJS Studio SDK', $notice);
    }

    public function test_marketing_views_keep_the_builder_and_navigation_contracts_visible(): void
    {
        $editor = file_get_contents(resource_path('views/livewire/admin/marketing/creative-editor.blade.php'));
        $assets = file_get_contents(resource_path('views/livewire/admin/marketing/assets-index.blade.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/admin-sidebar.blade.php'));

        $this->assertIsString($editor);
        $this->assertIsString($assets);
        $this->assertIsString($sidebar);

        $this->assertStringContainsString('data-marketing-editor-root', $editor);
        $this->assertStringContainsString('wire:ignore', $editor);
        $this->assertStringContainsString("'story' => ['Story', '1080 × 1920']", $editor);
        $this->assertStringContainsString("'post' => ['Post', '1080 × 1080']", $editor);
        $this->assertStringContainsString("'web' => ['Web', '1200 × 630']", $editor);
        $this->assertStringContainsString('data-marketing-safe-zone', $editor);
        $this->assertStringContainsString('data-marketing-export', $editor);
        $this->assertStringContainsString('data-mobile-pane="layout"', $editor);
        $this->assertStringContainsString("mobilePane = 'layout'", $editor);
        $this->assertStringContainsString('marketing-editor:viewport-change', $editor);
        $this->assertStringContainsString('data-marketing-artboard-label', $editor);
        $this->assertStringContainsString('data-marketing-scale-label', $editor);
        $this->assertStringContainsString('data-marketing-pan-hint', $editor);

        $this->assertStringContainsString('JPEG, PNG, WebP oder GIF · maximal 8 MB', $assets);
        $this->assertStringContainsString('x-on:change="replace(', $assets);
        $this->assertStringContainsString("route('admin.marketing.creatives.index')", $sidebar);
        $this->assertStringContainsString("route('admin.marketing.assets.index')", $sidebar);
    }

    public function test_real_admin_pages_render_while_staff_is_denied(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $asset = MarketingAsset::query()->create([
            'original_name' => 'railtime-einsatz.jpg',
            'disk' => 'private',
            'path' => 'marketing/assets/railtime-einsatz.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size' => 123456,
            'width' => 1600,
            'height' => 900,
            'sha256' => '0123456789abcdef'.str_repeat('0', 48),
            'created_by' => $admin->id,
        ]);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Job,
            $admin,
        );

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk()
            ->assertSee('Marketing-Motive')
            ->assertSee($creative->title);

        $this->actingAs($admin)
            ->get(route('admin.marketing.assets.index'))
            ->assertOk()
            ->assertSee('Marketing-Medien')
            ->assertSee('maximal 8 MB')
            ->assertSee(route('admin.marketing.assets.show', $asset).'?v=0123456789abcdef', false);

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.editor', $creative))
            ->assertOk()
            ->assertSee('data-marketing-editor-root', false)
            ->assertSee('data-mobile-pane="layout"', false)
            ->assertSee('Feste Exportfläche')
            ->assertSee('1080 × 1920')
            ->assertSee(route('admin.marketing.assets.show', $asset).'?v=0123456789abcdef', false);

        $this->actingAs($staff)
            ->get(route('admin.marketing.creatives.index'))
            ->assertForbidden();

        Livewire::actingAs($staff)
            ->test(CreativesIndex::class)
            ->assertForbidden();
    }

    public function test_create_action_rechecks_admin_role_after_the_component_was_mounted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(CreativesIndex::class);

        $admin->forceFill(['role' => 'staff'])->save();

        $component
            ->call('create', MarketingCreativeType::Job->value)
            ->assertForbidden();

        $this->assertDatabaseCount('marketing_creatives', 0);
    }
}
