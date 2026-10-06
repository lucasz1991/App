<?php

namespace App\Livewire\CustomerPortal;

use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMembership;
use App\Services\CustomerPortal\CustomerPortalBusinessService;
use App\Services\CustomerPortal\CustomerPortalSeriesService;
use App\Services\CustomerPortal\CustomerPortalSubmissionService;
use App\Services\CustomerPortal\CustomerPortalWorkspaceService;
use App\Support\CustomerPortal\CustomerPortalScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class Workspace extends Component
{
    use WithFileUploads;

    public const TABS = ['overview' => 'Übersicht', 'requests' => 'Anfragen', 'offers' => 'Angebote', 'orders' => 'Aufträge', 'calendar' => 'Termine', 'proofs' => 'Leistungen', 'documents' => 'Dokumente', 'messages' => 'Nachrichten', 'reports' => 'Berichte', 'profile' => 'Profil'];

    public static function statusLabel(string $status): string
    {
        return ['new' => 'Neu', 'pending' => 'Ausstehend', 'active' => 'Aktiv', 'suspended' => 'Pausiert', 'revoked' => 'Widerrufen',
            'submitted' => 'Eingegangen', 'review' => 'In Prüfung', 'needs_review' => 'In Prüfung', 'verified' => 'Geprüft', 'offered' => 'Angebot liegt vor',
            'accepted' => 'Angenommen', 'confirmed' => 'Bestätigt', 'converted' => 'Auftrag angelegt', 'rejected' => 'Abgelehnt', 'declined' => 'Abgesagt',
            'draft' => 'Entwurf', 'approved' => 'Freigegeben', 'published' => 'Freigegeben', 'quarantined' => 'Prüfung offen', 'withdrawn' => 'Zurückgezogen',
            'cancelled' => 'Storniert', 'completed' => 'Abgeschlossen', 'failed' => 'Versandfehler', 'unknown' => 'Übergabe ungeklärt',
            'sent' => 'Übergeben', 'provider_accepted' => 'Übergeben', 'queued' => 'Vorgemerkt', 'disabled' => 'Deaktiviert'][$status] ?? 'In Bearbeitung';
    }

    #[Locked]
    public int $customerId;

    #[Locked]
    public string $tab = 'overview';

    #[Locked]
    public string $modal = '';

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public string $recordType = '';

    #[Locked]
    public ?int $recordRevision = null;

    #[Locked]
    public ?int $recordSourceId = null;

    #[Locked]
    public ?int $recordStateVersion = null;

    #[Locked]
    public string $submissionKey = '';

    #[Locked]
    public array $terms = [];

    #[Locked]
    public array $seriesPreview = [];

    public bool $formOpen = false;

    public array $form = [];

    public string $search = '';

    public array $attachments = [];

    public string $anchorDate = '';

    #[Locked]
    public string $viewMode = 'week';

    public function mount(int $customer, string $section = 'overview'): void
    {
        $this->customerId = $customer;
        $this->anchorDate = now(config('operations.display_timezone', 'Europe/Berlin'))->toDateString();
        $this->setTab($section);
    }

    private function identity(): CustomerPortalIdentity
    {
        $identity = Auth::guard('customer_portal')->user();
        abort_unless($identity instanceof CustomerPortalIdentity, 403);

        $fresh = $identity->fresh();
        abort_unless($fresh, 403);

        return $fresh;
    }

    private function access(?string $ability = null): CustomerPortalMembership
    {
        return app(CustomerPortalScope::class)->membership($this->identity(), $this->customerId, $ability);
    }

    public function tabs(): array
    {
        $membership = $this->access();
        $modules = $membership->setting?->modules ?? [];

        return array_filter(self::TABS, function ($label, $tab) use ($membership, $modules) {
            if (in_array($tab, ['overview', 'profile'], true)) {
                return true;
            }
            $ability = match ($tab) {
                'requests' => 'requests.create', 'messages' => 'messages.create', 'reports' => 'reports.view', 'documents' => 'documents.view',
                default => 'orders.view',
            };

            return in_array($tab === 'calendar' ? 'orders' : $tab, $modules, true) && in_array($ability, $membership->capabilities ?? [], true);
        }, ARRAY_FILTER_USE_BOTH);
    }

    public function setTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, $this->tabs()), 403);
        $this->tab = $tab;
        $this->search = '';
        $this->close();
    }

    public function switchCustomer(int $customerId): void
    {
        app(CustomerPortalScope::class)->membership($this->identity(), $customerId);
        $this->redirectRoute('customer-portal.workspace', ['customer' => $customerId, 'section' => 'overview']);
    }

    public function close(): void
    {
        $this->access();
        $this->formOpen = false;
        $this->modal = '';
        $this->recordId = null;
        $this->recordRevision = null;
        $this->recordType = '';
        $this->recordSourceId = null;
        $this->recordStateVersion = null;
        $this->form = [];
        $this->attachments = [];
        $this->terms = [];
        $this->seriesPreview = [];
        $this->resetValidation();
    }

    public function openDetails(string $type, int $id): void
    {
        $this->access();
        $details = app(CustomerPortalWorkspaceService::class)->detail($this->identity(), $this->customerId, $type, $id);
        $this->close();
        $this->recordId = $id;
        $this->recordType = $type;
        $this->recordRevision = (int) ($details['revision'] ?? 0);
        $this->recordSourceId = isset($details['source_id']) ? (int) $details['source_id'] : null;
        $this->recordStateVersion = isset($details['state_version']) ? (int) $details['state_version'] : null;
        $this->modal = 'detail';
        $this->form = ['note' => '', 'confirmed' => false];
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function create(string $kind = 'performance'): void
    {
        $ability = match ($kind) {
            'performance' => 'requests.create', 'message' => 'messages.create',
            'change', 'cancel', 'disruption', 'replacement', 'complaint', 'master_data' => 'changes.create', default => abort(422),
        };
        $this->access($ability);
        $this->close();
        $this->recordId = null;
        $this->recordRevision = null;
        $this->recordType = '';
        $this->modal = $kind;
        $this->submissionKey = (string) Str::uuid();
        $this->form = $kind === 'performance' ? [
            'title' => '', 'role_name' => '', 'required_staff' => 1, 'starts_at' => '', 'ends_at' => '',
            'timezone' => config('operations.display_timezone', 'Europe/Berlin'), 'location_id' => '', 'location_name' => '',
            'reference' => '', 'intent' => 'quote', 'note' => '', 'positions' => [],
            'accept_conditions' => false,
            'condition_id' => '', 'quantity' => null, 'series_enabled' => false, 'planned_break_minutes' => 0, 'train_reference' => '', 'vehicle_reference' => '', 'cost_center' => '',
            'recurrence' => ['from_date' => now()->toDateString(), 'to_date' => now()->toDateString(), 'weekdays' => [], 'start_time' => '06:00', 'end_time' => '14:00', 'overnight' => false, 'timezone' => config('operations.display_timezone', 'Europe/Berlin'), 'exceptions' => []],
        ] : ['kind' => $kind, 'title' => '', 'message' => '', 'subject' => '', 'body' => '', 'order_id' => '', 'target_type' => 'customer', 'target_id' => $this->customerId, 'proposed_fields' => ['company_name' => '', 'name' => '', 'email' => '', 'phone' => '', 'street' => '', 'postal_code' => '', 'city' => '', 'country' => '']];
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function updatedForm(mixed $value, string $key): void
    {
        $this->access();
        if (str_starts_with($key, 'recurrence.') || in_array($key, ['series_enabled', 'starts_at', 'ends_at', 'role_name', 'required_staff', 'location_id', 'location_name', 'condition_id', 'quantity'], true)) {
            $this->seriesPreview = [];
        }
        if (in_array($key, ['intent', 'starts_at', 'timezone'], true)) {
            $this->terms = [];
            $this->form['accept_conditions'] = false;
        }
        if ($this->modal === 'master_data' && $key === 'target_type') {
            $this->form['target_id'] = $value === 'contact' ? $this->access()->contact_id : ($value === 'customer' ? $this->customerId : '');
        }
    }

    public function loadTerms(): void
    {
        $this->access('offers.accept');
        abort_unless($this->modal === 'performance' && ($this->form['intent'] ?? '') === 'framework', 409);
        $startsAt = ($this->form['series_enabled'] ?? false) ? ($this->seriesPreview[0]['starts_at'] ?? '') : $this->form['starts_at'];
        validator(['starts_at' => $startsAt, 'timezone' => $this->form['timezone']], ['starts_at' => 'required|string|max:80', 'timezone' => 'required|timezone'])->validate();
        $this->terms = app(CustomerPortalSubmissionService::class)->frameworkTerms($this->identity(), $this->customerId, $startsAt, $this->form['timezone']);
        $this->form['accept_conditions'] = false;
    }

    public function previewSeries(): void
    {
        $this->access('requests.create');
        abort_unless($this->modal === 'performance' && ($this->form['series_enabled'] ?? false), 409);
        $this->seriesPreview = app(CustomerPortalSeriesService::class)->preview($this->identity(), $this->customerId, $this->form['recurrence']);
    }

    public function templateFromOrder(int $publicationId): void
    {
        $this->access('requests.create');
        $template = app(CustomerPortalWorkspaceService::class)->template($this->identity(), $this->customerId, $publicationId);
        $this->create('performance');
        $this->form = array_replace($this->form, array_intersect_key($template, array_flip(['title', 'role_name', 'required_staff', 'location_id', 'location_name'])));
    }

    public function requestForOrder(string $kind): void
    {
        $this->access('changes.create');
        abort_unless($this->recordType === 'order' && $this->recordId, 409);
        $details = app(CustomerPortalWorkspaceService::class)->detail($this->identity(), $this->customerId, 'order', $this->recordId);
        $this->create($kind);
        $this->form['order_id'] = $details['source_id'];
        $this->form['title'] = $details['title'];
    }

    public function addException(): void
    {
        $this->access('requests.create');
        abort_unless($this->modal === 'performance' && count($this->form['recurrence']['exceptions']) < 366, 422);
        $this->form['recurrence']['exceptions'][] = $this->form['recurrence']['from_date'];
        $this->seriesPreview = [];
    }

    public function removeException(int $index): void
    {
        $this->access('requests.create');
        abort_unless(isset($this->form['recurrence']['exceptions'][$index]), 422);
        array_splice($this->form['recurrence']['exceptions'], $index, 1);
        $this->seriesPreview = [];
    }

    public function addPosition(): void
    {
        $this->access('requests.create');
        abort_unless($this->modal === 'performance' && count($this->form['positions'] ?? []) < 19, 422);
        $this->form['positions'][] = ['title' => '', 'role_name' => '', 'required_staff' => 1, 'starts_at' => '', 'ends_at' => '', 'location_id' => '', 'location_name' => '', 'timezone' => $this->form['timezone'], 'condition_id' => '', 'quantity' => null];
    }

    public function removePosition(int $index): void
    {
        $this->access('requests.create');
        abort_unless($this->modal === 'performance' && isset($this->form['positions'][$index]), 422);
        array_splice($this->form['positions'], $index, 1);
    }

    public function save(): void
    {
        abort_unless($this->formOpen, 409);
        $identity = $this->identity();
        $this->validate(['attachments' => 'array|max:20', 'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240']);
        if ($this->modal === 'performance') {
            $this->access('requests.create');
            $data = $this->form;
            $data['accepted_terms_hash'] = $this->terms['hash'] ?? null;
            foreach (['location_id', 'condition_id', 'quantity'] as $nullable) {
                if (blank($data[$nullable] ?? null)) {
                    $data[$nullable] = null;
                }
            }
            if ($data['positions'] ?? []) {
                $base = array_intersect_key($data, array_flip(['title', 'role_name', 'required_staff', 'starts_at', 'ends_at', 'timezone', 'location_id', 'location_name', 'condition_id', 'quantity', 'planned_break_minutes', 'train_reference', 'vehicle_reference', 'cost_center']));
                $data['positions'] = array_merge([$base], $data['positions']);
            }
            if ($data['series_enabled'] ?? false) {
                abort_unless($this->seriesPreview !== [] && ($data['positions'] ?? []) === [], 422, 'Serie zunächst prüfen.');
                app(CustomerPortalSeriesService::class)->submit($identity, $this->customerId, $this->submissionKey, $data['recurrence'], $data, $this->attachments);
            } else {
                if (($data['positions'] ?? []) === []) {
                    unset($data['positions']);
                }
                app(CustomerPortalSubmissionService::class)->submit($identity, $this->customerId, $this->submissionKey, $data, $this->attachments);
            }
        } elseif ($this->modal === 'message') {
            $this->access('messages.create');
            $data = $this->form;
            $data['order_id'] = filled($data['order_id']) ? (int) $data['order_id'] : null;
            app(CustomerPortalBusinessService::class)->message($identity, $this->customerId, $this->submissionKey, $data);
        } elseif (in_array($this->modal, ['change', 'cancel', 'disruption', 'replacement', 'complaint', 'master_data'], true)) {
            $this->access('changes.create');
            $data = $this->form;
            $data['order_id'] = filled($data['order_id']) ? (int) $data['order_id'] : null;
            $data['proposed_fields'] = array_filter($data['proposed_fields'], fn ($value) => filled($value));
            app(CustomerPortalBusinessService::class)->submitRequest($identity, $this->customerId, $this->submissionKey, $data);
        } else {
            abort(422);
        }
        session()->flash('operations.saved', 'Eingang gespeichert.');
        $this->close();
    }

    public function decideProof(string $action): void
    {
        $this->access('proofs.accept');
        abort_unless($this->modal === 'detail' && $this->recordType === 'proof' && $this->recordId, 409);
        $this->validate(['form.note' => 'required|string|min:5|max:2000', 'form.confirmed' => 'accepted']);
        app(CustomerPortalBusinessService::class)->decideProof($this->identity(), $this->customerId, $this->recordId, $this->recordRevision, $action, $this->form['note']);
        session()->flash('operations.saved', 'Rückmeldung gespeichert.');
        $this->close();
    }

    public function acceptOffer(): void
    {
        $this->access('offers.accept');
        abort_unless($this->modal === 'detail' && $this->recordType === 'offer' && $this->recordId, 409);
        $details = app(CustomerPortalWorkspaceService::class)->detail($this->identity(), $this->customerId, 'offer', $this->recordId);
        abort_unless(($details['revision'] ?? null) === $this->recordRevision && $this->recordSourceId && $this->recordStateVersion, 409, 'Angebot wurde geändert. Bitte neu öffnen.');
        $this->validate(['form.note' => 'required|string|min:5|max:2000', 'form.confirmed' => 'accepted']);
        app(CustomerPortalSubmissionService::class)->acceptOffer($this->identity(), $this->customerId, $this->recordSourceId, $this->recordStateVersion, $this->form['note']);
        session()->flash('operations.saved', 'Angebotsannahme gespeichert.');
        $this->close();
    }

    public function confirmDetail(): void
    {
        if ($this->recordType === 'offer') {
            $this->acceptOffer();
        } else {
            $this->decideProof('accept');
        }
    }

    public function export()
    {
        $this->access('reports.view');
        $csv = app(CustomerPortalWorkspaceService::class)->report($this->identity(), $this->customerId);

        return response()->streamDownload(fn () => print ($csv), 'RailTime-Auftraege.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function switchView(string $view): void
    {
        $this->access('orders.view');
        abort_unless(in_array($view, ['day', 'week', 'month', 'list'], true), 422);
        $this->viewMode = $view;
    }

    public function showDay(string $date): void
    {
        $this->access('orders.view');
        validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
        $this->anchorDate = $date;
        $this->viewMode = 'day';
    }

    public function previousPeriod(): void
    {
        $this->movePeriod(-1);
    }

    public function nextPeriod(): void
    {
        $this->movePeriod(1);
    }

    public function today(): void
    {
        $this->access('orders.view');
        $this->anchorDate = now()->toDateString();
    }

    private function movePeriod(int $direction): void
    {
        $this->access('orders.view');
        validator(['date' => $this->anchorDate], ['date' => 'required|date_format:Y-m-d'])->validate();
        $anchor = CarbonImmutable::parse($this->anchorDate);
        $this->anchorDate = match ($this->viewMode) {
            'month' => $anchor->addMonthsNoOverflow($direction)->toDateString(), 'day' => $anchor->addDays($direction)->toDateString(),
            default => $anchor->addWeeks($direction)->toDateString(),
        };
    }

    private function calendar(array $rows): array
    {
        validator(['date' => $this->anchorDate], ['date' => 'required|date_format:Y-m-d'])->validate();
        $timezone = config('operations.display_timezone', 'Europe/Berlin');
        $anchor = CarbonImmutable::parse($this->anchorDate, $timezone);
        $from = match ($this->viewMode) {
            'month' => $anchor->startOfMonth()->startOfWeek(), 'day' => $anchor->startOfDay(), default => $anchor->startOfWeek()
        };
        $until = match ($this->viewMode) {
            'month' => $anchor->endOfMonth()->endOfWeek(), 'day' => $anchor->endOfDay(), default => $from->addDays(6)->endOfDay()
        };
        $events = collect($rows)->filter(fn ($r) => filled($r['starts_at'] ?? null) && filled($r['ends_at'] ?? null))->map(function ($r) use ($timezone) {
            return (object) ($r + ['starts' => CarbonImmutable::parse($r['starts_at'])->setTimezone($timezone), 'ends' => CarbonImmutable::parse($r['ends_at'])->setTimezone($timezone)]);
        })->filter(fn ($r) => $r->starts <= $until && $r->ends >= $from);
        $days = [];
        for ($day = $from; $day <= $until; $day = $day->addDay()) {
            $days[] = ['date' => $day, 'in_month' => $day->month === $anchor->month, 'is_today' => $day->isToday(), 'events' => $events->filter(fn ($e) => $e->starts < $day->addDay() && $e->ends > $day)];
        }

        return ['calendarDays' => $days, 'calendarEvents' => $events, 'displayTimezone' => $timezone, 'periodLabel' => $this->viewMode === 'month' ? $anchor->locale('de')->isoFormat('MMMM YYYY') : $from->format('d.m.').' – '.$until->format('d.m.Y')];
    }

    public function render()
    {
        $membership = $this->access();
        abort_unless(array_key_exists($this->tab, $this->tabs()), 403);
        $identity = $this->identity();
        $data = app(CustomerPortalWorkspaceService::class)->workspace($identity, $this->customerId, $this->tab);
        $rows = collect($data['rows'])->filter(fn ($r) => blank($this->search) || Str::contains(mb_strtolower(implode(' ', array_filter([$r['title'] ?? '', $r['number'] ?? '', $r['summary'] ?? '']))), mb_strtolower(mb_substr($this->search, 0, 100))))->values();
        $details = $this->modal === 'detail' && $this->recordId ? app(CustomerPortalWorkspaceService::class)->detail($identity, $this->customerId, $this->recordType, $this->recordId) : null;
        $detailAttachments = $details['attachments'] ?? [];
        $viewData = $data + ['identity' => $identity, 'membership' => $membership, 'memberships' => app(CustomerPortalScope::class)->memberships($identity), 'items' => $rows->map(fn ($r) => (object) array_merge($r, ['action_id' => $r['id'], 'id' => (int) hexdec(substr(hash('sha256', ($r['subject_type'] ?? 'record').':'.$r['id']), 0, 12))])), 'tabs' => $this->tabs(), 'details' => $details, 'detailAttachments' => $detailAttachments];
        if ($this->tab === 'calendar') {
            $viewData += $this->calendar($data['rows']);
        }

        return view('livewire.customer-portal.workspace', $viewData)->layout('layouts.customer-portal');
    }
}
