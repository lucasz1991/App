<?php

namespace Tests\Feature;

use App\Models\DeviceReadinessCheck;
use App\Models\User;
use App\Services\DeviceManagement\DeviceInventoryService;
use App\Services\DeviceManagement\DeviceReadinessService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class DeviceReadinessHardeningTest extends TestCase
{
    use DatabaseMigrations;

    public function test_handover_inventory_checks_require_asset_serial_location_and_an_active_employee(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => 'N/A',
            'serial_number' => 'unknown',
            'display_name' => 'Unvollständiges Übergabegerät',
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'declared_location' => 'offen',
            'primary_provider' => 'simulation',
        ], $admin);
        app(DeviceInventoryService::class)->assign($device, $employee, $admin);

        $checks = app(DeviceReadinessService::class)->refresh($device->fresh())->keyBy('check_key');
        $this->assertSame('blocked', $checks->get('asset')?->status);
        $this->assertSame('blocked', $checks->get('serial_number')?->status);
        $this->assertSame('blocked', $checks->get('location')?->status);
        $this->assertSame('passed', $checks->get('assignment')?->status);

        $employee->update(['status' => false]);
        $checks = app(DeviceReadinessService::class)->refresh($device->fresh())->keyBy('check_key');
        $this->assertSame('blocked', $checks->get('assignment')?->status);
        $this->assertFalse(app(DeviceReadinessService::class)->isReady($device));
    }

    public function test_network_certificate_and_remote_support_receipts_expire_at_the_configured_boundary(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-READY-STALE-1',
            'serial_number' => 'SERIAL-READY-STALE-1',
            'display_name' => 'Readiness-Alterung',
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'declared_location' => 'IT-Labor Berlin',
            'primary_provider' => 'simulation',
        ], $admin);

        foreach (['network', 'certificate', 'remote_support'] as $key) {
            DeviceReadinessCheck::query()->updateOrCreate(
                ['device_id' => $device->id, 'check_key' => $key],
                [
                    'label' => DeviceReadinessService::REQUIRED_CHECKS[$key],
                    'status' => 'passed',
                    'source' => 'simulation',
                    'evidence' => ['receipt' => $key.'-ok'],
                    'checked_at' => now(),
                ],
            );
        }

        $this->travel(25)->hours();
        $checks = app(DeviceReadinessService::class)->refresh($device->fresh())->keyBy('check_key');

        foreach (['network', 'certificate', 'remote_support'] as $key) {
            $this->assertSame('stale', $checks->get($key)?->status);
            $this->assertSame(24, $checks->get($key)?->evidence['stale_after_hours'] ?? null);
        }
    }
}
