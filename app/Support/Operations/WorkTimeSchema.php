<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\Schema;

final class WorkTimeSchema
{
    public static function ready(): bool
    {
        $columns = [
            'work_time_entries' => ['id', 'shift_assignment_id', 'user_id', 'status', 'revision', 'starts_at', 'ends_at', 'paused_at', 'pause_seconds', 'timezone', 'plan_snapshot', 'note', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note', 'created_at', 'updated_at', 'work_context', 'capture_id', 'order_id', 'training_session_id', 'source'],
            'work_time_events' => ['id', 'work_time_entry_id', 'event_key', 'kind', 'actor_id', 'occurred_at', 'received_at', 'data'],
            'work_time_activities' => ['id', 'work_time_entry_id', 'kind', 'starts_at', 'ends_at', 'is_paid', 'source', 'note', 'created_at', 'updated_at'],
            'work_time_active_sessions' => ['user_id', 'work_time_entry_id'],
            'work_time_capture_devices' => ['id', 'user_id', 'public_id', 'encryption_key', 'last_sequence', 'revoked_at', 'created_at', 'updated_at'],
            'work_time_capture_receipts' => ['id', 'work_time_capture_device_id', 'event_key', 'sequence', 'payload_hash', 'payload', 'status', 'reason', 'reviewed_by', 'reviewed_at', 'review_note', 'work_time_entry_id', 'applied_revision', 'occurred_at', 'received_at'],
            'work_time_basic_exports' => ['id', 'public_id', 'created_by', 'schema_version', 'snapshot', 'created_at'],
        ];
        foreach ($columns as $table => $required) {
            if (! Schema::hasColumns($table, $required)) {
                return false;
            }
        }
        $assignment = collect(Schema::getColumns('work_time_entries'))->firstWhere('name', 'shift_assignment_id');
        if (! $assignment['nullable']) {
            return false;
        }
        $indices = [
            'work_time_entries' => [['capture_id'], ['shift_assignment_id']],
            'work_time_events' => [['event_key']],
            'work_time_active_sessions' => [['work_time_entry_id']],
            'work_time_capture_devices' => [['public_id']],
            'work_time_capture_receipts' => [['event_key'], ['work_time_capture_device_id', 'sequence']],
            'work_time_basic_exports' => [['public_id']],
        ];
        $metadata = [];
        foreach ($indices as $table => $unique) {
            $present = Schema::getIndexes($table);
            $metadata[$table] = $present;
            foreach ($unique as $required) {
                if (! collect($present)->contains(fn ($index) => $index['columns'] === $required && $index['unique'])) {
                    return false;
                }
            }
        }

        foreach (array_keys($columns) as $table) {
            $required = $table === 'work_time_active_sessions' ? ['user_id'] : ['id'];
            $present = $metadata[$table] ?? Schema::getIndexes($table);
            if (! collect($present)->contains(fn ($index) => $index['columns'] === $required && $index['primary'])) {
                return false;
            }
        }

        return true;
    }

    public static function requireReady(): void
    {
        abort_unless(self::ready(), 503, 'Arbeitszeitmodul noch nicht eingerichtet.');
    }
}
