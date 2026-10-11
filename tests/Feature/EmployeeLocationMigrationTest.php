<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeLocationMigrationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const TABLES = ['mobile_location_installations', 'employee_location_consents', 'mobile_location_codes', 'employee_location_consent_events', 'employee_location_points'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
    }

    public function test_missing_dependencies_abort_before_creating_any_location_table(): void
    {
        $message = $this->migrationFailure();
        $this->assertStringContainsString('devices', $message);
        $this->assertStringContainsString('device_assignments', $message);
        $this->assertStringContainsString('work_time_entries', $message);
        $this->assertStringContainsString('2026_08_17_070000', $message);
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_missing_dependencies_do_not_remove_an_empty_interrupted_table(): void
    {
        $this->partialInstallation();
        $this->migrationFailure();
        $this->assertTrue(Schema::hasColumn('mobile_location_installations', 'interrupted_marker'));
        $this->assertFalse(Schema::hasTable('employee_location_consents'));
    }

    public function test_missing_parent_identifier_is_reported_before_any_ddl(): void
    {
        Schema::create('devices', fn (Blueprint $table) => $table->string('label'));
        Schema::create('device_assignments', fn (Blueprint $table) => $table->id());
        Schema::create('work_time_entries', fn (Blueprint $table) => $table->id());
        $this->assertStringContainsString('devices.id', $this->migrationFailure());
        $this->assertFalse(Schema::hasTable('mobile_location_installations'));
    }

    public function test_empty_interrupted_tables_are_rebuilt_with_all_foreign_keys(): void
    {
        $this->dependencies();
        $this->partialInstallation();
        Schema::create('employee_location_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('installation_id')->constrained('mobile_location_installations');
        });
        $this->migration()->up();
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertFalse(Schema::hasColumn('mobile_location_installations', 'interrupted_marker'));
        $foreignTables = array_column(Schema::getForeignKeys('mobile_location_installations'), 'foreign_table');
        $this->assertContains('users', $foreignTables);
        $this->assertContains('device_assignments', $foreignTables);
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    public function test_any_populated_owned_table_blocks_all_partial_schema_cleanup(): void
    {
        $this->dependencies();
        $this->partialInstallation();
        Schema::create('employee_location_points', function (Blueprint $table): void {
            $table->id();
            $table->string('preserved_evidence');
        });
        DB::table('employee_location_points')->insert(['preserved_evidence' => 'must-survive']);
        $this->assertStringContainsString('employee_location_points', $this->migrationFailure());
        $this->assertTrue(Schema::hasColumn('mobile_location_installations', 'interrupted_marker'));
        $this->assertDatabaseHas('employee_location_points', ['preserved_evidence' => 'must-survive']);
        $this->assertFalse(Schema::hasTable('employee_location_consents'));
    }

    public function test_an_entirely_empty_complete_schema_can_resume_after_failed_migration_bookkeeping(): void
    {
        $this->dependencies();
        $this->migration()->up();
        $this->migration()->up();
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertTrue(Schema::hasColumns('employee_location_points', ['event_id', 'position', 'measured_at', 'received_at']));
    }

    public function test_mysql_domain_timestamps_keep_microseconds_without_implicit_timestamp_defaults(): void
    {
        $connection = new class(static fn () => throw new RuntimeException('This schema compilation test must never connect.'), 'schema-contract') extends MySqlConnection
        {
            public function isMaria(): bool
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.36';
            }
        };
        $connection->useDefaultSchemaGrammar();
        $statements = [];
        Schema::shouldReceive('hasTable')->andReturnUsing(static fn (string $table): bool => in_array($table, ['users', 'devices', 'device_assignments', 'work_time_entries'], true));
        Schema::shouldReceive('hasColumn')->andReturn(true);
        Schema::shouldReceive('create')->andReturnUsing(function (string $table, Closure $callback) use ($connection, &$statements): void {
            $blueprint = new Blueprint($connection, $table);
            $blueprint->create();
            $callback($blueprint);
            $statements[$table] = implode("\n", $blueprint->toSql());
        });
        $this->migration()->up();
        foreach (['employee_location_consents' => ['expires_at'], 'mobile_location_codes' => ['expires_at'], 'employee_location_consent_events' => ['occurred_at'], 'employee_location_points' => ['measured_at', 'received_at']] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertStringContainsString('`'.$column.'` datetime(6) not null', $statements[$table]);
            }
        }
        $this->assertStringContainsString('`permit_expires_at` datetime(6) null', $statements['mobile_location_installations']);
    }

    public function test_base_device_enrollment_expiry_compiles_without_a_zero_timestamp_default(): void
    {
        $connection = new MySqlConnection(static fn () => throw new RuntimeException('This schema compilation test must never connect.'), 'schema-contract');
        $connection->useDefaultSchemaGrammar();
        $statements = [];
        Schema::shouldReceive('hasTable')->andReturn(false);
        Schema::shouldReceive('create')->andReturnUsing(function (string $table, Closure $callback) use ($connection, &$statements): void {
            $blueprint = new Blueprint($connection, $table);
            $blueprint->create();
            $callback($blueprint);
            $statements[$table] = implode("\n", $blueprint->toSql());
        });
        (require database_path('migrations/2026_08_17_070000_create_device_management_tables.php'))->up();
        $this->assertStringContainsString('`expires_at` datetime not null', $statements['device_enrollments']);
        $this->assertStringNotContainsString('0000-00-00', $statements['device_enrollments']);
    }

    public function test_base_device_migration_recovers_its_empty_interruption_with_required_expiry(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('interrupted_marker')->nullable();
        });
        $migration = require database_path('migrations/2026_08_17_070000_create_device_management_tables.php');
        $migration->up();
        $this->assertFalse(Schema::hasColumn('devices', 'interrupted_marker'));
        $this->assertTrue(Schema::hasColumns('device_enrollments', ['expires_at', 'invited_at', 'token_hash']));
        $migration->up();
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('device_enrollments', 0);
        $migration->down();
        $this->assertFalse(Schema::hasTable('devices'));
        $this->assertFalse(Schema::hasTable('device_enrollments'));
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_base_device_recovery_keeps_populated_partial_schema_intact(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('preserved_evidence');
        });
        DB::table('devices')->insert(['preserved_evidence' => 'existing-device']);
        $migration = require database_path('migrations/2026_08_17_070000_create_device_management_tables.php');
        try {
            $migration->up();
            $this->fail('Device migration must not remove a populated partial schema.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('enthält bereits Daten', $exception->getMessage());
        }
        $this->assertDatabaseHas('devices', ['preserved_evidence' => 'existing-device']);
        $this->assertFalse(Schema::hasTable('device_assignments'));
    }

    private function dependencies(): void
    {
        foreach (['devices', 'device_assignments', 'work_time_entries'] as $name) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
        }
    }

    private function partialInstallation(): void
    {
        Schema::create('mobile_location_installations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('interrupted_marker')->nullable();
        });
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_11_120200_create_employee_location_tables.php');
    }

    private function migrationFailure(): string
    {
        try {
            $this->migration()->up();
            $this->fail('Migration must reject missing dependencies or populated existing tables.');
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
    }
}
