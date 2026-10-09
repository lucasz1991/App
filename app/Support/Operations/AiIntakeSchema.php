<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\Schema;

final class AiIntakeSchema
{
    public static function ready(): bool
    {
        return SchemaReadiness::remember(__METHOD__, function (): bool {
            foreach ([
                'ai_intakes' => ['public_id', 'status', 'revision', 'source_revision', 'customer_id', 'customer_contact_id', 'analysis', 'latest_inbound_message_id'],
                'ai_intake_messages' => ['intake_id', 'direction', 'receipt_key', 'body', 'sender_email', 'raw_path', 'raw_hash', 'metadata'],
                'ai_intake_attachments' => ['intake_id', 'message_id', 'file_path', 'file_hash', 'file_size', 'kind', 'extracted_text'],
                'ai_intake_runs' => ['intake_id', 'source_revision', 'settings_revision', 'supervising_user_id', 'input_hash', 'input_snapshot', 'result'],
                'ai_intake_proposals' => ['intake_id', 'source_revision', 'position_index', 'payload', 'inquiry_id', 'inquiry_fingerprint', 'approved_at', 'demand_id', 'demand_ids'],
                'ai_intake_deliveries' => ['intake_id', 'message_id', 'recipient_email', 'body', 'dedup_key', 'settings_revision', 'source_revision', 'intake_revision', 'failure_code'],
            ] as $table => $columns) {
                if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                    return false;
                }
            }

            return Schema::hasColumns('operation_audits', ['actor_kind', 'automation_run_id', 'supervising_user_id']);
        });
    }

    public static function requireReady(): void
    {
        abort_unless(self::ready(), 503, 'AI-Eingang ist noch nicht eingerichtet.');
    }
}
