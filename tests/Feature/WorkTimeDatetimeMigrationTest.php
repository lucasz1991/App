<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Grammars\MariaDbGrammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\SQLiteBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class WorkTimeDatetimeMigrationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const TABLES = ['work_time_entries', 'work_time_revisions', 'work_time_exports', 'work_time_basic_exports'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
    }

    public function test_sqlite_is_untouched_including_existing_raw_values(): void
    {
        $this->createTimestampFixtures();
        $before = $this->rows();
        DB::enableQueryLog();
        $this->migration()->up();
        $this->migration()->up();
        $this->migration()->down();
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($before, $this->rows());
        $this->assertSame('timestamp', Schema::getColumnType('work_time_entries', 'starts_at'));
    }

    public function test_change_definitions_preserve_rows_other_columns_indexes_and_are_idempotent(): void
    {
        $this->createTimestampFixtures();
        $before = $this->rows();
        $indexes = Schema::getIndexes('work_time_entries');
        $untouched = collect(Schema::getColumns('work_time_entries'))->firstWhere('name', 'untouched_at');

        // Execute the real change callbacks on isolated SQLite fixtures. This is
        // value-preservation coverage, not a substitute for MariaDB integration.
        $schema = Mockery::mock(SQLiteBuilder::class, [DB::connection()])->makePartial();
        $driver = Mockery::mock(Connection::class);
        $driver->shouldReceive('getDriverName')->andReturn('mysql');
        $schema->shouldReceive('getConnection')->andReturn($driver);
        Schema::swap($schema);

        $this->migration()->up();

        $this->assertSame($before, $this->rows());
        $this->assertSame($indexes, $schema->getIndexes('work_time_entries'));
        $this->assertSame($untouched, collect($schema->getColumns('work_time_entries'))->firstWhere('name', 'untouched_at'));
        $columns = collect($schema->getColumns('work_time_entries'))->keyBy('name');
        foreach (['starts_at' => false, 'ends_at' => true, 'paused_at' => true] as $column => $nullable) {
            $this->assertSame('datetime', $columns[$column]['type_name']);
            $this->assertSame($nullable, $columns[$column]['nullable']);
            $this->assertNull($columns[$column]['default']);
        }
        foreach (array_slice(self::TABLES, 1) as $table) {
            $column = collect($schema->getColumns($table))->firstWhere('name', 'created_at');
            $this->assertSame('datetime', $column['type_name']);
            $this->assertFalse($column['nullable']);
            $this->assertNull($column['default']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->migration()->up();
        $this->migration()->down();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], array_values(array_filter($queries, fn (array $query): bool => preg_match('/^\s*(alter|create|drop|insert|update|delete|replace)\b/i', $query['query']) === 1)));
        $this->assertSame($before, $this->rows());
    }

    public function test_mysql_and_mariadb_compile_only_targeted_datetime_changes_without_automatic_mutation(): void
    {
        foreach (['mysql' => MySqlGrammar::class, 'mariadb' => MariaDbGrammar::class] as $driver => $grammar) {
            $connection = new MySqlConnection(fn () => throw new \RuntimeException('No database connection allowed.'), 'fixture', 'rt_', ['driver' => $driver]);
            $connection->setSchemaGrammar(new $grammar($connection));
            $schema = Mockery::mock(Builder::class);
            $schema->shouldReceive('getConnection')->andReturn($connection);
            $schema->shouldReceive('hasTable')->with('work_time_entries')->andReturnTrue();
            $schema->shouldReceive('getColumns')->with('work_time_entries')->andReturn([
                ['name' => 'starts_at', 'type_name' => 'timestamp', 'type' => 'timestamp(6)', 'comment' => null],
                ['name' => 'ends_at', 'type_name' => 'timestamp', 'type' => 'timestamp', 'comment' => null],
                ['name' => 'paused_at', 'type_name' => 'timestamp', 'type' => 'timestamp', 'comment' => null],
                ['name' => 'other_at', 'type_name' => 'timestamp', 'type' => 'timestamp', 'comment' => null],
            ]);
            foreach (array_slice(self::TABLES, 1) as $table) {
                $schema->shouldReceive('hasTable')->with($table)->andReturnTrue();
                $schema->shouldReceive('getColumns')->with($table)->andReturn([
                    ['name' => 'created_at', 'type_name' => 'timestamp', 'type' => 'timestamp', 'comment' => null],
                ]);
            }
            $sql = [];
            $schema->shouldReceive('table')->times(4)->andReturnUsing(function (string $table, \Closure $callback) use ($connection, &$sql): void {
                $sql = [...$sql, ...(new Blueprint($connection, $table, $callback))->toSql()];
            });
            Schema::swap($schema);

            $this->migration()->up();

            $this->assertSame([
                'alter table `rt_work_time_entries` modify `starts_at` datetime(6) not null',
                'alter table `rt_work_time_entries` modify `ends_at` datetime null',
                'alter table `rt_work_time_entries` modify `paused_at` datetime null',
                'alter table `rt_work_time_revisions` modify `created_at` datetime not null',
                'alter table `rt_work_time_exports` modify `created_at` datetime not null',
                'alter table `rt_work_time_basic_exports` modify `created_at` datetime not null',
            ], $sql, $driver);
        }
    }

    public function test_missing_tables_columns_and_existing_datetime_columns_are_not_modified(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mariadb');
        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('getConnection')->andReturn($connection);
        $schema->shouldReceive('hasTable')->with('work_time_entries')->andReturnTrue();
        $schema->shouldReceive('getColumns')->with('work_time_entries')->andReturn([
            ['name' => 'starts_at', 'type_name' => 'datetime', 'type' => 'datetime'],
            ['name' => 'other_at', 'type_name' => 'timestamp', 'type' => 'timestamp'],
        ]);
        $schema->shouldReceive('hasTable')->with('work_time_revisions')->andReturnTrue();
        $schema->shouldReceive('getColumns')->with('work_time_revisions')->andReturn([]);
        $schema->shouldReceive('hasTable')->with('work_time_exports')->andReturnFalse();
        $schema->shouldReceive('hasTable')->with('work_time_basic_exports')->andReturnFalse();
        $schema->shouldNotReceive('table');
        Schema::swap($schema);

        $this->migration()->up();
        $this->migration()->down();
        $this->addToAssertionCount(1);
    }

    private function createTimestampFixtures(): void
    {
        DB::statement('CREATE TABLE work_time_entries (id INTEGER PRIMARY KEY, starts_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ends_at TIMESTAMP NULL, paused_at TIMESTAMP NULL, untouched_at TIMESTAMP NULL, note VARCHAR(100) NOT NULL DEFAULT \'preserved\')');
        DB::statement('CREATE INDEX work_time_entries_starts_at_index ON work_time_entries (starts_at)');
        DB::table('work_time_entries')->insert([
            ['id' => 1, 'starts_at' => '2026-03-29 00:59:59.123456', 'ends_at' => '2026-03-29 02:30:00', 'paused_at' => null, 'untouched_at' => '2026-03-28 19:00:00', 'note' => 'original'],
            ['id' => 2, 'starts_at' => '2026-10-25 01:30:00', 'ends_at' => null, 'paused_at' => '2026-10-25 01:45:00', 'untouched_at' => null, 'note' => 'paused'],
        ]);
        foreach (array_slice(self::TABLES, 1) as $table) {
            DB::statement("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, note VARCHAR(100) NOT NULL)");
            DB::table($table)->insert(['id' => 1, 'created_at' => '2026-07-15 20:15:30', 'note' => 'unchanged']);
        }
    }

    private function rows(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_08_120000_stabilize_work_time_datetime_columns.php');
    }
}
