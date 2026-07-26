<?php

namespace Tests\Feature;

use App\Livewire\Tools\FilePools\FilePreviewModal;
use App\Livewire\Tools\FilePools\ManageFilePools;
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

class FilePoolExplorerMoveTest extends TestCase
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
                'allowTeamPermissions' => true,
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
                $team->id => ['view' => true, 'download' => true, 'delete' => false],
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

    public function test_folder_permissions_are_saved_per_team(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $team = $this->team($admin, 'Betrieb');
        $pool = $this->companyPool();
        $folder = $this->folder($pool, 'Dienstanweisungen');

        Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
                'allowTeamPermissions' => true,
            ])
            ->call('openPermissions', $folder->id)
            ->set("folderPermissions.{$team->id}.view", true)
            ->set("folderPermissions.{$team->id}.download", true)
            ->set("folderPermissions.{$team->id}.delete", false)
            ->call('savePermissions')
            ->assertDispatched('swal:toast');

        $this->assertSame([
            (string) $team->id => [
                'view' => true,
                'download' => true,
                'delete' => false,
            ],
        ], $folder->fresh()->permissions);
    }

    public function test_global_preview_uses_team_membership_instead_of_legacy_role_share(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['role' => 'user']);
        $outsider = User::factory()->create(['role' => 'user']);
        $team = $this->team($owner, 'Disposition', $member);
        $pool = $this->companyPool();
        $file = $this->file($pool, $owner, [
            'shared_roles' => ['user'],
            'visible_teams' => [$team->id],
        ]);

        Livewire::actingAs($member->fresh())
            ->test(FilePreviewModal::class)
            ->call('openWith', $file->id)
            ->assertSet('open', true);

        Livewire::actingAs($outsider)
            ->test(FilePreviewModal::class)
            ->call('openWith', $file->id)
            ->assertForbidden();

        $legacyRoleOnlyFile = $this->file($pool, $owner, [
            'name' => 'alte-rollenfreigabe.txt',
            'shared_roles' => ['user'],
            'visible_teams' => [],
        ]);

        Livewire::actingAs($member->fresh())
            ->test(FilePreviewModal::class)
            ->call('openWith', $legacyRoleOnlyFile->id)
            ->assertForbidden();
    }

    public function test_company_root_file_requires_at_least_one_team_when_saved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $team = $this->team($admin, 'Leitstelle');
        $pool = $this->companyPool();
        $file = $this->file($pool, $admin, ['shared_roles' => ['staff']]);

        Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
                'allowTeamPermissions' => true,
            ])
            ->call('editFile', $file->id)
            ->call('safeFile')
            ->assertHasErrors('selectedFileVisibleTeams')
            ->set('selectedFileVisibleTeams', [$team->id])
            ->call('safeFile')
            ->assertHasNoErrors();

        $this->assertSame([$team->id], $file->fresh()->visible_teams);
        $this->assertNull($file->fresh()->shared_roles);
    }

    public function test_folder_team_matrix_separates_preview_and_download_permission(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['role' => 'user']);
        $team = $this->team($owner, 'Werkstatt', $member);
        $pool = $this->companyPool();
        $folder = FileFolder::create([
            'file_pool_id' => $pool->id,
            'name' => 'Nur Vorschau',
            'permissions' => [
                $team->id => ['view' => true, 'download' => false, 'delete' => false],
            ],
        ]);
        $file = $this->file($pool, $owner, ['folder_id' => $folder->id]);

        Livewire::actingAs($member->fresh())
            ->test(FilePreviewModal::class)
            ->call('openWith', $file->id)
            ->assertSet('open', true)
            ->call('downloadFile', $file->id)
            ->assertForbidden();
    }

    public function test_explorer_markup_exposes_square_cards_separated_actions_and_native_drop_targets(): void
    {
        $card = file_get_contents(resource_path('views/components/ui/filepool/file-card.blade.php'));
        $explorer = file_get_contents(resource_path('views/livewire/tools/file-pools/manage-file-pools.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('relative aspect-square overflow-hidden', $card);
        $this->assertStringContainsString('rt-file-card-action-overlay absolute inset-0', $card);
        $this->assertStringContainsString('rt-file-card-menu-trigger', $card);
        $this->assertStringContainsString('<x-dropdown-link wire:click.prevent="editFile', $card);
        $this->assertStringContainsString('role="toolbar"', $card);
        $this->assertStringContainsString('focus-within:ring-2', $card);
        $this->assertStringContainsString('@dragstart="startFileDrag', $card);
        $this->assertStringNotContainsString('$file->shared_roles', $explorer);
        $this->assertStringContainsString('folderPermissions.\'.$team->id', $explorer);
        $this->assertStringContainsString('<x-ui.forms.toggle-button', $explorer);
        $this->assertStringContainsString('application/x-railtime-file', $explorer);
        $this->assertStringContainsString('@drop.prevent.stop="dropFile', $explorer);
        $this->assertStringContainsString('rt-file-drop-breadcrumb', $explorer);
        $this->assertStringContainsString('.rt-file-drop-folder[data-drop-active="true"]', $styles);
        $this->assertStringContainsString('.rt-file-card-menu-trigger', $styles);
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function file(FilePool $pool, User $owner, array $attributes = []): File
    {
        Storage::disk('private')->put('uploads/files/fahrplan.txt', 'Fahrplan');

        return $pool->files()->create(array_merge([
            'filepool_id' => $pool->id,
            'user_id' => $owner->id,
            'name' => 'fahrplan.txt',
            'path' => 'uploads/files/fahrplan.txt',
            'disk' => 'private',
            'mime_type' => 'text/plain',
            'type' => 'default',
            'size' => 42,
        ], $attributes));
    }

    private function team(User $owner, string $name, ?User $member = null): Team
    {
        $team = new Team([
            'name' => $name,
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $team->user_id = $owner->id;
        $team->save();

        if ($member) {
            $team->users()->attach($member, ['role' => 'member']);
            $member->forceFill(['current_team_id' => $team->id])->save();
        }

        return $team;
    }
}
