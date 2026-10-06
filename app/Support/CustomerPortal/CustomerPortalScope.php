<?php

namespace App\Support\CustomerPortal;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalSetting;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CustomerPortalScope
{
    public function authorizeManager(User $actor, int $customerId, string $ability = 'customers.portal.manage'): void
    {
        CustomerPortalSchema::requireReady();
        $fresh = User::find($actor->id);
        abort_unless($fresh?->status && Customer::whereKey($customerId)->exists(), 403);
        Gate::forUser($fresh)->authorize($ability);
        if ($fresh->isAdmin()) {
            return;
        }
        $grant = DB::table('customer_portal_manager_grants')->where('customer_id', $customerId)->where('user_id', $fresh->id)->where('active', true)->whereNotNull('approved_at')->first();
        abort_unless($grant && in_array($ability, json_decode($grant->abilities, true) ?? [], true) && $grant->approved_by !== $fresh->id, 403);
    }

    public function manageableCustomers(User $actor, string $ability = 'customers.portal.manage'): Builder
    {
        CustomerPortalSchema::requireReady();
        $fresh = User::find($actor->id);
        abort_unless($fresh?->status, 403);
        Gate::forUser($fresh)->authorize($ability);
        $query = Customer::query();
        if ($fresh->isAdmin()) {
            return $query;
        }
        $ids = DB::table('customer_portal_manager_grants')->where('user_id', $fresh->id)->where('active', true)->whereNotNull('approved_at')->where('approved_by', '!=', $fresh->id)->get()->filter(fn ($grant) => in_array($ability, json_decode($grant->abilities, true) ?? [], true))->pluck('customer_id');

        return $query->whereIn('id', $ids);
    }

    public function membership(CustomerPortalIdentity $identity, int $customerId, ?string $ability = null, bool $lock = false): CustomerPortalMembership
    {
        CustomerPortalSchema::requireReady();
        $customer = Customer::whereKey($customerId)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $setting = CustomerPortalSetting::where('customer_id', $customerId)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $membership = CustomerPortalMembership::where('identity_id', $identity->id)->where('customer_id', $customerId)->where('status', 'active')->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $contact = $membership ? CustomerContact::whereKey($membership->contact_id)->where('customer_id', $customerId)->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
        $freshIdentity = CustomerPortalIdentity::whereKey($identity->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($customer?->is_active && $setting?->enabled && $membership && ! $membership->revoked_at && $contact?->is_active && $freshIdentity?->active && $freshIdentity->revision === $identity->revision && $freshIdentity->email_verified_at && hash_equals($freshIdentity->email, strtolower(trim((string) $contact->email))), 403, 'Zugang nicht verfügbar.');
        if ($ability !== null) {
            $module = explode('.', $ability)[0];
            abort_unless(in_array($ability, $membership->capabilities ?? [], true) && in_array($module, $setting->modules ?? [], true), 403);
            if ($setting->require_mfa && in_array($ability, ['offers.accept', 'proofs.accept'], true)) {
                abort_unless($freshIdentity->two_factor_confirmed_at && session('customer_portal_mfa_identity') === $freshIdentity->id && session('customer_portal_mfa_revision') === $freshIdentity->revision && session('customer_portal_mfa_at', 0) >= now()->subMinutes(15)->timestamp, 403, 'Zusätzliche Bestätigung erforderlich.');
            }
        }
        $membership->setRelation('customer', $customer)->setRelation('contact', $contact)->setRelation('identity', $freshIdentity)->setRelation('setting', $setting);

        return $membership;
    }

    public function memberships(CustomerPortalIdentity $identity): Collection
    {
        CustomerPortalSchema::requireReady();

        return CustomerPortalMembership::where('identity_id', $identity->id)->where('status', 'active')->get()->filter(function ($membership) use ($identity): bool {
            try {
                $this->membership($identity, $membership->customer_id);

                return true;
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 403) {
                    throw $exception;
                }

                return false;
            }
        })->values();
    }

    public function orders(CustomerPortalIdentity $identity, int $customerId, ?string $ability = 'orders.view'): Builder
    {
        $membership = $this->membership($identity, $customerId, $ability);
        $query = Order::where('customer_id', $customerId);
        $released = ['confirmed', 'planned', 'in_progress', 'completed', 'invoiced', 'cancelled'];
        $query->where(function ($status) use ($customerId, $released): void {
            $status->whereIn('status', $released);
            if (Schema::hasColumns('customer_portal_publications', ['customer_id', 'subject_type', 'subject_id', 'status', 'revision'])) {
                $status->orWhereExists(fn ($published) => $published->selectRaw('1')->from('customer_portal_publications as cp_visible')->whereColumn('cp_visible.subject_id', 'orders.id')->where('cp_visible.customer_id', $customerId)->where('cp_visible.subject_type', 'order')->where('cp_visible.status', 'published')->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('customer_portal_publications as cp_newer')->whereColumn('cp_newer.customer_id', 'cp_visible.customer_id')->whereColumn('cp_newer.subject_type', 'cp_visible.subject_type')->whereColumn('cp_newer.subject_id', 'cp_visible.subject_id')->whereColumn('cp_newer.revision', '>', 'cp_visible.revision')));
            }
        });
        if ($membership->history_from) {
            $query->where('ends_at', '>=', $membership->history_from->startOfDay()->utc());
        }
        if ($membership->location_ids) {
            if (! Schema::hasColumn('orders', 'customer_portal_location_id')) {
                return $query->whereRaw('1=0');
            }
            $query->whereIn('customer_portal_location_id', $membership->location_ids);
        }

        return $query;
    }

    public function inquiries(CustomerPortalIdentity $identity, int $customerId, ?string $ability = 'requests.create'): Builder
    {
        $membership = $this->membership($identity, $customerId, $ability);
        $query = OperationInquiry::where('customer_id', $customerId);
        if ($membership->history_from) {
            $query->where('created_at', '>=', $membership->history_from->startOfDay()->utc());
        }
        if ($membership->location_ids) {
            if (! Schema::hasColumn('operation_inquiries', 'customer_portal_location_id')) {
                return $query->whereRaw('1=0');
            }
            $query->whereIn('customer_portal_location_id', $membership->location_ids);
        }

        return $query;
    }
}
