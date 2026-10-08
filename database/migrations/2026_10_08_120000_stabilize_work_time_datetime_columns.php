<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::getFacadeRoot();
        if (! in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $targets = [
            'work_time_entries' => ['starts_at' => false, 'ends_at' => true, 'paused_at' => true],
            'work_time_revisions' => ['created_at' => false],
            'work_time_exports' => ['created_at' => false],
            'work_time_basic_exports' => ['created_at' => false],
        ];

        foreach ($targets as $name => $columns) {
            if (! $schema->hasTable($name)) {
                continue;
            }

            $timestamps = collect($schema->getColumns($name))
                ->filter(fn (array $column): bool => array_key_exists($column['name'], $columns)
                    && strtolower($column['type_name']) === 'timestamp');

            if ($timestamps->isEmpty()) {
                continue;
            }

            // Existing raw SQL date/time strings are already the application values.
            // Do not reinterpret timezones, rewrite rows, or retain implicit ON UPDATE.
            $schema->table($name, function (Blueprint $table) use ($timestamps, $columns): void {
                foreach ($timestamps as $column) {
                    preg_match('/\((\d+)\)/', $column['type'], $precision);
                    $table->dateTime($column['name'], (int) ($precision[1] ?? 0))
                        ->nullable($columns[$column['name']])
                        ->comment($column['comment'] ?? null)
                        ->change();
                }
            });
        }
    }

    public function down(): void
    {
        // Forward-only: restoring TIMESTAMP would restore implicit mutation and
        // session-timezone interpretation of immutable application timestamps.
    }
};
