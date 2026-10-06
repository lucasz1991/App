<?php

namespace App\Support\Operations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class CustomerWorkflowSchemaRepair
{
    public static function repair(): void
    {
        $pending = [];

        // Validate every repair before the first DDL statement. Never rewrite business history.
        foreach (self::definitions() as $table => $definition) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Workflow repair requires the original migration to create {$table} first.");
            }

            $required = array_unique(array_merge(['id'], array_keys($definition['foreign']), ...array_column($definition['unique'], 'columns')));
            if (! Schema::hasColumns($table, $required)) {
                throw new RuntimeException("Incomplete columns in {$table}; a reviewed forward schema repair is required.");
            }

            foreach (array_unique(array_merge(
                array_keys(array_filter($definition['foreign'], fn (array $key): bool => ! $key[2])),
                ...array_column($definition['unique'], 'columns'),
            )) as $column) {
                if (DB::table($table)->whereNull($column)->exists()) {
                    throw new RuntimeException("Missing required key in {$table}.{$column}; retain the rows for review.");
                }
            }

            $indexes = Schema::getIndexes($table);
            $foreignKeys = Schema::getForeignKeys($table);
            foreach ($definition['unique'] as $index) {
                if (collect($indexes)->contains(fn (array $present): bool => $present['unique'] && $present['columns'] === $index['columns'])) {
                    continue;
                }
                if (collect($indexes)->contains(fn (array $present): bool => strtolower($present['name']) === $index['name'])) {
                    throw new RuntimeException("Conflicting index {$index['name']} in {$table}; automatic replacement is not allowed.");
                }
                $duplicates = DB::table($table)->select($index['columns']);
                foreach ($index['columns'] as $column) {
                    $duplicates->whereNotNull($column);
                }
                if ($duplicates->groupBy($index['columns'])->havingRaw('COUNT(*) > 1')->exists()) {
                    throw new RuntimeException("Duplicate keys in {$table}; retain all revisions and review before adding {$index['name']}.");
                }
                $pending[] = ['kind' => 'unique', 'table' => $table] + $index;
            }

            foreach ($definition['foreign'] as $column => [$target, $name, $nullable]) {
                $matches = array_filter($foreignKeys, fn (array $key): bool => in_array($column, $key['columns'], true));
                foreach ($matches as $key) {
                    $schema = $key['foreign_schema'] ?? null;
                    $expectedSchema = Schema::getConnection()->getDatabaseName();
                    if ($key['columns'] !== [$column]
                        || $key['foreign_table'] !== $target
                        || $key['foreign_columns'] !== ['id']
                        || ($schema !== null && $schema !== $expectedSchema && ! (DB::getDriverName() === 'sqlite' && $schema === 'main'))
                        || ! in_array($key['on_delete'], ['restrict', 'no action'], true)
                        || ! in_array($key['on_update'], ['restrict', 'no action'], true)) {
                        throw new RuntimeException("Conflicting foreign key on {$table}.{$column}; automatic replacement is not allowed.");
                    }
                }
                if ($matches !== []) {
                    continue;
                }
                if (collect($foreignKeys)->contains(fn (array $key): bool => strtolower($key['name'] ?? '') === $name)) {
                    throw new RuntimeException("Conflicting foreign key name {$name}; a reviewed repair is required.");
                }
                if (! Schema::hasColumns($target, ['id'])) {
                    throw new RuntimeException("Missing parent table {$target}; workflow repair cannot proceed.");
                }
                if (DB::table($table)
                    ->leftJoin($target.' as repair_parent', $table.'.'.$column, '=', 'repair_parent.id')
                    ->whereNotNull($table.'.'.$column)
                    ->whereNull('repair_parent.id')
                    ->exists()) {
                    throw new RuntimeException("Orphaned reference in {$table}.{$column}; retain the rows for review.");
                }
                $pending[] = ['kind' => 'foreign', 'table' => $table, 'column' => $column, 'target' => $target, 'name' => $name];
            }
        }

        foreach ($pending as $constraint) {
            Schema::table($constraint['table'], function (Blueprint $table) use ($constraint): void {
                if ($constraint['kind'] === 'unique') {
                    $table->unique($constraint['columns'], $constraint['name']);
                } else {
                    $table->foreign($constraint['column'], $constraint['name'])
                        ->references('id')->on($constraint['target'])->restrictOnDelete();
                }
            });
        }
    }

    private static function definitions(): array
    {
        return [
            'commercial_offer_revisions' => [
                'unique' => [
                    ['columns' => ['subject_type', 'subject_id', 'revision'], 'name' => 'cor_subject_revision_unique'],
                ],
                'foreign' => [
                    'created_by' => ['users', 'cor_created_by_foreign', false],
                    'accepted_by' => ['users', 'cor_accepted_by_foreign', true],
                ],
            ],
            'employee_document_versions' => [
                'unique' => [
                    ['columns' => ['file_id'], 'name' => 'employee_document_versions_file_id_unique'],
                    ['columns' => ['employee_document_requirement_id', 'revision'], 'name' => 'employee_document_requirement_revision_unique'],
                ],
                'foreign' => [
                    'employee_document_requirement_id' => ['employee_document_requirements', 'edv_requirement_foreign', false],
                    'file_id' => ['files', 'edv_file_foreign', false],
                    'created_by' => ['users', 'edv_created_by_foreign', true],
                    'withdrawn_by' => ['users', 'edv_withdrawn_by_foreign', true],
                    'acknowledged_by' => ['users', 'edv_acknowledged_by_foreign', true],
                ],
            ],
        ];
    }
}
