<?php

namespace App\Services\CustomerPortal;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerPortalAudit;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalInvitation;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalSetting;
use App\Models\User;
use App\Support\CustomerPortal\CustomerPortalSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CustomerPortalInvitationService
{
    public function invite(int $customerId, int $membershipId, int $expectedRevision, User $actor): CustomerPortalInvitation
    {
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId);

        return DB::transaction(function () use ($customerId, $membershipId, $expectedRevision, $actor): CustomerPortalInvitation {
            $customer = Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId);
            $setting = CustomerPortalSetting::where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
            $membership = CustomerPortalMembership::whereKey($membershipId)->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
            $contact = CustomerContact::whereKey($membership->contact_id)->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
            abort_unless($customer->is_active && $setting->enabled && $contact->is_active && filter_var($contact->email, FILTER_VALIDATE_EMAIL), 422, 'Aktiver Kundenzugang und Kontakt erforderlich.');
            abort_unless($membership->revision === $expectedRevision, 409, 'Zugang wurde geändert.');
            abort_if($membership->status === 'active', 422, 'Zugang ist bereits aktiv.');
            $membership->forceFill(['status' => 'pending', 'revoked_at' => null, 'revision' => $membership->revision + 1, 'updated_by' => $actor->id])->save();
            CustomerPortalInvitation::where('membership_id', $membershipId)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $invite = $this->create($membership, $setting, $contact, 'invite', $actor->id);
            CustomerPortalAudit::create(['customer_id' => $customerId, 'membership_id' => $membershipId, 'actor_id' => $actor->id, 'action' => 'invitation_created', 'revision' => $membership->revision]);

            return $invite;
        });
    }

    private function create(CustomerPortalMembership $membership, CustomerPortalSetting $setting, CustomerContact $contact, string $purpose, ?int $actorId = null): CustomerPortalInvitation
    {
        $token = bin2hex(random_bytes(32));
        $identity = $membership->identity_id ? CustomerPortalIdentity::find($membership->identity_id) : null;
        $invite = CustomerPortalInvitation::create(['customer_id' => $membership->customer_id, 'membership_id' => $membership->id, 'purpose' => $purpose, 'token_hash' => hash('sha256', $token), 'recipient_email' => strtolower(trim($contact->email)), 'setting_revision' => $setting->revision, 'membership_revision' => $membership->revision, 'contact_revision' => $contact->revision, 'identity_revision' => $identity?->revision, 'expires_at' => $purpose === 'reset' ? now()->addMinutes(config('customer_portal.reset_minutes', 60)) : now()->addHours(config('customer_portal.invitation_hours', 48)), 'created_by' => $actorId]);
        $base = rtrim((string) config('app.url'), '/');
        abort_unless(filter_var($base, FILTER_VALIDATE_URL) && (app()->environment(['local', 'testing']) || str_starts_with($base, 'https://')), 503, 'Portal-Adresse nicht konfiguriert.');
        app(CustomerPortalDeliveryService::class)->enqueue($membership->customer_id, $membership->id, $purpose === 'reset' ? 'reset' : 'invitation', 'invitation:'.$invite->id, ['name' => $contact->name, 'url' => $base.'/kundenportal/'.($purpose === 'reset' ? 'passwort/' : 'einladung/').$token, 'expires_at' => $invite->expires_at->toIso8601String()], $invite->id);

        return $invite;
    }

    public function inspect(string $token, string $purpose = 'invite'): CustomerPortalInvitation
    {
        CustomerPortalSchema::requireReady();
        abort_unless(strlen($token) === 64 && ctype_alnum($token), 410, 'Link nicht mehr verfügbar.');
        $invitation = CustomerPortalInvitation::where('token_hash', hash('sha256', $token))->where('purpose', $purpose)->first();
        abort_unless($invitation && $this->valid($invitation), 410, 'Link nicht mehr verfügbar.');

        return $invitation;
    }

    private function valid(CustomerPortalInvitation $invite): bool
    {
        $customer = Customer::find($invite->customer_id);
        $setting = CustomerPortalSetting::where('customer_id', $invite->customer_id)->first();
        $membership = CustomerPortalMembership::whereKey($invite->membership_id)->where('customer_id', $invite->customer_id)->first();
        $contact = $membership ? CustomerContact::whereKey($membership->contact_id)->where('customer_id', $invite->customer_id)->first() : null;
        if (! $customer?->is_active || ! $setting?->enabled || ! $membership || ! $contact?->is_active || $invite->consumed_at || $invite->revoked_at || ! $invite->expires_at->isFuture() || $membership->revoked_at || $setting->revision !== (int) $invite->setting_revision || $membership->revision !== (int) $invite->membership_revision || $contact->revision !== (int) $invite->contact_revision || ! hash_equals($invite->recipient_email, strtolower(trim((string) $contact->email)))) {
            return false;
        }
        if ($invite->purpose === 'reset') {
            $identity = $membership->identity_id ? CustomerPortalIdentity::find($membership->identity_id) : null;

            return $membership->status === 'active' && $identity?->active && $identity->email_verified_at && $identity->revision === (int) $invite->identity_revision && hash_equals($identity->email, $invite->recipient_email);
        }

        return $membership->status === 'pending';
    }

    public function accept(string $token, array $data): CustomerPortalIdentity
    {
        $initial = $this->inspect($token);

        return DB::transaction(function () use ($initial, $token, $data): CustomerPortalIdentity {
            Customer::whereKey($initial->customer_id)->lockForUpdate()->firstOrFail();
            CustomerPortalSetting::where('customer_id', $initial->customer_id)->lockForUpdate()->firstOrFail();
            $membership = CustomerPortalMembership::whereKey($initial->membership_id)->lockForUpdate()->firstOrFail();
            CustomerContact::whereKey($membership->contact_id)->lockForUpdate()->firstOrFail();
            $identity = CustomerPortalIdentity::where('email', $initial->recipient_email)->lockForUpdate()->first();
            $invite = CustomerPortalInvitation::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->valid($invite) && hash_equals($invite->token_hash, hash('sha256', $token)), 410, 'Link nicht mehr verfügbar.');
            if ($identity) {
                abort_unless($identity->active && $identity->email_verified_at && Hash::check((string) ($data['current_password'] ?? ''), $identity->password), 422, 'Bestehendes Passwort bestätigen.');
            } else {
                $validated = Validator::make($data, ['name' => 'required|string|max:180', 'password' => 'required|string|min:12|max:128|confirmed'])->validate();
                $identity = CustomerPortalIdentity::create(['name' => $validated['name'], 'email' => $invite->recipient_email, 'password' => $validated['password'], 'active' => true, 'revision' => 1, 'email_verified_at' => now()]);
            }
            abort_if(CustomerPortalMembership::where('customer_id', $membership->customer_id)->where('identity_id', $identity->id)->where('id', '!=', $membership->id)->exists(), 422, 'Für diesen Kunden besteht bereits ein Zugang.');
            $membership->forceFill(['identity_id' => $identity->id, 'status' => 'active', 'activated_at' => now(), 'revision' => $membership->revision + 1])->save();
            $invite->forceFill(['consumed_at' => now()])->save();
            CustomerPortalAudit::create(['customer_id' => $membership->customer_id, 'membership_id' => $membership->id, 'identity_id' => $identity->id, 'action' => 'invitation_accepted', 'revision' => $membership->revision]);

            return $identity;
        });
    }

    public function passwordReset(string $email): void
    {
        CustomerPortalSchema::requireReady();
        $identity = CustomerPortalIdentity::where('email', strtolower(trim($email)))->where('active', true)->whereNotNull('email_verified_at')->first();
        if (! $identity) {
            return;
        }
        $membership = app(CustomerPortalScope::class)->memberships($identity)->first();
        if (! $membership) {
            return;
        }
        DB::transaction(function () use ($identity, $membership): void {
            $fresh = app(CustomerPortalScope::class)->membership($identity, $membership->customer_id, null, true);
            if (CustomerPortalInvitation::where('membership_id', $fresh->id)->where('purpose', 'reset')->where('created_at', '>=', now()->subMinute())->exists()) {
                return;
            }
            CustomerPortalInvitation::where('membership_id', $fresh->id)->where('purpose', 'reset')->whereNull('consumed_at')->update(['revoked_at' => now()]);
            $this->create($fresh, $fresh->setting, $fresh->contact, 'reset');
        });
    }

    public function resetPassword(string $token, array $data): CustomerPortalIdentity
    {
        $initial = $this->inspect($token, 'reset');
        $validated = Validator::make($data, ['password' => 'required|string|min:12|max:128|confirmed'])->validate();

        return DB::transaction(function () use ($initial, $validated): CustomerPortalIdentity {
            Customer::whereKey($initial->customer_id)->lockForUpdate()->firstOrFail();
            CustomerPortalSetting::where('customer_id', $initial->customer_id)->lockForUpdate()->firstOrFail();
            $membership = CustomerPortalMembership::whereKey($initial->membership_id)->lockForUpdate()->firstOrFail();
            CustomerContact::whereKey($membership->contact_id)->lockForUpdate()->firstOrFail();
            $identity = CustomerPortalIdentity::whereKey($membership->identity_id)->lockForUpdate()->firstOrFail();
            $invite = CustomerPortalInvitation::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->valid($invite), 410, 'Link nicht mehr verfügbar.');
            $identity->forceFill(['password' => $validated['password'], 'revision' => $identity->revision + 1, 'remember_token' => Str::random(60)])->save();
            $invite->forceFill(['consumed_at' => now()])->save();
            CustomerPortalInvitation::where('purpose', 'reset')->whereNull('consumed_at')->whereIn('membership_id', $identity->memberships()->pluck('id'))->update(['revoked_at' => now()]);
            CustomerPortalAudit::create(['customer_id' => $membership->customer_id, 'membership_id' => $membership->id, 'identity_id' => $identity->id, 'action' => 'password_reset', 'revision' => $identity->revision]);

            return $identity;
        });
    }
}
