<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\Schema;

final class OperationsEnhancementsSchema
{
    public static function ready(): bool
    {
        return SchemaReadiness::remember(__METHOD__, function (): bool {
            foreach ([
                'operation_workflows' => ['id', 'kind', 'user_id', 'order_id', 'shift_id', 'title', 'status', 'revision', 'payload', 'created_by'],
                'operation_workflow_revisions' => ['operation_workflow_id', 'revision', 'action', 'snapshot', 'actor_id', 'created_at'],
                'operations_rate_rules' => ['id', 'name', 'kind', 'user_id', 'starts_on', 'ends_on', 'configuration', 'approved_at', 'approved_by', 'revision'],
                'operations_month_closings' => ['id', 'user_id', 'month', 'timezone', 'status', 'revision', 'snapshot', 'prepared_by', 'closed_by', 'closed_at'],
                'operations_closing_revisions' => ['operations_month_closing_id', 'revision', 'action', 'snapshot', 'actor_id', 'created_at'],
                'operations_cost_rates' => ['user_id', 'starts_on', 'ends_on', 'hourly_cents', 'currency'],
                'operations_terminal_profiles' => ['id', 'user_id', 'pin_hash', 'terminal_id', 'location_consent', 'latitude', 'longitude', 'radius_metres', 'revoked_at'],
                'operations_terminal_sessions' => ['user_id', 'operations_terminal_profile_id', 'terminal_id', 'device_id', 'token_hash', 'sequence', 'expires_at', 'revoked_at'],
            ] as $table => $columns) {
                if (! Schema::hasColumns($table, $columns)) {
                    return false;
                }
            }

            foreach ([
                ['operation_workflow_revisions', ['operation_workflow_id', 'revision']],
                ['operations_month_closings', ['user_id', 'month']],
                ['operations_closing_revisions', ['operations_month_closing_id', 'revision']],
                ['operations_terminal_profiles', ['user_id']],
                ['operations_terminal_sessions', ['token_hash']],
            ] as [$table,$columns]) {
                if (! collect(Schema::getIndexes($table))->contains(fn ($index) => $index['unique'] && $index['columns'] === $columns)) {
                    return false;
                }
            }

            return true;
        });
    }

    public static function requireReady(): void
    {
        abort_unless(self::ready(), 503, 'Arbeitsbereich nicht verfügbar.');
    }
}
