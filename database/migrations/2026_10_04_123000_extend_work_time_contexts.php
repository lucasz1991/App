<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumns('work_time_entries', ['id', 'shift_assignment_id'])) {
            throw new RuntimeException('The base work-time migration must be completed before extending actual times.');
        }
        $assignment = collect(Schema::getColumns('work_time_entries'))->firstWhere('name', 'shift_assignment_id');
        if (! $assignment['nullable']) {
            Schema::table('work_time_entries', fn (Blueprint $table) => $table->unsignedBigInteger('shift_assignment_id')->nullable()->change());
        }
        if (! Schema::hasColumn('work_time_entries', 'work_context') && DB::table('work_time_entries')->whereNull('shift_assignment_id')->exists()) {
            throw new RuntimeException('Actual times without a shift require a reviewed context repair; historical contexts must not be invented.');
        }
        $this->columns('work_time_entries', [
            'work_context' => fn (Blueprint $table) => $table->string('work_context', 30)->default('shift'),
            'capture_id' => fn (Blueprint $table) => $table->uuid('capture_id')->nullable(),
            'order_id' => fn (Blueprint $table) => $table->unsignedBigInteger('order_id')->nullable(),
            'training_session_id' => fn (Blueprint $table) => $table->unsignedBigInteger('training_session_id')->nullable(),
            'source' => fn (Blueprint $table) => $table->string('source', 30)->default('online'),
        ]);
        $this->index('work_time_entries', ['work_context']);
        $this->index('work_time_entries', ['capture_id'], 'unique');
        $this->index('work_time_entries', ['order_id']);
        $this->index('work_time_entries', ['training_session_id']);

        $this->table('work_time_activities', [
            'id' => fn (Blueprint $table) => $table->id(),
            'work_time_entry_id' => fn (Blueprint $table) => $table->unsignedBigInteger('work_time_entry_id'),
            'kind' => fn (Blueprint $table) => $table->string('kind', 30),
            'starts_at' => fn (Blueprint $table) => $table->dateTime('starts_at'),
            'ends_at' => fn (Blueprint $table) => $table->dateTime('ends_at')->nullable(),
            'is_paid' => fn (Blueprint $table) => $table->boolean('is_paid')->nullable(),
            'source' => fn (Blueprint $table) => $table->string('source', 30)->default('clock'),
            'note' => fn (Blueprint $table) => $table->text('note')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ]);
        $this->index('work_time_activities', ['id'], 'primary');
        $this->foreign('work_time_activities', 'work_time_entry_id', 'work_time_entries');

        $this->table('work_time_active_sessions', [
            'user_id' => fn (Blueprint $table) => $table->unsignedBigInteger('user_id'),
            'work_time_entry_id' => fn (Blueprint $table) => $table->unsignedBigInteger('work_time_entry_id'),
        ]);
        $this->index('work_time_active_sessions', ['user_id'], 'primary');
        $this->index('work_time_active_sessions', ['work_time_entry_id'], 'unique');
        $this->foreign('work_time_active_sessions', 'user_id', 'users');
        $this->foreign('work_time_active_sessions', 'work_time_entry_id', 'work_time_entries');

        $this->table('work_time_capture_devices', [
            'id' => fn (Blueprint $table) => $table->id(),
            'user_id' => fn (Blueprint $table) => $table->unsignedBigInteger('user_id'),
            'public_id' => fn (Blueprint $table) => $table->uuid('public_id'),
            'encryption_key' => fn (Blueprint $table) => $table->text('encryption_key'),
            'last_sequence' => fn (Blueprint $table) => $table->unsignedBigInteger('last_sequence')->default(0),
            'revoked_at' => fn (Blueprint $table) => $table->timestamp('revoked_at')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ]);
        $this->index('work_time_capture_devices', ['id'], 'primary');
        $this->index('work_time_capture_devices', ['public_id'], 'unique');
        $this->foreign('work_time_capture_devices', 'user_id', 'users');

        $this->table('work_time_capture_receipts', [
            'id' => fn (Blueprint $table) => $table->id(),
            'work_time_capture_device_id' => fn (Blueprint $table) => $table->unsignedBigInteger('work_time_capture_device_id'),
            'event_key' => fn (Blueprint $table) => $table->uuid('event_key'),
            'sequence' => fn (Blueprint $table) => $table->unsignedBigInteger('sequence'),
            'payload_hash' => fn (Blueprint $table) => $table->char('payload_hash', 64),
            'payload' => fn (Blueprint $table) => $table->text('payload'),
            'status' => fn (Blueprint $table) => $table->string('status', 20),
            'reason' => fn (Blueprint $table) => $table->string('reason', 500)->nullable(),
            'reviewed_by' => fn (Blueprint $table) => $table->unsignedBigInteger('reviewed_by')->nullable(),
            'reviewed_at' => fn (Blueprint $table) => $table->dateTime('reviewed_at')->nullable(),
            'review_note' => fn (Blueprint $table) => $table->text('review_note')->nullable(),
            'work_time_entry_id' => fn (Blueprint $table) => $table->unsignedBigInteger('work_time_entry_id')->nullable(),
            'applied_revision' => fn (Blueprint $table) => $table->unsignedInteger('applied_revision')->nullable(),
            'occurred_at' => fn (Blueprint $table) => $table->dateTime('occurred_at'),
            'received_at' => fn (Blueprint $table) => $table->dateTime('received_at'),
        ]);
        $this->index('work_time_capture_receipts', ['id'], 'primary');
        $this->index('work_time_capture_receipts', ['event_key'], 'unique');
        $this->index('work_time_capture_receipts', ['work_time_capture_device_id', 'sequence'], 'unique', 'capture_device_sequence');
        $this->foreign('work_time_capture_receipts', 'work_time_capture_device_id', 'work_time_capture_devices');
        $this->foreign('work_time_capture_receipts', 'reviewed_by', 'users');
        $this->foreign('work_time_capture_receipts', 'work_time_entry_id', 'work_time_entries');

        $this->table('work_time_basic_exports', [
            'id' => fn (Blueprint $table) => $table->id(),
            'public_id' => fn (Blueprint $table) => $table->uuid('public_id'),
            'created_by' => fn (Blueprint $table) => $table->unsignedBigInteger('created_by'),
            'schema_version' => fn (Blueprint $table) => $table->unsignedInteger('schema_version')->default(2),
            'snapshot' => fn (Blueprint $table) => $table->text('snapshot'),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at'),
        ]);
        $this->index('work_time_basic_exports', ['id'], 'primary');
        $this->index('work_time_basic_exports', ['public_id'], 'unique');
        $this->foreign('work_time_basic_exports', 'created_by', 'users');
    }

    private function table(string $name, array $columns): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $column($table);
                }
            });

            return;
        }

        // Never reconstruct lost event identities, revocation state, encryption keys or snapshots in a used table.
        $this->columns($name, $columns, true);
    }

    private function columns(string $name, array $columns, bool $emptyOnly = false): void
    {
        $present = Schema::getColumnListing($name);
        $missing = array_diff_key($columns, array_flip($present));
        if ($missing && $emptyOnly && DB::table($name)->exists()) {
            throw new RuntimeException('Incomplete historical '.$name.' requires a reviewed repair; missing columns: '.implode(', ', array_keys($missing)).'.');
        }
        foreach ($missing as $nameOfColumn => $column) {
            if ($nameOfColumn === 'id' && Schema::getConnection()->getDriverName() === 'sqlite') {
                // SQLite cannot ADD a primary-key column; the subsequent standard change
                // rebuilds only the verified-empty table while retaining other constraints.
                Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('id'));
                Schema::table($name, fn (Blueprint $table) => $table->id()->change());

                continue;
            }
            Schema::table($name, $column);
        }
    }

    private function index(string $table, array $columns, string $type = 'index', ?string $name = null): void
    {
        if (Schema::hasIndex($table, $columns, $type === 'index' ? null : $type)) {
            return;
        }
        $name ??= $table.'_'.implode('_', $columns).'_'.$type;
        if (Schema::hasIndex($table, $name) || ($type === 'primary' && collect(Schema::getIndexes($table))->contains('primary', true))) {
            throw new RuntimeException('Unexpected index on '.$table.' requires a reviewed repair: '.$name.'.');
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->{$type}($columns, $name));
    }

    private function foreign(string $table, string $column, string $target): void
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if ($foreign['columns'] === [$column]) {
                if ($foreign['foreign_table'] !== $target || $foreign['foreign_columns'] !== ['id'] || ! in_array(strtolower($foreign['on_delete']), ['restrict', 'no action'], true)) {
                    throw new RuntimeException('Unexpected foreign key on '.$table.'.'.$column.' requires a reviewed repair.');
                }

                return;
            }
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column)->references('id')->on($target)->restrictOnDelete());
    }

    public function down(): void
    {
        // Actual times, encrypted pending receipts and historical exports must not be discarded by rollback.
        throw new RuntimeException('Work-time extensions contain historical data; use a reviewed forward migration.');
    }
};
