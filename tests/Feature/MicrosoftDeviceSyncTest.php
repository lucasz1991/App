<?php

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Enums\DeviceManagementStatus;
use App\Livewire\Devices\DeviceManagement;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\EmployeeIdentityAccount;
use App\Models\MicrosoftDeviceLink;
use App\Models\User;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftDeviceSyncService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MicrosoftDeviceSyncTest extends TestCase
{
    use DatabaseMigrations;

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const OBJECT = '22222222-2222-4222-8222-222222222222';

    private const DEVICE = '33333333-3333-4333-8333-333333333333';

    private const OWNER = '44444444-4444-4444-8444-444444444444';

    private const INTUNE = '55555555-5555-4555-8555-555555555555';

    private const PRIMARY = '66666666-6666-4666-8666-666666666666';

    private User $admin;

    private User $employee;

    private array $directory;

    private array $owners;

    private array $managed = [];

    private array $primaryUsers = [];

    private int $intuneStatus = 200;

    private ?array $secondDirectoryPage = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->settings()->save([
            'enabled' => true, 'tenant_id' => self::TENANT,
            'client_id' => '77777777-7777-4777-8777-777777777777',
            'client_secret' => str_repeat('test-only-', 5),
        ], $this->admin);
        EmployeeIdentityAccount::query()->create([
            'user_id' => $this->employee->id, 'provider' => AccountProvider::Microsoft365,
            'external_id' => self::OWNER, 'tenant_id' => self::TENANT,
            'principal' => 'employee@example.test', 'email' => 'employee@example.test',
            'lifecycle_status' => 'active', 'provisioning_status' => 'pending_provider', 'license_status' => 'unknown',
        ]);
        $this->directory = [[
            'id' => self::OBJECT, 'deviceId' => self::DEVICE, 'displayName' => 'RT-WINDOWS-01',
            'operatingSystem' => 'Windows', 'operatingSystemVersion' => '10.0.26100',
            'trustType' => 'AzureAd', 'accountEnabled' => true, 'isManaged' => false,
            'isCompliant' => null, 'approximateLastSignInDateTime' => now()->subDays(3)->toIso8601String(),
        ]];
        $this->owners = [self::OBJECT => [self::OWNER]];
        Http::preventStrayRequests();
        $this->fakeGraph();
    }

    public function test_windows_inventory_and_explicit_owner_link_sync_idempotently_without_claiming_mdm_or_live_contact(): void
    {
        $first = $this->sync();
        $this->assertSame('success', $first['status']);
        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $first['assigned']);
        $device = Device::query()->sole();
        $this->assertSame($this->employee->id, $device->activeAssignment->user_id);
        $this->assertSame(DeviceManagementStatus::Unmanaged, $device->management_status);
        $this->assertNull($device->last_seen_at);
        $this->assertNull($device->last_synced_at);
        $this->assertNull($device->asset_tag);
        $this->assertNull($device->activeAssignment->handover_at);
        $this->assertSame(self::DEVICE, $device->microsoftLink->entra_device_id);
        $this->assertSame('matched', $device->microsoftLink->assignment_status);
        $this->assertSame('pending_provider', EmployeeIdentityAccount::query()->sole()->provisioning_status);
        $this->assertSame('pending', $device->readinessChecks()->where('check_key', 'enrollment')->first()?->status);
        $second = $this->sync();
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['assigned']);
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('device_assignments', 1);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/v2.0/token')
            && $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'https://graph.microsoft.com/.default');
        Http::assertNotSent(fn (Request $request) => ! in_array($request->method(), ['GET', 'POST'], true));
    }

    public function test_foreign_or_unbound_tenant_cannot_assign_the_matching_object_id(): void
    {
        EmployeeIdentityAccount::query()->update(['tenant_id' => '99999999-9999-4999-8999-999999999999']);
        $result = $this->sync();
        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['assigned']);
        $this->assertSame('identity_unlinked', MicrosoftDeviceLink::query()->sole()->assignment_status);
        EmployeeIdentityAccount::query()->update(['tenant_id' => null]);
        $this->assertSame(0, $this->sync()['assigned']);
        $this->assertDatabaseCount('device_assignments', 0);
    }

    public function test_existing_assignment_and_recorded_return_are_not_replaced_by_microsoft(): void
    {
        $this->sync();
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        DeviceAssignment::query()->update(['user_id' => $other->id]);
        $this->assertSame(1, $this->sync()['conflicts']);
        $this->assertSame($other->id, DeviceAssignment::query()->sole()->user_id);
        $this->assertSame('assignment_conflict', MicrosoftDeviceLink::query()->sole()->assignment_status);
        DeviceAssignment::query()->update(['status' => 'returned', 'returned_at' => now()]);
        Device::query()->update(['lifecycle_status' => 'inventory']);
        $this->assertSame(0, $this->sync()['assigned']);
        $this->assertSame('manual_review', MicrosoftDeviceLink::query()->sole()->assignment_status);
        $this->assertDatabaseCount('device_assignments', 1);
    }

    public function test_multiple_owners_and_disabled_directory_accounts_do_not_assign(): void
    {
        $this->owners[self::OBJECT][] = self::PRIMARY;
        $result = $this->sync();
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(0, $result['assigned']);
        $this->assertSame('ambiguous_owner', MicrosoftDeviceLink::query()->sole()->assignment_status);
        $this->owners[self::OBJECT] = [self::OWNER];
        $this->directory[0]['accountEnabled'] = false;
        $this->sync();
        $this->assertSame('disabled', MicrosoftDeviceLink::query()->sole()->directory_status);
        $this->assertSame('directory_disabled', MicrosoftDeviceLink::query()->sole()->assignment_status);
        $this->assertDatabaseCount('device_assignments', 0);
    }

    public function test_intune_primary_user_takes_precedence_and_serial_matches_an_existing_inventory_device(): void
    {
        $this->settings()->save(['intune_enabled' => true], $this->admin);
        $primary = User::factory()->create(['role' => 'staff', 'status' => true]);
        EmployeeIdentityAccount::query()->create([
            'user_id' => $primary->id, 'provider' => AccountProvider::Microsoft365,
            'external_id' => self::PRIMARY, 'tenant_id' => self::TENANT,
            'principal' => 'primary@example.test', 'lifecycle_status' => 'active',
        ]);
        $existing = Device::query()->create([
            'asset_tag' => 'RT-EXISTING', 'serial_number' => 'SERIAL-EXISTING',
            'display_name' => 'Manuell gepflegter Name', 'platform' => 'windows',
            'primary_provider' => 'openuem', 'primary_provider_device_id' => 'open-42',
            'declared_location' => 'Lager Berlin',
        ]);
        $this->managed = [[
            'id' => self::INTUNE, 'azureADDeviceId' => self::DEVICE,
            'operatingSystem' => 'Windows', 'serialNumber' => 'SERIAL-EXISTING',
            'complianceState' => 'compliant', 'lastSyncDateTime' => now()->subMinutes(5)->toIso8601String(),
        ]];
        $this->primaryUsers[self::INTUNE] = [self::PRIMARY];
        $result = $this->sync();
        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['assigned']);
        $device = $existing->fresh();
        $this->assertSame($primary->id, $device->activeAssignment->user_id);
        $this->assertSame('Manuell gepflegter Name', $device->display_name);
        $this->assertSame('openuem', $device->primary_provider);
        $this->assertSame('Lager Berlin', $device->declared_location);
        $this->assertSame(self::INTUNE, $device->microsoftLink->intune_device_id);
        $this->assertSame('intune_primary_user', $device->microsoftLink->assignment_source);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_optional_intune_failure_preserves_inventory_but_does_not_guess_the_primary_user(): void
    {
        $this->settings()->save(['intune_enabled' => true], $this->admin);
        $this->intuneStatus = 403;
        $result = $this->sync();
        $this->assertSame('partial', $result['status']);
        $this->assertSame('forbidden', $result['intune_status']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['assigned']);
        $this->assertSame('intune_unavailable', MicrosoftDeviceLink::query()->sole()->assignment_status);
    }

    public function test_directory_removal_only_marks_absence_and_keeps_inventory_history(): void
    {
        $this->sync();
        $this->directory = [];
        $result = $this->sync();
        $this->assertSame('success', $result['status']);
        $this->assertSame('missing', MicrosoftDeviceLink::query()->sole()->directory_status);
        $this->assertSame($this->employee->id, DeviceAssignment::query()->sole()->user_id);
        $this->assertSame('active', DeviceAssignment::query()->sole()->status);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_complete_paging_and_reordered_batches_cover_more_than_twenty_devices(): void
    {
        $records = [];
        for ($index = 0; $index < 21; $index++) {
            $record = $this->directory[0];
            $record['id'] = (string) Str::uuid();
            $record['deviceId'] = (string) Str::uuid();
            $record['displayName'] = 'RT-PAGED-'.$index;
            $records[] = $record;
            $this->owners[$record['id']] = [self::OWNER];
        }
        $this->directory = array_slice($records, 0, 10);
        $this->secondDirectoryPage = array_slice($records, 10);
        $result = $this->sync();
        $this->assertSame('success', $result['status']);
        $this->assertSame(21, $result['created']);
        $this->assertSame(21, $result['assigned']);
        $this->assertDatabaseCount('devices', 21);
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/$batch')));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.microsoft.com/v1.0/devices?page=2');
    }

    public function test_present_directory_object_with_missing_os_is_not_marked_deleted(): void
    {
        $this->sync();
        $this->directory[0]['operatingSystem'] = null;
        $this->assertSame('success', $this->sync()['status']);
        $this->assertSame('present', MicrosoftDeviceLink::query()->sole()->directory_status);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_later_intune_discovery_fills_empty_serial_but_never_overwrites_a_different_serial(): void
    {
        $this->sync();
        $this->settings()->save(['intune_enabled' => true], $this->admin);
        $this->managed = [[
            'id' => self::INTUNE, 'azureADDeviceId' => self::DEVICE,
            'operatingSystem' => 'Windows', 'serialNumber' => 'SERIAL-ADDED-LATER',
        ]];
        $this->primaryUsers[self::INTUNE] = [self::OWNER];
        $this->assertSame('success', $this->sync()['status']);
        $this->assertSame('SERIAL-ADDED-LATER', Device::query()->sole()->serial_number);
        $this->managed[0]['serialNumber'] = 'SERIAL-CHANGED';
        $this->assertSame(1, $this->sync()['conflicts']);
        $this->assertSame('SERIAL-ADDED-LATER', Device::query()->sole()->serial_number);
        $this->assertSame('serial_conflict', MicrosoftDeviceLink::query()->sole()->assignment_status);
    }

    public function test_paging_is_complete_and_foreign_next_links_never_receive_the_graph_token(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*oauth2/v2.0/token' => Http::response(['access_token' => 'test-token', 'token_type' => 'Bearer']),
            'https://graph.microsoft.com/v1.0/devices*' => Http::response([
                'value' => $this->directory, '@odata.nextLink' => 'https://attacker.example/v1.0/devices?page=2',
            ]),
        ]);
        $this->assertSame('failed', $this->sync()['status']);
        $this->assertDatabaseCount('devices', 0);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'attacker.example'));
    }

    public function test_batch_item_failure_rolls_back_all_discovery_writes(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*oauth2/v2.0/token' => Http::response(['access_token' => 'test-token', 'token_type' => 'Bearer']),
            'https://graph.microsoft.com/v1.0/devices*' => Http::response(['value' => $this->directory]),
            'https://graph.microsoft.com/v1.0/$batch' => Http::response(['responses' => [[
                'id' => '0', 'status' => 403, 'body' => ['error' => ['message' => 'must-not-be-stored']],
            ]]]),
        ]);
        $this->assertSame('forbidden', $this->sync()['status']);
        $this->assertDatabaseCount('devices', 0);
        $this->assertStringNotContainsString('must-not-be-stored', json_encode($this->settings()->status()));
    }

    public function test_configuration_change_during_graph_response_discards_stale_data(): void
    {
        $this->fakeGraph(function (): void {
            $this->settings()->save(['enabled' => false], $this->admin);
        });
        $this->assertSame('stale_configuration', $this->sync()['status']);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_disabled_auto_assignment_shows_candidate_and_device_filter_works(): void
    {
        $this->settings()->save(['auto_assign' => false], $this->admin);
        $this->sync();
        $device = Device::query()->sole();
        $this->assertSame('suggested', $device->microsoftLink->assignment_status);
        $this->assertSame($this->employee->id, $device->microsoftLink->suggested_user_id);
        Livewire::actingAs($this->admin)->test(DeviceManagement::class)
            ->set('microsoftFilter', 'linked')
            ->assertSee('RT-WINDOWS-01')
            ->call('selectDevice', $device->public_id)
            ->assertSee('Microsoft Entra & Windows')
            ->assertSee('Intune-Verwaltung')
            ->call('clearFilters')
            ->assertSet('microsoftFilter', '');
    }

    private function fakeGraph(?callable $onBatch = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($onBatch) {
            if (str_contains($request->url(), '/oauth2/v2.0/token')) {
                return Http::response(['access_token' => 'test-token', 'token_type' => 'Bearer']);
            }
            if (str_contains($request->url(), '/$batch')) {
                $responses = [];
                foreach ($request['requests'] as $entry) {
                    $isIntune = str_contains($entry['url'], 'managedDevices/');
                    preg_match('~/([a-f0-9-]{36})/(registeredOwners|users)$~', $entry['url'], $match);
                    $ids = ($isIntune ? $this->primaryUsers : $this->owners)[$match[1] ?? ''] ?? [];
                    $responses[] = ['id' => $entry['id'], 'status' => 200, 'body' => ['value' => array_map(
                        fn ($id) => ['id' => $id, '@odata.type' => '#microsoft.graph.user'], $ids,
                    )]];
                }
                if ($onBatch) {
                    $onBatch();
                }

                return Http::response(['responses' => array_reverse($responses)]);
            }
            if (str_contains($request->url(), '/deviceManagement/managedDevices')) {
                return Http::response(['value' => $this->managed], $this->intuneStatus);
            }
            if (str_contains($request->url(), '/devices?')) {
                if (str_contains($request->url(), 'page=2')) {
                    return Http::response(['value' => $this->secondDirectoryPage]);
                }

                return Http::response(array_filter([
                    'value' => $this->directory,
                    '@odata.nextLink' => $this->secondDirectoryPage !== null ? 'https://graph.microsoft.com/v1.0/devices?page=2' : null,
                ], fn ($value) => $value !== null));
            }
            $this->fail('Unexpected Graph request.');
        });
    }

    private function sync(): array
    {
        return app(MicrosoftDeviceSyncService::class)->sync();
    }

    private function settings(): MicrosoftDeviceSettings
    {
        return app(MicrosoftDeviceSettings::class);
    }
}
