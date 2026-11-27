<?php

namespace App\Livewire\Concerns\MyWork;

use App\Livewire\Operations\AttentionCenter;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\CustomerCapacityCommitment;
use App\Models\CustomerLocation;
use App\Models\OperationAudit;
use App\Models\PersonnelTraining;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\CustomerPortal\CustomerCapacityService;
use App\Services\Operations\PlanChangeService;
use App\Services\Operations\PlanPublicationService;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\PersonalSchedule;
use App\Support\Operations\TimelineLocationPreview;
use App\Support\Operations\TimelineMiniCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reiter „Anfragen" und der Anfrage-Dialog: Dienst- und Kapazitätsanfragen sichten,
 * beantworten und im Verlauf zeigen (Paginatoren requestsPage, requestHistoryPage).
 *
 * Erwartet in der Komponente: access(), displayTimezone(), $tab und $focus.
 */
trait AnswersWorkRequests
{
    abstract private function access(): void;
    abstract private function displayTimezone(): string;

    public string $requestFilter = 'all';

    private ?Collection $capacityInboxRecords = null;

    private ?Collection $firstRequestRevisions = null;

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

    public function showRequestFilter(string $filter): void
    {
        $this->access();
        abort_unless(in_array($filter, ['all', 'open', 'answered'], true), 404);
        $this->requestFilter = $filter;
        $this->resetPage('requestHistoryPage');
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
                $answered = app(PersonalSchedule::class)->answeredRequests($actor, CarbonImmutable::now($this->displayTimezone())->subDays(90))->firstWhere('id', $id);
                if (! $answered) {
                    return array_replace($unavailable, ['notice' => 'Diese Anfrage wurde bereits beantwortet oder zurückgenommen.']);
                }
                $row = $this->shiftRequestRow($answered, $this->firstPublishedRevisions(collect([$answered])));
                $snapshot = $answered->shift->getAttribute('location_snapshot');
                $row['location_preview'] = $this->requestLocationPreview($row, $snapshot);

                return ['can_respond' => false, 'notice' => 'Deine Rückmeldung: '.($owned->status->value === 'confirmed' ? 'Dienst bestätigt.' : 'Dienst abgelehnt.'), 'row' => $row, 'changes' => []];
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

        $row = in_array($record->status, ['requested', 'consented', 'declined', 'approved'], true)
            ? $this->capacityRequestRows(collect([$record]))->first() : null;
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
        $preview = TimelineLocationPreview::fromShift($shift, true);
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
        if ($assignments->isEmpty()) {
            return collect();
        }

        // Open requests and answered history share one owner-scoped revision read.
        return $this->firstRequestRevisions ??= OperationAudit::where('subject_type', 'Shift')->where('action', 'shift.published')
            ->whereIn('subject_id', ShiftAssignment::where('user_id', auth()->id())->select('shift_id'))
            ->selectRaw('subject_id, MIN(revision) AS first_revision')->groupBy('subject_id')->pluck('first_revision', 'subject_id');
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
        $requests = $this->pendingRequestItems($assignments);
        $page = min(max(1, $this->getPage('requestsPage')), max(1, (int) ceil($requests->count() / 6)));

        return new LengthAwarePaginator($requests->forPage($page, 6)->values(), $requests->count(), 6, $page, ['pageName' => 'requestsPage']);
    }

    private function pendingRequestItems(Collection $assignments): Collection
    {
        $actor = $this->requestActor();
        $pending = $assignments->filter(fn ($assignment) => $assignment->status->value === 'requested' && ! $assignment->plan_is_stale && $assignment->shift->ends_at->isFuture());
        $firstRevisions = $this->firstPublishedRevisions($pending);
        $requests = $pending->map(fn ($assignment) => $this->shiftRequestRow($assignment, $firstRevisions));
        if (CustomerPortalIntakeSchema::ready()) {
            $capacity = $this->capacityInboxRecords()->filter(fn ($record) => $record->status === 'requested' && $record->starts_at->isFuture());
            $requests = $requests->concat($this->capacityRequestRows($capacity));
        }

        return $requests->sortBy(fn ($row) => sprintf('%020d-%s-%020d', $row['starts_at']->getTimestamp(), $row['type'], $row['id']))
            ->map(fn ($row) => $row + ['can_respond' => true])->values();
    }

