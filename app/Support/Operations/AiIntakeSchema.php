<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\Schema;

final class AiIntakeSchema
{
    public static function ready(): bool
    {
        foreach (['ai_intakes', 'ai_intake_messages', 'ai_intake_attachments', 'ai_intake_runs', 'ai_intake_proposals', 'ai_intake_deliveries'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return Schema::hasColumns('operation_audits', ['actor_kind', 'automation_run_id', 'supervising_user_id']);
    }

    public static function requireReady(): void
    {
        abort_unless(self::ready(), 503, 'AI-Eingang ist noch nicht eingerichtet.');
    }
}
