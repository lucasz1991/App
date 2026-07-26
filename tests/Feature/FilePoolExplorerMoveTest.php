<?php

namespace Tests\Feature;

use App\Livewire\Tools\FilePools\ManageFilePools;
use App\Models\File;
use App\Models\FileFolder;
use App\Models\FilePool;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class FilePoolExplorerMoveTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();

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

    public function test_admin_can_move_a_file_to_a_visible_folder_and_back_to_a_breadcrumb(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = $this->companyPool();
        $folder = $this->folder($pool, 'Unterlagen');
        $file = $this->file($pool, $admin);

        Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
                'allowRoleSharing' => true,
            ])
            ->call('moveFile', $file->id, $folder->id)
            ->assertDispatched('swal:toast')
            ->call('enterFolder', $folder->id)
            ->call('moveFile', $file->id, null)
            ->assertDispatched('swal:toast');

        $this->assertNull($file->fresh()->folder_id);
    }

    public function test_move_rejects_a_folder_from_another_pool(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sourcePool = $this->companyPool();
        $otherPool = FilePool::create([
            'filepoolable_type' => 'company-test',
            'filepoolable_id' => 1,
            'title' => 'Anderer Pool',
            'type' => 'company',
            'description' => '',
        ]);
        $foreignFolder = $this->folder($otherPool, 'Fremd');
        $file = $this->file($sourcePool, $admin);

        Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $sourcePool->id,
                'readOnly' => false,
            ])
            ->call('moveFile', $file->id, $foreignFolder->id)
            ->assertForbidden();

        $this->assertNull($file->fresh()->folder_id);
    }

    public function test_user_without_pool_permission_cannot_move_a_file_even_with_mutable_props(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $pool = $this->companyPool();
        $folder = $this->folder($pool, 'Intern');
        $file = $this->file($pool, $admin);

        Livewire::actingAs($staff)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
            ])
            ->call('moveFile', $file->id, $folder->id)
            ->assertForbidden();

        $this->assertNull($file->fresh()->folder_id);
    }

    public function test_team_member_cannot_move_into_folder_without_folder_permission(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $team = new Team([
            'name' => 'Mitarbeiter',
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $team->user_id = $staff->id;
        $team->save();
        $team->users()->attach($staff, ['role' => 'member']);
        $staff->forceFill(['current_team_id' => $team->id])->save();

        $pool = FilePool::create([
            'filepoolable_type' => Team::class,
            'filepoolable_id' => $team->id,
            'title' => 'Teamdateien',
            'type' => Team::class,
            'description' => '',
        ]);
        $folder = FileFolder::create([
            'file_pool_id' => $pool->id,
            'name' => 'Gesperrt',
            'permissions' => [
                'staff' => ['view' => true, 'download' => true, 'delete' => false],
            ],
        ]);
        $file = $this->file($pool, $staff);

        Livewire::actingAs($staff->fresh())
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
            ])
            ->call('moveFile', $file->id, $folder->id)
            ->assertForbidden();

        $this->assertNull($file->fresh()->folder_id);
    }

    public function test_explorer_markup_exposes_full_card_actions_and_native_drop_targets(): void
    {
        $card = file_get_contents(resource_path('views/components/ui/filepool/file-card.blade.php'));
        $explorer = file_get_contents(resource_path('views/livewire/tools/file-pools/manage-file-pools.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('rt-file-card-action-overlay absolute inset-0', $card);
        $this->assertStringNotContainsString('z-20 flex', $card);
        $this->assertStringContainsString('role="toolbar"', $card);
        $this->assertStringContainsString('focus-within:ring-2', $card);
        $this->assertStringContainsString('@dragstart="startFileDrag', $card);
        $this->assertStringContainsString('application/x-railtime-file', $explorer);
        $this->assertStringContainsString('@drop.prevent.stop="dropFile', $explorer);
        $this->assertStringContainsString('rt-file-drop-breadcrumb', $explorer);
        $this->assertStringContainsString('.rt-file-drop-folder[data-drop-active="true"]', $styles);
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

    private function folder(FilePool $pool, string $name): FileFolder
    {
        return FileFolder::create([
            'file_pool_id' => $pool->id,
            'name' => $name,
        ]);
    }

    private function file(FilePool $pool, User $owner): File
    {
        return $pool->files()->create([
            'filepool_id' => $pool->id,
            'user_id' => $owner->id,
            'name' => 'fahrplan.txt',
            'path' => 'uploads/files/fahrplan.txt',
            'disk' => 'private',
            'mime_type' => 'text/plain',
            'type' => 'default',
            'size' => 42,
        ]);
    }
}
