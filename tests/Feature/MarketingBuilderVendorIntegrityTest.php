<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeType;
use App\Livewire\Admin\Marketing\CreativesIndex;
use App\Models\FileFolder;
use App\Models\FilePool;
use App\Models\User;
use App\Services\Marketing\MarketingFileSourceService;
use App\Services\Marketing\MarketingStudioService;
use App\Support\MarketingFileSourceSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
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

    public function test_marketing_views_keep_the_builder_and_read_only_file_source_contracts_visible(): void
    {
        $editor = file_get_contents(resource_path('views/livewire/admin/marketing/creative-editor.blade.php'));
        $index = file_get_contents(resource_path('views/livewire/admin/marketing/creatives-index.blade.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/admin-sidebar.blade.php'));
        $adapter = file_get_contents(resource_path('js/marketing-studio.js'));

        $this->assertIsString($editor);
        $this->assertIsString($index);
        $this->assertIsString($sidebar);
        $this->assertIsString($adapter);

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
        $this->assertStringContainsString('data-marketing-media-source', $editor);

        $this->assertStringContainsString('wire:submit="saveMediaFolder"', $index);
        $this->assertStringContainsString('Grundverzeichnis (alle Ordner)', $index);
        $this->assertStringContainsString('data-marketing-media-source-invalid', $index);
        $this->assertStringContainsString('href="{{ $mediaFilesUrl }}"', $index);
        $this->assertStringContainsString("route('admin.marketing.creatives.index')", $sidebar);
        $this->assertStringNotContainsString('admin.marketing.assets', $sidebar);
        $this->assertStringNotContainsString('>Medien<', preg_replace('/\s+/', '', $sidebar) ?: $sidebar);
        $this->assertStringNotContainsString('assetUpload', $adapter);
        $this->assertStringNotContainsString('onUpload:', $adapter);
        $this->assertFileDoesNotExist(resource_path('views/livewire/admin/marketing/assets-index.blade.php'));
        $this->assertFileDoesNotExist(app_path('Livewire/Admin/Marketing/AssetsIndex.php'));
    }

    public function test_real_admin_pages_render_file_pool_images_while_staff_is_denied(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        Storage::fake('private');
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=', true);
        $this->assertIsString($image);
        Storage::disk('private')->put('uploads/files/railtime-einsatz.png', $image);

        $pool = FilePool::company();
        $folder = FileFolder::query()->create([
            'file_pool_id' => $pool->id,
            'name' => 'Marketing / Einsatzbilder',
        ]);
        $file = $pool->files()->create([
            'folder_id' => $folder->id,
            'user_id' => $admin->id,
            'name' => 'railtime-einsatz.png',
            'disk' => 'private',
            'path' => 'uploads/files/railtime-einsatz.png',
            'mime_type' => 'image/png',
            'size' => strlen($image),
        ]);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Job,
            $admin,
        );

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk()
            ->assertSee('Marketing-Motive')
            ->assertSee($creative->title)
            ->assertSee('Firmendateien / Grundverzeichnis')
            ->assertSee('1 Bild im Editor verfügbar');

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.editor', $creative))
            ->assertOk()
            ->assertSee('data-marketing-editor-root', false)
            ->assertSee('data-mobile-pane="layout"', false)
            ->assertSee('Feste Exportfläche')
            ->assertSee('1080 × 1920')
            ->assertSee('Bildquelle:')
            ->assertSee(route('admin.marketing.files.show', $file), false);

        $this->actingAs($admin)
            ->get(route('admin.marketing.files.show', $file))
            ->assertOk();

        $this->actingAs($staff)
            ->get(route('admin.marketing.creatives.index'))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('admin.marketing.files.show', $file))
            ->assertForbidden();

        Livewire::actingAs($staff)
            ->test(CreativesIndex::class)
            ->assertForbidden();
    }

    public function test_admin_explicitly_saves_a_file_folder_and_invalid_stored_source_fails_closed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $folder = FileFolder::query()->create([
            'file_pool_id' => $pool->id,
            'name' => 'Freigegebene Motive',
        ]);

        Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->set('mediaFolderId', (string) $folder->id)
            ->call('saveMediaFolder')
            ->assertHasNoErrors()
            ->assertSet('mediaFolderId', (string) $folder->id);

        $this->assertSame(
            $folder->id,
            app(MarketingFileSourceService::class)->selectedFolderId(uncached: true),
        );

        MarketingFileSourceSettings::setSelectedFolderId(999999);

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk()
            ->assertSee('data-marketing-media-source-invalid', false)
            ->assertSee('Die gespeicherte Bildquelle ist nicht mehr verfügbar')
            ->assertSee('0 Bilder im Editor verfügbar');
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
