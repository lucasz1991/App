<?php

namespace App\Support\CustomerPortal;

use App\Support\Operations\SchemaReadiness;
use Illuminate\Support\Facades\Schema;

final class CustomerPortalIntakeSchema
{
    public static function ready(): bool
    {
        return SchemaReadiness::remember(__METHOD__, function (): bool {
            if (! CustomerPortalSchema::ready() || ! CustomerPortalWorkflowSchema::ready()) {
                return false;
            }
            if (! Schema::hasColumn('customer_capacity_reservations', 'planned_break_minutes')) {
                return false;
            }
            foreach (['customer_portal_submissions' => ['customer_id', 'identity_id', 'membership_id', 'uuid', 'payload_hash', 'payload', 'status', 'revision', 'decision'], 'customer_portal_submission_items' => ['submission_id', 'customer_id', 'inquiry_id', 'location_id', 'position', 'payload'], 'customer_portal_automation_profiles' => ['customer_id', 'mode', 'allowed_roles', 'location_ids', 'condition_ids', 'minimum_lead_minutes', 'maximum_staff', 'maximum_total_cents', 'revision', 'approved_at', 'approved_by', 'created_by'], 'customer_portal_profile_revisions' => ['profile_id', 'revision', 'snapshot', 'actor_id'], 'customer_capacity_commitments' => ['customer_id', 'user_id', 'location_id', 'role_name', 'qualification_ids', 'starts_at', 'ends_at', 'status', 'revision', 'consented_at', 'approved_at', 'approved_by'], 'customer_capacity_reservations' => ['commitment_id', 'submission_id', 'inquiry_id', 'customer_id', 'user_id', 'order_id', 'starts_at', 'ends_at', 'status', 'role_name', 'location_name'], 'orders' => ['customer_portal_identity_id', 'customer_portal_membership_id', 'customer_portal_location_id'], 'operation_inquiries' => ['customer_portal_identity_id', 'customer_portal_membership_id', 'customer_portal_location_id'], 'operation_audits' => ['customer_portal_identity_id', 'customer_portal_membership_id'], 'commercial_offer_revisions' => ['customer_portal_identity_id', 'customer_portal_membership_id']] as $table => $columns) {
                if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                    return false;
                }
            }
            foreach ([['customer_portal_submissions', ['customer_id', 'uuid']], ['customer_portal_submission_items', ['inquiry_id']], ['customer_portal_submission_items', ['submission_id', 'position']], ['customer_capacity_reservations', ['inquiry_id', 'user_id']], ['customer_portal_automation_profiles', ['customer_id']], ['customer_portal_profile_revisions', ['profile_id', 'revision']]] as [$table, $columns]) {
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
