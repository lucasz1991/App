<?php

namespace App\Services\CustomerPortal;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalAudit;
use App\Models\CustomerPortalInvitation;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalSetting;
use App\Models\User;
use App\Support\CustomerPortal\CustomerPortalScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CustomerPortalAccessService
{
    public function saveSetting(int $customerId, int $expectedRevision, array $data, User $actor): CustomerPortalSetting
    {
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId);
        $data = Validator::make($data, ['enabled' => 'required|boolean', 'modules' => 'present|array', 'modules.*' => ['string', Rule::in(CustomerPortalSetting::MODULES)], 'automation_mode' => ['required', Rule::in(['manual', 'offer', 'accept'])], 'auto_reject' => 'required|boolean', 'booking_authority' => 'sometimes|boolean', 'require_mfa' => 'sometimes|boolean', 'activation_contact_id' => 'nullable|integer|min:1', 'notifications' => 'present|array', 'notifications.*' => ['string', Rule::in(['requests', 'decisions', 'orders', 'proofs', 'documents', 'messages'])]])->validate();

        return DB::transaction(function () use ($customerId, $expectedRevision, $data, $actor): CustomerPortalSetting {
            $customer = Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId);
            $setting = CustomerPortalSetting::where('customer_id', $customerId)->lockForUpdate()->first();
            abort_unless(($setting?->revision ?? 0) === $expectedRevision, 409, 'Einstellungen wurden geändert.');
            $changesAutomation = $data['automation_mode'] !== ($setting?->automation_mode ?? 'manual') || (bool) $data['auto_reject'] !== (bool) ($setting?->auto_reject ?? false) || (isset($data['booking_authority']) && (bool) $data['booking_authority'] !== (bool) ($setting?->booking_authority ?? false));
            if ($changesAutomation) {
                app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.automation');
            }
            abort_if($data['enabled'] && ! $customer->is_active, 422, 'Kunde ist nicht aktiv.');
            $previouslyEnabled = $setting?->enabled ?? false;
            $nominatedContactId = $data['activation_contact_id'] ?? null;
            unset($data['activation_contact_id']);
            $pending = collect();
            if ($data['enabled'] && ! $previouslyEnabled) {
                $pendingQuery = CustomerPortalMembership::where('customer_id', $customerId)->where('status', 'pending')->orderBy('id');
                if ($nominatedContactId !== null) {
                    $contact = CustomerContact::whereKey($nominatedContactId)->where('customer_id', $customerId)->where('is_active', true)->lockForUpdate()->first();
                    abort_unless($contact && filter_var($contact->email, FILTER_VALIDATE_EMAIL) && CustomerPortalMembership::where('customer_id', $customerId)->where('contact_id', $nominatedContactId)->exists(), 422, 'Benannten Kontakt zuerst einrichten.');
                    $pendingQuery->where('contact_id', $nominatedContactId);
                }
                $pending = $pendingQuery->lockForUpdate()->get();
                abort_if($nominatedContactId === null && $pending->count() > 1, 422, 'Einladungsempfänger auswählen.');
            }
            $setting ??= new CustomerPortalSetting(['customer_id' => $customerId]);
            $data['modules'] = array_values(array_unique($data['modules']));
            $data['notifications'] = array_values(array_unique($data['notifications']));
            sort($data['modules']);
            sort($data['notifications']);
            $setting->fill($data + ['booking_authority' => $setting->booking_authority ?? false, 'require_mfa' => $setting->require_mfa ?? false]);
            if ($setting->exists && ! $setting->isDirty()) {
                return $setting;
            }
            $setting->forceFill(['revision' => $expectedRevision + 1, 'updated_by' => $actor->id])->save();
            CustomerPortalInvitation::where('customer_id', $customerId)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            CustomerPortalAudit::create(['customer_id' => $customerId, 'actor_id' => $actor->id, 'action' => $setting->enabled ? 'setting_enabled' : 'setting_disabled', 'revision' => $setting->revision, 'details' => ['modules' => $setting->modules, 'automation_mode' => $setting->automation_mode]]);
            if ($setting->enabled && ! $previouslyEnabled) {
                foreach ($pending as $membership) {
                    $contact = CustomerContact::whereKey($membership->contact_id)->where('is_active', true)->first();
                    if ($contact && filter_var($contact->email, FILTER_VALIDATE_EMAIL)) {
                        app(CustomerPortalInvitationService::class)->invite($customerId, $membership->id, $membership->revision, $actor);
                    }
                }
            }

            return $setting;
        });
    }

    public function saveMembership(int $customerId, int $contactId, int $expectedRevision, array $data, User $actor): CustomerPortalMembership
    {
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId);
        $data = Validator::make($data, ['role' => ['required', Rule::in(array_keys(CustomerPortalMembership::ROLES))], 'status' => ['required', Rule::in(['pending', 'active', 'suspended', 'revoked'])], 'capabilities' => 'sometimes|array', 'capabilities.*' => 'string', 'location_ids' => 'present|array', 'location_ids.*' => 'integer|min:1|distinct', 'history_from' => 'nullable|date_format:Y-m-d'])->validate();
        $capabilities = $data['capabilities'] ?? CustomerPortalMembership::ROLES[$data['role']];
        abort_if(array_diff($capabilities, CustomerPortalMembership::ROLES[$data['role']]), 422, 'Rechte passen nicht zur Rolle.');

        return DB::transaction(function () use ($customerId, $contactId, $expectedRevision, $data, $actor, $capabilities): CustomerPortalMembership {
            Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId);
            $membership = CustomerPortalMembership::where('customer_id', $customerId)->where('contact_id', $contactId)->lockForUpdate()->first();
            $contact = CustomerContact::whereKey($contactId)->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
            abort_unless($contact->is_active && filter_var($contact->email, FILTER_VALIDATE_EMAIL), 422, 'Aktiver Kontakt mit E-Mail erforderlich.');
            abort_unless(($membership?->revision ?? 0) === $expectedRevision, 409, 'Zugang wurde geändert.');
            $locations = array_values(array_unique(array_map('intval', $data['location_ids'])));
            sort($locations);
            abort_unless(CustomerLocation::where('customer_id', $customerId)->where('is_active', true)->whereIn('id', $locations)->count() === count($locations), 422, 'Standort gehört nicht zum Kunden.');
            if ($data['status'] === 'active') {
                $identity = $membership?->identity()->lockForUpdate()->first();
                abort_unless($membership?->activated_at && ! $membership->revoked_at && $identity?->active && $identity->email_verified_at && hash_equals($identity->email, strtolower(trim($contact->email))), 422, 'Einladung muss angenommen werden.');
            }
            $isNew = $membership === null;
            $membership ??= new CustomerPortalMembership(['customer_id' => $customerId, 'contact_id' => $contactId]);
            $capabilities = array_values(array_unique($capabilities));
            sort($capabilities);
            $membership->fill($data)->forceFill(['capabilities' => $capabilities, 'location_ids' => $locations]);
            if (! $isNew && ! $membership->isDirty()) {
                return $membership;
            }
            $membership->forceFill(['revision' => $expectedRevision + 1, 'updated_by' => $actor->id, 'revoked_at' => $data['status'] === 'revoked' ? now() : $membership->revoked_at])->save();
            CustomerPortalInvitation::where('membership_id', $membership->id)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            CustomerPortalAudit::create(['customer_id' => $customerId, 'membership_id' => $membership->id, 'actor_id' => $actor->id, 'action' => 'membership_'.$membership->status, 'revision' => $membership->revision, 'details' => ['role' => $membership->role, 'capabilities' => $membership->capabilities, 'location_ids' => $locations]]);
            if ($isNew && $membership->status === 'pending' && CustomerPortalSetting::where('customer_id', $customerId)->where('enabled', true)->exists()) {
                app(CustomerPortalInvitationService::class)->invite($customerId, $membership->id, $membership->revision, $actor);
                $membership->refresh();
            }

            return $membership;
        });
    }

    public function revoke(int $membershipId, int $expectedRevision, User $actor): CustomerPortalMembership
    {
        $membership = CustomerPortalMembership::findOrFail($membershipId);
        app(CustomerPortalScope::class)->authorizeManager($actor, $membership->customer_id);

        return DB::transaction(function () use ($membership, $expectedRevision, $actor): CustomerPortalMembership {
            Customer::whereKey($membership->customer_id)->lockForUpdate()->firstOrFail();
            app(CustomerPortalScope::class)->authorizeManager($actor, $membership->customer_id);
            $fresh = CustomerPortalMembership::whereKey($membership->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->revision === $expectedRevision, 409, 'Zugang wurde geändert.');
            if ($fresh->status === 'revoked') {
                return $fresh;
            }
            // A stale/inactive contact or historic location must never prevent revocation.
            $fresh->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revision' => $fresh->revision + 1, 'updated_by' => $actor->id])->save();
            CustomerPortalInvitation::where('membership_id', $fresh->id)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            CustomerPortalAudit::create(['customer_id' => $fresh->customer_id, 'membership_id' => $fresh->id, 'actor_id' => $actor->id, 'action' => 'membership_revoked', 'revision' => $fresh->revision]);

            return $fresh;
        });
    }

    public function grantManager(int $customerId, int $userId, array $abilities, User $actor): void
    {
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId);
        abort_unless(User::find($actor->id)?->isAdmin() && $actor->id !== $userId && User::whereKey($userId)->where('status', true)->exists(), 403);
        abort_if(array_diff($abilities, ['customers.portal.manage', 'customers.portal.publish', 'customers.portal.automation']), 422);
        DB::transaction(function () use ($customerId, $userId, $abilities, $actor): void {
            Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId);
            DB::table('customer_portal_manager_grants')->updateOrInsert(['customer_id' => $customerId, 'user_id' => $userId], ['active' => count($abilities) > 0, 'abilities' => json_encode(array_values(array_unique($abilities))), 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
            CustomerPortalAudit::create(['customer_id' => $customerId, 'actor_id' => $actor->id, 'action' => 'manager_grant_updated', 'details' => ['manager_id' => $userId, 'abilities' => $abilities]]);
        });
    }
}
