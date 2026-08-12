<?php

namespace Tests\Feature;

use App\Livewire\Tools\FilePools\ManageFilePools;
use App\Models\File;
use App\Models\FileFolder;
use App\Models\FilePool;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Livewire;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class FilePoolUploadWorkflowTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
        Storage::fake('private');

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

    public function test_upload_target_state_is_locked(): void
    {
        foreach (['uploadFolderId', 'uploadTargetReady', 'uploadTargetPath'] as $property) {
            $attributes = (new ReflectionProperty(ManageFilePools::class, $property))
                ->getAttributes(Locked::class);

            $this->assertCount(1, $attributes, "$property muss serverseitig gesperrt sein.");
        }
    }

    public function test_opening_upload_freezes_the_authorized_folder_and_ignores_client_pool_and_navigation_changes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = $this->companyPool();
        $foreignPool = $this->companyPool('Fremder Pool', 1);
        $parent = $this->folder($pool, 'Personal');
        $target = $this->folder($pool, 'Vertraege', $parent->id);

        Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
                'allowTeamPermissions' => true,
            ])
            ->call('enterFolder', $target->id)
            ->call('openUploadForm')
            ->assertSet('uploadFolderId', $target->id)
            ->assertSet('uploadTargetReady', true)
            ->assertSet('uploadTargetPath', __('app.root_folder').' / Personal / Vertraege')
            ->set("fileUploads.{$pool->id}", [UploadedFile::fake()->create('vertrag.pdf', 512, 'application/pdf')])
            ->set('currentFolderId', null)
            ->call('uploadFile', $foreignPool->id)
            ->assertHasNoErrors()
            ->assertSet('openFileForm', false)
            ->assertSet('uploadTargetReady', false)
            ->assertDispatched('filepool:saved');

        $stored = File::query()->where('name', 'vertrag.pdf')->sole();

        $this->assertSame($pool->id, (int) $stored->fileable_id);
        $this->assertSame($target->id, (int) $stored->folder_id);
        Storage::disk('private')->assertExists($stored->path);
    }

    public function test_company_root_upload_keeps_team_visibility_rule_and_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $team = $this->team($admin, 'Leitstelle');
        $pool = $this->companyPool();
        $expiresAt = now()->addDays(2)->format('Y-m-d');
        $visibleFrom = now()->addDay()->format('Y-m-d');

        $component = Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
                'allowTeamPermissions' => true,
            ])
            ->call('openUploadForm')
            ->set("fileUploads.{$pool->id}", [UploadedFile::fake()->create('dienstplan.txt', 1, 'text/plain')])
            ->set("expires.{$pool->id}", $expiresAt)
            ->set('uploadVisibleFrom', $visibleFrom)
            ->set('uploadAutoDelete', true)
            ->call('uploadFile', $pool->id)
            ->assertHasErrors('uploadVisibleTeams')
            ->assertSet('openFileForm', true)
            ->assertSet('uploadTargetReady', true)
            ->set('uploadVisibleTeams', [$team->id, 999999])
            ->call('uploadFile', $pool->id)
            ->assertHasNoErrors()
            ->assertSet('openFileForm', false);

        $stored = File::query()->where('name', 'dienstplan.txt')->sole();

        $this->assertSame([$team->id], $stored->visible_teams);
        $this->assertTrue($stored->auto_delete);
        $this->assertSame($visibleFrom, $stored->visible_from?->format('Y-m-d'));
        $this->assertSame($expiresAt, $stored->expires_at?->format('Y-m-d'));
        $component->assertDispatched('filepool:saved');
    }

    public function test_upload_requires_writable_pool_context_and_an_open_server_target(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $pool = $this->companyPool();

        Livewire::actingAs($staff)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
            ])
            ->assertViewHas('canUploadFiles', false)
            ->call('openUploadForm')
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
            ])
            ->set("fileUploads.{$pool->id}", [UploadedFile::fake()->create('direkt.txt', 1)])
            ->call('uploadFile', $pool->id)
            ->assertForbidden();

        $this->assertDatabaseCount('files', 0);
    }

    public function test_team_folder_requires_existing_mutation_permission_when_upload_opens_and_saves(): void
    {
        $member = User::factory()->create(['role' => 'staff']);
        $team = $this->team($member, 'Werkstatt');
        $pool = FilePool::create([
            'filepoolable_type' => Team::class,
            'filepoolable_id' => $team->id,
            'title' => 'Teamdateien',
            'type' => Team::class,
            'description' => '',
        ]);
        $folder = FileFolder::create([
            'file_pool_id' => $pool->id,
            'name' => 'Nur Lesen',
            'permissions' => [
                $team->id => ['view' => true, 'download' => true, 'delete' => false],
            ],
        ]);

        Livewire::actingAs($member->fresh())
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
            ])
            ->call('enterFolder', $folder->id)
            ->call('openUploadForm')
            ->assertForbidden();

        $folder->update([
            'permissions' => [
                $team->id => ['view' => true, 'download' => true, 'delete' => true],
            ],
        ]);

        $component = Livewire::actingAs($member->fresh())
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
            ])
            ->call('enterFolder', $folder->id)
            ->call('openUploadForm')
            ->set("fileUploads.{$pool->id}", [UploadedFile::fake()->create('freigegeben.txt', 1)]);

        $folder->update([
            'permissions' => [
                $team->id => ['view' => true, 'download' => true, 'delete' => false],
            ],
        ]);

        $component
            ->call('uploadFile', $pool->id)
            ->assertForbidden();

        $this->assertDatabaseMissing('files', ['name' => 'freigegeben.txt']);
    }

    public function test_deleted_frozen_folder_is_not_silently_replaced_with_root(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = $this->companyPool();
        $folder = $this->folder($pool, 'Kurzfristig');

        $component = Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
            ])
            ->call('enterFolder', $folder->id)
            ->call('openUploadForm')
            ->set("fileUploads.{$pool->id}", [UploadedFile::fake()->create('ziel.txt', 1)]);

        $folder->delete();

        $component
            ->call('uploadFile', $pool->id)
            ->assertNotFound();

        $this->assertDatabaseMissing('files', ['name' => 'ziel.txt']);
    }

    public function test_upload_validation_limits_file_count_and_each_file_to_fifty_megabytes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = $this->companyPool();
        $tooMany = [];

        foreach (range(1, 21) as $index) {
            $tooMany[] = UploadedFile::fake()->create("datei-$index.txt", 1, 'text/plain');
        }

        Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
            ])
            ->call('openUploadForm')
            ->set("fileUploads.{$pool->id}", $tooMany)
            ->call('uploadFile', $pool->id)
            ->assertHasErrors("fileUploads.$pool->id")
            ->assertSet('openFileForm', true);

        Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
            ])
            ->call('openUploadForm')
            ->set("fileUploads.{$pool->id}", [
                UploadedFile::fake()->create('zu-gross.bin', 51201, 'application/octet-stream'),
            ])
            ->call('uploadFile', $pool->id)
            // Livewires globale Temp-Upload-Regel ist ebenfalls 50 MB und
            // weist die Datei bereits am Modellpfad ab, bevor die
            // komponenteneigene Wildcard-Regel laufen muss.
            ->assertHasErrors("fileUploads.$pool->id")
            ->assertSet('openFileForm', true);

        $this->assertDatabaseCount('files', 0);
        $this->assertContains('max:51200', config('livewire.temporary_file_upload.rules'));
    }

    public function test_multi_file_database_failure_rolls_back_models_and_removes_new_blobs_without_closing_draft(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = $this->companyPool();
        $creates = 0;

        File::creating(function () use (&$creates): void {
            $creates++;

            if ($creates === 2) {
                throw new RuntimeException('simulierter Datenbankfehler');
            }
        });

        Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
            ])
            ->call('openUploadForm')
            ->set("fileUploads.{$pool->id}", [
                UploadedFile::fake()->create('eins.txt', 1, 'text/plain'),
                UploadedFile::fake()->create('zwei.txt', 1, 'text/plain'),
            ])
            ->call('uploadFile', $pool->id)
            ->assertHasErrors("fileUploads.$pool->id")
            ->assertSet('openFileForm', true)
            ->assertSet('uploadTargetReady', true);

        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('uploads/files'));
    }

    public function test_cancel_and_entangled_close_share_the_same_idempotent_reset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = $this->companyPool();

        Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
            ])
            ->call('openUploadForm')
            ->set("fileUploads.{$pool->id}", [UploadedFile::fake()->create('abbruch.txt', 1)])
            ->set("expires.{$pool->id}", now()->addDays(2)->format('Y-m-d'))
            ->set('uploadVisibleFrom', now()->addDay()->format('Y-m-d'))
            ->set('uploadAutoDelete', true)
            ->set('openFileForm', false)
            ->assertSet('uploadTargetReady', false)
            ->assertSet('uploadFolderId', null)
            ->assertSet('uploadTargetPath', '')
            ->assertSet("fileUploads.$pool->id", [])
            ->assertSet('expires', [])
            ->assertSet('uploadVisibleFrom', '')
            ->assertSet('uploadAutoDelete', false)
            ->assertDispatched('filepool:cancelled')
            ->call('cancelUploadForm')
            ->assertSet("fileUploads.$pool->id", []);
    }

    private function companyPool(string $title = 'Firmendateien', int $suffix = 0): FilePool
    {
        return FilePool::create([
            'filepoolable_type' => $suffix === 0 ? 'company' : "company-test-$suffix",
            'filepoolable_id' => $suffix,
            'title' => $title,
            'type' => 'company',
            'description' => '',
        ]);
    }

    private function folder(FilePool $pool, string $name, ?int $parentId = null): FileFolder
    {
        return FileFolder::create([
            'file_pool_id' => $pool->id,
            'parent_id' => $parentId,
            'name' => $name,
        ]);
    }

    private function team(User $owner, string $name): Team
    {
        $team = new Team([
            'name' => $name,
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $team->user_id = $owner->id;
        $team->save();
        $team->users()->attach($owner, ['role' => 'member']);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        return $team;
    }
}
