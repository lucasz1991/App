<?php

namespace Tests\Feature;

use App\Livewire\Tools\FilePools\FilePreviewModal;
use App\Models\File;
use App\Models\FileFolder;
use App\Models\FilePool;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class FilePreviewFullscreenTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
        Storage::fake('private');
        Storage::fake('public');

        Schema::create('file_folders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('file_pool_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->json('permissions')->nullable();
            $table->date('visible_from')->nullable();
            $table->date('visible_until')->nullable();
            $table->boolean('auto_delete')->default(false);
            $table->json('visible_teams')->nullable();
            $table->timestamps();
        });
    }

    public function test_viewer_uses_the_shared_fullscreen_surface_and_secure_media_contracts(): void
    {
        $preview = file_get_contents(resource_path('views/livewire/tools/file-pools/file-preview-modal.blade.php'));
        $fullscreen = file_get_contents(resource_path('views/components/ui/fullscreen-modal.blade.php'));
        $styles = file_get_contents(resource_path('css/file-preview.css'));

        $this->assertStringContainsString('<x-ui.fullscreen-modal', $preview);
        $this->assertStringContainsString(':show-default-close="false"', $preview);
        $this->assertSame(1, substr_count($preview, 'data-file-preview-close'));
        $this->assertStringContainsString('wire:key="file-preview-stage-', $preview);
        $this->assertStringContainsString('sandbox=""', $preview);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $preview);
        $this->assertStringNotContainsString('allow-scripts', $preview);
        $this->assertStringNotContainsString('allow-forms', $preview);
        $this->assertStringNotContainsString('allow-popups', $preview);
        $this->assertStringNotContainsString('allow-top-navigation', $preview);
        $this->assertStringContainsString("'showDefaultClose' => true", $fullscreen);
        $this->assertStringContainsString('@if ($showDefaultClose)', $fullscreen);
        $this->assertStringContainsString('backdrop-filter: blur(30px)', $styles);
        $this->assertStringContainsString('env(safe-area-inset-top)', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
    }

    public function test_view_permission_does_not_expose_or_execute_download_action(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $team = Team::create([
            'user_id' => $staff->id,
            'name' => 'Disposition',
            'personal_team' => false,
        ]);
        $pool = $this->companyPool();
        $folder = FileFolder::create([
            'file_pool_id' => $pool->id,
            'name' => 'Nur ansehen',
            'visible_teams' => [$team->id],
            'permissions' => [
                (string) $team->id => ['view' => true, 'download' => false, 'delete' => false],
            ],
        ]);
        $file = $this->file($pool, $staff, [
            'folder_id' => $folder->id,
            'visible_teams' => [$team->id],
        ]);

        $component = Livewire::actingAs($staff)
            ->test(FilePreviewModal::class)
            ->call('openWith', $file->id)
            ->assertSet('open', true)
            ->assertSeeHtml('data-file-preview-action="open"')
            ->assertDontSeeHtml('data-file-preview-action="download"');

        $component
            ->call('downloadFile', $file->id)
            ->assertForbidden();
    }

    public function test_close_clears_file_identity_and_reopening_uses_a_real_version_key(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = $this->file($this->companyPool(), $admin, ['mime_type' => 'image/png']);

        Livewire::actingAs($admin)
            ->test(FilePreviewModal::class)
            ->call('openWith', $file->id)
            ->assertSet('open', true)
            ->assertSet('fileId', $file->id)
            ->assertSeeHtml('wire:key="file-preview-stage-'.$file->id.'-'.$file->updated_at->getTimestamp().'"')
            ->call('close')
            ->assertSet('open', false)
            ->assertSet('fileId', null)
            ->assertSet('file', null);
    }

    public function test_html_and_text_files_render_only_in_a_strict_sandbox(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = $this->file($this->companyPool(), $admin, [
            'name' => 'hinweis.html',
            'mime_type' => 'text/html',
        ]);

        Livewire::actingAs($admin)
            ->test(FilePreviewModal::class)
            ->call('openWith', $file->id)
            ->assertSeeHtml('data-file-preview-sandboxed')
            ->assertSeeHtml('sandbox=""')
            ->assertDontSeeHtml('allow-scripts')
            ->assertDontSeeHtml('allow-forms');
    }

    private function companyPool(): FilePool
    {
        return FilePool::create([
            'filepoolable_type' => 'company',
            'filepoolable_id' => 0,
            'title' => 'Firmendateien',
            'type' => 'company',
            'description' => '',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function file(FilePool $pool, User $owner, array $attributes = []): File
    {
        $path = 'uploads/files/'.uniqid('preview-', true).'.dat';
        Storage::disk('private')->put($path, '<strong>Vorschau</strong>');

        return $pool->files()->create(array_merge([
            'filepool_id' => $pool->id,
            'user_id' => $owner->id,
            'name' => 'vorschau.txt',
            'path' => $path,
            'disk' => 'private',
            'mime_type' => 'text/plain',
            'type' => 'default',
            'size' => 26,
        ], $attributes));
    }
}
