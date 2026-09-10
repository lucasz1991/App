<?php

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\EmployeeWorkplaceProvision;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\EmployeeWorkplaceProvisioner;
use App\Services\DeviceManagement\MicrosoftProvisioningGraph;
use App\Support\OutlookAddin\VerifiedEntraIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EmployeeWorkplaceProvisionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private const TENANT = 'd9961e25-4ef8-40c9-8613-f7d2a00652b0';

    private const OBJECT = '1b5c1615-08f4-44d7-b4e0-5dce3c85f6e3';

    private const SKU = '78c06688-7a4e-4da2-9717-7d7c097e62bc';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => false, 'email_verified_at' => null, 'email' => 'pilot@example.test']);
        Setting::setValue('device_management', 'microsoft_provisioning', ['enabled' => true, 'tenant_id' => self::TENANT, 'client_id' => self::OBJECT, 'secret' => Crypt::encryptString('synthetic-not-a-credential')]);
    }

    private function order(?string $sku = null): EmployeeWorkplaceProvision
    {
        return app(EmployeeWorkplaceProvisioner::class)->approve($this->employee, ['principal' => $this->employee->email, 'usage_location' => 'DE', 'profile_key' => 'railtime_basic', 'sku_id' => $sku, 'confirmed' => true], $this->admin);
    }

    private function remote(array $licenses = []): array
    {
        return ['id' => self::OBJECT, 'userPrincipalName' => $this->employee->email, 'accountEnabled' => true, 'userType' => 'Member', 'usageLocation' => 'DE', 'assignedLicenses' => $licenses, 'provisionedPlans' => []];
    }

    private function bind(): void
    {
        EmployeeIdentityAccount::query()->create(['user_id' => $this->employee->id, 'provider' => AccountProvider::Microsoft365, 'tenant_id' => self::TENANT, 'external_id' => self::OBJECT, 'principal' => $this->employee->email, 'email' => $this->employee->email, 'lifecycle_status' => 'active']);
    }

    public function test_approval_deduplicates_without_creating_device_or_licensing(): void
    {
        $order = $this->order();
        $this->assertSame($order->id, $this->order()->id);
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('mails', 0);
        $this->assertFalse($this->employee->fresh()->isActive());
        Http::assertNothingSent();
    }

    public function test_existing_license_is_reused_and_account_is_only_activated_after_matching_verified_sign_in(): void
    {
        $this->bind();
        $order = $this->order(self::SKU);
        $this->mock(MicrosoftProvisioningGraph::class, function ($mock): void {
            $mock->shouldReceive('begin')->once();
            $mock->shouldReceive('user')->once()->with(self::OBJECT)->andReturn($this->remote([['skuId' => self::SKU]]));
            $mock->shouldNotReceive('createUser');
            $mock->shouldNotReceive('assignLicense');
        });
        $service = app(EmployeeWorkplaceProvisioner::class);
        $service->run($order, $this->admin);
        $this->assertSame('assigned', $order->fresh()->license_state);
        $this->assertSame('awaiting_sign_in', $order->fresh()->state);
        $this->assertFalse($this->employee->fresh()->isActive());
        $service->activate(new VerifiedEntraIdentity(self::TENANT, self::OBJECT, 'someone@example.test', 'Wrong'));
        $this->assertFalse($this->employee->fresh()->isActive());
        $service->activate(new VerifiedEntraIdentity(self::TENANT, self::OBJECT, $this->employee->email, 'Pilot'));
        $this->assertTrue($this->employee->fresh()->isActive());
        $this->assertSame('active', $order->fresh()->state);
        $this->assertDatabaseCount('mails', 0);
    }

    public function test_missing_license_seats_produce_review_error_and_never_buy(): void
    {
        $this->bind();
        $order = $this->order(self::SKU);
        $this->mock(MicrosoftProvisioningGraph::class, function ($mock): void {
            $mock->shouldReceive('begin')->once();
            $mock->shouldReceive('user')->once()->andReturn($this->remote());
            $mock->shouldReceive('skus')->once()->andReturn([['skuId' => self::SKU, 'capabilityStatus' => 'Enabled', 'prepaidUnits' => ['enabled' => 1], 'consumedUnits' => 1]]);
            $mock->shouldNotReceive('assignLicense');
        });
        app(EmployeeWorkplaceProvisioner::class)->run($order, $this->admin);
        $this->assertSame('license_seats_exhausted', $order->fresh()->error_code);
    }

    public function test_lost_creation_response_is_never_blindly_repeated(): void
    {
        $order = $this->order();
        $this->mock(MicrosoftProvisioningGraph::class, function ($mock): void {
            $mock->shouldReceive('begin')->twice();
            $mock->shouldReceive('user')->twice()->andReturn(null);
            $mock->shouldReceive('domains')->once()->andReturn([['id' => 'example.test', 'isVerified' => true, 'authenticationType' => 'Managed']]);
            $mock->shouldReceive('createUser')->once()->andThrow(new \RuntimeException('graph_response_uncertain'));
        });
        $service = app(EmployeeWorkplaceProvisioner::class);
        $service->run($order, $this->admin);
        $service->run($order, $this->admin);
        $this->assertSame('account_result_requires_review', $order->fresh()->error_code);
        $this->assertSame('uncertain', $order->fresh()->account_state);
        $this->assertNotSame($order->fresh()->initial_password, DB::table('employee_workplace_provisions')->value('initial_password'));
        $this->assertStringNotContainsString($order->fresh()->initial_password, json_encode(DB::table('activity_log')->get()));
    }

    public function test_inactive_unapproved_identity_cannot_activate(): void
    {
        $this->bind();
        app(EmployeeWorkplaceProvisioner::class)->activate(new VerifiedEntraIdentity(self::TENANT, self::OBJECT, $this->employee->email, 'Pilot'));
        $this->assertFalse($this->employee->fresh()->isActive());
    }

    public function test_cancelled_approval_cannot_be_activated_or_sent_to_microsoft(): void
    {
        $this->bind();
        $order = $this->order();
        $order->update(['object_id' => self::OBJECT, 'state' => 'awaiting_sign_in']);
        $service = app(EmployeeWorkplaceProvisioner::class);
        $service->cancel($order, $this->admin);
        $service->activate(new VerifiedEntraIdentity(self::TENANT, self::OBJECT, $this->employee->email, 'Pilot'));
        $this->assertFalse($this->employee->fresh()->isActive());
        $this->assertSame('cancelled', $order->fresh()->state);
        Http::assertNothingSent();
        $this->expectException(HttpException::class);
        $service->run($order, $this->admin);
    }
}