    private function requestHistory(Collection $assignments): array
    {
        abort_unless(in_array($this->requestFilter, ['all', 'open', 'answered'], true), 404);
        $actor = $this->requestActor();
        $from = CarbonImmutable::now($this->displayTimezone())->subDays(90);
        $open = $this->pendingRequestItems($assignments);
        $answeredAssignments = app(PersonalSchedule::class)->answeredRequests($actor, $from);
        $firstRevisions = $this->firstPublishedRevisions($answeredAssignments);
        $answered = $answeredAssignments->map(fn ($assignment) => $this->shiftRequestRow($assignment, $firstRevisions));
        if (CustomerPortalIntakeSchema::ready()) {
            $answered = $answered->concat($this->capacityRequestRows($this->capacityInboxRecords()
                ->filter(fn ($record) => in_array($record->status, ['consented', 'declined', 'approved'], true) && $record->ends_at->gt($from))));
        }
        $answered = $answered->map(fn ($row) => $row + ['can_respond' => false]);
        $items = match ($this->requestFilter) {
            'open' => $open,
            'answered' => $answered,
            default => $open->concat($answered),
        };
        $items = $items->sortBy(fn ($row) => sprintf('%d-%020d-%s-%020d', $row['can_respond'] ? 0 : 1, $row['starts_at']->getTimestamp(), $row['type'], $row['id']))->values();
        $page = min(max(1, $this->getPage('requestHistoryPage')), max(1, (int) ceil($items->count() / 8)));

        return [
            'rows' => new LengthAwarePaginator($items->forPage($page, 8)->values(), $items->count(), 8, $page, ['pageName' => 'requestHistoryPage']),
            'counts' => ['all' => $open->count() + $answered->count(), 'open' => $open->count(), 'answered' => $answered->count()],
        ];
    }

    private function capacityInboxRecords(): Collection
    {
        return $this->capacityInboxRecords ??= CustomerCapacityCommitment::where('user_id', $this->requestActor()->id)
            ->when($this->tab === 'requests', fn ($query) => $query->whereIn('status', ['requested', 'consented', 'declined', 'approved'])
                ->where('ends_at', '>', now()->subDays(90)->utc()),
                fn ($query) => $query->where('status', 'requested')->where('starts_at', '>', now()->utc()))->get();
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

    /** Je Anfrage neu lesen: die Merker gelten nur innerhalb eines render(). */
    private function forgetRequestCaches(): void
    {
        $this->capacityInboxRecords = null;
        $this->firstRequestRevisions = null;
    }

    /** Offene Anfragen für Zähler und Posteingang; fokussierte Einzelansichten haben keinen. */
    private function requestInbox(?Collection $loaded): ?LengthAwarePaginator
    {
        if ($this->focus !== '') {
            return null;
        }
        $requestAssignments = in_array($this->tab, ['today', 'requests'], true) ? $loaded
            : app(PersonalSchedule::class)->assignments(auth()->user(), CarbonImmutable::now($this->displayTimezone())->startOfDay());

        return $this->pendingRequests($requestAssignments);
    }

    private function selectedRequestData(): ?array
    {
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

        return $selectedRequest;
    }

    /** Daten für Posteingang, Verlauf und den Anfrage-Dialog. */
    private function requestViewData(?LengthAwarePaginator $requestInbox, ?array $requestHistory, ?array $selectedRequest): array
    {
        return [
            'requestRows' => $requestHistory['rows'] ?? null,
            'requestCounts' => $requestHistory['counts'] ?? null,
            'pendingRequests' => in_array($this->tab, ['today', 'requests'], true) ? $requestInbox : null,
            'requestCount' => $requestInbox?->total() ?? 0,
            'selectedRequest' => $selectedRequest,
            'requestCalendar' => $this->requestCalendar($selectedRequest),
        ];
    }
}
