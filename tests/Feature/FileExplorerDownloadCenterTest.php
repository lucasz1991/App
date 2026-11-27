<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\UserFiles;
use App\Models\FilePool;
use App\Models\ManagedDocument;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class FileExplorerDownloadCenterTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(LogActivity::class);
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_07_21_000006_create_managed_documents_tables.php'))->up();
        Storage::fake('private');
        Storage::fake('public');
    }

    public function test_legacy_search_links_include_authorized_sources_before_choosing_a_scope(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $team = Team::forceCreate(['user_id' => $admin->id, 'name' => 'Leitstelle', 'personal_team' => false]);
        $team->users()->attach($staff, ['role' => 'member']);
        $otherTeam = Team::forceCreate(['user_id' => $admin->id, 'name' => 'Andere Leitstelle', 'personal_team' => false]);

        $personal = $this->personalPool($staff);
        $company = FilePool::company();
        $personal->files()->create($this->attributes($admin, 'Dienstplan-fuer-mich.pdf'));
        $company->files()->create($this->attributes($admin, 'Dienstplan-geteilt.docx') + ['visible_teams' => [$team->id]]);
        $company->files()->create($this->attributes($admin, 'Dienstplan-privat.csv') + ['visible_teams' => [$otherTeam->id]]);

        Livewire::actingAs($staff)
            ->withQueryParams(['q' => 'dienstplan', 'layout' => 'details'])
            ->test(UserFiles::class)
            ->assertSet('search', 'dienstplan')
            ->assertSet('scope', 'all')
            ->assertSee('Dienstplan-fuer-mich.pdf')
            ->assertSee('Dienstplan-geteilt.docx')
            ->assertDontSee('Dienstplan-privat.csv')
            ->assertDontSeeHtml('value="csv"')
            ->set('fileType', 'docx')
            ->assertDontSee('Dienstplan-fuer-mich.pdf')
            ->assertSee('Dienstplan-geteilt.docx')
            ->call('setExplorerScope', 'personal')
            ->assertSet('search', '')
            ->assertSet('fileType', '')
            ->assertSet('layout', 'details')
            ->assertSee('Dienstplan-fuer-mich.pdf')
            ->assertDontSee('Dienstplan-geteilt.docx');
    }

    public function test_embedded_download_center_is_personal_even_with_a_different_scope_in_the_url(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->personalPool($staff)->files()->create($this->attributes($staff, 'Persoenliche-Freigabe.pdf'));

        Livewire::actingAs($staff)
            ->withQueryParams(['scope' => 'shared'])
            ->test(UserFiles::class, ['embedded' => true, 'scopeLinks' => ['personal' => '/files?scope=personal']])
            ->assertSet('scope', 'personal')
            ->assertSee('Persoenliche-Freigabe.pdf')
            ->call('setExplorerScope', 'shared')
            ->assertSet('scope', 'personal');
    }

    public function test_managed_document_details_keep_the_current_version_and_its_download_route(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $document = ManagedDocument::create(['title' => 'Verbindliche Meldeliste', 'audience_type' => ManagedDocument::AUDIENCE_ALL]);
        $document->versions()->create(['version_number' => 1, 'is_current' => false])
            ->file()->create($this->attributes($admin, 'Meldeliste-alt.pdf'));
        $document->versions()->create(['version_number' => 2, 'is_current' => true])
            ->file()->create($this->attributes($admin, 'Meldeliste-aktuell.pdf'));

        Livewire::actingAs($staff)
            ->withQueryParams(['scope' => 'required', 'layout' => 'details'])
            ->test(UserFiles::class)
            ->assertSet('scope', 'required')
            ->assertSee('Verbindliche Meldeliste')
            ->assertSee('v2')
            ->assertSeeHtml('href="'.route('managed-documents.download', $document).'"')
            ->assertDontSeeHtml('data-file-preview')
            ->set('fileType', 'docx')
            ->assertDontSee('Verbindliche Meldeliste');

        $this->actingAs($staff)->get(route('managed-documents.download', $document))
            ->assertOk()
            ->assertDownload('Meldeliste-aktuell.pdf');
    }

    private function personalPool(User $user): FilePool
    {
        return FilePool::create([
            'filepoolable_type' => User::class,
            'filepoolable_id' => $user->id,
            'title' => 'Persönlich',
            'type' => User::class,
            'description' => '',
        ]);
    }

    private function attributes(User $owner, string $name): array
    {
        $path = 'uploads/files/'.$name;
        Storage::disk('private')->put($path, 'Dateiinhalt');

        return [
            'user_id' => $owner->id,
            'name' => $name,
            'path' => $path,
            'disk' => 'private',
            'mime_type' => 'application/octet-stream',
            'type' => 'default',
            'size' => 42,
        ];
    }
}
