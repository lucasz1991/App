<?php

namespace Tests\Feature;

use App\Livewire\Admin\Employees\TeamRbacModal;
use App\Livewire\Admin\Settings;
use App\Models\Team;
use App\Models\User;
use App\Support\Rbac\RbacCatalog;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TeamCreationModalTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        (require database_path('migrations/2026_09_08_171000_create_employee_workplace_provisions.php'))->up();
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Bus::fake();

        User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->team = Team::forceCreate([
            'user_id' => $this->admin->id,
            'name' => 'Disposition Nord',
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $this->admin->forceFill(['current_team_id' => $this->team->id])->save();
    }

    #[DataProvider('hosts')]
    public function test_creation_is_an_explicit_form_modal_with_one_native_dialog_in_each_host(bool $embedded): void
    {
        $component = $this->manager($embedded)->assertSet('showCreateTeamModal', false);
        $this->assertCount(1, $this->xpath($component->html())->query('//button[@*[name()="wire:click"]="openCreateTeam"]'));
        $this->assertCount(0, $this->xpath($component->html())->query('//form[@data-team-create-form]'));

        $component->call('openCreateTeam')->assertSuccessful()->assertSet('showCreateTeamModal', true)
            ->assertSee('Team erstellen')->assertSee('Teamname');
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//form[@data-team-create-form and @*[name()="wire:submit"]="createTeam"]'));
        $formId = $xpath->query('//form[@data-team-create-form]')->item(0)?->getAttribute('id');
        $this->assertNotEmpty($formId);
        $this->assertCount(1, $xpath->query('//button[@type="submit" and @form="'.$formId.'"]'));
        $this->assertCount(0, $xpath->query('//*[@role="dialog"]//button[@*[name()="wire:click"]="save"]'));
        $this->assertCount(1, $xpath->query('//*[@role="dialog"]'));
        $dialogId = $embedded ? 'settings-team-create-dialog' : 'directory-team-rbac-dialog';
        $titleId = $dialogId.'-title';
        $this->assertCount(1, $xpath->query('//*[@data-rt-modal-shell and @id="'.$dialogId.'"]'));
        $this->assertSame($titleId, $xpath->query('//*[@role="dialog"]')->item(0)?->getAttribute('aria-labelledby'));
        $this->assertCount(1, $xpath->query('//*[@id="'.$titleId.'"]'));
        $this->assertSame('Team erstellen', trim($xpath->query('//*[@id="'.$titleId.'"]')->item(0)?->textContent ?? ''));
        $this->assertCount(0, $xpath->query('//form//form'));
        $this->assertSame(1, Team::count());
        $this->assertNoTransports();
    }

    #[DataProvider('hosts')]
    public function test_cancel_clears_name_and_validation_but_preserves_selected_team_and_unsaved_rights(bool $embedded): void
    {
        $key = str_replace('.', '__dot__', 'employees.view');
        $before = $this->databaseState();
        $component = $this->manager($embedded)->set('matrix.'.$this->team->id.'.'.$key, true)
            ->call('openCreateTeam')->call('createTeam')->assertHasErrors('newTeamName')
            ->set('newTeamName', 'Noch nicht angelegt')->call('closeCreateTeam')
            ->assertSet('showCreateTeamModal', false)->assertSet('newTeamName', '')
            ->assertSet('selectedTeamId', $this->team->id)
            ->assertSet('matrix.'.$this->team->id.'.'.$key, true)->assertHasNoErrors();

        $this->assertCount(0, $this->xpath($component->html())->query('//form[@data-team-create-form]'));
        if (! $embedded) {
            $component->assertSet('showModal', true);
        }
        $this->assertSame($before, $this->databaseState());
        $component->call('openCreateTeam')->assertSet('newTeamName', '')->assertHasNoErrors();
        $this->assertNoTransports();
    }

    #[DataProvider('invalidNames')]
    public function test_validation_keeps_creation_open_without_writes_in_both_hosts(bool $embedded, string $name): void
    {
        $before = $this->databaseState();
        $this->manager($embedded)->call('openCreateTeam')->set('newTeamName', $name)->call('createTeam')
            ->assertHasErrors('newTeamName')->assertSet('showCreateTeamModal', true)
            ->assertSet('newTeamName', $name)->assertSet('selectedTeamId', $this->team->id)
            ->assertSee('data-team-create-form', false);
        $this->assertSame($before, $this->databaseState());
        $this->assertNoTransports();
    }

    #[DataProvider('hosts')]
    public function test_success_returns_to_rights_selects_new_team_and_preserves_other_drafts_and_actor_context(bool $embedded): void
    {
        $key = str_replace('.', '__dot__', 'employees.view');
        $beforeActor = $this->admin->fresh()->getAttributes();
        $beforeTeam = $this->team->fresh()->getAttributes();
        $beforeMemberships = DB::table('team_user')->orderBy('id')->get()->toJson();
        $component = $this->manager($embedded)->set('matrix.'.$this->team->id.'.'.$key, true)
            ->call('openCreateTeam')->set('newTeamName', '  Einsatzteam Süd  ')->call('createTeam')
            ->assertHasNoErrors()->assertSet('showCreateTeamModal', false)->assertSet('newTeamName', '')
            ->assertDispatched('employeeSaved');

        $created = Team::where('name', 'Einsatzteam Süd')->sole();
        $component->assertSet('selectedTeamId', $created->id)
            ->assertSet('matrix.'.$this->team->id.'.'.$key, true)
            ->assertSet('matrix.'.$created->id.'.'.$key, false)
            ->assertDontSee('data-team-create-form', false);
        if (! $embedded) {
            $component->assertSet('showModal', true);
        }
        $this->assertFalse($created->personal_team);
        $this->assertSame($this->admin->id, $created->user_id);
        $this->assertSame(RbacCatalog::defaultTeamPermissions(), $created->rbac_permissions);
        $this->assertNotContains(true, $created->rbac_permissions);
        $this->assertSame($beforeActor, $this->admin->fresh()->getAttributes());
        $this->assertSame($beforeTeam, $this->team->fresh()->getAttributes());
        $this->assertSame($beforeMemberships, DB::table('team_user')->orderBy('id')->get()->toJson());
        $this->assertSame(1, DB::table('activity_log')->where('description', 'employee_team_created')->count());
        $this->assertNoTransports();
    }

    public function test_closing_or_reopening_directory_manager_discards_only_creation_draft(): void
    {
        $before = $this->databaseState();
        $component = $this->manager(false)->call('openCreateTeam')->set('newTeamName', 'Verwerfen')
            ->call('close')->assertSet('showModal', false)->assertSet('showCreateTeamModal', false)
            ->assertSet('newTeamName', '')->assertHasNoErrors();
        $component->call('open')->assertSet('showModal', true)->assertSet('showCreateTeamModal', false)
            ->call('openCreateTeam')->set('newTeamName', 'Auch verwerfen')->call('open')
            ->assertSet('showCreateTeamModal', false)->assertSet('newTeamName', '')->assertHasNoErrors();
        $this->assertSame($before, $this->databaseState());
        $this->assertNoTransports();
    }

    #[DataProvider('privilegedActions')]
    public function test_open_and_create_require_fresh_role_permission_in_both_hosts(bool $embedded, string $action): void
    {
        $team = Team::forceCreate([
            'user_id' => $this->admin->id, 'name' => 'Verwaltung', 'personal_team' => false,
            'rbac_permissions' => ['roles.manage' => true],
        ]);
        $manager = User::factory()->create(['role' => 'staff', 'status' => true, 'current_team_id' => $team->id]);
        $manager->teams()->attach($team, ['role' => 'team_access']);
        $component = $this->manager($embedded, $manager);
        if ($action === 'createTeam') {
            $component->call('openCreateTeam')->set('newTeamName', 'Nicht erlaubt');
        }
        Team::whereKey($team->id)->update(['rbac_permissions' => []]);
        $before = $this->databaseState();
        $component->call($action)->assertForbidden();
        $this->assertSame($before, $this->databaseState());
        $this->assertNoTransports();
    }

    public function test_system_settings_contains_the_same_embedded_manager_entry(): void
    {
        $before = $this->databaseState();
        $component = Livewire::actingAs($this->admin)->test(Settings::class)->assertSuccessful();
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//*[@data-admin-users-section="permissions"]//*[@data-team-rbac-livewire="embedded"]'));
        $this->assertCount(1, $xpath->query('//*[@data-team-rbac-livewire="embedded"]//button[@*[name()="wire:click"]="openCreateTeam"]'));
        $this->assertCount(0, $xpath->query('//*[@data-team-rbac-livewire="embedded"]//form[@data-team-create-form]'));
        $this->assertSame($before, $this->databaseState());
        $this->assertNoTransports();
    }

    public static function hosts(): array
    {
        return ['directory' => [false], 'settings' => [true]];
    }

    public static function invalidNames(): array
    {
        $cases = [];
        foreach (self::hosts() as $host => [$embedded]) {
            foreach (['blank' => '  ', 'duplicate' => ' disposition nord ', 'long' => str_repeat('x', 256)] as $name => $value) {
                $cases[$host.'-'.$name] = [$embedded, $value];
            }
        }

        return $cases;
    }

    public static function privilegedActions(): array
    {
        $cases = [];
        foreach (self::hosts() as $host => [$embedded]) {
            foreach (['openCreateTeam', 'createTeam'] as $action) {
                $cases[$host.'-'.$action] = [$embedded, $action];
            }
        }

        return $cases;
    }

    private function manager(bool $embedded, ?User $actor = null): Testable
    {
        $component = Livewire::actingAs($actor ?? $this->admin)->test(TeamRbacModal::class, ['embedded' => $embedded]);
        if (! $embedded) {
            $component->call('open');
        }

        return $component;
    }

    private function databaseState(): array
    {
        return collect(['users', 'teams', 'team_user', 'activity_log'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function assertNoTransports(): void
    {
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
