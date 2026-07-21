<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\Admin\ManagedDocuments;
use App\Livewire\UserFiles;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentVersion;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ManagedDocumentsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(LogActivity::class);
        $this->buildMinimalRailTimeSchema();
        $this->buildManagedDocumentSchema();
        Storage::fake('private');
        config()->set('broadcasting.default', 'null');
    }

    public function test_admin_can_create_a_managed_document_and_notify_all_employees_with_a_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'staff']);
        $guest = User::factory()->create(['role' => 'guest']);

        Livewire::actingAs($admin)
            ->test(ManagedDocuments::class)
            ->call('openCreate')
            ->set('title', 'Wagenliste')
            ->set('description', 'Aktuelle Wagenübersicht für den täglichen Einsatz.')
            ->set('audienceType', ManagedDocument::AUDIENCE_ALL)
            ->set('notifyOnUpdate', true)
            ->set('upload', UploadedFile::fake()->createWithContent('wagenliste.xlsx', 'version-eins'))
            ->set('changeNotes', 'Erste freigegebene Fassung')
            ->call('save')
            ->assertHasNoErrors();

        $document = ManagedDocument::with('currentVersion.file')->sole();
        $this->assertSame('wagenliste', $document->slug);
        $this->assertSame(1, $document->currentVersion->version_number);
        $this->assertTrue($document->currentVersion->is_current);
        Storage::disk('private')->assertExists($document->currentVersion->file->path);

        $this->assertSame(1, $employee->receivedMessages()->count());
        $this->assertSame(1, $guest->receivedMessages()->count());
        $this->assertSame(0, $admin->receivedMessages()->count());

        $message = $employee->receivedMessages()->firstOrFail();
        $this->assertSame('/files/verbindlich/wagenliste', $message->action_url);
        $this->assertSame(__('app.open_current_file'), $message->action_label);
    }

    public function test_team_document_is_only_visible_downloadable_and_notified_for_team_members(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['role' => 'staff']);
        $outsider = User::factory()->create(['role' => 'staff']);
        $team = Team::forceCreate([
            'user_id' => $admin->id,
            'name' => 'Rangierdienst',
            'personal_team' => false,
        ]);
        $team->users()->attach($member, ['role' => 'team_access']);

        Livewire::actingAs($admin)
            ->test(ManagedDocuments::class)
            ->call('openCreate')
            ->set('title', 'Meldeliste')
            ->set('audienceType', ManagedDocument::AUDIENCE_TEAMS)
            ->set('teamIds', [$team->id])
            ->set('notifyOnUpdate', true)
            ->set('upload', UploadedFile::fake()->createWithContent('meldeliste.pdf', 'team-version'))
            ->call('save')
            ->assertHasNoErrors();

        $document = ManagedDocument::with('currentVersion.file')->sole();
        $this->assertTrue($document->canBeViewedBy($member));
        $this->assertFalse($document->canBeViewedBy($outsider));
        $this->assertSame(1, $member->receivedMessages()->count());
        $this->assertSame(0, $outsider->receivedMessages()->count());

        $this->actingAs($member)
            ->get(route('managed-documents.download', $document))
            ->assertOk();

        $this->actingAs($outsider)
            ->get(route('managed-documents.download', $document))
            ->assertForbidden();

        Livewire::actingAs($member)
            ->test(UserFiles::class)
            ->assertSee('Meldeliste')
            ->assertSee(__('app.download_current_version'));

        Livewire::actingAs($outsider)
            ->test(UserFiles::class)
            ->assertDontSee('Meldeliste');
    }

    public function test_new_versions_are_preserved_and_an_admin_can_restore_an_older_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(ManagedDocuments::class)
            ->call('openCreate')
            ->set('title', 'Tagesmeldung')
            ->set('notifyOnUpdate', false)
            ->set('upload', UploadedFile::fake()->createWithContent('meldung-v1.pdf', 'inhalt-v1'))
            ->call('save');

        $document = ManagedDocument::firstOrFail();

        Livewire::actingAs($admin)
            ->test(ManagedDocuments::class)
            ->call('openVersionUpload', $document->id)
            ->set('upload', UploadedFile::fake()->createWithContent('meldung-v2.pdf', 'inhalt-v2'))
            ->set('changeNotes', 'Neue Tagesdaten')
            ->call('uploadVersion')
            ->assertHasNoErrors();

        $this->assertSame(2, $document->versions()->count());
        $this->assertSame(2, $document->currentVersion()->firstOrFail()->version_number);
        $oldVersion = $document->versions()->where('version_number', 1)->with('file')->firstOrFail();
        $newVersion = $document->versions()->where('version_number', 2)->with('file')->firstOrFail();
        Storage::disk('private')->assertExists($oldVersion->file->path);
        Storage::disk('private')->assertExists($newVersion->file->path);

        Livewire::actingAs($admin)
            ->test(ManagedDocuments::class)
            ->call('restoreVersion', $oldVersion->id)
            ->assertHasNoErrors();

        $this->assertSame(1, $document->currentVersion()->firstOrFail()->version_number);
        $this->assertSame(2, ManagedDocumentVersion::where('managed_document_id', $document->id)->count());
        Storage::disk('private')->assertExists($newVersion->file->path);
    }

    public function test_admin_navigation_uses_a_file_management_submenu(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/admin-sidebar.blade.php'));

        $this->assertStringContainsString("route('admin.files')", $sidebar);
        $this->assertStringContainsString("route('admin.managed-documents')", $sidebar);
        $this->assertStringContainsString("__('app.download_files')", $sidebar);
        $this->assertStringContainsString("__('app.managed_documents')", $sidebar);
    }

    protected function buildManagedDocumentSchema(): void
    {
        Schema::create('managed_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('audience_type')->default('all');
            $table->boolean('notify_on_update')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('content_updated_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('managed_document_team', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('managed_document_id');
            $table->unsignedBigInteger('team_id');
            $table->timestamps();
        });

        Schema::create('managed_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('managed_document_id');
            $table->unsignedInteger('version_number');
            $table->boolean('is_current')->default(false);
            $table->text('change_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }
}
