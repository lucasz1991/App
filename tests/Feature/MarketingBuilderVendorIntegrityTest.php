<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeType;
use App\Livewire\Admin\Marketing\CreativesIndex;
use App\Models\MarketingCreative;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MarketingBuilderVendorIntegrityTest extends TestCase
{
    use DatabaseMigrations;

    /** @var array<string, string> */
    private const EXPECTED_SHA256 = [
        // Commit d0bb4374 intentionally renamed the bundled GrapesJS entry
        // points inside this adapter. Keep the integrity pin on those exact
        // approved bytes instead of the pre-rename adapter hash.
        'lmz-builder.js' => '75D0FAE92553C44A19493A4E04DD5C45D871EBFF3D7CFB2E8EA8E426AD8FEC48',
        'lmz-builder-core.js' => '1511DA9E52323A75F7329645778749FEAA11245BBADB93C8292A03C080283048',
        'lmz-builder.css' => '62C9335D4B7416F1CF3FDB4DE52A80F2F92CD888322323CDC028AF1A3C5C8E70',
        'lmz-builder-core.css' => '2DAA348D7C55F4E9DB7A3C7FD775AE4DFD651FFF56498559E569E4AF249C954A',
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

    public function test_marketing_index_exposes_the_file_library_without_pagebuilder_contracts(): void
    {
        $index = file_get_contents(resource_path('views/livewire/admin/marketing/creatives-index.blade.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/admin-sidebar.blade.php'));

        $this->assertIsString($index);
        $this->assertIsString($sidebar);

        $this->assertStringContainsString('wire:click="openCreateMotive"', $index);
        $this->assertStringContainsString('wire:model="motiveTitle"', $index);
        $this->assertStringContainsString('wire:model="motiveType"', $index);
        $this->assertStringContainsString('wire:model.live="createMotiveOpen"', $index);
        $this->assertStringContainsString('<x-ui.filepool.drop-zone', $index);
        $this->assertStringContainsString('model="motiveUploads"', $index);
        $this->assertStringContainsString(':max-files="20"', $index);
        $this->assertStringContainsString(':max-filesize="50"', $index);
        $this->assertStringContainsString("route('admin.marketing.creatives.files'", $index);
        $this->assertStringContainsString('data-marketing-motive-card', $index);
        $this->assertStringContainsString('wire:click="deleteMotive(', $index);
        $this->assertStringNotContainsString('x-ui.page-builder.preview-card', $index);
        $this->assertStringNotContainsString('admin.marketing.creatives.preview', $index);
        $this->assertStringNotContainsString('admin.marketing.creatives.editor', $index);
        $this->assertStringNotContainsString('saveMediaFolder', $index);
        $this->assertStringNotContainsString('importOpen', $index);
        $this->assertStringNotContainsString('<iframe', $index);
        $this->assertStringContainsString("route('admin.marketing.creatives.index')", $sidebar);
        $this->assertStringNotContainsString('admin.marketing.assets', $sidebar);
        $this->assertStringNotContainsString('>Medien<', preg_replace('/\s+/', '', $sidebar) ?: $sidebar);
        $this->assertFileDoesNotExist(resource_path('views/livewire/admin/marketing/assets-index.blade.php'));
        $this->assertFileDoesNotExist(app_path('Livewire/Admin/Marketing/AssetsIndex.php'));
    }

    public function test_blade_directives_do_not_leak_from_component_attributes_into_browser_markup(): void
    {
        $views = [
            'livewire/admin/marketing/creatives-index.blade.php',
            'components/chat/reaction-dropdown.blade.php',
            'livewire/operations/partials/wagon-sheet-grid.blade.php',
        ];

        foreach ($views as $view) {
            $source = file_get_contents(resource_path('views/'.$view));

            $this->assertIsString($source, $view);
            $this->assertStringNotContainsString('@js(', Blade::compileString($source), $view);
        }
    }

    public function test_knowledge_summary_textarea_is_compiled_as_a_blade_component(): void
    {
        $view = 'livewire/admin/assistant-knowledge-manager.blade.php';
        $source = file_get_contents(resource_path('views/'.$view));

        $this->assertIsString($source);
        $this->assertStringNotContainsString(
            '<x-ui.forms.textarea',
            Blade::compileString($source),
            $view,
        );
    }

    public function test_admin_can_create_a_file_motive_and_staff_is_denied_from_both_library_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        Storage::fake('private');
        Storage::fake('public');

        $component = Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->assertSee('Motiv anlegen')
            ->call('openCreateMotive')
            ->assertSet('createMotiveOpen', true)
            ->set('motiveTitle', 'Deutschlandkarte · Herbstkampagne')
            ->set('motiveType', MarketingCreativeType::Info->value)
            ->set('motiveUploads', [UploadedFile::fake()->image('deutschlandkarte.png', 1080, 1080)])
            ->call('createMotive')
            ->assertHasNoErrors();

        $creative = MarketingCreative::query()
            ->where('title', 'Deutschlandkarte · Herbstkampagne')
            ->firstOrFail();
        $component->assertRedirect(route('admin.marketing.creatives.files', ['creative' => $creative]));

        $this->assertSame(MarketingCreativeType::Info, $creative->type);
        $this->assertSame(1, $creative->filePool()->firstOrFail()->files()->count());

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk()
            ->assertSee('Marketing-Motive')
            ->assertSee($creative->title)
            ->assertSee('Informationsmotiv')
            ->assertSee('1 Datei')
            ->assertSee('Dateien verwalten')
            ->assertSee(route('admin.marketing.creatives.files', ['creative' => $creative]), false)
            ->assertDontSee('data-marketing-editor-root', false)
            ->assertDontSee('Motivpaket importieren')
            ->assertDontSee('Story 9:16');

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.files', ['creative' => $creative]))
            ->assertOk()
            ->assertSee($creative->title);

        $this->actingAs($staff)
            ->get(route('admin.marketing.creatives.index'))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('admin.marketing.creatives.files', ['creative' => $creative]))
            ->assertForbidden();

        Livewire::actingAs($staff)
            ->test(CreativesIndex::class)
            ->assertForbidden();
    }

    public function test_create_modal_requires_a_title_type_and_at_least_one_file(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->call('openCreateMotive')
            ->set('motiveTitle', '')
            ->set('motiveType', 'ungueltig')
            ->set('motiveUploads', [])
            ->call('createMotive')
            ->assertHasErrors(['motiveTitle', 'motiveType', 'motiveUploads'])
            ->assertSet('createMotiveOpen', true);

        $this->assertDatabaseCount('marketing_creatives', 0);
    }

    public function test_create_action_rechecks_admin_role_after_the_component_was_mounted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(CreativesIndex::class);

        $admin->forceFill(['role' => 'staff'])->save();

        $component
            ->call('openCreateMotive')
            ->assertForbidden();

        $this->assertDatabaseCount('marketing_creatives', 0);
    }
}
