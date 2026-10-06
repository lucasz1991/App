<?php

namespace App\Livewire\Operations;

use App\Models\Customer;
use App\Models\CustomerCapacityCommitment;
use App\Models\CustomerCondition;
use App\Models\CustomerContact;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalAttachment;
use App\Models\CustomerPortalAutomationProfile;
use App\Models\CustomerPortalDelivery;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalMessage;
use App\Models\CustomerPortalPublication;
use App\Models\CustomerPortalRequest;
use App\Models\CustomerPortalSetting;
use App\Models\CustomerPortalSubmission;
use App\Models\QualificationType;
use App\Models\User;
use App\Services\CustomerPortal\CustomerCapacityService;
use App\Services\CustomerPortal\CustomerPortalAccessService;
use App\Services\CustomerPortal\CustomerPortalBusinessService;
use App\Services\CustomerPortal\CustomerPortalDecisionService;
use App\Services\CustomerPortal\CustomerPortalIntakeAttachmentService;
use App\Services\CustomerPortal\CustomerPortalInvitationService;
use App\Services\CustomerPortal\CustomerPortalPublicationService;
use App\Services\CustomerPortal\CustomerPortalWorkspaceService;
use App\Services\Operations\CommercialOfferService;
use App\Services\Operations\PersonnelScopeService;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerPortalManagement extends Component
{
    use WithFileUploads;

    public const TABS = ['access' => 'Zugang', 'contacts' => 'Kontakte', 'automation' => 'Automatik', 'publications' => 'Freigaben', 'delivery' => 'Versand', 'requests' => 'Anfragen'];

    public const ROLES = ['reader' => 'Lesender', 'requester' => 'Anfragender', 'orderer' => 'Besteller', 'proof_reviewer' => 'Leistungsprüfer', 'coordinator' => 'Kundenkoordinator'];

    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public string $tab = 'access';

    #[Locked]
    public bool $embedded = false;

    #[Locked]
    public ?int $contextCustomerId = null;

    #[Locked]
    public string $contextTab = '';

    #[Locked]
    public string $modal = '';

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public array $contactRevisions = [];

    #[Locked]
    public array $simulation = [];

    public array $form = [];

    public bool $formOpen = false;

    public string $search = '';

    public $upload;

    public function mount(string $tab = '', ?int $customerId = null, bool $embedded = false, ?int $initialRecordId = null, string $initialSource = ''): void
    {
        $customers = app(CustomerPortalScope::class)->manageableCustomers($this->actor());
        $this->embedded = $embedded;
        $requested = $customerId ?? request()->query('customer');
        if ($requested !== null) {
            abort_unless((is_int($requested) && $requested > 0) || (is_string($requested) && ctype_digit($requested) && (int) $requested > 0), 404);
            $this->customerId = (int) $customers->findOrFail((int) $requested)->id;
        } else {
            abort_if($embedded, 404);
            $this->customerId = $customers->orderBy('company_name')->value('id');
        }
        $initialTab = $tab ?: request()->query('tab', 'access');
        $this->tab = is_string($initialTab) && array_key_exists($initialTab, self::TABS) ? $initialTab : 'access';
        if ($embedded) {
            abort_unless(in_array($this->tab, ['access', 'automation', 'publications', 'delivery', 'requests'], true), 404);
            $this->contextCustomerId = $this->customerId;
            $this->contextTab = $this->tab;
        }
        if ($this->customerId) {
            $this->access();
        }
        if ($initialRecordId !== null || $initialSource !== '') {
            abort_unless($this->tab === 'requests' && $initialRecordId > 0 && in_array($initialSource, ['submission', 'request'], true), 404);
            $this->edit($initialSource === 'submission' ? 'decision' : 'review-request', $initialRecordId);
        }
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $fresh = $actor->fresh();
        abort_unless($fresh, 403);

        return $fresh;
    }

    private function access(string $ability = 'customers.portal.manage'): void
    {
        abort_unless($this->customerId, 403);
        abort_unless(! $this->embedded || ($this->customerId === $this->contextCustomerId && $this->tab === $this->contextTab), 403);
        app(CustomerPortalScope::class)->authorizeManager($this->actor(), $this->customerId, $ability);
    }

    public function selectCustomer(int $id): void
    {
        abort_unless(! $this->embedded || $this->contextCustomerId === $id, 403);
        app(CustomerPortalScope::class)->authorizeManager($this->actor(), $id);
        $this->customerId = $id;
        $this->close();
        $this->search = '';
    }

    public function setTab(string $tab): void
    {
        $this->access();
        abort_unless(! $this->embedded || $this->contextTab === $tab, 403);
        abort_unless(array_key_exists($tab, self::TABS), 422);
        $this->tab = $tab;
        $this->close();
        $this->search = '';
    }

    public function close(): void
    {
        if ($this->customerId) {
            $this->access();
        }
        $this->formOpen = false;
        $this->modal = '';
        $this->recordId = null;
        $this->revision = 0;
        $this->form = [];
        $this->simulation = [];
        $this->upload = null;
        $this->resetValidation();
    }

    public function edit(string $modal, ?int $id = null): void
    {
        $this->access();
        abort_unless(in_array($modal, ['access', 'contact', 'invite', 'revoke', 'profile', 'approve-profile', 'commitment', 'approve-commitment', 'publish', 'document', 'review-document', 'review-attachment', 'withdraw', 'decision', 'review-request', 'reply'], true), 422);
        $this->close();
        $this->modal = $modal;
        $this->recordId = $id;
        $this->form = ['confirmed' => false, 'note' => '', 'reason' => ''];
        if ($modal === 'access') {
            $setting = CustomerPortalSetting::where('customer_id', $this->customerId)->first();
            $this->revision = $setting?->revision ?? 0;
            $this->form += $setting?->only(['enabled', 'modules', 'automation_mode', 'auto_reject', 'booking_authority', 'require_mfa', 'notifications']) ?? ['enabled' => false, 'modules' => [], 'automation_mode' => 'manual', 'auto_reject' => false, 'booking_authority' => false, 'require_mfa' => false, 'notifications' => []];
            $this->form['contact_id'] = '';
            $this->contactRevisions = CustomerPortalMembership::where('customer_id', $this->customerId)->pluck('revision', 'contact_id')->all();
        } elseif (in_array($modal, ['contact', 'invite', 'revoke'], true)) {
            $membership = $id ? CustomerPortalMembership::where('customer_id', $this->customerId)->findOrFail($id) : null;
            $this->revision = $membership?->revision ?? 0;
            $this->form += ['contact_id' => $membership?->contact_id ?? '', 'role' => $membership?->role ?? 'requester', 'status' => $membership?->status ?? 'pending', 'location_ids' => $membership?->location_ids ?? [], 'history_from' => $membership?->history_from?->format('Y-m-d') ?? ''];
            if ($membership) {
                $this->form['email'] = $membership->contact?->email ?? '';
            }
        } elseif (in_array($modal, ['profile', 'approve-profile'], true)) {
            $this->access('customers.portal.automation');
            $profile = $id ? CustomerPortalAutomationProfile::where('customer_id', $this->customerId)->findOrFail($id) : CustomerPortalAutomationProfile::where('customer_id', $this->customerId)->first();
            $this->recordId = $profile?->id;
            $this->revision = $profile?->revision ?? 0;
            $this->form += $profile?->only(['name', 'mode', 'auto_reject', 'location_ids', 'minimum_lead_minutes', 'maximum_staff', 'valid_from', 'valid_until']) ?? ['name' => '', 'mode' => 'manual', 'auto_reject' => false, 'location_ids' => [], 'minimum_lead_minutes' => null, 'maximum_staff' => null, 'valid_from' => now()->toDateString(), 'valid_until' => ''];
            $this->form['allowed_roles'] = implode("\n", $profile?->allowed_roles ?? []);
            $this->form['condition_ids'] = $profile?->condition_ids ?? [];
            $this->form['maximum_total'] = $profile?->maximum_total_cents !== null ? number_format($profile->maximum_total_cents / 100, 2, '.', '') : null;
            foreach (['valid_from', 'valid_until'] as $key) {
                $this->form[$key] = $profile?->$key?->format('Y-m-d') ?? $this->form[$key] ?? '';
            }
        } elseif (in_array($modal, ['review-document', 'withdraw'], true)) {
            $this->access('customers.portal.publish');
            $publication = CustomerPortalPublication::where('customer_id', $this->customerId)->findOrFail($id);
            $this->revision = $publication->revision;
            $this->form['title'] = $publication->title;
        } elseif (in_array($modal, ['publish', 'document'], true)) {
            $this->access('customers.portal.publish');
            $this->form += ['target' => '', 'title' => '', 'kind' => 'document', 'location_id' => '', 'summary' => '', 'order_id' => ''];
        } elseif ($modal === 'decision') {
            $this->authorizeDecision();
            $submission = CustomerPortalSubmission::where('customer_id', $this->customerId)->findOrFail($id);
            $this->revision = $submission->revision;
            $this->form += ['title' => $submission->payload['positions'][0]['title'] ?? 'Leistungsanfrage', 'action' => 'review'];
        } elseif (in_array($modal, ['commitment', 'approve-commitment'], true)) {
            $this->access('customers.portal.automation');
            $commitment = $id ? CustomerCapacityCommitment::where('customer_id', $this->customerId)->findOrFail($id) : null;
            if ($commitment) {
                app(PersonnelScopeService::class)->authorize($this->actor(), $commitment->user_id, 'employees.master-data.view');
            }
            $this->revision = $commitment?->revision ?? 0;
            $this->form += $commitment?->only(['user_id', 'location_id', 'role_name', 'timezone', 'planned_break_minutes', 'qualification_ids']) ?? ['user_id' => '', 'location_id' => '', 'role_name' => '', 'timezone' => config('operations.display_timezone', 'Europe/Berlin'), 'planned_break_minutes' => 0, 'qualification_ids' => []];
            $this->form['starts_at'] = $commitment?->starts_at?->setTimezone($commitment->timezone)->format('Y-m-d\TH:i') ?? '';
            $this->form['ends_at'] = $commitment?->ends_at?->setTimezone($commitment->timezone)->format('Y-m-d\TH:i') ?? '';
        } elseif ($modal === 'review-request') {
            $this->access('customers.portal.publish');
            $request = CustomerPortalRequest::where('customer_id', $this->customerId)->findOrFail($id);
            $this->revision = $request->revision;
            $this->form += ['title' => $request->title, 'message' => $request->payload['message'] ?? '', 'status' => 'reviewing', 'response' => ''];
        } elseif ($modal === 'reply') {
            $this->access('customers.portal.publish');
            $message = $id ? CustomerPortalMessage::where('customer_id', $this->customerId)->findOrFail($id) : null;
            $this->form += ['subject' => $message?->subject ?? '', 'order_id' => $message?->order_id ?? '', 'body' => '', 'visibility' => 'customer'];
        } elseif ($modal === 'review-attachment') {
            $this->access('customers.portal.publish');
            $attachment = CustomerPortalAttachment::where('customer_id', $this->customerId)->where('status', 'quarantined')->findOrFail($id);
            $this->revision = $attachment->revision;
            $this->form['title'] = $attachment->file_name;
        }
        $this->formOpen = true;
    }

    public function save(): void
    {
        $this->access();
        abort_unless($this->formOpen, 409);
        $actor = $this->actor();
        if ($this->modal === 'access') {
            $this->validate(['form.confirmed' => 'accepted']);
            $old = CustomerPortalSetting::where('customer_id', $this->customerId)->first();
            $activating = $this->form['enabled'] && ! $old?->enabled;
            if ($activating) {
                $this->validate(['form.contact_id' => 'required|integer|min:1']);
            }
            OperationsTransaction::run(function () use ($actor, $activating) {
                if ($activating) {
                    $contactId = (int) $this->form['contact_id'];
                    $membership = CustomerPortalMembership::where('customer_id', $this->customerId)->where('contact_id', $contactId)->first();
                    $membership = app(CustomerPortalAccessService::class)->saveMembership($this->customerId, $contactId, (int) ($this->contactRevisions[$contactId] ?? 0), ['role' => $membership?->role ?? 'requester', 'status' => 'pending', 'capabilities' => $membership?->capabilities ?? CustomerPortalMembership::ROLES['requester'], 'location_ids' => $membership?->location_ids ?? [], 'history_from' => $membership?->history_from?->format('Y-m-d')], $actor);
                }
                app(CustomerPortalAccessService::class)->saveSetting($this->customerId, $this->revision, $this->form + ['activation_contact_id' => $activating ? (int) $this->form['contact_id'] : null], $actor);
            });
        } elseif ($this->modal === 'contact') {
            $this->validate(['form.confirmed' => 'accepted']);
            $contactId = $this->recordId ? CustomerPortalMembership::where('customer_id', $this->customerId)->findOrFail($this->recordId)->contact_id : (int) $this->form['contact_id'];
            $data = $this->form;
            $data['history_from'] = filled($data['history_from'] ?? null) ? $data['history_from'] : null;
            app(CustomerPortalAccessService::class)->saveMembership($this->customerId, $contactId, $this->revision, $data, $actor);
        } elseif ($this->modal === 'invite') {
            $this->validate(['form.confirmed' => 'accepted']);
            app(CustomerPortalInvitationService::class)->invite($this->customerId, $this->recordId, $this->revision, $actor);
        } elseif ($this->modal === 'revoke') {
            $this->validate(['form.confirmed' => 'accepted']);
            app(CustomerPortalAccessService::class)->revoke($this->recordId, $this->revision, $actor);
        } elseif ($this->modal === 'profile') {
            $this->access('customers.portal.automation');
            $this->validate(['form.maximum_total' => 'nullable|decimal:0,2|min:0|max:99999999']);
            $data = $this->form;
            $data['allowed_roles'] = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $data['allowed_roles']))));
            $data['maximum_total_cents'] = filled($data['maximum_total']) ? CommercialOfferService::scaled((string) $data['maximum_total'], 2) : null;
            foreach (['valid_until', 'minimum_lead_minutes', 'maximum_staff'] as $key) {
                if (blank($data[$key] ?? null)) {
                    $data[$key] = null;
                }
            }
            app(CustomerPortalDecisionService::class)->saveProfile($actor, $this->customerId, $this->revision, $data);
        } elseif ($this->modal === 'approve-profile') {
            $this->validate(['form.confirmed' => 'accepted']);
            app(CustomerPortalDecisionService::class)->approveProfile($actor, $this->customerId, $this->recordId, $this->revision);
        } elseif ($this->modal === 'publish') {
            $this->access('customers.portal.publish');
            $this->validate(['form.confirmed' => 'accepted', 'form.target' => 'required|string|regex:/^(order|offer|proof):[1-9][0-9]*$/']);
            [$type, $id] = explode(':', $this->form['target']);
            app(CustomerPortalPublicationService::class)->publish($actor, $this->customerId, $type, (int) $id, $this->form);
        } elseif ($this->modal === 'document') {
            $this->access('customers.portal.publish');
            $this->validate(['upload' => 'required|file|max:10240']);
            app(CustomerPortalPublicationService::class)->quarantineDocument($actor, $this->customerId, $this->upload, $this->form);
        } elseif ($this->modal === 'review-document') {
            $this->access('customers.portal.publish');
            $this->validate(['form.confirmed' => 'accepted', 'form.note' => 'required|string|min:5|max:2000']);
            app(CustomerPortalPublicationService::class)->reviewDocument($actor, $this->recordId, $this->revision, true, $this->form['note']);
        } elseif ($this->modal === 'withdraw') {
            $this->access('customers.portal.publish');
            app(CustomerPortalPublicationService::class)->withdraw($actor, $this->recordId, $this->revision, $this->form['reason']);
        } elseif ($this->modal === 'decision') {
            $this->authorizeDecision();
            app(CustomerPortalDecisionService::class)->decide($actor, $this->customerId, $this->recordId, $this->revision, $this->form['action'], $this->form['note']);
        } elseif ($this->modal === 'commitment') {
            $this->access('customers.portal.automation');
            app(CustomerCapacityService::class)->propose($actor, $this->customerId, $this->form);
        } elseif ($this->modal === 'approve-commitment') {
            $this->access('customers.portal.automation');
            $this->validate(['form.confirmed' => 'accepted']);
            app(CustomerCapacityService::class)->approve($actor, $this->customerId, $this->recordId, $this->revision);
        } elseif ($this->modal === 'review-request') {
            $this->access('customers.portal.publish');
            app(CustomerPortalBusinessService::class)->reviewRequest($actor, $this->recordId, $this->revision, $this->form['status'], $this->form['response']);
        } elseif ($this->modal === 'reply') {
            $this->access('customers.portal.publish');
            app(CustomerPortalBusinessService::class)->reply($actor, $this->customerId, $this->form);
        } elseif ($this->modal === 'review-attachment') {
            $this->access('customers.portal.publish');
            CustomerPortalAttachment::where('customer_id', $this->customerId)->findOrFail($this->recordId);
            $this->validate(['form.confirmed' => 'accepted', 'form.note' => 'required|string|min:5|max:2000']);
            app(CustomerPortalIntakeAttachmentService::class)->review($actor, $this->recordId, $this->revision, true, $this->form['note']);
        } else {
            abort(422);
        }
        session()->flash('operations.saved', 'Gespeichert.');
        $this->close();
    }

    public function simulate(int $id): void
    {
        $this->access('customers.portal.automation');
        $this->simulation = app(CustomerPortalDecisionService::class)->simulate($this->actor(), $this->customerId, $id);
    }

    public function canCustomer(string $ability): bool
    {
        try {
            $this->access($ability);

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

    private function authorizeDecision(): void
    {
        $this->access();
        abort_unless($this->actor()->can('operations.inquiries.manage'), 403);
    }

    public function canDecide(): bool
    {
        return $this->canCustomer('customers.portal.manage') && $this->actor()->can('operations.inquiries.manage');
    }

    public function render()
    {
        $customers = app(CustomerPortalScope::class)->manageableCustomers($this->actor())->when($this->embedded, fn ($query) => $query->whereKey($this->contextCustomerId))->orderBy('company_name')->limit(500)->get(['id', 'company_name']);
        if (! $this->customerId) {
            return view('livewire.operations.customer-portal-management', ['customers' => $customers, 'customer' => null]);
        }
        $this->access();
        $customer = Customer::findOrFail($this->customerId);
        $setting = CustomerPortalSetting::where('customer_id', $this->customerId)->first();
        if ($this->tab === 'automation') {
            $this->access('customers.portal.automation');
        }
        if ($this->tab === 'publications') {
            $this->access('customers.portal.publish');
        }
        $management = in_array($this->tab, ['publications', 'requests'], true) && $this->canCustomer('customers.portal.publish') ? app(CustomerPortalWorkspaceService::class)->management($this->actor(), $this->customerId, $this->tab) : ['rows' => [], 'targets' => []];
        $records = match ($this->tab) {
            'access', 'contacts' => CustomerPortalMembership::where('customer_id', $this->customerId)->with('contact')->orderBy('id')->limit(200)->get()->map(fn ($r) => (object) ['id' => $r->id, 'title' => $r->contact?->name ?? 'Kontakt', 'summary' => $r->contact?->email, 'role' => self::ROLES[$r->role] ?? $r->role, 'status' => $r->status, 'revision' => $r->revision, 'portal_enabled' => $setting?->enabled ?? false]),
            'automation' => CustomerPortalAutomationProfile::where('customer_id', $this->customerId)->orderByDesc('id')->limit(100)->get(),
            'delivery' => CustomerPortalDelivery::where('customer_id', $this->customerId)->orderByDesc('id')->limit(200)->get(),
            default => collect($management['rows'])->map(fn ($r) => (object) $r),
        };
        if ($this->tab === 'requests') {
            if ($this->canCustomer('customers.portal.publish')) {
                $messages = app(CustomerPortalWorkspaceService::class)->management($this->actor(), $this->customerId, 'messages')['rows'];
                $records = $records->merge(collect($messages)->map(fn ($r) => (object) $r));
                $records = $records->merge(CustomerPortalAttachment::where('customer_id', $this->customerId)->latest('id')->limit(250)->get()->map(fn ($r) => (object) ['id' => $r->id, 'title' => $r->file_name, 'status' => $r->status, 'revision' => $r->revision, 'subject_type' => 'attachment', 'summary' => 'Eingangsanlage']));
            }
            if ($this->canDecide()) {
                $records = $records->merge(CustomerPortalSubmission::where('customer_id', $this->customerId)->latest('id')->limit(250)->get()->map(fn ($r) => (object) ['id' => $r->id, 'title' => $r->payload['positions'][0]['title'] ?? 'Leistungsanfrage', 'status' => $r->status, 'revision' => $r->revision, 'subject_type' => 'submission', 'summary' => $r->decision['message'] ?? '']));
            }
        }
        $staff = collect();
        $commitments = collect();
        if ($this->tab === 'automation' && $this->actor()->can('employees.master-data.view')) {
            $staff = app(PersonnelScopeService::class)->applyUsers(User::where('role', 'staff')->where('status', true), $this->actor(), 'employees.master-data.view')->orderBy('name')->limit(500)->get(['id', 'name']);
            $commitments = CustomerCapacityCommitment::where('customer_id', $this->customerId)->whereIn('user_id', $staff->pluck('id'))->latest('id')->limit(200)->get()->map(fn ($r) => (object) ['id' => $r->id, 'title' => $r->role_name.' · '.($staff->firstWhere('id', $r->user_id)?->name ?? 'Mitarbeiter'), 'status' => $r->status, 'revision' => $r->revision, 'kind' => 'commitment', 'starts_at' => $r->starts_at, 'ends_at' => $r->ends_at, 'timezone' => $r->timezone]);
        }
        $records = $records->filter(fn ($r) => blank($this->search) || Str::contains(mb_strtolower(($r->title ?? $r->name ?? '').' '.($r->summary ?? '')), mb_strtolower(mb_substr($this->search, 0, 100))))->values();
        if ($this->tab === 'requests') {
            $records = $records->map(fn ($r) => (object) array_merge(get_object_vars($r), ['action_id' => $r->id, 'id' => (int) hexdec(substr(hash('sha256', ($r->subject_type ?? 'record').':'.$r->id), 0, 12))]));
        }

        return view('livewire.operations.customer-portal-management', ['customers' => $customers, 'customer' => $customer, 'setting' => $setting, 'items' => $records, 'contacts' => CustomerContact::where('customer_id', $this->customerId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']), 'locations' => CustomerLocation::where('customer_id', $this->customerId)->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'targets' => $management['targets'] ?? [], 'staff' => $staff, 'commitments' => $commitments, 'qualifications' => $this->tab === 'automation' ? QualificationType::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(), 'conditions' => $this->tab === 'automation' ? CustomerCondition::where('customer_id', $this->customerId)->orderBy('code')->get(['id', 'label', 'unit', 'valid_from', 'valid_until']) : collect()]);
    }
}
