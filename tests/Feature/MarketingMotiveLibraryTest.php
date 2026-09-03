<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Livewire\Admin\Marketing\CreativeFiles;
use App\Livewire\Admin\Marketing\CreativesIndex;
use App\Livewire\Tools\FilePools\FilePreviewModal;
use App\Livewire\Tools\FilePools\ManageFilePools;
use App\Models\File;
use App\Models\FilePool;
use App\Models\MarketingCreative;
use App\Models\User;
use App\Services\Marketing\MarketingStudioService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class MarketingMotiveLibraryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        config()->set('marketing.disk', 'private');
    }

    public function test_admin_creates_a_file_backed_motive_atomically_with_multiple_private_files(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $uploads = [
            UploadedFile::fake()->create('wagenmeister-story.png', 512, 'image/png'),
            UploadedFile::fake()->create('druckversion.pdf', 768, 'application/pdf'),
        ];

        $component = Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->call('openCreateMotive')
            ->set('motiveTitle', 'Wagenmeister Herbstkampagne')
            ->set('motiveType', MarketingCreativeType::Job->value)
            ->set('motiveUploads', $uploads)
            ->call('createMotive')
            ->assertHasNoErrors()
            ->assertDispatched('filepool:saved');

        $creative = MarketingCreative::query()->sole();
        $pool = $creative->filePool()->with('files')->sole();

        $component->assertRedirect(route('admin.marketing.creatives.files', $creative));
        $this->assertSame('Wagenmeister Herbstkampagne', $creative->title);
        $this->assertSame(MarketingCreativeType::Job, $creative->type);
        $this->assertSame(MarketingCreativeStatus::Draft, $creative->status);
        $this->assertSame('files', $creative->shared_content['storage_mode']);
        $this->assertSame('marketing-motive', $pool->type);
        $this->assertSame($creative->id, (int) $pool->filepoolable_id);
        $this->assertSame(MarketingCreative::class, $pool->filepoolable_type);
        $this->assertCount(2, $pool->files);
        $this->assertDatabaseCount('marketing_creative_variants', 0);

        foreach ($pool->files as $file) {
            $this->assertSame(FilePool::class, $file->fileable_type);
            $this->assertSame($pool->id, (int) $file->fileable_id);
            $this->assertSame('private', $file->disk);
            $this->assertStringStartsWith('uploads/marketing-motives/', $file->path);
            Storage::disk('private')->assertExists($file->path);
        }
    }

    public function test_new_motive_requires_a_file_and_rejects_more_than_twenty_or_over_fifty_megabytes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $poolCountBefore = FilePool::query()->count();

        Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->call('openCreateMotive')
            ->set('motiveTitle', 'Ohne Datei')
            ->call('createMotive')
            ->assertHasErrors(['motiveUploads']);

        $tooMany = [];
        for ($index = 1; $index <= 21; $index++) {
            $tooMany[] = UploadedFile::fake()->create("motiv-{$index}.png", 1, 'image/png');
        }

        Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->call('openCreateMotive')
            ->set('motiveTitle', 'Zu viele Dateien')
            ->set('motiveUploads', $tooMany)
            ->call('createMotive')
            ->assertHasErrors(['motiveUploads']);

        Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->call('openCreateMotive')
            ->set('motiveTitle', 'Zu große Datei')
            ->set('motiveUploads', [
                UploadedFile::fake()->create('zu-gross.psd', 51_201, 'application/octet-stream'),
            ])
            ->call('createMotive')
            ->assertHasErrors(['motiveUploads']);

        $this->assertDatabaseCount('marketing_creatives', 0);
        $this->assertDatabaseCount('file_pools', $poolCountBefore);
        $this->assertDatabaseCount('files', 0);
    }

    public function test_every_create_dialog_close_path_resets_the_temporary_draft(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->call('openCreateMotive')
            ->set('motiveTitle', 'Nicht speichern')
            ->set('motiveType', MarketingCreativeType::Info->value)
            ->set('motiveUploads', [UploadedFile::fake()->create('entwurf.png', 1, 'image/png')])
            ->set('createMotiveOpen', false)
            ->assertSet('createDraftReady', false)
            ->assertSet('motiveTitle', '')
            ->assertSet('motiveType', MarketingCreativeType::Job->value)
            ->assertSet('motiveUploads', [])
            ->assertDispatched('filepool:cancelled');

        $component
            ->call('openCreateMotive')
            ->set('motiveTitle', 'Auch nicht speichern')
            ->call('cancelCreateMotive')
            ->assertSet('createMotiveOpen', false)
            ->assertSet('createDraftReady', false)
            ->assertSet('motiveTitle', '')
            ->assertDispatched('filepool:cancelled');

        $this->assertDatabaseCount('marketing_creatives', 0);
    }

    public function test_failed_multi_file_create_rolls_back_records_and_blobs_but_keeps_the_draft_open(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $poolCountBefore = FilePool::query()->count();
        $creates = 0;

        File::creating(function () use (&$creates): void {
            $creates++;

            if ($creates === 2) {
                throw new RuntimeException('simulierter Datenbankfehler');
            }
        });

        Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->call('openCreateMotive')
            ->set('motiveTitle', 'Atomarer Import')
            ->set('motiveType', MarketingCreativeType::Info->value)
            ->set('motiveUploads', [
                UploadedFile::fake()->create('eins.png', 1, 'image/png'),
                UploadedFile::fake()->create('zwei.pdf', 1, 'application/pdf'),
            ])
            ->call('createMotive')
            ->assertHasErrors('motiveUploads')
            ->assertSet('createMotiveOpen', true)
            ->assertSet('createDraftReady', true);

        $this->assertDatabaseCount('marketing_creatives', 0);
        $this->assertDatabaseCount('file_pools', $poolCountBefore);
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('uploads/marketing-motives'));
    }

    public function test_legacy_motive_receives_exactly_one_file_pool_without_losing_approval_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Info,
            $admin,
        );
        $creative->forceFill([
            'status' => MarketingCreativeStatus::Approved,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'approval_dependency_hash' => str_repeat('a', 64),
        ])->save();
        $approvedAt = $creative->approved_at;

        Livewire::actingAs($admin)
            ->test(CreativeFiles::class, ['creative' => $creative])
            ->assertSet('title', $creative->title)
            ->assertSee('Dateien hochladen und organisieren');

        Livewire::actingAs($admin)
            ->test(CreativeFiles::class, ['creative' => $creative]);

        $creative->refresh();
        $this->assertSame(1, FilePool::query()
            ->where('filepoolable_type', MarketingCreative::class)
            ->where('filepoolable_id', $creative->id)
            ->count());
        $this->assertSame(MarketingCreativeStatus::Approved, $creative->status);
        $this->assertSame($admin->id, (int) $creative->approved_by);
        $this->assertTrue($approvedAt?->equalTo($creative->approved_at));
        $this->assertSame(str_repeat('a', 64), $creative->approval_dependency_hash);
    }

    public function test_motive_pool_reuses_the_hardened_filepool_upload_and_rejects_a_client_pool_swap(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = $this->createMotive($admin, 'Dateipool-Motiv');
        $pool = $creative->filePool;
        $foreign = FilePool::query()->create([
            'title' => 'Fremder Pool',
            'type' => 'company-test',
            'description' => '',
            'filepoolable_type' => 'company-test',
            'filepoolable_id' => 99,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $pool->id,
                'readOnly' => false,
                'allowTeamPermissions' => false,
            ])
            ->call('openUploadForm')
            ->set("fileUploads.{$pool->id}", [
                UploadedFile::fake()->create('linkedin-post.png', 250, 'image/png'),
            ])
            ->call('uploadFile', $foreign->id)
            ->assertHasNoErrors()
            ->assertDispatched('filepool:saved');

        $uploaded = File::query()->where('name', 'linkedin-post.png')->sole();
        $this->assertSame($pool->id, (int) $uploaded->fileable_id);
        $this->assertNotSame($foreign->id, (int) $uploaded->fileable_id);
        Storage::disk('private')->assertExists($uploaded->path);
    }

    public function test_soft_delete_hides_the_motive_but_preserves_its_private_file_pool(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = $this->createMotive($admin, 'Nur ausblenden');
        $pool = $creative->filePool;
        $file = $pool->files()->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->call('deleteMotive', $creative->public_id)
            ->assertDispatched('swal:toast')
            ->assertDontSee('Nur ausblenden');

        $this->assertSoftDeleted('marketing_creatives', ['id' => $creative->id]);
        $this->assertDatabaseHas('file_pools', ['id' => $pool->id]);
        $this->assertDatabaseHas('files', ['id' => $file->id]);
        Storage::disk('private')->assertExists($file->path);

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.files', $creative))
            ->assertNotFound();
    }

    public function test_index_and_compatibility_route_expose_only_the_file_workflow_to_admins(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $creative = $this->createMotive($admin, 'Social Recruiting');

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk()
            ->assertSee('Motiv anlegen')
            ->assertSee('Dateien verwalten')
            ->assertSee('50 MB')
            ->assertDontSee('Motivpaket importieren')
            ->assertDontSee('data-page-builder-preview-card', false)
            ->assertDontSee('Vollbildeditor');

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.editor', $creative))
            ->assertRedirect(route('admin.marketing.creatives.files', $creative));

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.files', $creative))
            ->assertOk()
            ->assertSee('data-marketing-motive-files', false)
            ->assertSee('data-filepool-external-drop', false)
            ->assertSee('Privater Motiv-Dateipool');

        $this->actingAs($staff)
            ->get(route('admin.marketing.creatives.index'))
            ->assertForbidden();
        $this->actingAs($staff)
            ->get(route('admin.marketing.creatives.files', $creative))
            ->assertForbidden();
        $this->actingAs($staff)
            ->get(route('admin.marketing.creatives.editor', $creative))
            ->assertForbidden();

        $this->assertTrue(Route::has('admin.mail-documents.editor'));
        $this->assertStringContainsString(
            "from './mail-builder'",
            file_get_contents(resource_path('js/app.js')) ?: '',
        );
        $this->assertStringNotContainsString(
            "import './marketing-studio'",
            file_get_contents(resource_path('js/app.js')) ?: '',
        );
    }

    public function test_permission_loss_after_opening_the_create_dialog_fails_closed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $poolCountBefore = FilePool::query()->count();
        $component = Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->call('openCreateMotive')
            ->set('motiveTitle', 'Nicht erlaubt')
            ->set('motiveUploads', [UploadedFile::fake()->create('motiv.png', 1, 'image/png')]);

        $admin->forceFill(['role' => 'staff'])->save();

        $component->call('createMotive')->assertForbidden();
        $this->assertDatabaseCount('marketing_creatives', 0);
        $this->assertDatabaseCount('file_pools', $poolCountBefore);
        $this->assertDatabaseCount('files', 0);
    }

    public function test_delegated_file_rights_cannot_cross_the_admin_only_motive_pool_boundary(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = $this->createMotive($admin, 'Nur fuer Administratoren');
        $file = $creative->filePool->files->firstOrFail();
        $manager = User::factory()->create(['role' => 'staff']);
        $managerTeam = $manager->ownedTeams()->create([
            'name' => 'Dateiverwaltung',
            'personal_team' => true,
            'rbac_permissions' => ['files.manage' => true, 'users.edit' => true],
        ]);
        $manager->forceFill(['current_team_id' => $managerTeam->id])->save();

        Livewire::actingAs($manager)
            ->test(FilePreviewModal::class)
            ->call('openWith', $file->id)
            ->assertForbidden();

        Livewire::actingAs($manager)
            ->test(ManageFilePools::class, [
                'poolId' => $creative->filePool->id,
                'readOnly' => false,
                'allowTeamPermissions' => false,
            ])
            ->assertForbidden();
    }

    public function test_open_motive_pool_closes_after_admin_permission_is_revoked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = $this->createMotive($admin, 'Rechte werden neu geprueft');
        $team = $admin->ownedTeams()->create([
            'name' => 'Delegierte Dateiverwaltung',
            'personal_team' => true,
            'rbac_permissions' => ['files.manage' => true],
        ]);
        $admin->forceFill(['current_team_id' => $team->id])->save();

        $component = Livewire::actingAs($admin)
            ->test(ManageFilePools::class, [
                'poolId' => $creative->filePool->id,
                'readOnly' => false,
                'allowTeamPermissions' => false,
            ]);

        $admin->forceFill(['role' => 'staff'])->save();

        $component->call('openUploadForm')->assertForbidden();
        $this->assertDatabaseCount('files', 1);
    }

    private function createMotive(User $admin, string $title): MarketingCreative
    {
        Livewire::actingAs($admin)
            ->test(CreativesIndex::class)
            ->call('openCreateMotive')
            ->set('motiveTitle', $title)
            ->set('motiveType', MarketingCreativeType::Info->value)
            ->set('motiveUploads', [UploadedFile::fake()->create('motiv.png', 1, 'image/png')])
            ->call('createMotive')
            ->assertHasNoErrors();

        return MarketingCreative::query()->where('title', $title)->with('filePool.files')->sole();
    }
}
