<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\CustomerCapacityCommitment;
use App\Models\CustomerLocation;
use App\Models\EmployeeQualification;
use App\Models\OperationAudit;
use App\Models\PersonnelTraining;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\CustomerPortal\CustomerCapacityService;
use App\Services\Operations\OperationsReportService;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\PlanChangeService;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\WorkTimeExtensionService;
use App\Services\Operations\WorkTimeService;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\Operations\GermanyMap;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\PersonalSchedule;
use App\Support\Operations\ReportingPeriod;
use App\Support\Operations\TimelineLocationPreview;
use App\Support\Operations\TimelineMiniCalendar;
use App\Support\Operations\WorkTimeSchema;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MyWork extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $tab = 'today';

    public int $week = 0;

    public string $anchorDate = '';

    public string $viewMode = 'week';

    /** Monatsversatz des Mini-Kalenders auf „Heute“ (0 = aktueller Monat). */
    public int $miniMonth = 0;

    public bool $calendarEventOpen = false;

    #[Locked]
    public ?string $selectedCalendarEventId = null;

    public bool $manualOpen = false;

    public bool $correctionOpen = false;

    public bool $qualificationOpen = false;

    public bool $absenceOpen = false;

    public bool $generalManualOpen = false;

    public bool $generalStartOpen = false;

    public bool $sectionsOpen = false;

    public bool $requestOpen = false;

    public bool $requestConfirmed = false;

    #[Locked]
    public string $requestType = '';

    #[Locked]
    public ?int $requestId = null;

    #[Locked]
    public ?int $requestRevision = null;

    #[Locked]
    public string $requestNotice = '';

    #[Locked]
    public ?int $sectionsId = null;

    public function openSections(int $id): void
    {
        $this->access();
        WorkTimeSchema::requireReady();
        $entry = WorkTimeEntry::where('user_id', auth()->id())->findOrFail($id);
        abort_unless(in_array($entry->status, ['completed', 'returned'], true), 403);
        $this->sectionsId = $id;
        $this->sectionsOpen = true;
    }

    public function closeSections(): void
    {
        $this->access();
        $this->reset(['sectionsId', 'sectionsOpen']);
    }

    public array $generalManual = ['work_context' => 'internal', 'title' => '', 'timezone' => 'Europe/Berlin', 'training_session_id' => null, 'starts_at' => '', 'ends_at' => '', 'pause_minutes' => 0, 'note' => ''];

    public function openGeneralManual(): void
    {
        $this->access();
        WorkTimeSchema::requireReady();
        $this->generalManualOpen = true;
        $this->resetValidation();
    }

    public function saveGeneralManual(WorkTimeService $service): void
    {
        $this->access();
        $data = $this->generalManual;
        if (empty($data['training_session_id'])) {
            unset($data['training_session_id']);
        }
        $service->manualContext($data, $this->eventKey, auth()->user());
        $this->eventKey = (string) Str::uuid();
        $this->reset(['generalManual', 'generalManualOpen']);
        session()->flash('operations.saved', 'Arbeitszeit gespeichert.');
    }

    /**
     * Abwesenheiten und Qualifikationen haben eigene Menüpunkte und laufen
     * als fokussierte Einzelansicht; der Arbeitstag selbst behält nur den Tag.
     */
    #[Locked]
    public string $focus = '';

    private function tabs(): array
    {
        return match ($this->focus) {
            'absences' => ['absences' => 'Urlaub & Abwesenheit'],
            'records' => ['records' => 'Qualifikationen'],
            default => ['today' => 'Heute', 'requests' => 'Anfragen', 'schedule' => 'Kalender', 'time' => 'Zeiten'],
        };
    }

    public string $timeFrom = '';

    public string $timeUntil = '';

    public function updatedTimeFrom(): void
    {
        $this->resetPage('timesPage');
    }

    public function updatedTimeUntil(): void
    {
        $this->resetPage('timesPage');
    }

    public function exportOwnTimes(OperationsReportService $service)
    {
        $this->access();
        $csv = $service->ownTimes(auth()->user(), $this->timeFrom, $this->timeUntil);

        return response()->streamDownload(fn () => print ($csv), 'RailTime-Meine-Zeiten-'.$this->timeFrom.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportOwnWorkTimes(OperationsReportService $service)
    {
        $this->access();
        WorkTimeSchema::requireReady();
        $csv = $service->ownTimesV2(auth()->user(), $this->timeFrom, $this->timeUntil);

        return response()->streamDownload(fn () => print ($csv), 'RailTime-Meine-Arbeitszeiten-v2-'.$this->timeFrom.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function openForm(string $form): void
    {
        $this->access();
        abort_unless(in_array($form, ['manual', 'qualification', 'absence'], true), 404);
        $this->reset(['manualOpen', 'correctionOpen', 'qualificationOpen', 'absenceOpen', 'generalManualOpen', 'sectionsOpen', 'sectionsId']);
        $this->resetValidation();
        $this->{$form.'Open'} = true;
    }

    #[Locked]
    public string $eventKey;

    #[Locked]
    public ?int $correctingId = null;

    #[Locked]
    public ?int $correctingRevision = null;

    public array $correction = ['starts_at' => '', 'ends_at' => '', 'pause_minutes' => 0, 'note' => ''];

    public array $absence = ['kind' => 'vacation', 'starts_at' => '', 'ends_at' => '', 'timezone' => 'Europe/Berlin', 'note' => ''];

    public array $qualification = ['qualification_type_id' => '', 'valid_from' => '', 'valid_until' => ''];

    public $evidence;

    public ?int $manualAssignmentId = null;

    public array $manualTime = ['starts_at' => '', 'ends_at' => '', 'pause_minutes' => 0, 'note' => ''];

    public function saveManual(WorkTimeService $service): void
    {
        $this->access();
        $this->validate(['manualAssignmentId' => 'required|integer']);
        $assignment = ShiftAssignment::where('user_id', auth()->id())->findOrFail($this->manualAssignmentId);
        $service->manual($assignment->id, $assignment->plan_revision, $this->manualTime, $this->eventKey, auth()->user());
        $this->eventKey = (string) Str::uuid();
        $this->reset(['manualTime', 'manualAssignmentId']);
        $this->manualOpen = false;
        session()->flash('operations.saved', 'Zeit nachgetragen.');
    }

    public function mount(string $focus = '', string $initialTab = '', array $initialRequest = []): void
    {
        $this->access();
        $this->eventKey = (string) Str::uuid();
        $this->today();
        $this->focus = in_array($focus, ['absences', 'records'], true) ? $focus : '';
        $queryTab = request()->query('tab', '');
        $initialTab = $initialTab ?: (is_string($queryTab) ? $queryTab : '');
        $this->tab = $this->focus ?: (array_key_exists($initialTab, $this->tabs()) ? $initialTab : 'today');
        // "Urlaub planen" auf dem Arbeitstag öffnet den Antrag direkt.
        if ($this->focus === 'absences' && request()->boolean('new')) {
            $this->absenceOpen = true;
        }
        if ($this->focus === 'records' && request()->boolean('new')) {
            $this->qualificationOpen = true;
        }
        if ($this->focus === '') {
            $this->initializeRequest($initialRequest ?: request()->only(['request_type', 'request_id', 'request_revision']));
        }
    }

    private function access(): void
    {
        OperationsAccess::requireReady();
        OperationsAccess::own(auth()->user(), auth()->id());
    }

    public function showTab(string $tab): void
    {
        $this->access();
        abort_unless(array_key_exists($tab, $this->tabs()), 404);
        $this->tab = $tab;
        $this->closeCalendarEvent();
        $this->closeConsentRequest();
        $this->reset(['manualOpen', 'correctionOpen', 'qualificationOpen', 'absenceOpen']);
        $this->resetValidation();
    }

    public function respond(int $id, int $revision, bool $accept, PlanPublicationService $service): void
    {
        $this->access();
        $service->respond($id, $revision, $accept, auth()->user());
    }

    /** A notification GET selects a request; only respondToRequest can submit an answer. */
    private function initializeRequest(array $input): void
    {
        if ($input === []) {
            return;
        }
        $this->tab = 'today';
        $type = $input['request_type'] ?? null;
        $id = $this->requestInteger($input['request_id'] ?? null);
        $revision = $this->requestInteger($input['request_revision'] ?? null);
        if (! is_string($type) || ! in_array($type, ['shift', 'capacity'], true) || $id === null || $revision === null) {
            $this->requestOpen = true;
            $this->requestNotice = 'Diese Anfrage ist nicht verfügbar. Deine offenen Anfragen findest du im Arbeitstag.';

            return;
        }
        try {
            $this->openConsentRequest($type, $id, $revision);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw $exception;
            }
            $this->requestOpen = true;
            $this->requestNotice = 'Diese Anfrage ist nicht verfügbar. Deine offenen Anfragen findest du im Arbeitstag.';
            $this->requestType = '';
            $this->requestId = $this->requestRevision = null;
        }
    }

    private function requestInteger(mixed $value): ?int
    {
        return (is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]{0,17}\z/', (string) $value) ? (int) $value : null;
    }

    private function requestActor(): User
    {
        $this->access();
        $actor = User::findOrFail(auth()->id());
        OperationsAccess::own($actor, $actor->id);

        return $actor;
    }

    public function openConsentRequest(string $type, int $id, int $revision): void
    {
        $actor = $this->requestActor();
        abort_unless(in_array($type, ['shift', 'capacity'], true) && $id > 0 && $revision > 0 && $this->focus === '', 404);
        $this->consentRequest($actor, $type, $id, $revision);
        $this->requestType = $type;
        $this->requestId = $id;
        $this->requestRevision = $revision;
        $this->requestConfirmed = false;
        $this->requestNotice = '';
        $this->requestOpen = true;
        $this->resetValidation();
    }

    public function closeConsentRequest(): void
    {
        $this->access();
        $this->reset(['requestOpen', 'requestConfirmed', 'requestType', 'requestId', 'requestRevision', 'requestNotice']);
        $this->resetErrorBag(['request', 'requestConfirmed']);
    }

    public function updatedRequestOpen(bool $open): void
    {
        if (! $open) {
            $this->closeConsentRequest();
        }
    }

    public function respondToRequest(bool $accept): void
    {
        $actor = $this->requestActor();
        abort_unless($this->requestOpen && $this->requestId && $this->requestRevision && in_array($this->requestType, ['shift', 'capacity'], true), 409);
        $request = $this->consentRequest($actor, $this->requestType, $this->requestId, $this->requestRevision);
        if (! $request['can_respond']) {
            $this->requestConfirmed = false;
            $this->addError('request', $request['notice']);

            return;
        }
        try {
            if ($this->requestType === 'capacity') {
                $this->validate(['requestConfirmed' => 'accepted'], ['requestConfirmed.accepted' => 'Bitte prüfe und bestätige den angefragten Zeitraum.']);
                app(CustomerCapacityService::class)->consent($actor, $this->requestId, $this->requestRevision, $accept);
                $message = $accept ? 'Verfügbarkeit bestätigt.' : 'Kapazitätsanfrage abgelehnt.';
            } else {
                app(PlanPublicationService::class)->respond($this->requestId, $this->requestRevision, $accept, $actor);
                $message = $accept ? 'Dienst bestätigt.' : 'Dienst abgelehnt.';
            }
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $this->requestConfirmed = false;
            $this->addError('request', 'Die Anfrage wurde zwischenzeitlich geändert oder beantwortet. Prüfe den aktuellen Stand erneut.');

            return;
        }
        $this->closeConsentRequest();
        $this->resetPage('requestsPage');
        $this->dispatch('personal-request-answered')->to(AttentionCenter::class);
        session()->flash('operations.saved', $message);
    }

    /** Every read repeats the own-user and published-revision checks. */
    private function consentRequest(User $actor, string $type, int $id, int $revision): array
    {
        $unavailable = ['can_respond' => false, 'notice' => 'Diese Anfrage ist nicht mehr verfügbar.', 'row' => null, 'changes' => []];
        if ($type === 'shift') {
            $owned = ShiftAssignment::where('user_id', $actor->id)->find($id);
            abort_unless($owned, 404);
            if ($owned->status->value !== 'requested') {
                return array_replace($unavailable, ['notice' => 'Diese Anfrage wurde bereits beantwortet oder zurückgenommen.']);
            }
            try {
                $assignment = app(PersonalSchedule::class)->assignment($actor, $id);
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 404) {
                    throw $exception;
                }

                return $unavailable;
            }
            $row = $this->shiftRequestRow($assignment, $this->firstPublishedRevisions(collect([$assignment])));
            $snapshot = array_key_exists('location_snapshot', $assignment->shift->getAttributes()) ? $assignment->shift->getAttribute('location_snapshot') : null;
            $row['location_preview'] = $this->requestLocationPreview($row, $snapshot);
            $changes = app(PlanChangeService::class)->publishedChanges($assignment->shift_id, $assignment->plan_revision);
            $notice = match (true) {
                $assignment->plan_is_stale => 'Der Dienst wird gerade überarbeitet. Eine Antwort ist nach der Veröffentlichung wieder möglich.',
                $assignment->plan_revision !== $revision => 'Diese Anfrage hat sich geändert. Prüfe die aktuelle Version, bevor du antwortest.',
                ! $assignment->shift->ends_at->isFuture() => 'Dieser Dienst ist bereits beendet. Eine Antwort ist nicht mehr möglich.',
                default => '',
            };

            return ['can_respond' => $notice === '', 'notice' => $notice, 'row' => $row, 'changes' => $row['changed_plan'] ? $changes : []];
        }
        if (! CustomerPortalIntakeSchema::ready()) {
            return $unavailable;
        }
        $record = CustomerCapacityCommitment::where('user_id', $actor->id)->find($id);
        abort_unless($record, 404);
        $notice = match (true) {
            $record->status !== 'requested' => 'Diese Kapazitätsanfrage wurde bereits beantwortet oder zurückgenommen.',
            ! $record->starts_at->isFuture() => 'Der angefragte Zeitraum hat bereits begonnen. Eine Antwort ist nicht mehr möglich.',
            $record->revision !== $revision => 'Diese Anfrage hat sich geändert. Prüfe die aktuelle Version, bevor du antwortest.',
            default => '',
        };

        $row = $record->status === 'requested' ? $this->capacityRequestRows(collect([$record]))->first() : null;
        if ($row) {
            $row['location_preview'] = $this->requestLocationPreview($row);
        }

        return ['can_respond' => $notice === '', 'notice' => $notice, 'row' => $row, 'changes' => []];
    }

    /** Local map facts for the selected own publication; never borrow a live order or directory address. */
    private function requestLocationPreview(array $row, ?array $snapshot = null): array
    {
        $shift = new Shift;
        $shift->forceFill(['location_name' => $row['location'], 'title' => $row['title'], 'role_name' => $row['role'],
            'location_snapshot' => $snapshot ? array_intersect_key($snapshot, array_flip(['name', 'latitude', 'longitude', 'confirmed'])) : null]);
        $shift->setRelation('order', null);
        $preview = TimelineLocationPreview::fromShift($shift);
        $safe = function (array $location) use (&$safe): array {
            $facts = array_intersect_key($location, array_flip(['state', 'label', 'place', 'confirmed', 'estimated', 'latitude', 'longitude']));
            if (isset($location['points'])) {
                $facts['points'] = array_map($safe, $location['points']);
            }

            return $facts;
        };

        return $safe($preview);
    }

    private function firstPublishedRevisions(Collection $assignments): Collection
    {
        return $assignments->isEmpty() ? collect() : OperationAudit::where('subject_type', 'Shift')->where('action', 'shift.published')
            ->whereIn('subject_id', $assignments->pluck('shift_id'))->selectRaw('subject_id, MIN(revision) AS first_revision')->groupBy('subject_id')->pluck('first_revision', 'subject_id');
    }

    private function shiftRequestRow(ShiftAssignment $assignment, Collection $firstRevisions): array
    {
        $shift = $assignment->shift;
        $changed = isset($firstRevisions[$shift->id]) && (int) $firstRevisions[$shift->id] < $assignment->plan_revision;

        // Internal notes are not part of the employee publication contract.
        return ['type' => 'shift', 'id' => $assignment->id, 'revision' => $assignment->plan_revision, 'changed_plan' => $changed,
            'label' => $changed ? 'Planänderung' : 'Dienstanfrage', 'title' => $shift->title, 'role' => $shift->role_name,
            'customer' => $shift->order?->customer?->company_name, 'location' => $shift->location_name,
            'order_reference' => $shift->order?->order_number, 'notes' => null, 'status' => $assignment->status->value,
            'duration_minutes' => intdiv($shift->ends_at->getTimestamp() - $shift->starts_at->getTimestamp(), 60),
            'starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at, 'timezone' => $shift->timezone, 'break_minutes' => $shift->planned_break_minutes];
    }

    private function capacityRequestRows(Collection $records): Collection
    {
        if ($records->isEmpty()) {
            return collect();
        }
        $customers = Customer::whereIn('id', $records->pluck('customer_id'))->pluck('company_name', 'id');
        $locations = CustomerLocation::whereIn('id', $records->pluck('location_id'))->whereIn('customer_id', $records->pluck('customer_id'))->get(['id', 'customer_id', 'name'])->keyBy('id');

        return $records->map(function (CustomerCapacityCommitment $record) use ($customers, $locations): array {
            $location = $locations->get($record->location_id);

            return ['type' => 'capacity', 'id' => $record->id, 'revision' => $record->revision, 'changed_plan' => false,
                'label' => 'Kapazitätsanfrage', 'title' => $record->role_name, 'role' => $record->role_name,
                'customer' => $customers->get($record->customer_id), 'location' => $location && (int) $location->customer_id === (int) $record->customer_id ? $location->name : 'Einsatzort nicht verfügbar',
                'order_reference' => null, 'notes' => null, 'status' => $record->status,
                'duration_minutes' => intdiv($record->ends_at->getTimestamp() - $record->starts_at->getTimestamp(), 60),
                'starts_at' => $record->starts_at, 'ends_at' => $record->ends_at, 'timezone' => $record->timezone, 'break_minutes' => $record->planned_break_minutes];
        });
    }

    private function pendingRequests(Collection $assignments): LengthAwarePaginator
    {
        $actor = $this->requestActor();
        $pending = $assignments->filter(fn ($assignment) => $assignment->status->value === 'requested' && ! $assignment->plan_is_stale && $assignment->shift->ends_at->isFuture());
        $firstRevisions = $this->firstPublishedRevisions($pending);
        $requests = $pending->map(fn ($assignment) => $this->shiftRequestRow($assignment, $firstRevisions));
        if (CustomerPortalIntakeSchema::ready()) {
            $capacity = CustomerCapacityCommitment::where('user_id', $actor->id)->where('status', 'requested')->where('starts_at', '>', now()->utc())->get();
            $requests = $requests->concat($this->capacityRequestRows($capacity));
        }
        $requests = $requests->sortBy(fn ($row) => sprintf('%020d-%s-%020d', $row['starts_at']->getTimestamp(), $row['type'], $row['id']))->values();
        $page = min(max(1, $this->getPage('requestsPage')), max(1, (int) ceil($requests->count() / 6)));

        return new LengthAwarePaginator($requests->forPage($page, 6)->values(), $requests->count(), 6, $page, ['pageName' => 'requestsPage']);
    }

    public function start(int $id, int $revision, WorkTimeService $service): void
    {
        $this->access();
        $service->start($id, $revision, $this->eventKey, auth()->user());
        $this->eventKey = (string) Str::uuid();
    }

    public function clock(int $id, int $revision, string $action, WorkTimeService $service): void
    {
        $this->access();
        $service->clock($id, $revision, $action, $this->eventKey, auth()->user());
        $this->eventKey = (string) Str::uuid();
    }

    public function editTime(int $id): void
    {
        $this->access();
        $entry = WorkTimeEntry::where('user_id', auth()->id())->findOrFail($id);
        abort_unless(in_array($entry->status, ['completed', 'returned'], true), 403);
        $this->correctingId = $entry->id;
        $this->resetValidation();
        $this->correctionOpen = true;
        $this->correctingRevision = $entry->revision;
        $this->correction = ['starts_at' => $entry->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $entry->ends_at->format('Y-m-d\TH:i'), 'pause_minutes' => intdiv($entry->pause_seconds, 60), 'note' => ''];
    }

    public function correct(WorkTimeService $service): void
    {
        $this->access();
        $service->correct($this->correctingId, $this->correctingRevision, $this->correction, auth()->user());
        $this->correctingId = null;
        $this->correctionOpen = false;
    }

    public function cancelCorrection(): void
    {
        $this->access();
        $this->correctingId = null;
        $this->correctionOpen = false;
    }

    public function requestAbsence(PersonnelWorkflowService $service): void
    {
        $this->access();
        $service->requestAbsence(auth()->user(), $this->absence);
        $this->reset('absence');
        $this->absenceOpen = false;
        session()->flash('operations.saved', 'Antrag eingereicht.');
    }

    public function withdraw(int $id, int $revision, PersonnelWorkflowService $service): void
    {
        $this->access();
        $service->absence(AbsenceRequest::where('user_id', auth()->id())->findOrFail($id), $revision, 'withdraw', '', auth()->user());
    }

    public function upload(PersonnelWorkflowService $service): void
    {
        $this->access();
        $this->validate(['evidence' => 'required|file|mimes:pdf,jpg,jpeg,png|max:'.config('operations.evidence_max_kilobytes')]);
        $service->submitQualification(auth()->user(), $this->qualification, $this->evidence);
        $this->reset(['evidence', 'qualification']);
        $this->qualificationOpen = false;
        session()->flash('operations.saved', 'Nachweis eingereicht.');
    }

    private function displayTimezone(): string
    {
        return (string) config('operations.display_timezone', 'Europe/Berlin');
    }

    private function anchor(): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->anchorDate, $this->displayTimezone());
            if ($date && $date->toDateString() === $this->anchorDate) {
                return $date;
            }
        } catch (\Throwable) {
        }

        return CarbonImmutable::now($this->displayTimezone())->startOfDay();
    }

    public function updatedAnchorDate(): void
    {
        $this->access();
        $this->closeCalendarEvent();
        $this->validate(['anchorDate' => 'required|date_format:Y-m-d']);
    }

    public function updatedWeek(): void
    {
        $this->access();
        $this->anchorDate = CarbonImmutable::now($this->displayTimezone())->startOfWeek()->addWeeks(max(-12, min(52, $this->week)))->toDateString();
        $this->closeCalendarEvent();
    }

    public function today(): void
    {
        $this->access();
        $this->anchorDate = CarbonImmutable::now($this->displayTimezone())->toDateString();
        $this->week = 0;
        $this->closeCalendarEvent();
        $this->resetValidation('anchorDate');
    }

    public function switchView(string $view): void
    {
        $this->access();
        abort_unless(in_array($view, ['day', 'week', 'month', 'list'], true), 404);
        $this->viewMode = $view;
        $this->closeCalendarEvent();
    }

    public function showDay(string $date): void
    {
        $this->access();
        $this->anchorDate = $date;
        $this->updatedAnchorDate();
        $this->viewMode = 'day';
    }

    public function shiftMiniMonth(int $direction): void
    {
        $this->access();
        // 0 springt zurück in den aktuellen Monat, ±1 blättert.
        $this->miniMonth = $direction === 0 ? 0 : max(-12, min(12, $this->miniMonth + ($direction <=> 0)));
    }

    /** Sprung aus dem Mini-Kalender in die Tagesansicht des großen Kalenders. */
    public function openDay(string $date): void
    {
        $this->access();
        abort_unless(preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1, 404);
        $this->showTab('schedule');
        $this->showDay($date);
    }

    public function previousPeriod(): void
    {
        $this->movePeriod(-1);
    }

    public function nextPeriod(): void
    {
        $this->movePeriod(1);
    }

    private function movePeriod(int $direction): void
    {
        $this->access();
        $date = $this->anchor();
        $this->anchorDate = match ($this->viewMode) {
            'day' => $date->addDays($direction)->toDateString(),
            'month' => $date->addMonthsNoOverflow($direction)->toDateString(),
            default => $date->addWeeks($direction)->toDateString(),
        };
        $this->closeCalendarEvent();
        $this->resetValidation('anchorDate');
    }

    public function openCalendarEvent(string $id): void
    {
        $this->access();
        $event = $this->calendarEvent($id);
        if ($event->kind === 'shift') {
            app(PlanChangeService::class)->opened($event->record->id, $event->record->plan_revision, auth()->user());
        }
        $this->selectedCalendarEventId = $id;
        $this->calendarEventOpen = true;
        $this->resetValidation();
    }

    public function closeCalendarEvent(): void
    {
        $this->access();
        $this->calendarEventOpen = false;
        $this->selectedCalendarEventId = null;
    }

    private function event(object $record, string $kind): object
    {
        $scheduled = $kind === 'shift' ? $record->shift : $record;

        return (object) [
            'id' => $kind.'-'.$record->id,
            'kind' => $kind,
            'record' => $record,
            'starts' => $scheduled->starts_at->setTimezone($this->displayTimezone()),
            'ends' => $scheduled->ends_at->setTimezone($this->displayTimezone()),
            'title' => in_array($kind, ['shift', 'training'], true) ? $scheduled->title : (['vacation' => 'Urlaub', 'unavailable' => 'Nicht verfügbar', 'other' => 'Abwesenheit', 'sick' => 'Krankmeldung'][$record->kind] ?? 'Abwesenheit'),
            'status' => $kind === 'shift' ? $record->status->value : $record->status,
        ];
    }

    private function calendarEvent(string $id): object
    {
        abort_unless(preg_match('/\A(shift|absence|training)-([1-9][0-9]{0,17})\z/', $id, $match), 404);
        if ($match[1] === 'shift') {
            $record = app(PersonalSchedule::class)->assignment(auth()->user(), (int) $match[2]);
            $record->setAttribute('has_active_time', WorkTimeEntry::where('user_id', auth()->id())->whereIn('status', ['running', 'paused'])->exists());
        } elseif ($match[1] === 'training') {
            abort_unless(Schema::hasTable('personnel_trainings'), 404);
            $record = PersonnelTraining::where('status', 'scheduled')->whereHas('participants', fn ($q) => $q->where('user_id', auth()->id())->whereIn('status', ['confirmed', 'attended']))->find((int) $match[2]);
            abort_unless($record, 404);
        } else {
            $record = AbsenceRequest::where('user_id', auth()->id())->whereIn('status', ['pending', 'approved', 'reported'])->find((int) $match[2]);
            abort_unless($record, 404);
        }

        return $this->event($record, $match[1]);
    }

    /**
     * Kurzstatus der ausgelagerten Bereiche, damit auf dem Arbeitstag sichtbar
     * bleibt, was ansteht – ohne deren Listen hier erneut zu führen.
     */
    private function glance(): array
    {
        $userId = auth()->id();
        $now = now();
        $nextAbsence = AbsenceRequest::where('user_id', $userId)->where('status', 'approved')->where('ends_at', '>', $now->utc())->orderBy('starts_at')->first(['id', 'kind', 'starts_at', 'ends_at']);

        return [
            'open_times' => WorkTimeSchema::ready() ? WorkTimeEntry::where('user_id', $userId)->whereIn('status', ['completed', 'returned'])->count() : 0,
            'pending_absences' => AbsenceRequest::where('user_id', $userId)->where('status', 'pending')->count(),
            'next_absence' => $nextAbsence?->starts_at->setTimezone($this->displayTimezone()),
            'expiring_qualifications' => EmployeeQualification::where('user_id', $userId)->where('status', 'approved')
                ->whereDate('valid_until', '>=', $now->toDateString())->whereDate('valid_until', '<=', $now->copy()->addDays(30)->toDateString())->count(),
        ];
    }

    /** Monatsraster mit Markierungen für Dienste und Abwesenheiten. */
    private function miniCalendar(array $month, $assignments): array
    {
        $absences = AbsenceRequest::where('user_id', auth()->id())->whereIn('status', ['pending', 'approved', 'reported'])
            ->where('starts_at', '<', $month['to']->utc())->where('ends_at', '>', $month['from']->utc())->get(['id', 'kind', 'status', 'starts_at', 'ends_at']);
        $days = [];
        for ($date = $month['from']; $date->lt($month['to']); $date = $date->addDay()) {
            $next = $date->addDay();
            $duties = $assignments->filter(fn ($assignment) => $assignment->shift->starts_at->lt($next) && $assignment->shift->ends_at->gt($date));
            $days[] = [
                'date' => $date,
                'in_month' => $date->month === $month['anchor']->month,
                'is_today' => $date->isSameDay(CarbonImmutable::now($this->displayTimezone())),
                'duties' => $duties->count(),
                'open' => $duties->contains(fn ($assignment) => $assignment->status->value === 'requested' && ! $assignment->plan_is_stale),
                'absence' => $absences->first(fn ($absence) => $absence->starts_at->lt($next) && $absence->ends_at->gt($date))?->kind,
            ];
        }

        return ['label' => $month['anchor']->locale('de')->isoFormat('MMMM YYYY'), 'days' => $days, 'offset' => $this->miniMonth];
    }

    /** Read-only calendar context for the owned request, using published shift projections. */
    private function requestCalendar(?array $request): ?array
    {
        $row = $request['row'] ?? null;
        if (! $row) {
            return null;
        }
        $actor = $this->requestActor();
        $zone = $row['timezone'];
        $start = CarbonImmutable::instance($row['starts_at'])->setTimezone($zone);
        $month = TimelineMiniCalendar::forIntervalSeed($start, CarbonImmutable::instance($row['ends_at'])->setTimezone($zone));
        $from = CarbonImmutable::parse($month['gridStart'], $zone);
        $to = $from->addDays($month['count']);
        $events = app(PersonalSchedule::class)->assignments($actor, $from, $to)->map(fn ($assignment) => [
            'id' => 'shift-'.$assignment->id, 'kind' => 'shift', 'title' => $assignment->shift->title,
            'starts_at' => CarbonImmutable::instance($assignment->shift->starts_at)->setTimezone($zone),
            'ends_at' => CarbonImmutable::instance($assignment->shift->ends_at)->setTimezone($zone),
            'location' => $assignment->shift->location_name, 'status' => $assignment->status->value,
            'is_request' => $row['type'] === 'shift' && (int) $row['id'] === (int) $assignment->id,
        ]);
        $absenceLabels = ['vacation' => 'Urlaub', 'unavailable' => 'Nicht verfügbar', 'other' => 'Abwesenheit', 'sick' => 'Krankmeldung'];
        $events = $events->concat(AbsenceRequest::where('user_id', $actor->id)->whereIn('status', ['pending', 'approved', 'reported'])
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get()
            ->map(fn ($absence) => [
                'id' => 'absence-'.$absence->id, 'kind' => 'absence', 'title' => $absenceLabels[$absence->kind] ?? 'Abwesenheit',
                'starts_at' => CarbonImmutable::instance($absence->starts_at)->setTimezone($zone),
                'ends_at' => CarbonImmutable::instance($absence->ends_at)->setTimezone($zone),
                'location' => null, 'status' => $absence->status, 'is_request' => false,
            ]));
        if (Schema::hasTable('personnel_trainings') && Schema::hasTable('personnel_training_participants')) {
            $events = $events->concat(PersonnelTraining::where('status', 'scheduled')
                ->whereHas('participants', fn ($query) => $query->where('user_id', $actor->id)->whereIn('status', ['confirmed', 'attended']))
                ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get()
                ->map(fn ($training) => [
                    'id' => 'training-'.$training->id, 'kind' => 'training', 'title' => $training->title,
                    'starts_at' => CarbonImmutable::instance($training->starts_at)->setTimezone($zone),
                    'ends_at' => CarbonImmutable::instance($training->ends_at)->setTimezone($zone),
                    'location' => null, 'status' => $training->status, 'is_request' => false,
                ]));
        }
        if ($row['type'] === 'capacity') {
            $events = $events->push([
                'id' => 'capacity-'.$row['id'], 'kind' => 'capacity', 'title' => $row['title'],
                'starts_at' => $start, 'ends_at' => CarbonImmutable::instance($row['ends_at'])->setTimezone($zone),
                'location' => $row['location'], 'status' => 'requested', 'is_request' => true,
            ]);
        }

        return ['timezone' => $zone, 'from' => $from, 'to' => $to, 'events' => $events->sortBy(fn ($event) => $event['starts_at']->getTimestamp())->values()->all()];
    }

    public function render()
    {
        $this->access();
        abort_unless(array_key_exists($this->tab, $this->tabs()), 404);
        $timeQuery = WorkTimeEntry::where('user_id', auth()->id());
        if ($this->tab === 'time' && $this->timeFrom && $this->timeUntil) {
            ReportingPeriod::apply($timeQuery, $this->timeFrom, $this->timeUntil);
        }
        abort_unless(in_array($this->viewMode, ['day', 'week', 'month', 'list'], true), 404);
        $anchor = $this->anchor();
        $from = match ($this->viewMode) {
            'day' => $anchor,
            'month' => $anchor->startOfMonth()->startOfWeek(),
            default => $anchor->startOfWeek(),
        };
        $to = match ($this->viewMode) {
            'day' => $from->addDay(),
            'month' => $anchor->endOfMonth()->endOfWeek()->addDay()->startOfDay(),
            default => $from->addWeek(),
        };
        $rangeStart = $this->tab === 'schedule' ? $from : CarbonImmutable::now($this->displayTimezone())->startOfDay();
        $rangeEnd = $this->tab === 'schedule' ? $to : $rangeStart->addDays(14);
        $miniMonth = null;
        if ($this->tab === 'today') {
            // Ein Abruf für Dienstliste (14 Tage) und Mini-Kalender (Monatsraster).
            $monthAnchor = CarbonImmutable::now($this->displayTimezone())->startOfMonth()->addMonthsNoOverflow($this->miniMonth);
            $miniMonth = ['anchor' => $monthAnchor, 'from' => $monthAnchor->startOfWeek(), 'to' => $monthAnchor->endOfMonth()->endOfWeek()->addDay()->startOfDay()];
            // Keep the 14-day duty view and month intact while the request inbox
            // covers every future published request, including later months.
            $loaded = app(PersonalSchedule::class)->assignments(auth()->user(), $rangeStart->min($miniMonth['from']));
            $visible = $loaded->filter(fn ($assignment) => $assignment->shift->ends_at->gt($rangeStart) && $assignment->shift->starts_at->lt($rangeEnd))->values();
        } elseif ($this->tab === 'requests') {
            $loaded = app(PersonalSchedule::class)->assignments(auth()->user(), $rangeStart);
            $visible = $loaded->filter(fn ($assignment) => $assignment->shift->ends_at->gt($rangeStart) && $assignment->shift->starts_at->lt($rangeEnd))->values();
        } else {
            $visible = app(PersonalSchedule::class)->assignments(auth()->user(), $rangeStart, $rangeEnd);
        }

        $requestInbox = null;
        if ($this->focus === '') {
            $requestAssignments = in_array($this->tab, ['today', 'requests'], true) ? $loaded
                : app(PersonalSchedule::class)->assignments(auth()->user(), CarbonImmutable::now($this->displayTimezone())->startOfDay());
            $requestInbox = $this->pendingRequests($requestAssignments);
        }

        $activeTime = WorkTimeEntry::where('user_id', auth()->id())->whereIn('status', ['running', 'paused'])->first();
        $visible->each(fn ($assignment) => $assignment->setAttribute('has_active_time', $activeTime !== null));

        $events = $this->tab === 'schedule' ? $visible->map(fn ($assignment) => $this->event($assignment, 'shift'))
            ->concat(AbsenceRequest::where('user_id', auth()->id())->whereIn('status', ['pending', 'approved', 'reported'])
                ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get()
                ->map(fn ($absence) => $this->event($absence, 'absence')))
            ->sortBy(fn ($event) => $event->starts->getTimestamp())->values() : collect();
        if ($this->tab === 'schedule' && Schema::hasTable('personnel_trainings')) {
            $events = $events->concat(PersonnelTraining::where('status', 'scheduled')->whereHas('participants', fn ($q) => $q->where('user_id', auth()->id())->whereIn('status', ['confirmed', 'attended']))->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get()->map(fn ($training) => $this->event($training, 'training')))->sortBy(fn ($event) => $event->starts->getTimestamp())->values();
        }
        $days = collect(range(0, (int) $from->diffInDays($to) - 1))->map(function ($offset) use ($from, $anchor, $events) {
            $date = $from->addDays($offset);

            return ['date' => $date, 'is_today' => $date->isToday(), 'in_month' => $date->month === $anchor->month,
                'events' => $events->filter(fn ($event) => $event->starts->lt($date->addDay()) && $event->ends->gt($date))->values()];
        });
        $selected = null;
        if ($this->calendarEventOpen && $this->selectedCalendarEventId) {
            try {
                $selected = $this->calendarEvent($this->selectedCalendarEventId);
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 404) {
                    throw $exception;
                }
                $this->closeCalendarEvent();
            }
        }
        $selectedPlanChanges = $selected?->kind === 'shift' ? app(PlanChangeService::class)->publishedChanges($selected->record->shift_id, $selected->record->plan_revision) : [];
        $selectedCalendarRequest = null;
        if ($selected?->kind === 'shift') {
            $assignment = $selected->record;
            $row = $this->shiftRequestRow($assignment, $this->firstPublishedRevisions(collect([$assignment])));
            $row['label'] = $assignment->status->value === 'requested' ? $row['label'] : 'Dienst';
            $snapshot = array_key_exists('location_snapshot', $assignment->shift->getAttributes()) ? $assignment->shift->getAttribute('location_snapshot') : null;
            $row['location_preview'] = $this->requestLocationPreview($row, $snapshot);
            $selectedCalendarRequest = ['can_respond' => false, 'notice' => $assignment->plan_is_stale ? 'Der Dienst wird gerade überarbeitet. Angezeigt wird der veröffentlichte Stand.' : '',
                'row' => $row, 'changes' => $row['changed_plan'] ? $selectedPlanChanges : []];
        }
        $selectedRequest = null;
        if ($this->requestOpen && $this->requestId && $this->requestRevision && in_array($this->requestType, ['shift', 'capacity'], true)) {
            try {
                $selectedRequest = $this->consentRequest($this->requestActor(), $this->requestType, $this->requestId, $this->requestRevision);
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 404) {
                    throw $exception;
                }
                $selectedRequest = ['can_respond' => false, 'notice' => 'Diese Anfrage ist nicht mehr verfügbar.', 'row' => null, 'changes' => []];
            }
        }

        return view('livewire.operations.my-work', [
            'timeCompleteness' => $this->tab === 'time' && WorkTimeSchema::ready() ? app(WorkTimeExtensionService::class)->completeness(auth()->user(), $this->timeFrom ?: now($this->displayTimezone())->startOfMonth()->toDateString(), $this->timeUntil ?: now($this->displayTimezone())->toDateString(), auth()->user()) : null,
            'personalTabs' => $this->tabs(),
            'workTimeReady' => WorkTimeSchema::ready(),
            'clockTrainings' => Schema::hasTable('personnel_trainings') && Schema::hasTable('personnel_training_participants') ? PersonnelTraining::where('status', 'scheduled')->whereHas('participants', fn ($q) => $q->where('user_id', auth()->id())->whereIn('status', ['confirmed', 'attended']))->orderBy('starts_at')->limit(100)->get() : collect(),
            'from' => $from, 'to' => $to,
            'calendarDays' => $days, 'calendarEvents' => $events, 'selectedCalendarEvent' => $selected,
            'selectedPlanChanges' => $selectedPlanChanges,
            'selectedCalendarRequest' => $selectedCalendarRequest,
            'selectedCalendarContext' => $this->requestCalendar($selectedCalendarRequest),
            'displayTimezone' => $this->displayTimezone(),
            'periodLabel' => match ($this->viewMode) {
                'day' => $anchor->locale('de')->isoFormat('dddd, D. MMMM YYYY'),
                'month' => $anchor->locale('de')->isoFormat('MMMM YYYY'),
                default => $from->format('d.m.').' – '.$to->subDay()->format('d.m.Y'),
            },
            'assignments' => $visible,
            'pendingRequests' => in_array($this->tab, ['today', 'requests'], true) ? $requestInbox : null,
            'requestCount' => $requestInbox?->total() ?? 0,
            'selectedRequest' => $selectedRequest,
            'requestCalendar' => $this->requestCalendar($selectedRequest),
            'activeTime' => $activeTime,
            'glance' => $this->tab === 'today' ? $this->glance() : [],
            'miniCalendar' => $miniMonth ? $this->miniCalendar($miniMonth, $loaded) : null,
            'dutyMap' => $this->tab === 'today' ? GermanyMap::personal($visible, $this->displayTimezone()) : null,
            'times' => $this->tab === 'time' ? $timeQuery->latest('starts_at')->paginate(20, ['*'], 'timesPage') : collect(),
            'qualifications' => $this->tab === 'records' ? EmployeeQualification::where('user_id', auth()->id())->with('type')->latest()->paginate(20, ['*'], 'qualificationsPage') : collect(),
            'absences' => $this->tab === 'absences' ? AbsenceRequest::where('user_id', auth()->id())->latest()->paginate(20, ['*'], 'absencesPage') : collect(),
            'types' => $this->tab === 'records' ? QualificationType::where('is_active', true)->orderBy('name')->get() : collect(),
            'manualAssignments' => $this->tab === 'time' ? ShiftAssignment::where('user_id', auth()->id())->where('status', 'confirmed')->whereDoesntHave('timeEntry')->whereHas('shift', fn ($q) => $q->where('published_revision', '>', 0)->whereColumn('published_revision', 'revision')->where('starts_at', '<=', now()->utc())->where('ends_at', '>=', now()->subDays(90)->utc())->where('status', '!=', 'cancelled'))->with('shift')->limit(50)->get() : collect(),
        ]);
    }
}
