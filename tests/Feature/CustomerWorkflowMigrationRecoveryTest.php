<?php

namespace Tests\Feature;

use App\Support\Operations\CustomerWorkflowSchemaRepair;
use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\MySqlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomerWorkflowMigrationRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Migration recovery fixtures require isolated SQLite :memory:.');
        }

        Schema::dropAllTables();
        foreach (['users', 'customers', 'files', 'operation_inquiries', 'employee_document_requirements'] as $name) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
            DB::table($name)->insert(['id' => 1]);
        }
    }

    public function test_actual_mysql_grammar_compiles_every_fresh_index_and_foreign_key_within_identifier_limit(): void
    {
        // The repair's read-only data validation uses this empty isolated mirror.
        $this->migration()->up();
        $mysql = new class(null, 'synthetic_mysql', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'version' => '8.0.36']) extends MySqlConnection
        {
            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.36';
            }
        };
        $mysql->useDefaultSchemaGrammar();
        $builder = new class($mysql) extends MySqlBuilder
        {
            public array $blueprints = [];

            public array $statements = [];

            public function hasTable($table)
            {
                return isset($this->blueprints[$table]);
            }

            public function create($table, Closure $callback)
            {
                $blueprint = new Blueprint($this->connection, $table);
                $blueprint->create();
                $callback($blueprint);
                $this->statements[$table] = $blueprint->toSql();
                $this->blueprints[$table] = $blueprint;
            }

            public function hasColumns($table, array $columns)
            {
                $present = array_map(fn ($column) => $column->name, $this->blueprints[$table]->getColumns());

                return array_diff($columns, $present) === [];
            }

            public function getIndexes($table)
            {
                $indexes = [];
                foreach ($this->blueprints[$table]->getCommands() as $command) {
                    if (in_array($command->name, ['unique', 'index', 'primary'], true)) {
                        $indexes[] = ['name' => $command->index, 'columns' => $command->columns, 'unique' => $command->name !== 'index'];
                    }
                }

                return $indexes;
            }

            public function getForeignKeys($table)
            {
                $keys = [];
                foreach ($this->blueprints[$table]->getCommands() as $command) {
                    if ($command->name === 'foreign') {
                        $keys[] = [
                            'name' => $command->index, 'columns' => $command->columns,
                            'foreign_schema' => $this->connection->getDatabaseName(), 'foreign_table' => (string) $command->on,
                            'foreign_columns' => (array) $command->references, 'on_delete' => $command->onDelete ?? 'no action',
                            'on_update' => $command->onUpdate ?? 'no action',
                        ];
                    }
                }

                return $keys;
            }
        };
        $original = Schema::getFacadeRoot();
        try {
            Schema::swap($builder);
            $this->migration()->up();
        } finally {
            Schema::swap($original);
        }

        $sql = implode("\n", array_merge(...array_values($builder->statements)));
        $this->assertStringContainsString('add unique `cor_subject_revision_unique`', $sql);
        $this->assertStringContainsString('add constraint `edv_requirement_foreign`', $sql);
        $this->assertCount(7, $builder->blueprints);
        foreach ($builder->blueprints as $table => $blueprint) {
            foreach ($blueprint->getCommands() as $command) {
                if (in_array($command->name, ['unique', 'index', 'foreign', 'primary'], true)) {
                    $this->assertLessThanOrEqual(64, strlen($command->index), $table.': '.$command->index);
                }
            }
        }
        $this->assertNull($mysql->getRawPdo(), 'SQL grammar verification must not open a MySQL connection.');
    }

    public function test_fresh_migration_and_forward_repair_preserve_all_constraints_on_repeated_runs(): void
    {
        $this->migration()->up();
        $this->assertWorkflowConstraints();
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow());
        $before = $this->rows();
        $indexes = $this->workflowIndexes();
        $foreignKeys = $this->workflowForeignKeys();

        $this->migration()->up();
        $this->forwardMigration()->up();
        $this->forwardMigration()->up();

        $this->assertSame($before, $this->rows());
        $this->assertSame($indexes, $this->workflowIndexes());
        $this->assertSame($foreignKeys, $this->workflowForeignKeys());
        $this->assertWorkflowConstraints();
    }

    public function test_retry_after_offer_table_ddl_repairs_its_missing_unique_and_foreign_keys_without_changing_rows(): void
    {
        $this->partialOfferTable();
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        $before = $this->tableRows('commercial_offer_revisions');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($before, $this->tableRows('commercial_offer_revisions'));
        $this->assertWorkflowConstraints();
        $this->expectException(QueryException::class);
        DB::table('commercial_offer_revisions')->insert($this->offerRow(['id' => 2]));
    }

    public function test_retry_after_document_table_ddl_repairs_all_missing_constraints_and_preserves_snapshots(): void
    {
        $this->partialDocumentTable();
        DB::table('employee_document_versions')->insert($this->documentRow());
        $before = $this->tableRows('employee_document_versions');

        $this->migration()->up();
        $this->forwardMigration()->up();

        $this->assertSame($before, $this->tableRows('employee_document_versions'));
        $this->assertWorkflowConstraints();
        $this->expectException(QueryException::class);
        DB::table('employee_document_requirements')->where('id', 1)->delete();
    }

    public function test_forward_repair_covers_a_migration_previously_marked_complete_with_both_tables_partial(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow());
        $before = $this->rows();

        $this->forwardMigration()->up();
        $this->forwardMigration()->up();

        $this->assertSame($before, $this->rows());
        $this->assertWorkflowConstraints();
        $this->assertFalse(Schema::hasTable('customer_contacts'));
    }

    public function test_valid_semantically_equivalent_constraint_names_are_retained_without_duplicates(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        Schema::table('commercial_offer_revisions', function (Blueprint $table): void {
            $table->unique(['subject_type', 'subject_id', 'revision'], 'reviewed_offer_revision_unique');
            $table->foreign('created_by', 'reviewed_offer_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('accepted_by', 'reviewed_offer_acceptor_fk')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('employee_document_versions', function (Blueprint $table): void {
            $table->unique(['employee_document_requirement_id', 'revision'], 'reviewed_requirement_revision_unique');
            $table->unique('file_id', 'reviewed_document_file_unique');
            $table->foreign('employee_document_requirement_id', 'reviewed_document_requirement_fk')->references('id')->on('employee_document_requirements')->restrictOnDelete();
            $table->foreign('file_id', 'reviewed_document_file_fk')->references('id')->on('files')->restrictOnDelete();
            foreach (['created_by', 'withdrawn_by', 'acknowledged_by'] as $column) {
                $table->foreign($column, 'reviewed_document_'.$column.'_fk')->references('id')->on('users')->restrictOnDelete();
            }
        });
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow());
        $before = $this->rows();
        $indexes = $this->workflowIndexes();
        $foreignKeys = $this->workflowForeignKeys();

        $this->forwardMigration()->up();

        $this->assertSame($before, $this->rows());
        $this->assertSame($indexes, $this->workflowIndexes());
        $this->assertSame($foreignKeys, $this->workflowForeignKeys());
        $this->assertWorkflowConstraints();
    }

    public function test_duplicate_offer_revisions_block_repair_before_any_missing_constraint_is_added(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        DB::table('commercial_offer_revisions')->insert([$this->offerRow(), $this->offerRow(['id' => 2])]);
        DB::table('employee_document_versions')->insert($this->documentRow());

        $this->assertRepairBlockedWithoutChanges('commercial_offer_revisions');
    }

    public function test_duplicate_document_revisions_block_repair_without_deleting_history(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        DB::table('files')->insert(['id' => 2]);
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert([$this->documentRow(), $this->documentRow(['id' => 2, 'file_id' => 2])]);

        $this->assertRepairBlockedWithoutChanges('employee_document_versions');
    }

    public function test_duplicate_document_files_block_repair_without_deleting_history(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert([$this->documentRow(), $this->documentRow(['id' => 2, 'revision' => 2])]);

        $this->assertRepairBlockedWithoutChanges('employee_document_versions');
    }

    public function test_orphan_document_requirement_blocks_both_table_repairs_before_ddl(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow(['employee_document_requirement_id' => 999]));

        $this->assertRepairBlockedWithoutChanges('employee_document_versions');
    }

    public function test_orphan_offer_actor_blocks_repair_without_changing_offer_snapshot(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        DB::table('commercial_offer_revisions')->insert($this->offerRow(['accepted_by' => 999]));
        DB::table('employee_document_versions')->insert($this->documentRow());

        $this->assertRepairBlockedWithoutChanges('commercial_offer_revisions');
    }

    public function test_wrong_existing_foreign_key_target_is_not_accepted_or_replaced_silently(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        Schema::table('employee_document_versions', fn (Blueprint $table) => $table->foreign('employee_document_requirement_id', 'reviewed_but_wrong_target_fk')->references('id')->on('files')->restrictOnDelete());
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow());

        $this->assertRepairBlockedWithoutChanges('employee_document_versions');
    }

    public function test_cascading_existing_foreign_key_is_rejected_as_incompatible_with_history_retention(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        Schema::table('employee_document_versions', fn (Blueprint $table) => $table->foreign('employee_document_requirement_id', 'reviewed_but_cascading_fk')->references('id')->on('employee_document_requirements')->cascadeOnDelete());
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow());

        $this->assertRepairBlockedWithoutChanges('employee_document_versions');
    }

    public function test_reserved_repair_index_name_collision_fails_closed_without_changing_rows(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        Schema::table('commercial_offer_revisions', fn (Blueprint $table) => $table->index('status', 'cor_subject_revision_unique'));
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow());

        $this->assertRepairBlockedWithoutChanges('commercial_offer_revisions');
    }

    #[DataProvider('requiredKeyColumns')]
    public function test_null_required_keys_in_malformed_partial_tables_block_repair(string $table, string $column): void
    {
        $this->partialOfferTable(true);
        $this->partialDocumentTable(true);
        DB::table('commercial_offer_revisions')->insert($this->offerRow($table === 'commercial_offer_revisions' ? [$column => null] : []));
        DB::table('employee_document_versions')->insert($this->documentRow($table === 'employee_document_versions' ? [$column => null] : []));

        $this->assertRepairBlockedWithoutChanges($table);
    }

    public static function requiredKeyColumns(): array
    {
        return [
            'offer subject type' => ['commercial_offer_revisions', 'subject_type'],
            'offer subject ID' => ['commercial_offer_revisions', 'subject_id'],
            'offer revision' => ['commercial_offer_revisions', 'revision'],
            'document revision' => ['employee_document_versions', 'revision'],
            'document requirement' => ['employee_document_versions', 'employee_document_requirement_id'],
            'document file' => ['employee_document_versions', 'file_id'],
        ];
    }

    public function test_null_required_reference_is_rejected_even_if_matching_foreign_key_exists(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable(true);
        Schema::table('employee_document_versions', fn (Blueprint $table) => $table->foreign('employee_document_requirement_id', 'existing_nullable_requirement_fk')->references('id')->on('employee_document_requirements')->restrictOnDelete());
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow(['employee_document_requirement_id' => null]));

        $this->assertRepairBlockedWithoutChanges('employee_document_versions');
    }

    public function test_missing_required_column_blocks_repair_before_adding_any_constraint(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow());
        Schema::table('commercial_offer_revisions', fn (Blueprint $table) => $table->dropColumn('subject_id'));

        $this->assertRepairBlockedWithoutChanges('commercial_offer_revisions');
    }

    public function test_missing_parent_table_blocks_repair_before_adding_any_constraint(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow());
        Schema::drop('files');

        $this->assertRepairBlockedWithoutChanges('files');
    }

    public function test_no_action_foreign_key_is_preserved_as_semantically_valid(): void
    {
        $this->partialOfferTable();
        $this->partialDocumentTable();
        Schema::table('commercial_offer_revisions', fn (Blueprint $table) => $table->foreign('created_by', 'existing_no_action_creator_fk')->references('id')->on('users'));
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow());
        $before = $this->rows();
        $existing = Schema::getForeignKeys('commercial_offer_revisions')[0];

        $this->forwardMigration()->up();

        $this->assertSame($before, $this->rows());
        $this->assertWorkflowConstraints();
        $keys = array_values(array_filter(Schema::getForeignKeys('commercial_offer_revisions'), fn (array $key) => $key['columns'] === ['created_by']));
        $this->assertSame([$existing], $keys);
    }

    public function test_forward_rollback_does_not_remove_original_workflow_constraints_or_history(): void
    {
        $this->migration()->up();
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow());
        $before = $this->rows();
        $indexes = $this->workflowIndexes();
        $foreignKeys = $this->workflowForeignKeys();

        $this->forwardMigration()->down();

        $this->assertSame($before, $this->rows());
        $this->assertSame($indexes, $this->workflowIndexes());
        $this->assertSame($foreignKeys, $this->workflowForeignKeys());
    }

    public function test_original_rollback_still_blocks_deletion_of_populated_workflow_history(): void
    {
        $this->migration()->up();
        DB::table('commercial_offer_revisions')->insert($this->offerRow());
        DB::table('employee_document_versions')->insert($this->documentRow());
        $before = $this->rows();

        try {
            $this->migration()->down();
            $this->fail('Populated workflow history must not be dropped.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('history must be retained', $exception->getMessage());
        }

        $this->assertSame($before, $this->rows());
        $this->assertWorkflowConstraints();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_04_121000_create_customer_workflow_extensions.php');
    }

    private function forwardMigration(): Migration
    {
        return require database_path('migrations/2026_10_06_113000_repair_customer_workflow_constraints.php');
    }

    private function partialOfferTable(bool $nullableKeys = false): void
    {
        Schema::create('commercial_offer_revisions', function (Blueprint $table) use ($nullableKeys): void {
            $table->id();
            $table->string('subject_type', 30)->nullable($nullableKeys);
            $table->unsignedBigInteger('subject_id')->nullable($nullableKeys);
            $table->unsignedInteger('revision')->nullable($nullableKeys);
            $table->unsignedInteger('state_version')->default(1);
            $table->string('kind', 20);
            $table->string('status', 24)->default('draft');
            $table->json('snapshot');
            $table->unsignedBigInteger('total_cents');
            $table->date('valid_until')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_by')->nullable();
            $table->text('acceptance_note')->nullable();
            $table->timestamps();
        });
    }

    private function partialDocumentTable(bool $nullableKeys = false): void
    {
        Schema::create('employee_document_versions', function (Blueprint $table) use ($nullableKeys): void {
            $table->id();
            $table->unsignedBigInteger('employee_document_requirement_id')->nullable($nullableKeys);
            $table->unsignedBigInteger('file_id')->nullable($nullableKeys);
            $table->unsignedInteger('revision')->nullable($nullableKeys);
            $table->json('snapshot');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->dateTime('created_at');
        });
    }

    private function offerRow(array $overrides = []): array
    {
        return array_replace([
            'id' => 1, 'subject_type' => 'inquiry', 'subject_id' => 41, 'revision' => 7,
            'state_version' => 3, 'kind' => 'offer', 'status' => 'accepted',
            'snapshot' => '{"immutable":"offer history","version":7}', 'total_cents' => 12345,
            'valid_until' => '2026-11-15', 'created_by' => 1, 'issued_at' => '2026-10-01 09:00:00',
            'accepted_at' => '2026-10-02 10:00:00', 'accepted_by' => 1, 'acceptance_note' => 'Retain exact historical note',
            'created_at' => '2026-10-01 08:00:00', 'updated_at' => '2026-10-02 10:00:00',
        ], $overrides);
    }

    private function documentRow(array $overrides = []): array
    {
        return array_replace([
            'id' => 1, 'employee_document_requirement_id' => 1, 'file_id' => 1, 'revision' => 2,
            'snapshot' => '{"immutable":"personnel history","version":2}', 'created_by' => 1,
            'withdrawn_at' => null, 'withdrawn_by' => null, 'acknowledged_at' => '2026-10-02 11:00:00',
            'acknowledged_by' => 1, 'created_at' => '2026-10-01 08:00:00',
        ], $overrides);
    }

    private function tableRows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function rows(): array
    {
        return [
            'offers' => $this->tableRows('commercial_offer_revisions'),
            'documents' => $this->tableRows('employee_document_versions'),
        ];
    }

    private function workflowIndexes(): array
    {
        return [Schema::getIndexes('commercial_offer_revisions'), Schema::getIndexes('employee_document_versions')];
    }

    private function workflowForeignKeys(): array
    {
        return [Schema::getForeignKeys('commercial_offer_revisions'), Schema::getForeignKeys('employee_document_versions')];
    }

    private function assertRepairBlockedWithoutChanges(string $table): void
    {
        $before = $this->rows();
        $indexes = $this->workflowIndexes();
        $foreignKeys = $this->workflowForeignKeys();
        try {
            CustomerWorkflowSchemaRepair::repair();
            $this->fail('Incompatible or invalid retained history must block the repair.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($table, $exception->getMessage());
        }

        $this->assertSame($before, $this->rows());
        $this->assertSame($indexes, $this->workflowIndexes());
        $this->assertSame($foreignKeys, $this->workflowForeignKeys());
    }

    private function assertWorkflowConstraints(): void
    {
        foreach ([
            ['commercial_offer_revisions', ['subject_type', 'subject_id', 'revision']],
            ['employee_document_versions', ['file_id']],
            ['employee_document_versions', ['employee_document_requirement_id', 'revision']],
        ] as [$table, $columns]) {
            $matches = array_filter(Schema::getIndexes($table), fn (array $index) => $index['unique'] && $index['columns'] === $columns);
            $this->assertCount(1, $matches, $table.' unique '.implode(',', $columns));
        }

        foreach ([
            'commercial_offer_revisions' => ['created_by' => 'users', 'accepted_by' => 'users'],
            'employee_document_versions' => [
                'employee_document_requirement_id' => 'employee_document_requirements', 'file_id' => 'files',
                'created_by' => 'users', 'withdrawn_by' => 'users', 'acknowledged_by' => 'users',
            ],
        ] as $table => $requirements) {
            $keys = Schema::getForeignKeys($table);
            $this->assertCount(count($requirements), $keys, $table.' foreign keys');
            foreach ($requirements as $column => $parent) {
                $matches = array_filter($keys, fn (array $key) => $key['columns'] === [$column]
                    && $key['foreign_table'] === $parent && $key['foreign_columns'] === ['id']
                    && in_array(strtolower($key['on_delete']), ['restrict', 'no action'], true));
                $this->assertCount(1, $matches, $table.'.'.$column);
            }
        }
    }
}
