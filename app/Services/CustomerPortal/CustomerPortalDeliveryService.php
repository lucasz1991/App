<?php

namespace App\Services\CustomerPortal;

use App\Jobs\DeliverCustomerPortalMail;
use App\Mail\CustomerPortalMail;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerPortalDelivery;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalInvitation;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalSetting;
use App\Support\CustomerPortal\CustomerPortalSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class CustomerPortalDeliveryService
{
    public function enqueue(int $customerId, int $membershipId, string $kind, string $dedupKey, array $payload, ?int $invitationId = null): CustomerPortalDelivery
    {
        CustomerPortalSchema::requireReady();
        abort_unless(DB::transactionLevel() > 0, 409, 'Nachrichten benötigen einen bestätigten Vorgang.');
        $setting = CustomerPortalSetting::where('customer_id', $customerId)->firstOrFail();
        $membership = CustomerPortalMembership::whereKey($membershipId)->where('customer_id', $customerId)->firstOrFail();
        $contact = CustomerContact::whereKey($membership->contact_id)->where('customer_id', $customerId)->firstOrFail();
        $payload['recipient_email'] = strtolower(trim((string) $contact->email));
        $delivery = CustomerPortalDelivery::firstOrCreate(['dedup_key' => hash('sha256', $dedupKey)], ['customer_id' => $customerId, 'membership_id' => $membershipId, 'invitation_id' => $invitationId, 'kind' => $kind, 'status' => 'pending', 'payload' => $payload, 'setting_revision' => $setting->revision, 'membership_revision' => $membership->revision, 'contact_revision' => $contact->revision]);
        abort_unless($delivery->customer_id === $customerId && $delivery->membership_id === $membershipId && $delivery->kind === $kind, 409, 'Nachrichtenkennung ist bereits vergeben.');
        if ($delivery->wasRecentlyCreated && config('customer_portal.delivery_enabled', false)) {
            DeliverCustomerPortalMail::dispatch($delivery->id)->afterCommit();
        }

        return $delivery;
    }

    public function deliver(int $deliveryId): string
    {
        CustomerPortalSchema::requireReady();
        if (! config('customer_portal.delivery_enabled', false)) {
            return 'disabled';
        }
        $base = CustomerPortalDelivery::findOrFail($deliveryId);
        // SMTP cannot prove exactly-once after a timeout. A started/unknown send is never auto-retried.
        $ready = DB::transaction(function () use ($base): bool {
            Customer::whereKey($base->customer_id)->lockForUpdate()->firstOrFail();
            $delivery = CustomerPortalDelivery::whereKey($base->id)->lockForUpdate()->firstOrFail();
            if ($delivery->status !== 'pending') {
                return false;
            }
            if (! $this->freshRecipient($delivery)) {
                $delivery->forceFill(['status' => 'canceled', 'failure_code' => 'access_changed'])->save();

                return false;
            }
            $delivery->forceFill(['status' => 'sending', 'attempts' => $delivery->attempts + 1, 'attempted_at' => now()])->save();

            return true;
        });
        if (! $ready) {
            return CustomerPortalDelivery::findOrFail($deliveryId)->status;
        }
        try {
            return DB::transaction(function () use ($base): string {
                Customer::whereKey($base->customer_id)->lockForUpdate()->firstOrFail();
                $delivery = CustomerPortalDelivery::whereKey($base->id)->lockForUpdate()->firstOrFail();
                if (! $this->freshRecipient($delivery)) {
                    $delivery->forceFill(['status' => 'canceled', 'failure_code' => 'access_changed'])->save();

                    return 'canceled';
                }
                Mail::to($delivery->payload['recipient_email'])->send(new CustomerPortalMail($delivery->kind, $delivery->payload));
                $delivery->forceFill(['status' => 'sent', 'sent_at' => now(), 'failure_code' => null])->save();

                return 'sent';
            });
        } catch (\Throwable $exception) {
            CustomerPortalDelivery::whereKey($deliveryId)->where('status', 'sending')->update(['status' => 'unknown', 'failure_code' => 'transport_outcome_unknown']);

            return 'unknown';
        }
    }

    private function freshRecipient(CustomerPortalDelivery $delivery): bool
    {
        $customer = Customer::whereKey($delivery->customer_id)->first();
        $setting = CustomerPortalSetting::where('customer_id', $delivery->customer_id)->first();
        $membership = CustomerPortalMembership::whereKey($delivery->membership_id)->where('customer_id', $delivery->customer_id)->first();
        $contact = $membership ? CustomerContact::whereKey($membership->contact_id)->where('customer_id', $delivery->customer_id)->first() : null;
        if (! $customer?->is_active || ! $setting?->enabled || ! $contact?->is_active || ! $membership || $membership->revoked_at || $delivery->setting_revision !== $setting->revision || $delivery->membership_revision !== $membership->revision || $delivery->contact_revision !== $contact->revision || ! hash_equals(strtolower(trim((string) $contact->email)), (string) ($delivery->payload['recipient_email'] ?? ''))) {
            return false;
        }
        if ($delivery->invitation_id) {
            $invite = CustomerPortalInvitation::whereKey($delivery->invitation_id)->where('customer_id', $customer->id)->where('membership_id', $membership->id)->first();
            if (! $invite || $invite->consumed_at || $invite->revoked_at || ! $invite->expires_at->isFuture() || ! in_array($membership->status, $invite->purpose === 'reset' ? ['active'] : ['pending'], true)) {
                return false;
            }
            if ($invite->purpose === 'reset') {
                $identity = $membership->identity_id ? CustomerPortalIdentity::find($membership->identity_id) : null;

                return $identity?->active && $identity->email_verified_at && $identity->revision === $invite->identity_revision && hash_equals($identity->email, strtolower(trim((string) $contact->email)));
            }

            return true;
        }
        $identity = $membership->identity_id ? CustomerPortalIdentity::find($membership->identity_id) : null;

        return $membership->status === 'active' && $identity?->active && $identity->email_verified_at && hash_equals($identity->email, strtolower(trim((string) $contact->email))) && in_array($delivery->kind, $setting->notifications ?? [], true);
    }
}
