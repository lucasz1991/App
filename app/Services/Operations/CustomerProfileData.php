<?php

namespace App\Services\Operations;

use App\Livewire\CustomerPortal\Workspace;
use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\CustomerPortalInvitation;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalPublication;
use App\Models\CustomerPortalSetting;
use App\Models\InquiryFollowUp;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Read-only customer dossier projection; business actions remain in their existing services. */
class CustomerProfileData
{
    public function availableViews(User $actor, ?int $customerId = null): array
    {
        $actor = $this->actor($actor);
        if ($customerId !== null) {
            $this->customer($actor, $customerId);
        }
        $manage = $actor->can('operations.manage');
        $inquiries = $actor->can('operations.inquiries.manage');
        $operationsReady = OperationsAccess::ready();
        $publish = CustomerPortalWorkflowSchema::ready() && $this->portalAllowed($actor, $customerId, 'customers.portal.publish');

        return array_filter([
            'orders' => $manage && $operationsReady && $this->ordersReady() ? 'Aufträge' : null,
            'inquiries' => $inquiries && $operationsReady && $this->inquiriesReady() ? 'Anfragen' : null,
            'offers' => ($manage && $this->ordersReady() || $inquiries && $this->inquiriesReady()) && CommercialOfferService::ready() ? 'Angebote' : null,
            'communication' => (($manage || $inquiries) && $this->inquiriesReady()) || $publish ? 'Kommunikation' : null,
            'documents' => $publish ? 'Dokumente' : null,
            'history' => ($manage && Schema::hasColumns('customers', ['created_at', 'updated_at']))
                || (($manage || $inquiries) && Schema::hasColumns('operation_audits', ['subject_type', 'subject_id', 'action', 'created_at']))
                || ($this->portalAllowed($actor, $customerId, 'customers.portal.manage') && Schema::hasTable('customer_portal_audits')) ? 'Historie' : null,
        ]);
    }

