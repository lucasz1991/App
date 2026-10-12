<?php

namespace Tests\Feature;

use App\Livewire\Operations\EmployeeLocationStatus;
use App\Livewire\Operations\MyWork;
use App\Livewire\Settings\SessionPermissions;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\EmployeeLocationConsent;
use App\Models\EmployeeLocationConsentEvent;
use App\Models\EmployeeLocationPoint;
use App\Models\MobileLocationCode;
use App\Models\MobileLocationInstallation;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\EmployeeLocation\EmployeeLocationReader;
use App\Services\EmployeeLocation\EmployeeLocationService;
use App\Services\Operations\WorkTimeService;
use App\Services\Privacy\SessionPermissionService;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeLocationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    private User $admin;

    private DeviceAssignment $assignment;

    private EmployeeLocationService $service;

    private Store $browserSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
        (require database_path('migrations/2026_08_17_070000_create_device_management_tables.php'))->up();
        (require database_path('migrations/2026_10_11_120200_create_employee_location_tables.php'))->up();
        (require database_path('migrations/2026_10_11_200000_create_session_permission_grants.php'))->up();
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-11T08:00:00Z'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = Device::create(['platform' => 'android', 'ownership' => 'corporate', 'lifecycle_status' => 'assigned', 'display_name' => 'Geschäftshandy']);
        $this->assignment = DeviceAssignment::create(['device_id' => $device->id, 'user_id' => $this->employee->id, 'status' => 'active', 'assigned_at' => now()]);
        $this->service = app(EmployeeLocationService::class);
        EmployeeLocationSettings::save(['enabled' => true], $this->admin);
        $this->actingAs($this->employee);
        request()->setLaravelSession(app('session')->driver());
        request()->session()->start();
        app(SessionPermissionService::class)->touch($this->employee);
        $this->browserSession = request()->session();
        $this->browserSession->save();
        // Livewire's unit request broker disables StartSession; attach the same real test store.
        foreach (['mount', 'hydrate'] as $event) {
            Livewire::listen($event, fn () => request()->setLaravelSession($this->browserSession));
        }
    }

    public function test_device_pairing_uses_hashed_one_use_code_and_revocable_location_only_token(): void
    {
        $code = $this->service->pairingCode($this->employee, $this->assignment->id);
        $this->assertStringNotContainsString($code, json_encode(DB::table('mobile_location_codes')->first()));
        $response = $this->service->register(['code' => $code, 'installation_id' => (string) Str::uuid(), 'platform' => 'android']);
        $this->assertSame(hash('sha256', $response['token']), MobileLocationInstallation::first()->token_hash);
        $this->assertStringNotContainsString($response['token'], json_encode(MobileLocationInstallation::first()->toArray()));
        $this->expectException(HttpException::class);
        $this->service->register(['code' => $code, 'installation_id' => (string) Str::uuid(), 'platform' => 'android']);
    }

    public function test_only_an_active_corporate_phone_assigned_to_the_employee_can_pair(): void
    {
        DB::table('devices')->where('id', $this->assignment->device_id)->update(['ownership' => 'byod']);
        $this->expectException(HttpException::class);
        $this->service->pairingCode($this->employee, $this->assignment->id);
    }

    public function test_pairing_expires_after_fifteen_minutes(): void
    {
        $code = $this->service->pairingCode($this->employee, $this->assignment->id);
        $this->travel(15)->minutes();
        $this->expectException(HttpException::class);
        $this->service->register(['code' => $code, 'installation_id' => (string) Str::uuid(), 'platform' => 'android']);
    }

    public function test_new_installation_revokes_the_previous_one_and_requires_new_consent(): void
    {
        [$installation] = $this->ready();
        $code = $this->service->pairingCode($this->employee, $this->assignment->id);
        $this->service->register(['code' => $code, 'installation_id' => (string) Str::uuid(), 'platform' => 'android']);
        $this->assertNotNull($installation->fresh()->revoked_at);
        $this->assertSame('consent_missing', $this->ownStatus()['state']);
        $this->assertTrue($this->ownStatus()['needs_decision']);
        $this->assertNull(app(SessionPermissionService::class)->snapshot($this->employee)['location_decision']);
        $this->ownDecide(true, 3);
        $newInstallation = MobileLocationInstallation::whereNull('revoked_at')->firstOrFail();
        $this->service->start($newInstallation, $this->ownHandoff());
        $this->assertSame('active', $this->ownStatus()['state']);
    }

    public function test_declining_consent_keeps_time_running_and_is_not_repeated_on_every_refresh(): void
    {
        $entry = $this->work();
        $this->ownDecide(false, 3);
        $status = $this->ownStatus();
        $this->assertFalse($status['needs_decision']);
        $this->assertSame('running', $entry->fresh()->status);
        $this->assertDatabaseCount('employee_location_points', 0);
    }

    public function test_consent_before_pairing_is_bound_only_at_explicit_native_handoff(): void
    {
        $consent = $this->ownDecide(true, 3);
        $this->assertNull($consent->installation_id);
        $code = $this->service->pairingCode($this->employee, $this->assignment->id);
        $this->service->register(['code' => $code, 'installation_id' => (string) Str::uuid(), 'platform' => 'android']);
        $installation = MobileLocationInstallation::first();
        $this->assertNull($consent->fresh()->installation_id);
        $this->work();
        $permit = $this->service->start($installation, $this->ownHandoff());
        $this->assertSame($installation->id, $consent->fresh()->installation_id);
        $this->assertNotNull($permit['permit_id']);
        $this->assertDatabaseCount('employee_location_consent_events', 1);
    }

    public function test_native_permit_renewal_does_not_extend_the_authenticated_session(): void
    {
        [$installation] = $this->ready();
        $grant = app(SessionPermissionService::class)->current($this->employee);
        $expires = $grant->expires_at->toISOString();
        $activity = $grant->last_activity_at->toISOString();
        $this->travel(1)->minutes();
        $this->service->permit($installation);
        $this->assertSame($expires, $grant->fresh()->expires_at->toISOString());
        $this->assertSame($activity, $grant->fresh()->last_activity_at->toISOString());
    }

    public function test_consent_session_expiry_rejects_a_still_unexpired_native_permit_and_reader(): void
    {
        [$installation, $permit] = $this->ready();
        $this->service->point($installation, $this->sample($permit));
        $grant = app(SessionPermissionService::class)->current($this->employee);
        $grant->forceFill(['expires_at' => now()->utc()->addSeconds(60)])->save();
        $this->travel(60)->seconds();
        $this->assertTrue($installation->fresh()->permit_expires_at->isFuture());
        $this->assertDenied(fn () => $this->service->permit($installation));
        $this->assertDenied(fn () => $this->service->point($installation, $this->sample($permit)));
        $result = app(EmployeeLocationReader::class)->latestForMany(collect([$this->employee]), ['actor' => $this->employee]);
        $this->assertSame('consent_missing', $result[$this->employee->id]['status']);
        $this->assertNull($result[$this->employee->id]['point']);
    }

    public function test_first_sample_is_encrypted_then_the_fifteen_minute_interval_is_enforced(): void
    {
        [$installation, $permit] = $this->ready();
        $sample = $this->sample($permit);
        $response = $this->service->point($installation, $sample);
        $this->assertTrue($response['accepted']);
        $point = EmployeeLocationPoint::firstOrFail();
        $this->assertSame(52.52, $point->position['latitude']);
        $raw = DB::table('employee_location_points')->value('position');
        $this->assertStringNotContainsString('52.52', $raw);
        $this->assertStringNotContainsString('latitude', $raw);
        $this->assertArrayNotHasKey('position', $point->toArray());
        $this->travel(1)->seconds();
        $this->expectException(ValidationException::class);
        $this->service->point($installation, $this->sample($permit));
    }

    public function test_identical_sample_retry_is_idempotent_and_changed_retry_is_rejected(): void
    {
        [$installation, $permit] = $this->ready();
        $sample = $this->sample($permit);
        $this->service->point($installation, $sample);
        $this->assertTrue($this->service->point($installation, $sample)['duplicate']);
        $this->assertDatabaseCount('employee_location_points', 1);
        $this->expectException(HttpException::class);
        $this->service->point($installation, array_replace($sample, ['longitude' => 11]));
    }

    public function test_second_sample_after_fifteen_minutes_needs_continuously_renewed_permits(): void
    {
        [$installation, $permit] = $this->ready();
        $this->service->point($installation, $this->sample($permit));
        for ($minute = 0; $minute < 15; $minute++) {
            $this->travel(1)->minutes();
            $permit = $this->service->permit($installation);
        }
        $this->service->point($installation, $this->sample($permit));
        $this->assertDatabaseCount('employee_location_points', 2);
    }

    public function test_expired_short_permit_cannot_be_silently_restarted_or_used_for_upload(): void
    {
        [$installation, $permit] = $this->ready();
        $this->travel(120)->seconds();
        $this->assertDenied(fn () => $this->service->permit($installation));
        $this->assertDenied(fn () => $this->service->point($installation, $this->sample($permit)));
        $this->assertDatabaseCount('employee_location_points', 0);
    }

    public function test_imprecise_stale_and_future_points_are_not_saved(): void
    {
        [$installation, $permit] = $this->ready();
        foreach ([['accuracy_m' => 51], ['measured_at' => now()->subSeconds(31)->toIso8601String()], ['measured_at' => now()->addSeconds(6)->toIso8601String()]] as $override) {
            try {
                $this->service->point($installation, array_replace($this->sample($permit), $override));
                $this->fail('Invalid point was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('employee_location_points', 0);
            }
        }
    }

    public function test_withdrawal_deletes_session_points_and_invalidates_permit_immediately(): void
    {
        [$installation, $permit] = $this->ready();
        $this->service->point($installation, $this->sample($permit));
        $this->service->withdraw($this->employee);
        $this->assertDatabaseCount('employee_location_points', 0);
        $this->assertNotNull(EmployeeLocationConsent::first()->withdrawn_at);
        $this->assertNull($installation->fresh()->session_id);
        $this->assertDenied(fn () => $this->service->point($installation, $this->sample($permit)));
        $this->assertSame('running', WorkTimeEntry::first()->status);
    }

    public function test_midnight_preserves_active_session_consent_and_night_work(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11T21:59:30Z'));
        [$installation, $permit] = $this->ready();
        $this->assertSame('2026-10-11T22:01:30.000000Z', $permit['expires_at']);
        $this->travel(30)->seconds();
        $renewed = $this->service->permit($installation);
        $this->assertSame('2026-10-11T22:02:00.000000Z', $renewed['expires_at']);
        $this->assertFalse($this->ownStatus()['needs_decision']);
        $this->assertSame('running', WorkTimeEntry::first()->status);
    }

    public function test_work_pause_and_stop_clear_sessions_and_resume_does_not_auto_start(): void
    {
        [$installation, $permit] = $this->ready();
        $entry = WorkTimeEntry::first();
        $entry = app(WorkTimeService::class)->clock($entry->id, $entry->revision, 'pause', (string) Str::uuid(), $this->employee);
        $this->assertNull($installation->fresh()->session_id);
        $this->assertDenied(fn () => $this->service->point($installation, $this->sample($permit)));
        $entry = app(WorkTimeService::class)->clock($entry->id, $entry->revision, 'resume', (string) Str::uuid(), $this->employee);
        $this->assertSame('start_required', $this->ownStatus()['state']);
        app(WorkTimeService::class)->clock($entry->id, $entry->revision, 'stop', (string) Str::uuid(), $this->employee);
        $this->assertSame('not_working', $this->ownStatus()['state']);
    }

    public function test_authority_checks_reject_returned_devices_and_inactive_users_even_without_events(): void
    {
        [$installation, $permit] = $this->ready();
        DB::table('device_assignments')->where('id', $this->assignment->id)->update(['returned_at' => now(), 'status' => 'returned']);
        $this->assertDenied(fn () => $this->service->point($installation, $this->sample($permit)));
        DB::table('device_assignments')->where('id', $this->assignment->id)->update(['returned_at' => null, 'status' => 'active']);
        DB::table('users')->where('id', $this->employee->id)->update(['status' => false]);
        $this->assertDenied(fn () => $this->service->permit($installation));
    }

    public function test_device_return_and_logout_hooks_clear_native_authorization(): void
    {
        [$installation] = $this->ready();
        Event::dispatch(new Logout('web', $this->employee));
        $this->assertNull($installation->fresh()->session_id);
        $this->assignment->update(['status' => 'returned', 'returned_at' => now()]);
        $this->assertNotNull($installation->fresh()->revoked_at);
    }

    public function test_global_disable_and_explanation_revision_are_checked_uncached(): void
    {
        [$installation, $permit] = $this->ready();
        EmployeeLocationSettings::save(['enabled' => false], $this->admin);
        $this->assertDenied(fn () => $this->service->point($installation, $this->sample($permit)));
        EmployeeLocationSettings::save(['enabled' => true, 'explanation_text' => EmployeeLocationSettings::DEFAULTS['explanation_text'].' Weitere Erklärung.'], $this->admin);
        $this->assertSame(4, EmployeeLocationSettings::read()['explanation_version']);
        $this->assertTrue($this->ownStatus()['needs_decision']);
        $this->assertDenied(fn () => $this->ownHandoff());
    }

    public function test_reader_has_no_implicit_administrator_access_and_checks_freshness(): void
    {
        [$installation, $permit] = $this->ready();
        $this->service->point($installation, $this->sample($permit));
        $reader = app(EmployeeLocationReader::class);
        $this->assertSame('available', $reader->latestForMany(collect([$this->employee]), ['actor' => $this->employee])[$this->employee->id]['status']);
        $this->assertNull($reader->latestForMany(collect([$this->employee]), ['actor' => $this->admin])[$this->employee->id]['point']);
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Disposition', 'personal_team' => false, 'rbac_permissions' => ['operations.locations.view' => true]]);
        $this->admin->forceFill(['current_team_id' => $team->id])->save();
        $this->admin->unsetRelation('currentTeam');
        $this->assertSame('available', $reader->latestForMany(collect([$this->employee]), ['actor' => $this->admin])[$this->employee->id]['status']);
        for ($minute = 0; $minute < 15; $minute++) {
            $this->travel(1)->minutes();
            $this->service->permit($installation);
        }
        $this->assertSame('available', $reader->latestForMany(collect([$this->employee]), ['actor' => $this->admin])[$this->employee->id]['status']);
        $this->travel(1)->seconds();
        $this->assertSame('stale', $reader->latestForMany(collect([$this->employee]), ['actor' => $this->admin])[$this->employee->id]['status']);
    }

    public function test_reader_requires_a_current_short_permit_even_for_a_recent_point(): void
    {
        [$installation, $permit] = $this->ready();
        $this->service->point($installation, $this->sample($permit));
        $reader = app(EmployeeLocationReader::class);
        $this->travel(60)->seconds();
        $this->service->permit($installation);
        $this->assertSame('available', $reader->latestForMany(collect([$this->employee]), ['actor' => $this->employee])[$this->employee->id]['status']);
        $this->travel(120)->seconds();
        $result = $reader->latestForMany(collect([$this->employee]), ['actor' => $this->employee])[$this->employee->id];
        $this->assertSame('permit_expired', $result['status']);
        $this->assertNull($result['point']);
        $this->assertStringContainsString('Serverfreigabe', $result['reason']);
        $this->assertCount(1, $this->ownStatus(true)['history']);
    }

    public function test_quality_reports_are_metadata_only_throttled_and_leave_sample_schedule_unchanged(): void
    {
        [$installation, $permit, $token] = $this->ready();
        $next = $installation->fresh()->next_sample_at->toISOString();
        $payload = ['permit_id' => $permit['permit_id'], 'quality_status' => 'inaccurate'];
        $this->withToken($token)->postJson('/api/employee-location/quality', $payload)->assertOk()->assertJsonPath('reported', true);
        $this->assertSame('inaccurate', $this->ownStatus()['state']);
        $this->assertSame($next, $installation->fresh()->next_sample_at->toISOString());
        $this->assertNull($installation->fresh()->last_sample_at);
        $this->assertDatabaseCount('employee_location_points', 0);
        $this->withToken($token)->postJson('/api/employee-location/quality', $payload)->assertOk()->assertJsonPath('reported', false)->assertJsonPath('throttled', true);
        $this->travel(60)->seconds();
        $this->service->permit($installation);
        $this->assertSame('inaccurate', $this->ownStatus()['state']);
        $this->withToken($token)->postJson('/api/employee-location/quality', array_replace($payload, ['quality_status' => 'unavailable']))->assertOk()->assertJsonPath('reported', true);
        Livewire::actingAs($this->employee)->test(EmployeeLocationStatus::class)->assertSee('Standortmessung derzeit nicht verfügbar');
        $this->service->point($installation, $this->sample($permit));
        $this->assertSame('precise', $installation->fresh()->quality_status);
        $this->assertSame('active', $this->ownStatus()['state']);
        $this->assertSame('running', WorkTimeEntry::first()->status);
    }

    public function test_quality_rejects_location_payloads_expired_permits_and_paused_work(): void
    {
        [$installation, $permit, $token] = $this->ready();
        $payload = ['permit_id' => $permit['permit_id'], 'quality_status' => 'unavailable'];
        $this->withToken($token)->postJson('/api/employee-location/quality', $payload + ['latitude' => 52.52])->assertUnprocessable();
        $this->assertNotSame('unavailable', $installation->fresh()->quality_status);
        $this->travel(120)->seconds();
        $this->withToken($token)->postJson('/api/employee-location/quality', $payload)->assertForbidden();
        $this->service->start($installation, $this->ownHandoff());
        DB::table('work_time_entries')->update(['status' => 'paused', 'paused_at' => now()]);
        $this->withToken($token)->postJson('/api/employee-location/quality', $payload)->assertForbidden();
        $this->assertDatabaseCount('employee_location_points', 0);
    }

    public function test_reader_and_upload_never_use_a_paused_or_revised_work_entry(): void
    {
        [$installation, $permit] = $this->ready();
        $this->service->point($installation, $this->sample($permit));
        DB::table('work_time_entries')->update(['status' => 'paused', 'paused_at' => now()]);
        $this->assertSame('not_working', app(EmployeeLocationReader::class)->latestForMany(collect([$this->employee]), ['actor' => $this->employee])[$this->employee->id]['status']);
        $this->assertDenied(fn () => $this->service->permit($installation));
        DB::table('work_time_entries')->update(['status' => 'running', 'paused_at' => null, 'revision' => 2]);
        $this->assertDenied(fn () => $this->service->point($installation, $this->sample($permit)));
    }

    public function test_seven_day_retention_hides_expired_points_before_hourly_deletion(): void
    {
        [$installation, $permit] = $this->ready();
        $this->service->point($installation, $this->sample($permit));
        DB::table('employee_location_points')->update(['measured_at' => now()->utc()->subDays(7)->format('Y-m-d H:i:s.u')]);
        $this->assertTrue($this->ownStatus(true)['history']->isEmpty());
        $this->assertSame(1, $this->service->purge());
        $this->assertDatabaseCount('employee_location_points', 0);
    }

    public function test_handoff_code_is_single_use_bound_to_installation_and_expires_in_sixty_seconds(): void
    {
        [$installation] = $this->ready();
        $code = $this->ownHandoff();
        $this->service->start($installation, $code);
        $this->assertDenied(fn () => $this->service->start($installation, $code));
        $code = $this->ownHandoff();
        $this->travel(60)->seconds();
        $this->assertDenied(fn () => $this->service->start($installation, $code));
    }

    public function test_native_stop_consumes_pending_start_codes_without_changing_time_or_sample_facts(): void
    {
        [$installation, $permit, $token] = $this->ready();
        $this->service->point($installation, $this->sample($permit));
        $entryBefore = WorkTimeEntry::first()->getRawOriginal();
        $sampleBefore = EmployeeLocationPoint::first()->getRawOriginal();
        $lastSample = $installation->fresh()->last_sample_at->toISOString();
        $nextSample = $installation->fresh()->next_sample_at->toISOString();
        $code = $this->ownHandoff();
        $this->withToken($token)->postJson('/api/employee-location/stop')->assertNoContent();
        $this->assertNotNull(MobileLocationCode::where('code_hash', hash('sha256', $code))->firstOrFail()->consumed_at);
        $this->withToken($token)->postJson('/api/employee-location/start', ['code' => $code])->assertForbidden();
        $this->assertNull($installation->fresh()->session_id);
        $this->assertNull($installation->fresh()->permit_id);
        $this->assertSame($lastSample, $installation->fresh()->last_sample_at->toISOString());
        $this->assertSame($nextSample, $installation->fresh()->next_sample_at->toISOString());
        $this->assertSame($entryBefore, WorkTimeEntry::first()->getRawOriginal());
        $this->assertSame($sampleBefore, EmployeeLocationPoint::first()->getRawOriginal());
    }

    public function test_employee_wide_stop_consumes_pending_handoffs_and_allows_only_a_new_explicit_start(): void
    {
        [$installation] = $this->ready();
        $code = $this->ownHandoff();
        $this->service->stopForUser($this->employee->id);
        $this->assertDenied(fn () => $this->service->start($installation, $code));
        $this->assertNotNull(MobileLocationCode::where('code_hash', hash('sha256', $code))->firstOrFail()->consumed_at);
        $this->assertNull($installation->fresh()->session_id);
        $freshCode = $this->ownHandoff();
        $this->service->start($installation, $freshCode);
        $this->assertNotNull($installation->fresh()->session_id);
        $this->assertSame('running', WorkTimeEntry::first()->status);
    }

    public function test_employee_ui_explains_session_choice_history_and_pwa_without_forcing_consent(): void
    {
        $this->work();
        Livewire::actingAs($this->employee)->test(SessionPermissions::class)
            ->assertSet('dialogOpen', true)->assertSee('Push und Standort freigeben')->assertSee('Ohne Standort fortfahren')
            ->call('decide', 'location', false)->assertHasNoErrors();
        Livewire::actingAs($this->employee)->test(SessionPermissions::class, ['automatic' => false])
            ->assertSee('Deine Freigaben')->assertSee('Für diese Sitzung abgelehnt')->assertSet('dialogOpen', false);
        // Standortfreigaben bleiben im globalen Dialog und Profil; im Arbeitstag steht kein Standortbereich.
        Livewire::actingAs($this->employee)->test(MyWork::class)
            ->assertSee('RailTime als App installieren')->assertDontSeeLivewire(EmployeeLocationStatus::class)
            ->assertDontSee('data-employee-location-status', false)->assertDontSee('App-Standort')
            ->call('showTab', 'time')->assertSet('tab', 'time')->assertDontSeeLivewire(EmployeeLocationStatus::class)
            ->assertDontSee('data-employee-location-status', false)->assertDontSee('App-Standort');
        $this->assertSame('declined', EmployeeLocationConsent::first()->decision);
        $this->assertSame('running', WorkTimeEntry::first()->status);
    }

    public function test_manual_handoff_is_explicit_ephemeral_and_cleared_at_expiry_or_withdrawal(): void
    {
        config(['app.url' => 'https://railtime.example']);
        [$installation] = $this->ready();
        $component = Livewire::actingAs($this->employee)->test(EmployeeLocationStatus::class)
            ->assertSet('handoffCode', '')->assertDontSee('Einmaliger Startcode')->call('prepareHandoff')->assertHasNoErrors()
            ->assertSee('Einmaliger Startcode')->assertSee('60 Sekunden')->assertSee('Startcode');
        $code = $component->get('handoffCode');
        $issued = MobileLocationCode::where('code_hash', hash('sha256', $code))->firstOrFail();
        $this->assertSame(now()->utc()->addSeconds(60)->toISOString(), $component->get('handoffExpiresAt'));
        $this->assertSame($issued->expires_at->toISOString(), $component->get('handoffExpiresAt'));
        $this->assertSame('https://railtime.example/employee-location/open#code='.$code.'&server=https%3A%2F%2Frailtime.example', $component->get('handoffUrl'));
        $this->assertStringNotContainsString($code, json_encode(DB::table('mobile_location_codes')->get()));
        $this->travel(60)->seconds();
        $component->call('refreshStatus')->assertSet('handoffCode', '')->assertSet('handoffUrl', '')->assertSet('handoffExpiresAt', null)->assertDontSee('Einmaliger Startcode');
        $this->assertDenied(fn () => $this->service->start($installation, $code));
        $component->call('prepareHandoff')->call('withdraw')->assertSet('handoffCode', '')->assertSet('handoffUrl', '')->assertSet('handoffExpiresAt', null);
        $this->assertSame('running', WorkTimeEntry::first()->status);
    }

    public function test_client_cannot_forge_a_manual_start_code(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->employee)->test(EmployeeLocationStatus::class)->set('handoffCode', 'forged');
    }

    public function test_session_dialog_uses_the_current_explanation_version_after_midnight(): void
    {
        $this->ready();
        $component = Livewire::actingAs($this->employee)->test(EmployeeLocationStatus::class)->assertSet('explanationVersion', 3);
        EmployeeLocationSettings::save(['explanation_text' => EmployeeLocationSettings::DEFAULTS['explanation_text'].' Neue Erklärung.'], $this->admin);
        $this->travelTo(CarbonImmutable::parse('2026-10-11T22:00:00Z'));
        $component->call('refreshStatus')->assertSet('explanationVersion', 4)->assertSee('Neue Erklärung.')->assertSee('Für diese Sitzung erlauben')
            ->call('accept')->assertHasNoErrors();
        $this->assertSame(4, EmployeeLocationConsent::where('local_date', '2026-10-12')->firstOrFail()->explanation_version);
        $this->assertSame('running', WorkTimeEntry::first()->status);
    }

    public function test_handoff_requires_https_before_issuing_any_new_code(): void
    {
        config(['app.url' => 'http://railtime.example']);
        $this->ready();
        $count = MobileLocationCode::count();
        Livewire::actingAs($this->employee)->test(EmployeeLocationStatus::class)->call('prepareHandoff')->assertStatus(422);
        $this->assertSame($count, MobileLocationCode::count());
    }

    public function test_employee_can_read_and_delete_own_retained_history_while_tracking_is_disabled(): void
    {
        [$installation, $permit] = $this->ready();
        $this->service->point($installation, $this->sample($permit));
        EmployeeLocationSettings::save(['enabled' => false], $this->admin);
        $status = $this->ownStatus(true);
        $this->assertFalse($status['enabled']);
        $this->assertSame('disabled', $status['state']);
        $this->assertCount(1, $status['history']);
        $this->assertNotNull($status['last_point']);
        Livewire::actingAs($this->employee)->test(EmployeeLocationStatus::class)
            ->assertSee('Die Standorterfassung ist ausgeschaltet')->assertDontSee('Für diese Sitzung erlauben')
            ->set('historyOpen', true)->assertSee('52,520000')->assertSee('Meinen Verlauf löschen')
            ->call('deleteHistory')->assertHasNoErrors();
        $this->assertDatabaseCount('employee_location_points', 0);
        $this->assertSame('running', WorkTimeEntry::first()->status);
        $this->assertNull($this->ownStatus(true)['last_point']);
    }

    public function test_native_http_contract_is_no_store_and_token_does_not_authorize_time_writes(): void
    {
        $code = $this->service->pairingCode($this->employee, $this->assignment->id);
        $registration = $this->postJson('/api/employee-location/register', ['code' => $code, 'installation_id' => (string) Str::uuid(), 'platform' => 'android'])
            ->assertCreated()->assertJsonStructure(['token', 'installation_id', 'server_time']);
        $this->assertStringContainsString('no-store', $registration->headers->get('Cache-Control'));
        $this->ownDecide(true, 3);
        $this->work();
        $handoff = $this->ownHandoff();
        $token = $registration->json('token');
        $permit = $this->withToken($token)->postJson('/api/employee-location/start', ['code' => $handoff])
            ->assertOk()->assertJsonStructure(['permit_id', 'expires_at', 'server_time', 'next_sample_at', 'work_time_entry_id']);
        $this->withToken($token)->postJson('/api/employee-location/points', $this->sample($permit->json()))->assertOk()->assertJsonPath('accepted', true);
        // Native bearer authentication must be tested without the separately authenticated browser guard.
        auth('web')->forgetUser();
        $this->withToken($token)->getJson('/api/v2/operations/me/work-times')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/employee-location/stop')->assertNoContent();
        $this->withToken($token)->postJson('/api/employee-location/permit')->assertForbidden();
    }

    public function test_native_endpoint_rejects_another_device_and_session_tokens(): void
    {
        [$installation, $permit] = $this->ready();
        $this->postJson('/api/employee-location/points', $this->sample($permit))->assertUnauthorized();
        $this->actingAs($this->employee)->postJson('/api/employee-location/points', $this->sample($permit))->assertUnauthorized();
        $this->withToken(Str::random(80))->postJson('/api/employee-location/points', $this->sample($permit))->assertUnauthorized();
        $this->assertDatabaseCount('employee_location_points', 0);
    }

    public function test_ios_business_device_uses_same_scoped_contract_and_rejects_wrong_platform(): void
    {
        DB::table('devices')->where('id', $this->assignment->device_id)->update(['platform' => 'ios']);
        $code = $this->service->pairingCode($this->employee, $this->assignment->id);
        $this->postJson('/api/employee-location/register', ['code' => $code, 'installation_id' => (string) Str::uuid(), 'platform' => 'android'])->assertForbidden();
        $this->postJson('/api/employee-location/register', ['code' => $code, 'installation_id' => (string) Str::uuid(), 'platform' => 'ios'])->assertCreated();
        $this->assertSame('ios', MobileLocationInstallation::first()->platform);
    }

    public function test_fractional_utc_clock_preserves_short_permit_and_precise_sample(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11T08:00:00.925321Z'));
        [$installation, $permit] = $this->ready();
        $this->assertSame('2026-10-11T08:00:00.925321Z', $permit['server_time']);
        $this->assertSame('2026-10-11T08:02:00.925321Z', $permit['expires_at']);
        $sample = array_replace($this->sample($permit), ['measured_at' => now()->utc()->toISOString()]);
        $this->service->point($installation, $sample);
        $this->assertSame('2026-10-11T08:00:00.925321Z', EmployeeLocationPoint::first()->measured_at->toISOString());
        $this->assertSame('2026-10-11T08:15:00.925321Z', $installation->fresh()->next_sample_at->toISOString());
    }

    public function test_precision_status_does_not_change_without_active_authorization(): void
    {
        [$installation, $permit] = $this->ready();
        $this->service->withdraw($this->employee);
        $this->assertDenied(fn () => $this->service->point($installation, array_replace($this->sample($permit), ['accuracy_m' => 100])));
        $this->assertSame('stopped', $installation->fresh()->quality_status);
    }

    public function test_history_delete_removes_own_points_and_keeps_clock_untouched(): void
    {
        [$installation, $permit] = $this->ready();
        $this->service->point($installation, $this->sample($permit));
        $this->service->deleteHistory($this->employee);
        $this->assertDatabaseCount('employee_location_points', 0);
        $this->assertSame('running', WorkTimeEntry::first()->status);
        $this->assertNotNull(EmployeeLocationConsent::first()->withdrawn_at);
    }

    public function test_consent_evidence_preserves_the_explained_text_after_purpose_revision_and_withdrawal(): void
    {
        $this->ready();
        $initial = EmployeeLocationConsentEvent::first();
        EmployeeLocationSettings::save(['explanation_text' => EmployeeLocationSettings::DEFAULTS['explanation_text'].' Ergänzung.'], $this->admin);
        $this->service->withdraw($this->employee);
        $this->assertSame(EmployeeLocationSettings::DEFAULTS['explanation_text'], $initial->fresh()->explanation_text);
        $this->assertSame(['accepted', 'withdrawn'], EmployeeLocationConsentEvent::orderBy('id')->pluck('action')->all());
        $this->assertSame($initial->explanation_hash, EmployeeLocationConsentEvent::latest('id')->first()->explanation_hash);
        $this->assertArrayNotHasKey('position', $initial->toArray());
    }

    public function test_dispatcher_access_respects_explicit_personnel_responsibilities(): void
    {
        [$installation, $permit] = $this->ready();
        $this->service->point($installation, $this->sample($permit));
        $dispatcher = User::factory()->create(['role' => 'staff', 'status' => true]);
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Disposition', 'personal_team' => false, 'rbac_permissions' => ['operations.locations.view' => true]]);
        $dispatcher->forceFill(['current_team_id' => $team->id])->save();
        Schema::create('personnel_responsibilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('responsible_user_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->json('abilities');
            $table->timestamps();
        });
        DB::table('personnel_responsibilities')->insert(['user_id' => $this->employee->id, 'responsible_user_id' => $dispatcher->id, 'starts_on' => '2026-10-01', 'abilities' => json_encode(['operations.locations.view'])]);
        $result = app(EmployeeLocationReader::class)->latestForMany(collect([$this->employee, $other]), ['actor' => $dispatcher]);
        $this->assertSame('available', $result[$this->employee->id]['status']);
        $this->assertSame('unauthorized', $result[$other->id]['status']);
        $this->assertNull($result[$other->id]['point']);
        DB::table('personnel_responsibilities')->update(['abilities' => json_encode(['operations.time.review'])]);
        $this->assertSame('unauthorized', app(EmployeeLocationReader::class)->latestForMany(collect([$this->employee]), ['actor' => $dispatcher])[$this->employee->id]['status']);
    }

    public function test_precision_link_associations_are_limited_to_location_open_path(): void
    {
        EmployeeLocationSettings::save(['android_certificate_sha256' => implode(':', array_fill(0, 32, 'AA')), 'ios_app_id' => 'ABCDEFGHIJ.de.railtime.location'], $this->admin);
        $this->getJson('/.well-known/assetlinks.json')->assertOk()->assertJsonPath('0.target.package_name', 'de.railtime.location');
        $this->getJson('/.well-known/apple-app-site-association')->assertOk()->assertJsonPath('applinks.details.0.paths.0', '/employee-location/open');
        $response = $this->get('/employee-location/open')->assertOk()->assertSee('60 Sekunden');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    private function ownStatus(bool $includeHistory = false): array
    {
        request()->setLaravelSession($this->browserSession);

        return $this->service->status($this->employee, $includeHistory);
    }

    private function ownHandoff(): string
    {
        request()->setLaravelSession($this->browserSession);

        return $this->service->handoff($this->employee);
    }

    private function ownDecide(bool $accepted, int $version): EmployeeLocationConsent
    {
        request()->setLaravelSession($this->browserSession);

        return $this->service->decide($this->employee, $accepted, $version);
    }

    private function ready(): array
    {
        $code = $this->service->pairingCode($this->employee, $this->assignment->id);
        $registered = $this->service->register(['code' => $code, 'installation_id' => (string) Str::uuid(), 'platform' => 'android']);
        $installation = MobileLocationInstallation::first();
        $this->ownDecide(true, 3);
        $this->work();
        $handoff = $this->ownHandoff();
        $permit = $this->service->start($installation, $handoff);

        return [$installation, $permit, $registered['token']];
    }

    private function work(): WorkTimeEntry
    {
        return WorkTimeEntry::create(['user_id' => $this->employee->id, 'work_context' => 'internal', 'status' => 'running', 'timezone' => 'Europe/Berlin', 'starts_at' => now()->subMinutes(10), 'plan_snapshot' => ['title' => 'Interne Arbeit']]);
    }

    private function sample(array $permit): array
    {
        return ['permit_id' => $permit['permit_id'], 'event_id' => (string) Str::uuid(), 'latitude' => 52.52, 'longitude' => 13.405, 'accuracy_m' => 5, 'measured_at' => now()->utc()->toIso8601String()];
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Operation should have been denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
