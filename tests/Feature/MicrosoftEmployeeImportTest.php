<?php

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftEmployeeImportService;
use App\Services\DeviceManagement\MicrosoftEmployeeImportSettings;
use App\Services\DeviceManagement\MicrosoftGraphDeviceClient;
use App\Services\DeviceManagement\MicrosoftGraphDeviceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MicrosoftEmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const CLIENT = '22222222-2222-4222-8222-222222222222';

    private const FIRST = '33333333-3333-4333-8333-333333333333';

    private const SECOND = '44444444-4444-4444-8444-444444444444';

    private User $admin;

    private array $records;

    private array $responses = [];

    private $duringRequest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        app(MicrosoftDeviceSettings::class)->save([
            'enabled' => false, 'tenant_id' => self::TENANT, 'client_id' => self::CLIENT,
            'client_secret' => 'synthetic-import-secret-not-a-production-credential',
        ], $this->admin);
        $this->records = [
            self::FIRST => $this->record(self::FIRST, 'pilot.one@example.test'),
            self::SECOND => $this->record(self::SECOND, 'pilot.two@example.test'),
        ];
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token') {
                return Http::response(['access_token' => 'synthetic-import-access-token', 'token_type' => 'Bearer']);
            }
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1.0/devices') {
                return Http::response(['value' => []]);
            }
            foreach ([self::FIRST, self::SECOND] as $objectId) {
                if ($path === '/v1.0/users/'.$objectId) {
                    if ($this->duringRequest !== null) {
                        ($this->duringRequest)($objectId);
                    }

                    return Http::response($this->records[$objectId], $this->responses[$objectId] ?? 200);
                }
            }
            $this->fail('Unexpected external URL; pilot may read only the exact configured IDs.');
        });
    }

    public function test_default_disabled_preview_and_import_do_not_contact_microsoft_or_create_users(): void
    {
        $this->assertSame(['enabled' => false, 'pilot_object_ids' => []], $this->settings()->forForm());
        $this->assertSame('disabled', $this->service()->preview()['status']);
        $this->assertSame('disabled', $this->service()->import()['status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employee_identity_accounts', 0);
    }

    public function test_disabled_empty_scope_can_be_saved_and_opt_in_requires_ids_and_credentials(): void
    {
        $this->settings()->save(['enabled' => false, 'pilot_object_ids' => []], $this->admin);
        $this->assertSame(false, $this->settings()->configuration()['enabled']);
        $this->assertInvalid(['enabled' => true, 'pilot_object_ids' => []]);
        app(MicrosoftDeviceSettings::class)->save(['clear_client_secret' => true], $this->admin);
        $this->assertInvalid(['enabled' => true, 'pilot_object_ids' => [self::FIRST]]);
        Http::assertNothingSent();
    }

    public function test_scope_is_canonical_explicit_unique_and_capped_and_ignores_unrelated_settings(): void
    {
        foreach ([['*'], ['https://elsewhere.test'], [self::FIRST, self::FIRST], array_fill(0, 21, self::FIRST)] as $ids) {
            $this->assertInvalid(['enabled' => true, 'pilot_object_ids' => $ids]);
        }
        $this->settings()->save([
            'enabled' => true, 'pilot_object_ids' => [self::SECOND, self::FIRST],
            'tenant_id' => 'evil', 'client_secret' => 'must-not-be-stored', 'activate_users' => true,
        ], $this->admin);
        $this->assertSame(['enabled' => true, 'pilot_object_ids' => [self::FIRST, self::SECOND]], $this->settings()->forForm());
        $stored = json_encode(Setting::getValueUncached(MicrosoftEmployeeImportSettings::GROUP, MicrosoftEmployeeImportSettings::KEY));
        $this->assertStringNotContainsString('must-not-be-stored', $stored);
        $this->assertStringNotContainsString('activate_users', $stored);
    }

    public function test_preview_uses_exact_gets_and_has_no_employee_profile_identity_mail_or_queue_effects(): void
    {
        $this->enable([self::FIRST]);
        $result = $this->service()->preview($this->settings()->fingerprint(), $this->admin);
        $this->assertSame('preview', $result['status']);
        $this->assertSame(1, $result['would_create']);
        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_profiles', 0);
        $this->assertDatabaseCount('employee_identity_accounts', 0);
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('device_commands', 0);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/users/'.self::FIRST.'?%24select=')
            && $request->hasHeader('Authorization', 'Bearer synthetic-import-access-token'));
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSafeSummary($result);
        $this->assertSafeSummary($this->settings()->status());
    }

    public function test_import_creates_only_inactive_staff_with_unknown_provisioning_and_no_verification_team_or_password_leak(): void
    {
        $this->enable([self::FIRST]);
        $result = $this->service()->import($this->settings()->fingerprint(), $this->admin);
        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['created']);
        $user = User::query()->where('email', 'pilot.one@example.test')->sole();
        $this->assertFalse($user->isActive());
        $this->assertFalse($user->isSuperAdmin());
        $this->assertSame('staff', $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->current_team_id);
        $this->assertCount(0, $user->teams);
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertFalse(Hash::check('synthetic-import-secret-not-a-production-credential', $user->password));
        $this->assertSame('Pilot', $user->profile->first_name);
        $identity = $user->identityAccounts()->sole();
        $this->assertSame(self::FIRST, $identity->external_id);
        $this->assertSame(self::TENANT, $identity->tenant_id);
        $this->assertSame('pending_provider', $identity->provisioning_status);
        $this->assertSame('unknown', $identity->license_status);
        $this->assertDatabaseCount('device_assignments', 0);
        $this->assertDatabaseCount('device_commands', 0);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSafeSummary($result);
        $audit = DB::table('activity_log')->where('event', 'microsoft_employee_imported_inactive')->sole();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertStringNotContainsString('pilot.one@example.test', $audit->properties);
        $this->assertStringNotContainsString($user->password, json_encode($this->settings()->status()));
    }

    public function test_repeat_is_idempotent_and_never_overwrites_local_activation_role_name_or_profile(): void
    {
        $this->enable([self::FIRST]);
        $this->assertSame('success', $this->service()->import()['status']);
        $user = User::query()->where('id', '!=', 1)->sole();
        $user->forceFill(['status' => true, 'role' => 'admin', 'name' => 'Locally reviewed name', 'email_verified_at' => now()])->save();
        $user->profile->update(['first_name' => 'Local']);
        $before = $user->fresh()->getAttributes();
        $identityBefore = $user->identityAccounts()->sole()->getAttributes();
        $result = $this->service()->import();
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['existing']);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame($identityBefore, $user->identityAccounts()->sole()->getAttributes());
        $this->assertSame('Local', $user->profile->fresh()->first_name);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_same_email_never_attaches_to_manual_users_even_when_inactive(): void
    {
        $this->enable([self::FIRST]);
        $manual = User::factory()->create(['email' => 'PILOT.ONE@example.test', 'status' => false]);
        $before = $manual->fresh()->getAttributes();
        $result = $this->service()->import();
        $this->assertSame('partial', $result['status']);
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(0, $result['created']);
        $this->assertSame($before, $manual->fresh()->getAttributes());
        $this->assertDatabaseCount('employee_identity_accounts', 0);
    }

    public function test_foreign_tenant_binding_is_a_conflict_and_never_replaced(): void
    {
        $this->enable([self::FIRST]);
        $employee = User::factory()->create();
        $identity = EmployeeIdentityAccount::query()->create([
            'user_id' => $employee->id, 'provider' => AccountProvider::Microsoft365,
            'external_id' => self::FIRST, 'tenant_id' => self::SECOND,
            'principal' => 'pilot.one@example.test', 'email' => 'pilot.one@example.test',
        ]);
        $before = $identity->fresh()->getAttributes();
        $result = $this->service()->import();
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(0, $result['created']);
        $this->assertSame($before, $identity->fresh()->getAttributes());
    }

    public function test_ambiguous_addresses_inside_one_pilot_do_not_choose_an_arbitrary_owner(): void
    {
        $this->enable([self::FIRST, self::SECOND]);
        $this->records[self::SECOND]['mail'] = 'pilot.one@example.test';
        $result = $this->service()->import();
        $this->assertSame(2, $result['conflicts']);
        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_guests_and_disabled_members_are_skipped_without_activation_or_mail(): void
    {
        $this->enable([self::FIRST, self::SECOND]);
        $this->records[self::FIRST]['userType'] = 'Guest';
        $this->records[self::SECOND]['accountEnabled'] = false;
        $result = $this->service()->import();
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(0, $result['eligible']);
        $this->assertDatabaseCount('users', 1);
        Mail::assertNothingSent();
    }

    public function test_missing_enabled_property_or_mismatched_object_id_rejects_the_whole_response(): void
    {
        $this->enable([self::FIRST]);
        unset($this->records[self::FIRST]['accountEnabled']);
        $this->assertSame('invalid_response', $this->service()->import()['status']);
        $this->records[self::FIRST]['accountEnabled'] = true;
        $this->records[self::FIRST]['id'] = self::SECOND;
        $this->assertSame('invalid_response', $this->service()->import()['status']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_later_graph_permission_error_rolls_back_all_planned_user_writes_and_redacts_body(): void
    {
        $this->enable([self::FIRST, self::SECOND]);
        $this->responses[self::SECOND] = 403;
        $this->records[self::SECOND] = ['error' => ['message' => 'private-directory-error']];
        $result = $this->service()->import();
        $this->assertSame('forbidden', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employee_identity_accounts', 0);
        $this->assertStringNotContainsString('private-directory-error', json_encode($this->settings()->status()));
    }

    public function test_configuration_drift_during_fetch_discards_records_and_cannot_publish_old_success(): void
    {
        $this->enable([self::FIRST]);
        $this->duringRequest = function (): void {
            $this->settings()->save(['enabled' => false, 'pilot_object_ids' => [self::FIRST]], $this->admin);
        };
        $this->assertSame('stale_configuration', $this->service()->import()['status']);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame([], $this->settings()->status()['last_result']);
    }

    public function test_admin_deactivation_during_fetch_is_rechecked_before_employee_writes(): void
    {
        $this->enable([self::FIRST]);
        $this->duringRequest = function (): void {
            User::query()->whereKey($this->admin->id)->update(['status' => false]);
        };
        $result = $this->service()->import($this->settings()->fingerprint(), $this->admin);
        $this->assertSame('failed', $result['status']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employee_identity_accounts', 0);
    }

    public function test_pilot_never_bootstraps_system_user_one_on_an_empty_user_table(): void
    {
        $this->enable([self::FIRST]);
        DB::table('users')->delete();
        $result = $this->service()->import();
        $this->assertSame('invalid_configuration', $result['status']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('employee_identity_accounts', 0);
    }

    public function test_stale_preview_fingerprint_cannot_authorize_a_changed_import_and_contacts_nothing(): void
    {
        $this->enable([self::FIRST]);
        $old = $this->settings()->fingerprint();
        $this->enable([self::SECOND]);
        $this->assertSame('stale_configuration', $this->service()->import($old)['status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_short_total_transport_budget_aborts_before_any_user_writes(): void
    {
        $this->enable([self::FIRST, self::SECOND]);
        $graph = app(MicrosoftGraphDeviceClient::class);
        $this->app->instance(MicrosoftGraphDeviceClient::class, $graph);
        $this->duringRequest = function (string $objectId) use ($graph): void {
            if ($objectId === self::FIRST) {
                (new ReflectionProperty($graph, 'startedAt'))->setValue($graph, microtime(true) - 16);
            }
        };
        $this->assertSame('request_limit', $this->service()->import()['status']);
        $this->assertDatabaseCount('users', 1);
        Http::assertSentCount(2);
    }

    public function test_ordinary_graph_begin_resets_pilot_context_and_preserves_device_probe(): void
    {
        $graph = app(MicrosoftGraphDeviceClient::class);
        $configuration = app(MicrosoftDeviceSettings::class)->configuration();
        $graph->beginEmployeeImport($configuration);
        $this->assertSame(self::FIRST, $graph->user(self::FIRST)['id']);
        $graph->begin($configuration, shortProbe: true);
        try {
            $graph->user(self::FIRST);
            $this->fail('Ordinary discovery must not retain the employee pilot context.');
        } catch (MicrosoftGraphDeviceException $exception) {
            $this->assertSame('invalid_configuration', $exception->reason);
        }
        $this->assertSame([], $graph->devices(probe: true));
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/devices?'));
    }

    public function test_credentials_change_invalidates_only_safe_stored_result_evidence(): void
    {
        $this->enable([self::FIRST]);
        $this->service()->preview();
        $status = $this->settings()->status();
        $this->assertSame('preview', $status['last_result']['status']);
        $this->assertArrayHasKey('recorded_at', $status['last_result']);
        $this->settings()->recordResult(['status' => 'success', 'created' => 1, 'token' => 'must-not-be-stored',
            'message' => 'must-not-be-stored', 'users' => [['name' => 'must-not-be-stored']]], $this->settings()->fingerprint());
        $stored = json_encode(Setting::getValueUncached(MicrosoftEmployeeImportSettings::GROUP, MicrosoftEmployeeImportSettings::KEY));
        $this->assertStringNotContainsString('must-not-be-stored', $stored);
        app(MicrosoftDeviceSettings::class)->save(['client_secret' => 'replacement-synthetic-secret'], $this->admin);
        $this->assertSame([], $this->settings()->status()['last_result']);
    }

    public function test_tenant_change_requires_a_new_explicit_pilot_opt_in_before_any_request(): void
    {
        $this->enable([self::FIRST]);
        app(MicrosoftDeviceSettings::class)->save([
            'tenant_id' => self::SECOND, 'client_secret' => 'synthetic-other-tenant-secret',
        ], $this->admin);
        $this->assertFalse($this->settings()->forForm()['enabled']);
        $this->assertSame('disabled', $this->service()->import()['status']);
        Http::assertNothingSent();
        $this->enable([self::FIRST]);
        $this->assertTrue($this->settings()->forForm()['enabled']);
        Http::assertNothingSent();
    }

    public function test_non_superadmin_actor_cannot_invoke_import_even_with_admin_role(): void
    {
        $this->enable([self::FIRST]);
        $other = User::factory()->create(['role' => 'admin', 'status' => true]);
        try {
            $this->service()->import(null, $other);
            $this->fail('Only the active system administrator may run the pilot.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_console_defaults_to_preview_and_apply_is_explicit_without_pii_output(): void
    {
        $this->enable([self::FIRST]);
        $this->artisan('devices:microsoft-users:import')->assertSuccessful();
        $this->assertDatabaseCount('users', 1);
        $this->artisan('devices:microsoft-users:import', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 2);
        $this->assertSame('import', $this->settings()->status()['last_result']['mode']);
    }

    private function record(string $id, string $address): array
    {
        return ['id' => $id, 'displayName' => 'Pilot Employee', 'givenName' => 'Pilot', 'surname' => 'Employee',
            'userPrincipalName' => $address, 'mail' => $address, 'accountEnabled' => true, 'userType' => 'Member'];
    }

    private function enable(array $ids): void
    {
        $this->settings()->save(['enabled' => true, 'pilot_object_ids' => $ids], $this->admin);
    }

    private function settings(): MicrosoftEmployeeImportSettings
    {
        return app(MicrosoftEmployeeImportSettings::class);
    }

    private function service(): MicrosoftEmployeeImportService
    {
        return app(MicrosoftEmployeeImportService::class);
    }

    private function assertInvalid(array $values): void
    {
        try {
            $this->settings()->save($values, $this->admin);
            $this->fail('An invalid import scope must be rejected.');
        } catch (ValidationException) {
            $this->assertFalse($this->settings()->configuration()['enabled']);
        }
    }

    private function assertSafeSummary(array $summary): void
    {
        $encoded = json_encode($summary);
        foreach (['pilot.one@example.test', 'Pilot Employee', 'synthetic-import-secret', 'synthetic-import-access-token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }
}
