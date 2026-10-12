<?php

namespace Tests\Feature;

use App\Livewire\Profile\EmployeeLocationHistory;
use App\Models\Chat;
use App\Models\ChatLiveLocation;
use App\Models\ChatLocationPoint;
use App\Models\CustomerPortalIdentity;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\EmployeeLocationConsent;
use App\Models\EmployeeLocationPoint;
use App\Models\MobileLocationInstallation;
use App\Models\SessionPermissionGrant;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\EmployeeLocation\BrowserLocationService;
use App\Services\EmployeeLocation\EmployeeLocationHistoryService;
use App\Services\Operations\PersonnelScopeService;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeProfileLocationHistoryTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private Team $team;

    private EmployeeLocationHistoryService $service;

    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
        (require database_path('migrations/2026_08_17_070000_create_device_management_tables.php'))->up();
        (require database_path('migrations/2026_10_11_120200_create_employee_location_tables.php'))->up();
        (require database_path('migrations/2026_10_11_200000_create_session_permission_grants.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2026-10-11T18:00:00Z'));
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Administratoren', 'personal_team' => false, 'rbac_permissions' => ['operations.locations.view' => true]]);
        $this->admin->forceFill(['current_team_id' => $this->team->id])->save();
        Setting::setValue(EmployeeLocationSettings::GROUP, EmployeeLocationSettings::KEY, array_replace(EmployeeLocationSettings::DEFAULTS, ['enabled' => true]));
        $this->service = app(EmployeeLocationHistoryService::class);
        $this->actingAs($this->admin);
    }

    public function test_only_an_active_global_administrator_can_mount_including_manager_and_administration_teams(): void
    {
        foreach (['Verwaltung', 'Administratoren', 'Mitarbeiter', 'Gäste'] as $teamName) {
            $actor = User::factory()->create(['role' => 'staff', 'status' => true]);
            $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => $teamName, 'personal_team' => false, 'rbac_permissions' => ['employees.view' => true, 'operations.locations.view' => true]]);
            $actor->forceFill(['current_team_id' => $team->id])->save();
            $this->actingAs($actor);
            Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])->assertForbidden();
        }
        $this->admin->update(['status' => false]);
        $this->actingAs($this->admin);
        Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])->assertForbidden();
    }

    public function test_employee_view_gate_is_checked_separately_from_the_admin_role(): void
    {
        $gate = \Mockery::mock(GateContract::class);
        $gate->shouldReceive('authorize')->once()->with('employees.view')->andThrow(new AuthorizationException);
        Gate::shouldReceive('forUser')->once()->withArgs(fn ($actor) => $actor->id === $this->admin->id)->andReturn($gate);
        $this->expectException(AuthorizationException::class);
        $this->service->read($this->employee->id, '2026-10-11');
    }

    public function test_a_customer_portal_principal_never_inherits_the_admin_account_with_a_matching_id(): void
    {
        $portal = new CustomerPortalIdentity;
        $portal->forceFill(['id' => $this->admin->id, 'role' => 'admin', 'status' => true]);
        auth()->setUser($portal);
        $queries = $this->locationQueries(fn () => $this->assertDenied(fn () => $this->service->read($this->employee->id, '2026-10-11')));
        $this->assertSame([], $queries);
        Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])->assertForbidden();
    }

    public function test_non_employee_targets_are_rejected_before_location_reads_including_admin_self(): void
    {
        foreach ([$this->admin, User::factory()->create(['role' => 'customer'])] as $target) {
            $queries = $this->locationQueries(fn () => $this->assertDenied(fn () => $this->service->read($target->id, '2026-10-11')));
            $this->assertSame([], $queries);
        }
    }

    public function test_explicit_stored_permission_is_required_even_for_admin_and_no_location_tables_are_read(): void
    {
        $this->point('2026-10-11T08:00:00Z');
        foreach ([[], ['operations.locations.view' => false], ['operations.locations.view' => 'true']] as $permissions) {
            $this->team->update(['rbac_permissions' => $permissions]);
            $queries = $this->locationQueries(function (): void {
                $component = Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id]);
                $component->assertSee('Standortzugriff nicht freigegeben')->assertDontSee('52,52000')->assertDontSee('GPS-Messpunkt');
            });
            $this->assertSame([], $queries);
        }
    }

    public function test_personnel_scope_is_required_before_point_or_consent_queries(): void
    {
        $this->point('2026-10-11T08:00:00Z');
        $this->mock(PersonnelScopeService::class, fn ($mock) => $mock->shouldReceive('allows')->withArgs(fn ($actor, $employee, $ability) => $actor->id === $this->admin->id && $employee->id === $this->employee->id && $ability === 'operations.locations.view')->andReturn(false));
        $queries = $this->locationQueries(function (): void {
            $view = $this->service->read($this->employee->id, '2026-10-11');
            $this->assertSame('restricted', $view['state']);
            $this->assertNull($view['records']);
            $this->assertNull($view['latest']);
            $this->assertNull($view['map']);
        });
        $this->assertSame([], $queries);
    }

    public function test_explicit_superadmin_role_still_requires_stored_location_permission_before_every_read(): void
    {
        $this->point('2026-10-11T08:00:00Z');
        $superadmin = User::factory()->create(['role' => 'superadmin', 'status' => true]);
        $superadmin->forceFill(['current_team_id' => $this->team->id])->save();
        $this->assertNotSame(1, $superadmin->id);
        $this->assertTrue($superadmin->isAdmin());
        $this->assertTrue($superadmin->isSuperAdmin());
        $this->actingAs($superadmin);

        $component = null;
        $authorizedQueries = $this->locationQueries(function () use (&$component): void {
            $component = Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id]);
            $component->assertSee('GPS-Messpunkt')->assertSee('52,52000 / 13,40500')->assertDontSee('Standortzugriff nicht freigegeben');
        });
        $this->assertNotEmpty($authorizedQueries);

        $this->team->update(['rbac_permissions' => []]);
        $restrictedQueries = $this->locationQueries(fn () => $component->call('refreshHistory')
            ->assertSee('Standortzugriff nicht freigegeben')->assertDontSee('GPS-Messpunkt')->assertDontSee('52,52000'));
        $this->assertSame([], $restrictedQueries);

        $initialRestrictedQueries = $this->locationQueries(fn () => Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])
            ->assertSee('Standortzugriff nicht freigegeben')->assertDontSee('GPS-Messpunkt'));
        $this->assertSame([], $initialRestrictedQueries);
    }

    public function test_historical_consent_is_valid_at_measurement_without_current_work_or_active_device(): void
    {
        $point = $this->point('2026-10-10T12:30:00Z');
        EmployeeLocationConsent::where('id', $point->consent_id)->update(['explanation_version' => 1]);
        $settings = array_replace(EmployeeLocationSettings::DEFAULTS, ['enabled' => false, 'explanation_version' => 2]);
        Setting::setValue(EmployeeLocationSettings::GROUP, EmployeeLocationSettings::KEY, $settings);
        MobileLocationInstallation::where('id', $point->installation_id)->update(['revoked_at' => now(), 'session_id' => null]);
        $component = Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])->set('selectedDay', '2026-10-10');
        $component->assertSee('GPS-Messpunkt')->assertSee('52,52000 / 13,40500')->assertSee('14:30')->assertSee('Die Erfassung ist deaktiviert.')->assertSee('kein Live-Standort');
        $this->assertSame('completed', WorkTimeEntry::first()->status);
        $this->assertDatabaseCount('employee_location_points', 1);
    }

    public function test_consent_owner_purpose_decision_withdrawal_interval_and_device_links_are_enforced(): void
    {
        $point = $this->point('2026-10-11T08:00:00Z');
        $consent = EmployeeLocationConsent::findOrFail($point->consent_id);
        $original = $consent->getAttributes();
        foreach ([
            ['purpose' => 'other'], ['decision' => 'declined'], ['withdrawn_at' => now()],
            ['accepted_at' => '2026-10-11T08:00:01Z'], ['expires_at' => '2026-10-11T08:00:00Z'],
            ['installation_id' => null], ['device_assignment_id' => null], ['user_id' => $this->admin->id],
            ['local_date' => '2026-10-10'],
        ] as $override) {
            $consent->forceFill($original)->forceFill($override)->save();
            $view = $this->service->read($this->employee->id, '2026-10-11');
            $this->assertSame('empty', $view['state']);
            $this->assertSame(0, $view['total']);
            $this->assertNull($view['latest']);
        }
        $consent->forceFill($original)->save();
        $this->assertSame(1, $this->service->read($this->employee->id, '2026-10-11')['total']);
    }

    public function test_day_boundary_timezone_precision_and_future_points_are_filtered(): void
    {
        $this->point('2026-10-10T21:59:59Z');
        $onDay = $this->point('2026-10-10T22:00:00Z');
        $this->point('2026-10-11T08:00:00Z', ['accuracy_m' => 51]);
        $this->point('2026-10-11T18:00:01Z');
        $other = User::factory()->create(['role' => 'staff']);
        $this->point('2026-10-11T09:00:00Z', [], $other);
        $view = $this->service->read($this->employee->id, '2026-10-11');
        $this->assertSame(1, $view['total']);
        $this->assertSame($onDay->id, $view['records']->first()['id']);
        $this->assertSame('00:00', $view['records']->first()['time']);
        $this->assertSame('2026-10-11', $view['records']->first()['date']);
        $this->assertCount(8, $view['days']);
    }

    public function test_dst_repeated_hour_keeps_two_real_measurements_in_stable_order(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T08:00:00Z'));
        $first = $this->point('2026-10-25T00:30:00Z');
        $second = $this->point('2026-10-25T01:30:00Z');
        $view = $this->service->read($this->employee->id, '2026-10-25');
        $this->assertSame([$second->id, $first->id], $view['records']->pluck('id')->all());
        $this->assertSame(['02:30', '02:30'], $view['records']->pluck('time')->all());
        $this->assertSame(['2026-10-25T02:30:00+01:00', '2026-10-25T02:30:00+02:00'], $view['records']->pluck('measured_at')->all());
    }

    public function test_named_pagination_is_bounded_stable_and_clamps_forged_extreme_pages(): void
    {
        $ids = [];
        foreach (range(1, 43) as $number) {
            $ids[] = $this->point('2026-10-11T08:00:00Z')->id;
        }
        $page = $this->service->read($this->employee->id, '2026-10-11', 2)['records'];
        $this->assertSame(EmployeeLocationHistoryService::PAGE_NAME, $page->getPageName());
        $this->assertSame(20, $page->perPage());
        $this->assertSame(43, $page->total());
        $this->assertSame(array_slice(array_reverse($ids), 20, 20), $page->pluck('id')->all());
        $last = $this->service->read($this->employee->id, '2026-10-11', PHP_INT_MAX)['records'];
        $this->assertSame(3, $last->currentPage());
        $this->assertCount(3, $last);
        $component = Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id]);
        $component->call('nextPage', EmployeeLocationHistoryService::PAGE_NAME)->assertSet('paginators.locationHistoryPage', 2)
            ->assertSee('locationHistoryPage')->call('previousDay')->assertSet('paginators.locationHistoryPage', 1);
    }

    public function test_mixed_sources_share_one_bounded_chronology_and_unique_render_keys(): void
    {
        $chat = Chat::create(['type' => 'direct', 'created_by' => $this->employee->id]);
        $message = $chat->messages()->create(['user_id' => $this->employee->id, 'body' => '', 'message_type' => 'live_location']);
        $share = ChatLiveLocation::create(['chat_message_id' => $message->id, 'user_id' => $this->employee->id,
            'duration_minutes' => 90, 'latest_position' => [], 'last_position_at' => now(), 'expires_at' => now()->addHour(),
            'profile_history_accepted_at' => CarbonImmutable::parse('2026-10-11T07:00:00Z'), 'profile_history_version' => 1]);
        foreach (range(0, 12) as $minute) {
            $at = CarbonImmutable::parse('2026-10-11T08:00:00Z')->addMinutes($minute);
            $this->point($at->toIso8601String());
            ChatLocationPoint::create(['chat_live_location_id' => $share->id, 'user_id' => $this->employee->id,
                'position' => ['latitude' => 53.55, 'longitude' => 9.99], 'accuracy_m' => 8,
                'measured_at' => $at->addSeconds(30), 'received_at' => $at->addSeconds(30)]);
        }
        $first = $this->service->read($this->employee->id, '2026-10-11');
        $this->assertSame(26, $first['total']);
        $this->assertCount(20, $first['records']);
        $this->assertSame(['chat', 'work', 'chat', 'work'], $first['records']->take(4)->pluck('source')->all());
        $second = $this->service->read($this->employee->id, '2026-10-11', 2);
        $this->assertCount(6, $second['records']);
        $this->assertSame($first['latest'], $second['latest']);
        $keys = $first['records']->pluck('key')->merge($second['records']->pluck('key'));
        $this->assertCount(26, $keys->unique());
        Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])
            ->assertSee('Chat-Standortfreigabe')->assertSee('Arbeitsstandort');
        $message->delete();
        $this->assertSame(13, $this->service->read($this->employee->id, '2026-10-11')['total']);
    }

    public function test_session_consent_keeps_valid_following_day_points_without_accepting_invalid_daily_consents(): void
    {
        $point = $this->point('2026-10-11T08:00:00Z');
        $grant = SessionPermissionGrant::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->employee->id,
            'session_hash' => hash('sha256', 'isolated-test-session'), 'last_activity_at' => now()->utc(), 'expires_at' => now()->utc()->addDays(3)]);
        EmployeeLocationConsent::whereKey($point->consent_id)->update(['session_permission_grant_id' => $grant->id,
            'local_date' => '2026-10-10', 'accepted_at' => '2026-10-10 08:00:00.000000', 'expires_at' => '2026-10-13 08:00:00.000000']);
        $this->assertSame(1, $this->service->read($this->employee->id, '2026-10-11')['total']);
        EmployeeLocationConsent::whereKey($point->consent_id)->update(['session_permission_grant_id' => null]);
        $this->assertSame(0, $this->service->read($this->employee->id, '2026-10-11')['total']);
    }

    public function test_latest_app_point_is_scoped_and_separate_from_retained_history_and_public_state(): void
    {
        (require database_path('migrations/2026_10_13_010200_create_browser_location_points.php'))->up();
        $this->mock(BrowserLocationService::class, function ($mock): void {
            $mock->shouldReceive('latestForMany')->twice()->withArgs(fn ($ids) => $ids->all() === [$this->employee->id])->andReturn([
                $this->employee->id => ['status' => 'available', 'reason' => null, 'point' => [
                    'latitude' => 52.52, 'longitude' => 13.405, 'accuracy_m' => 7,
                    'measured_at' => '2026-10-11T17:59:00Z', 'source' => 'browser',
                ]],
            ]);
        });
        $view = $this->service->read($this->employee->id, '2026-10-11');
        $this->assertSame(0, $view['total']);
        $this->assertNull($view['latest']);
        $this->assertSame('App-Standort', $view['current_app']['source_label']);
        $this->assertSame('19:59', $view['current_app']['time']);
        $component = Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])
            ->assertSee('Letzter gemeldeter App-Standort')->assertSee('52,52000 / 13,40500');
        $this->assertStringNotContainsString('52.52', json_encode($component->instance()->all()));
        $this->team->update(['rbac_permissions' => []]);
        $restricted = $this->service->read($this->employee->id, '2026-10-11');
        $this->assertSame('restricted', $restricted['state']);
        $this->assertNull($restricted['current_app']);
    }

    public function test_no_coordinates_are_public_livewire_properties_and_the_target_id_is_locked(): void
    {
        $this->point('2026-10-11T08:00:00Z');
        $component = Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id]);
        $properties = $component->instance()->all();
        $this->assertSame(['userId', 'selectedDay', 'paginators'], array_keys($properties));
        $this->assertStringNotContainsString('latitude', json_encode($properties));
        $this->assertStringNotContainsString('52.52', json_encode($properties));
        $this->assertStringNotContainsString('position', json_encode($properties));
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $snapshot = (new \DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]')->item(0)->getAttribute('wire:snapshot');
        $this->assertStringNotContainsString('latitude', $snapshot);
        $this->assertStringNotContainsString('52.52', $snapshot);
        $this->assertStringNotContainsString('position', $snapshot);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('userId', $this->admin->id);
    }

    public function test_permission_role_status_and_target_changes_are_revalidated_on_follow_up_requests(): void
    {
        $this->point('2026-10-11T08:00:00Z');
        $component = Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id]);
        $this->team->update(['rbac_permissions' => []]);
        $queries = $this->locationQueries(fn () => $component->call('refreshHistory')->assertSee('Standortzugriff nicht freigegeben')->assertDontSee('52,52000'));
        $this->assertSame([], $queries);
        $this->admin->update(['role' => 'staff']);
        $component->call('previousDay')->assertForbidden();
        $this->admin->update(['role' => 'admin', 'status' => true]);
        $component = Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id]);
        $this->admin->update(['status' => false]);
        $component->call('refreshHistory')->assertForbidden();
        $this->admin->update(['status' => true]);
        $component = Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id]);
        $this->employee->update(['role' => 'guest']);
        $component->call('nextPage', EmployeeLocationHistoryService::PAGE_NAME)->assertForbidden();
    }

    public function test_invalid_dates_and_wrong_paginator_are_rejected_without_point_queries(): void
    {
        foreach (['2026-10-12', '2026-10-03', '2026-02-30', 'nonsense'] as $day) {
            $queries = $this->locationQueries(function () use ($day): void {
                try {
                    $this->service->read($this->employee->id, $day);
                    $this->fail('Invalid history date accepted.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('selectedDay', $exception->errors());
                }
            });
            $this->assertSame([], $queries);
        }
        Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])
            ->call('gotoPage', 2, 'otherPage')->assertHasErrors('locationHistoryPage')
            ->set('selectedDay', '2026-10-12')->assertHasErrors('selectedDay')->assertSet('selectedDay', '2026-10-11');
    }

    public function test_exact_rolling_retention_includes_oldest_partial_day_but_not_the_cutoff_or_older_points(): void
    {
        $this->point('2026-10-04T17:59:59Z');
        $this->point('2026-10-04T18:00:00Z');
        $retained = $this->point('2026-10-04T18:00:01Z');
        $view = $this->service->read($this->employee->id, '2026-10-04');
        $this->assertSame('2026-10-04', $view['first_day']);
        $this->assertTrue($view['partial_day']);
        $this->assertSame('20:00', $view['cutoff_time']);
        $this->assertSame([$retained->id], $view['records']->pluck('id')->all());
        $this->assertFalse($view['previous_available']);
        Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])
            ->set('selectedDay', '2026-10-04')->assertSee('Teil des Tages')->assertSee('20:00')
            ->call('previousDay')->assertSet('selectedDay', '2026-10-04');
    }

    public function test_empty_and_unconfigured_states_do_not_fabricate_measurements_or_enable_collection(): void
    {
        Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])->assertSee('Keine freigegebenen Messpunkte')->assertDontSee('GPS-Messpunkt');
        Schema::drop('mobile_location_codes');
        Schema::drop('chat_location_points');
        $queries = $this->locationQueries(fn () => Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])->assertSee('Standortverlauf noch nicht eingerichtet')->assertDontSee('GPS-Messpunkt'));
        $this->assertSame([], $queries);
        $this->assertTrue(EmployeeLocationSettings::read()['enabled']);
    }

    public function test_shared_day_controls_keep_native_actions_boundary_states_and_accessible_loading(): void
    {
        $component = Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id]);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $older = $xpath->query('//button[@aria-label="Vorheriger Tag"]')->item(0);
        $newer = $xpath->query('//button[@aria-label="Nächster Tag"]')->item(0);
        $this->assertFalse($older->hasAttribute('disabled'));
        $this->assertSame('previousDay', $older->getAttribute('wire:click'));
        $this->assertTrue($newer->hasAttribute('disabled'));
        $this->assertCount(1, $xpath->query('//button[@aria-label="Standortverlauf aktualisieren"]'));
        $this->assertStringContainsString('wire:loading', $component->html());
        $this->assertStringContainsString('wire:offline', $component->html());
        $this->assertStringContainsString('data-rt-custom-select', $component->html());
        $this->assertStringContainsString('data-table-pagination', $component->html());
        $this->assertStringNotContainsString('wire:poll', $component->html());
    }

    public function test_malformed_position_does_not_invent_coordinates_or_a_map_pin(): void
    {
        $point = $this->point('2026-10-11T08:00:00Z', ['position' => ['latitude' => 'invalid', 'longitude' => 13.4]]);
        $view = $this->service->read($this->employee->id, '2026-10-11');
        $this->assertNull($view['map']);
        $this->assertNull($view['latest']['coordinates']);
        DB::table('employee_location_points')->where('id', $point->id)->update(['position' => 'invalid ciphertext']);
        Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])->assertSee('Koordinaten nicht verfügbar')->assertSee('Kein darstellbarer Messpunkt.');
    }

    public function test_refresh_day_navigation_and_paging_only_read_without_external_requests_or_writes(): void
    {
        foreach (range(1, 21) as $number) {
            $this->point('2026-10-11T08:00:00Z');
        }
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(?:insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        Livewire::test(EmployeeLocationHistory::class, ['userId' => $this->employee->id])->call('refreshHistory')
            ->call('nextPage', EmployeeLocationHistoryService::PAGE_NAME)->call('previousPage', EmployeeLocationHistoryService::PAGE_NAME)
            ->call('previousDay')->call('nextDay');
        $this->assertSame([], $writes);
        $this->assertDatabaseCount('employee_location_points', 21);
        $this->assertDatabaseCount('employee_location_consents', 1);
        Http::assertNothingSent();
    }

    private function point(string $at, array $override = [], ?User $employee = null): EmployeeLocationPoint
    {
        $employee ??= $this->employee;
        $measured = CarbonImmutable::parse($at)->utc();
        $day = $measured->setTimezone(EmployeeLocationHistoryService::ZONE)->startOfDay();
        $key = $employee->id.'|'.$day->toDateString();
        if (! isset($this->fixtures[$key])) {
            $device = Device::create(['platform' => 'android', 'ownership' => 'corporate', 'lifecycle_status' => 'assigned', 'display_name' => 'Test handset']);
            $assignment = DeviceAssignment::create(['device_id' => $device->id, 'user_id' => $employee->id, 'status' => 'active', 'assigned_at' => $day->utc()]);
            $installation = MobileLocationInstallation::create(['uuid' => (string) Str::uuid(), 'user_id' => $employee->id, 'device_assignment_id' => $assignment->id, 'platform' => 'android', 'token_hash' => hash('sha256', (string) Str::uuid())]);
            $consent = EmployeeLocationConsent::create(['user_id' => $employee->id, 'device_assignment_id' => $assignment->id, 'installation_id' => $installation->id, 'local_date' => $day->toDateString(), 'purpose' => 'work_routing', 'explanation_version' => 1, 'explanation_hash' => hash('sha256', EmployeeLocationSettings::DEFAULTS['explanation_text']), 'decision' => 'accepted', 'accepted_at' => $day->utc(), 'expires_at' => $day->addDay()->utc()]);
            $entry = WorkTimeEntry::create(['user_id' => $employee->id, 'work_context' => 'internal', 'timezone' => 'Europe/Berlin', 'starts_at' => $day->utc(), 'ends_at' => $day->addDay()->utc(), 'status' => 'completed', 'plan_snapshot' => []]);
            $this->fixtures[$key] = ['user_id' => $employee->id, 'installation_id' => $installation->id, 'device_assignment_id' => $assignment->id, 'consent_id' => $consent->id, 'work_time_entry_id' => $entry->id];
        }

        return EmployeeLocationPoint::create(array_replace($this->fixtures[$key], ['event_id' => (string) Str::uuid(), 'position' => ['latitude' => 52.52, 'longitude' => 13.405], 'accuracy_m' => 5, 'measured_at' => $measured, 'received_at' => $measured], $override));
    }

    private function locationQueries(callable $callback): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $callback();

            return collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => ! str_contains(strtolower($sql), 'sqlite_master') && (str_contains(strtolower($sql), 'employee_location_points') || str_contains(strtolower($sql), 'employee_location_consents')))->values()->all();
        } finally {
            DB::disableQueryLog();
        }
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Location profile access should be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
