<?php

namespace Tests\Feature;

use App\Models\EmployeeLocationConsent;
use App\Models\SessionPermissionGrant;
use App\Models\Setting;
use App\Models\User;
use App\Services\Privacy\SessionPermissionService;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class SessionPermissionMigrationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_08_17_070000_create_device_management_tables.php',
            '2026_10_11_120200_create_employee_location_tables.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
    }

    public function test_old_daily_consent_is_preserved_as_evidence_and_never_grants_new_session_rights(): void
    {
        $legacy = EmployeeLocationConsent::create(['user_id' => $this->employee->id, 'purpose' => 'work_routing', 'local_date' => now()->toDateString(),
            'explanation_version' => 1, 'explanation_hash' => hash('sha256', EmployeeLocationSettings::LEGACY_EXPLANATION), 'decision' => 'accepted',
            'accepted_at' => now()->utc(), 'expires_at' => now()->utc()->addDay()]);
        $before = $legacy->fresh()->getRawOriginal();
        $this->migration()->up();
        $after = $legacy->fresh()->getRawOriginal();
        $this->assertNull($after['session_permission_grant_id']);
        unset($after['session_permission_grant_id']);
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('session_permission_grants', 0);
        $this->actingAs($this->employee);
        request()->setLaravelSession(app('session')->driver());
        request()->session()->start();
        $grant = app(SessionPermissionService::class)->touch($this->employee);
        $this->assertNull($grant->location_decision);
        $this->assertNull($grant->push_decision);
    }

    public function test_custom_explanation_and_operational_values_survive_with_explicit_new_session_notice(): void
    {
        $custom = str_repeat('Betrieblich individuell erklärter Zweck. ', 4);
        Setting::create(['type' => EmployeeLocationSettings::GROUP, 'key' => EmployeeLocationSettings::KEY,
            'value' => ['enabled' => true, 'explanation_text' => $custom, 'explanation_version' => 7, 'android_install_url' => 'https://example.test/install']]);
        $this->migration()->up();
        $settings = EmployeeLocationSettings::read();
        $this->assertTrue($settings['enabled']);
        $this->assertStringStartsWith($custom, $settings['explanation_text']);
        $this->assertStringContainsString('aktive Anmeldesitzung', $settings['explanation_text']);
        $this->assertSame(8, $settings['explanation_version']);
        $this->assertSame('https://example.test/install', $settings['android_install_url']);
    }

    public function test_old_default_explanation_changes_to_session_wording_and_disabled_remains_disabled(): void
    {
        Setting::create(['type' => EmployeeLocationSettings::GROUP, 'key' => EmployeeLocationSettings::KEY,
            'value' => ['enabled' => false, 'explanation_text' => EmployeeLocationSettings::LEGACY_EXPLANATION, 'explanation_version' => 1]]);
        $this->migration()->up();
        $settings = EmployeeLocationSettings::read();
        $this->assertFalse($settings['enabled']);
        $this->assertSame(EmployeeLocationSettings::DEFAULTS['explanation_text'], $settings['explanation_text']);
        $this->assertSame(2, $settings['explanation_version']);
        $this->assertStringNotContainsString('um Mitternacht', $settings['explanation_text']);
        $this->assertTrue(Schema::hasColumn('push_subscriptions', 'session_permission_grant_id'));
        $this->assertTrue(Schema::hasColumn('mobile_location_codes', 'consent_id'));
    }

    public function test_new_unique_key_supports_two_explicit_login_consents_on_the_same_date(): void
    {
        $this->migration()->up();
        $first = $this->grant();
        $second = $this->grant();
        foreach ([$first, $second] as $grant) {
            EmployeeLocationConsent::create(['user_id' => $this->employee->id, 'session_permission_grant_id' => $grant->id,
                'local_date' => now()->toDateString(), 'purpose' => 'work_routing', 'explanation_version' => 2,
                'explanation_hash' => hash('sha256', EmployeeLocationSettings::DEFAULTS['explanation_text']), 'decision' => 'declined', 'expires_at' => now()->utc()->addDay()]);
        }
        $this->assertDatabaseCount('employee_location_consents', 2);
    }

    public function test_empty_schema_can_roll_back_but_existing_permission_evidence_blocks_destructive_rollback(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();
        $this->assertFalse(Schema::hasTable('session_permission_grants'));
        $this->assertFalse(Schema::hasColumn('employee_location_consents', 'session_permission_grant_id'));
        $migration->up();
        $this->grant();
        try {
            $migration->down();
            $this->fail('Populated permission evidence must not be dropped.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Nachweise', $exception->getMessage());
        }
        $this->assertDatabaseCount('session_permission_grants', 1);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_11_200000_create_session_permission_grants.php');
    }

    private function grant(): SessionPermissionGrant
    {
        return SessionPermissionGrant::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->employee->id,
            'session_hash' => hash('sha256', random_bytes(20)), 'session_identifier' => 'test-session', 'last_activity_at' => now()->utc(), 'expires_at' => now()->utc()->addDay()]);
    }
}
