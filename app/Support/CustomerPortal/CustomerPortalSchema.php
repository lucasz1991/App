<?php

namespace App\Support\CustomerPortal;

use App\Support\Operations\SchemaReadiness;
use Illuminate\Support\Facades\Schema;

final class CustomerPortalSchema
{
    public static function ready(): bool
    {
        return SchemaReadiness::remember(__METHOD__, function (): bool {
            foreach ([
                'customer_portal_identities' => ['id', 'name', 'email', 'password', 'active', 'revision', 'email_verified_at', 'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_last_counter', 'two_factor_pending_secret', 'two_factor_pending_expires_at', 'two_factor_pending_session_hash'],
                'customer_portal_settings' => ['customer_id', 'enabled', 'revision', 'modules', 'automation_mode', 'auto_reject', 'notifications', 'require_mfa', 'booking_authority'],
                'customer_portal_memberships' => ['id', 'identity_id', 'customer_id', 'contact_id', 'status', 'revision', 'role', 'capabilities', 'location_ids', 'history_from', 'revoked_at'],
                'customer_portal_invitations' => ['id', 'customer_id', 'membership_id', 'purpose', 'token_hash', 'recipient_email', 'setting_revision', 'membership_revision', 'contact_revision', 'identity_revision', 'expires_at', 'consumed_at', 'revoked_at'],
                'customer_portal_deliveries' => ['id', 'customer_id', 'membership_id', 'invitation_id', 'kind', 'dedup_key', 'payload', 'status', 'setting_revision', 'membership_revision', 'contact_revision', 'attempts', 'failure_code'],
                'customer_portal_audits' => ['customer_id', 'membership_id', 'identity_id', 'actor_id', 'action', 'revision', 'details'],
                'customer_portal_manager_grants' => ['customer_id', 'user_id', 'active', 'abilities', 'approved_by', 'approved_at'],
                'customer_contacts' => ['id', 'customer_id', 'email', 'revision', 'is_active'],
            ] as $table => $columns) {
                if (! Schema::hasColumns($table, $columns)) {
                    return false;
                }
            }
            foreach ([['customer_portal_identities', ['email']], ['customer_portal_settings', ['customer_id']], ['customer_portal_memberships', ['customer_id', 'contact_id']], ['customer_portal_memberships', ['customer_id', 'identity_id']], ['customer_portal_invitations', ['token_hash']], ['customer_portal_deliveries', ['dedup_key']], ['customer_portal_manager_grants', ['customer_id', 'user_id']]] as [$table, $columns]) {
                if (! collect(Schema::getIndexes($table))->contains(fn ($index) => $index['unique'] && $index['columns'] === $columns)) {
                    return false;
                }
            }

            return true;
        });
    }

    public static function requireReady(): void
    {
        abort_unless(self::ready(), 503, 'Kundenportal nicht verfügbar.');
    }
}
