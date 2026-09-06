<?php

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Livewire\Devices\MicrosoftEmployeeLinks;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftEmployeeLinkService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MicrosoftEmployeeLinkTest extends TestCase
{
    use DatabaseMigrations;

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const OBJECT_ID = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setValue(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY, ['tenant_id' => self::TENANT]);
    }

    public function test_binding_is_idempotent_and_does_not_claim_provisioning_or_license_success(): void
    {
        $admin = $this->admin();
        $employee = User::factory()->create(['status' => true]);
        $service = app(MicrosoftEmployeeLinkService::class);
        $identity = $service->bind($employee, self::OBJECT_ID, ' Employee@Company.test ', $admin);
        $again = $service->bind($employee, strtoupper(self::OBJECT_ID), 'employee@company.test', $admin);

        $this->assertSame($identity->id, $again->id);
        $this->assertSame(self::TENANT, $identity->tenant_id);
        $this->assertSame('employee@company.test', $identity->principal);
        $this->assertSame(AccountProvider::Microsoft365, $identity->provider);
        $this->assertSame('active', $identity->lifecycle_status);
        $this->assertSame('pending_provider', $identity->provisioning_status);
        $this->assertSame('unknown', $identity->license_status);
        $this->assertNull($identity->last_synced_at);
        $this->assertDatabaseCount('employee_identity_accounts', 1);

        $audits = DB::table('activity_log')->where('description', 'microsoft_employee_linked')->get();
        $this->assertCount(1, $audits);
        $this->assertSame($admin->id, $audits->first()->causer_id);
        $this->assertStringNotContainsString(self::OBJECT_ID, $audits->first()->properties);
        $this->assertStringNotContainsString('employee@company.test', $audits->first()->properties);
        $this->assertSame($identity->id, json_decode($audits->first()->properties, true)['identity_id']);
    }

    public function test_pending_exact_principal_can_receive_its_first_object_id_without_losing_evidence(): void
    {
        $admin = $this->admin();
        $employee = User::factory()->create(['status' => true]);
        $pending = $this->identity($employee, [
            'external_id' => null,
            'tenant_id' => null,
            'provisioning_status' => 'pending_provider',
            'license_status' => 'assigned',
            'metadata' => ['source' => 'railtime_desired_state'],
        ]);

        $identity = app(MicrosoftEmployeeLinkService::class)->bind($employee, self::OBJECT_ID, $pending->principal, $admin);

        $this->assertSame($pending->id, $identity->id);
        $this->assertSame(self::OBJECT_ID, $identity->external_id);
        $this->assertSame(self::TENANT, $identity->tenant_id);
        $this->assertSame('pending_provider', $identity->provisioning_status);
        $this->assertSame('assigned', $identity->license_status);
        $this->assertSame('railtime_desired_state', $identity->metadata['source']);
        $this->assertSame('administrator_mapping', $identity->metadata['microsoft_link_source']);
    }

    public function test_existing_binding_cannot_be_moved_to_another_employee_principal_object_or_tenant(): void
    {
        $admin = $this->admin();
        $employee = User::factory()->create(['status' => true]);
        $other = User::factory()->create(['status' => true]);
        $identity = $this->identity($employee);
        $service = app(MicrosoftEmployeeLinkService::class);

        foreach ([
            [$other, self::OBJECT_ID, $identity->principal],
            [$other, self::OBJECT_ID, 'different@company.test'],
            [$other, '33333333-3333-4333-8333-333333333333', $identity->principal],
            [$employee, self::OBJECT_ID, 'different@company.test'],
            [$employee, '33333333-3333-4333-8333-333333333333', $identity->principal],
        ] as [$target, $objectId, $principal]) {
            $this->assertInvalid(fn () => $service->bind($target, $objectId, $principal, $admin), 'object_id');
        }

        Setting::setValue(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY, [
            'tenant_id' => '44444444-4444-4444-8444-444444444444',
        ]);
        $this->assertInvalid(fn () => $service->bind($employee, self::OBJECT_ID, $identity->principal, $admin), 'object_id');
        $this->assertSame($employee->id, $identity->fresh()->user_id);
        $this->assertSame(self::TENANT, $identity->fresh()->tenant_id);
        $this->assertDatabaseCount('employee_identity_accounts', 1);
        $this->assertDatabaseMissing('activity_log', ['description' => 'microsoft_employee_linked']);
    }

    public function test_inactive_employee_or_revoked_identity_cannot_be_linked(): void
    {
        $admin = $this->admin();
        $employee = User::factory()->create(['status' => false]);
        $service = app(MicrosoftEmployeeLinkService::class);
        $this->assertInvalid(fn () => $service->bind($employee, self::OBJECT_ID, 'employee@company.test', $admin), 'employee_id');
        $employee->forceFill(['status' => true])->save();
        $this->identity($employee, ['lifecycle_status' => 'revoked']);
        $this->assertInvalid(fn () => $service->bind($employee, self::OBJECT_ID, 'employee@company.test', $admin), 'object_id');
        $this->assertSame('revoked', EmployeeIdentityAccount::first()->lifecycle_status);
    }

    public function test_concrete_tenant_and_valid_admin_inputs_are_required(): void
    {
        $admin = $this->admin();
        $employee = User::factory()->create(['status' => true]);
        $service = app(MicrosoftEmployeeLinkService::class);
        $this->assertInvalid(fn () => $service->bind($employee, 'not-an-object-id', 'employee@company.test', $admin), 'object_id');
        $this->assertInvalid(fn () => $service->bind($employee, self::OBJECT_ID, 'invalid-principal', $admin), 'principal');

        foreach (['', 'common', 'organizations'] as $tenant) {
            Setting::setValue(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY, ['tenant_id' => $tenant]);
            $this->assertInvalid(fn () => $service->bind($employee, self::OBJECT_ID, 'employee@company.test', $admin), 'tenant_id');
        }

        $this->assertDatabaseCount('employee_identity_accounts', 0);
    }

    public function test_ordinary_employee_cannot_read_modal_or_invoke_binding_service(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        Livewire::actingAs($employee)->test(MicrosoftEmployeeLinks::class)->assertForbidden();

        $this->expectException(AuthorizationException::class);
        app(MicrosoftEmployeeLinkService::class)->bind($employee, self::OBJECT_ID, 'employee@company.test', $employee);
    }

    public function test_modal_uses_delegated_account_gate_handles_errors_and_lists_saved_binding(): void
    {
        $operator = User::factory()->create(['role' => 'staff', 'status' => true]);
        $employee = User::factory()->create(['name' => 'Active Device Employee', 'role' => 'staff', 'status' => true]);
        User::factory()->create(['name' => 'Inactive Device Employee', 'status' => false]);
        Gate::define('devices.accounts.manage', fn (User $user): bool => $user->is($operator));

        Livewire::actingAs($operator)->test(MicrosoftEmployeeLinks::class)
            ->assertSee('Microsoft-Konten')
            ->assertDontSee('Active Device Employee')
            ->call('openModal')
            ->assertSet('showModal', true)
            ->assertSee('Active Device Employee')
            ->assertDontSee('Inactive Device Employee')
            ->set('employee_id', (string) $employee->id)
            ->set('object_id', 'incorrect')
            ->set('principal', 'employee@company.test')
            ->call('save')
            ->assertHasErrors('object_id')
            ->assertSet('showModal', true)
            ->set('object_id', self::OBJECT_ID)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('employee@company.test')
            ->assertSee('Verknüpft')
            ->assertSet('employee_id', '')
            ->call('closeModal')
            ->assertSet('showModal', false)
            ->assertDontSee('employee@company.test');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => true]);
    }

    private function identity(User $employee, array $attributes = []): EmployeeIdentityAccount
    {
        return EmployeeIdentityAccount::query()->create(array_merge([
            'user_id' => $employee->id,
            'provider' => AccountProvider::Microsoft365,
            'external_id' => self::OBJECT_ID,
            'tenant_id' => self::TENANT,
            'principal' => 'employee@company.test',
            'email' => 'employee@company.test',
            'lifecycle_status' => 'active',
            'provisioning_status' => 'pending_provider',
            'license_status' => 'unknown',
        ], $attributes));
    }

    private function assertInvalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('The conflicting or invalid identity mapping must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