    public function summary(User $actor, int $customerId): array
    {
        $actor = $this->actor($actor);
        $customer = $this->customer($actor, $customerId);
        $views = $this->availableViews($actor, $customerId);
        $metrics = [];
        $recentOrders = $recentInquiries = $followUps = collect();
        if (isset($views['orders'])) {
            $orders = Order::where('customer_id', $customerId);
            $metrics[] = $this->metric('orders_open', 'Offene Aufträge', (clone $orders)->whereNotIn('status', ['completed', 'invoiced', 'cancelled'])->count(), 'orders', 'fa-briefcase');
            $metrics[] = $this->metric('orders_total', 'Aufträge gesamt', (clone $orders)->count(), 'orders', 'fa-clipboard-list');
            $recentOrders = $orders->latest('updated_at')->orderByDesc('id')->limit(5)
                ->get(['id', 'order_number', 'title', 'status', 'starts_at', 'ends_at', 'timezone', 'location_name'])
                ->map(fn (Order $record) => [
                    'id' => $record->id, 'title' => $record->title, 'number' => $record->order_number,
                    'status' => $record->status->value, 'status_label' => $record->status->label(),
                    'starts_at' => $record->starts_at, 'time_label' => $this->schedule($record->starts_at, $record->ends_at, $record->timezone),
                    'location' => $record->location_name,
                    'detail_url' => OperationsPages::url('cases', ['view' => 'orders', 'customer' => $customerId, 'order' => $record->id]),
                ]);
        }
        if (isset($views['inquiries'])) {
            $inquiries = OperationInquiry::where('customer_id', $customerId);
            $metrics[] = $this->metric('inquiries_open', 'Offene Anfragen', (clone $inquiries)->whereNull('order_id')->whereNull('duplicate_of_id')->where('status', '!=', 'rejected')->count(), 'inquiries', 'fa-inbox');
            $records = $inquiries->latest('updated_at')->orderByDesc('id')->limit(5)
                ->get(['id', 'customer_id', 'title', 'status', 'revision', 'channel', 'starts_at', 'ends_at', 'timezone', 'location_name']);
            $portalSources = $this->portalSources($records->pluck('id'));
            $recentInquiries = $records->map(fn (OperationInquiry $record) => [
                'id' => $record->id, 'title' => $record->title, 'number' => $record->number,
                'status' => $record->status, 'status_label' => Workspace::statusLabel($record->status), 'channel' => $record->channel,
                'starts_at' => $record->starts_at, 'time_label' => $this->schedule($record->starts_at, $record->ends_at, $record->timezone),
                'location' => $record->location_name,
                'detail_url' => $this->inquiryUrl($actor, $record, $portalSources),
            ]);
            if (Schema::hasColumns('inquiry_follow_ups', ['operation_inquiry_id', 'status', 'title', 'kind', 'due_at', 'timezone', 'assignee_id'])) {
                $tasks = InquiryFollowUp::where('status', 'open')->where('due_at', '<=', now()->utc())
                    ->whereHas('inquiry', fn (Builder $query) => $query->where('customer_id', $customerId))
                    ->with(['assignee:id,name', 'inquiry:id,customer_id,revision'])->orderBy('due_at')->orderBy('id')->limit(5)
                    ->get(['id', 'operation_inquiry_id', 'title', 'kind', 'due_at', 'timezone', 'assignee_id']);
                $sources = $this->portalSources($tasks->pluck('operation_inquiry_id'));
                $followUps = $tasks->map(function (InquiryFollowUp $task) use ($actor, $sources): array {
                    // CustomerWorkflowService persists OperationsDateTime::local() UTC instants.
                    // Read that storage contract, not the legacy model's app-timezone date cast.
                    $due = CarbonImmutable::parse($task->getRawOriginal('due_at'), 'UTC');

                    return [
                        'id' => $task->id, 'title' => $task->title, 'kind' => $task->kind,
                        'due_at' => $due, 'due_label' => $due->setTimezone($this->timezone($task->timezone))->format('d.m.Y H:i'),
                        'assignee' => $task->assignee?->name, 'inquiry_id' => $task->operation_inquiry_id,
                        'detail_url' => $this->inquiryUrl($actor, $task->inquiry, $sources),
                    ];
                });
            }
        }
        if (isset($views['offers'])) {
            $metrics[] = $this->metric('offers_open', 'Offene Angebote', $this->offers($actor, $customerId)->whereIn('status', ['draft', 'offered'])->count(), 'offers', 'fa-file-invoice');
        }
        $portal = $this->portalAllowed($actor, $customerId, 'customers.portal.manage') ? $this->portal($actor, $customerId) : null;
        if ($portal !== null && CustomerPortalIntakeSchema::ready() && CustomerPortalWorkflowSchema::ready()) {
            $metrics[] = $this->metric('portal_members', 'Nutzbare Portalzugänge', $portal['usable_memberships'], 'portal', 'fa-user-shield');
        }
        if (isset($views['documents'])) {
            $documents = CustomerPortalPublication::where('customer_id', $customerId)->whereIn('subject_type', ['document', 'invoice'])
                ->where('status', 'published')->whereNull('withdrawn_at')->whereNotNull('reviewed_at')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('customer_portal_publications as newer')
                    ->whereColumn('newer.customer_id', 'customer_portal_publications.customer_id')
                    ->whereColumn('newer.subject_type', 'customer_portal_publications.subject_type')
                    ->whereColumn('newer.subject_id', 'customer_portal_publications.subject_id')
                    ->whereColumn('newer.revision', '>', 'customer_portal_publications.revision'));
            $metrics[] = $this->metric('documents', 'Freigegebene Dokumente', $documents->count(), 'documents', 'fa-folder-open');
        }

