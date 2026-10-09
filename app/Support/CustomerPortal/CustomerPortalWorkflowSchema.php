<?php

namespace App\Support\CustomerPortal;

use App\Support\Operations\SchemaReadiness;
use Illuminate\Support\Facades\Schema;

final class CustomerPortalWorkflowSchema
{
    public static function ready(): bool
    {
        return SchemaReadiness::remember(__METHOD__, function (): bool {
            foreach ([
                'customer_portal_publications' => ['customer_id', 'location_id', 'order_id', 'subject_type', 'subject_id', 'source_revision', 'revision', 'service_starts_at', 'service_ends_at', 'status', 'payload', 'file_path', 'file_hash', 'reviewed_at', 'withdrawn_at'],
                'customer_portal_requests' => ['customer_id', 'identity_id', 'membership_id', 'order_id', 'kind', 'client_uuid', 'request_hash', 'revision', 'payload', 'response'],
                'customer_portal_messages' => ['customer_id', 'identity_id', 'membership_id', 'order_id', 'visibility', 'client_uuid', 'request_hash', 'body'],
                'customer_portal_attachments' => ['customer_id', 'identity_id', 'membership_id', 'source_type', 'source_id', 'client_uuid', 'status', 'revision', 'file_path', 'file_hash', 'reviewed_at'],
            ] as $table => $columns) {
                if (! Schema::hasColumns($table, $columns)) {
                    return false;
                }
            }
            foreach ([['customer_portal_publications', ['customer_id', 'subject_type', 'subject_id', 'revision']], ['customer_portal_requests', ['identity_id', 'client_uuid']], ['customer_portal_messages', ['customer_id', 'client_uuid']], ['customer_portal_attachments', ['customer_id', 'client_uuid']]] as [$table, $columns]) {
                if (! collect(Schema::getIndexes($table))->contains(fn ($index) => $index['unique'] && $index['columns'] === $columns)) {
                    return false;
                }
            }

            return true;
        });
    }

    public static function requireReady(): void
    {
        abort_unless(self::ready(), 503, 'Kundenbereich nicht verfügbar.');
    }
}
