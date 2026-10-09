<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\Schema;

final class AiCustomerCommunicationSchema
{
    public static function ready(): bool
    {
        return SchemaReadiness::remember(__METHOD__, function (): bool {
            return AiIntakeSchema::ready() && Schema::hasColumns('ai_intake_deliveries', ['message_type', 'approved_by', 'approved_at', 'order_id', 'order_fingerprint', 'approval_audit_id']);
        });
    }
}