        return compact('customer', 'metrics', 'recentOrders', 'recentInquiries', 'followUps', 'portal') + ['canEdit' => $actor->can('operations.manage')];
    }

    private function actor(User $actor): User
    {
        $actor = $actor->fresh();
        abort_unless($actor?->status, 403);

        return $actor;
    }

    private function customer(User $actor, int $customerId): Customer
    {
        abort_unless($customerId > 0, 404);
        $manage = $actor->can('operations.manage');
        $query = Customer::query();
        if (! $manage && ! $actor->can('operations.inquiries.manage')) {
            abort_unless(CustomerPortalSchema::ready() && $actor->can('customers.portal.manage'), 403);
            $query = app(CustomerPortalScope::class)->manageableCustomers($actor);
        }
        $columns = ['id', 'company_name'];
        if ($manage) {
            $columns = array_merge($columns, ['customer_number', 'contact_name', 'email', 'phone', 'street', 'postal_code', 'city', 'country', 'notes', 'is_active', 'created_at', 'updated_at']);
        }

        return $query->select($columns)->findOrFail($customerId);
    }

    private function portalAllowed(User $actor, ?int $customerId, string $ability): bool
    {
        if (! CustomerPortalSchema::ready() || ! $actor->can('customers.portal.manage') || ! $actor->can($ability)) {
            return false;
        }
        try {
            $scope = app(CustomerPortalScope::class);
            if ($customerId === null) {
                return $scope->manageableCustomers($actor)->whereIn('id', $scope->manageableCustomers($actor, $ability)->select('id'))->exists();
            }
            $scope->authorizeManager($actor, $customerId);
            $scope->authorizeManager($actor, $customerId, $ability);

            return true;
        } catch (AuthorizationException $exception) {
            return false;
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }

            return false;
        }
    }

    private function ordersReady(): bool
    {
        return Schema::hasColumns('orders', ['id', 'customer_id', 'order_number', 'title', 'status', 'starts_at', 'ends_at', 'timezone', 'location_name', 'updated_at', 'deleted_at']);
    }

    private function inquiriesReady(): bool
    {
        return Schema::hasColumns('operation_inquiries', ['id', 'customer_id', 'title', 'status', 'revision', 'channel', 'starts_at', 'ends_at', 'timezone', 'location_name', 'order_id', 'duplicate_of_id', 'updated_at']);
    }

    private function offers(User $actor, int $customerId): Builder
    {
        return CommercialOfferRevision::where(function (Builder $query) use ($actor, $customerId): void {
            $query->whereRaw('1 = 0');
            if ($actor->can('operations.manage') && $this->ordersReady()) {
                $query->orWhere(fn (Builder $query) => $query->where('subject_type', 'Order')
                    ->whereIn('subject_id', Order::where('customer_id', $customerId)->select('id'))->whereNull('snapshot->origin_inquiry_id'));
            }
            if ($actor->can('operations.inquiries.manage') && $this->inquiriesReady()) {
                $query->orWhere(fn (Builder $query) => $query->where('subject_type', 'OperationInquiry')
                    ->whereIn('subject_id', OperationInquiry::where('customer_id', $customerId)->select('id')));
            }
        })->where('revision', fn ($query) => $query->selectRaw('MAX(latest.revision)')->from('commercial_offer_revisions as latest')
            ->whereColumn('latest.subject_type', 'commercial_offer_revisions.subject_type')->whereColumn('latest.subject_id', 'commercial_offer_revisions.subject_id'));
    }

    private function portalSources(Collection $inquiryIds): Collection
    {
        if ($inquiryIds->isEmpty() || ! Schema::hasColumns('customer_portal_submission_items', ['inquiry_id', 'submission_id', 'customer_id'])) {
            return collect();
        }

        return DB::table('customer_portal_submission_items')->whereIn('inquiry_id', $inquiryIds)
            ->get(['inquiry_id', 'submission_id', 'customer_id'])->keyBy('inquiry_id');
    }

    private function inquiryUrl(User $actor, OperationInquiry $record, Collection $sources): ?string
    {
        $submission = $sources->get($record->id);
        if ($submission) {
            if ((int) $submission->customer_id !== (int) $record->customer_id || ! CustomerPortalIntakeSchema::ready()
                || ! $this->portalAllowed($actor, $record->customer_id, 'customers.portal.manage')
                || ! DB::table('customer_portal_submissions')->where('id', $submission->submission_id)->where('customer_id', $record->customer_id)->exists()) {
                return null;
            }

            return OperationsPages::url('cases', ['view' => 'inbox', 'section' => 'portal', 'customer' => $record->customer_id, 'source' => 'submission', 'record' => $submission->submission_id]);
        }

        return OperationsPages::url('cases', ['view' => 'inbox', 'section' => 'overview', 'customer' => $record->customer_id, 'inquiry' => $record->id, 'revision' => $record->revision, 'filter' => 'all']);
    }

    private function portal(User $actor, int $customerId): array
    {
        $setting = CustomerPortalSetting::where('customer_id', $customerId)->first(['enabled', 'automation_mode', 'require_mfa', 'booking_authority', 'modules']);
        $memberships = CustomerPortalMembership::where('customer_id', $customerId);
        $usable = 0;
        if ($setting?->enabled && Customer::whereKey($customerId)->where('is_active', true)->exists()) {
            $records = (clone $memberships)->where('status', 'active')->whereNull('revoked_at')
                ->with(['contact:id,customer_id,email,is_active', 'identity:id,email,active,email_verified_at'])
                ->get(['id', 'customer_id', 'contact_id', 'identity_id']);
            $usable = $records->filter(fn ($record) => $record->contact?->is_active && (int) $record->contact->customer_id === $customerId
                && $record->identity?->active && $record->identity->email_verified_at
                && hash_equals((string) $record->identity->email, strtolower(trim((string) $record->contact->email))))->count();
        }
        $invitations = CustomerPortalInvitation::where('customer_id', $customerId)->where('purpose', 'invite');

        return [
            'enabled' => (bool) $setting?->enabled, 'automation_mode' => $setting?->automation_mode ?? 'manual',
            'require_mfa' => (bool) $setting?->require_mfa, 'booking_authority' => (bool) $setting?->booking_authority,
            'modules' => array_values(array_intersect(CustomerPortalSetting::MODULES, $setting?->modules ?? [])),
            'memberships' => (clone $memberships)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($count) => (int) $count)->all(),
            'usable_memberships' => $usable,
            'capabilities' => array_values(array_filter(['manage', 'publish', 'automation'], fn ($ability) => $this->portalAllowed($actor, $customerId, 'customers.portal.'.$ability))),
            'invitations' => [
                'pending' => (clone $invitations)->whereNull('consumed_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
                'expired' => (clone $invitations)->whereNull('consumed_at')->whereNull('revoked_at')->where('expires_at', '<=', now())->count(),
                'accepted' => (clone $invitations)->whereNotNull('consumed_at')->count(),
                'revoked' => (clone $invitations)->whereNotNull('revoked_at')->count(),
            ],
        ];
    }

    private function metric(string $key, string $label, int $value, string $view, string $icon): array
    {
        return compact('key', 'label', 'value', 'view', 'icon');
    }

    private function timezone(?string $timezone): string
    {
        return in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true) ? $timezone : 'Europe/Berlin';
    }

    private function schedule(?CarbonInterface $start, ?CarbonInterface $end, ?string $timezone): string
    {
        if (! $start) {
            return '—';
        }
        $start = $start->copy()->setTimezone($this->timezone($timezone));
        $end = $end?->copy()->setTimezone($this->timezone($timezone));

        return $start->format('d.m.Y H:i').($end ? ' – '.$end->format($start->isSameDay($end) ? 'H:i' : 'd.m.Y H:i') : '');
    }
}
