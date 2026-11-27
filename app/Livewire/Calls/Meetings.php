<?php

namespace App\Livewire\Calls;

use App\Livewire\Concerns\ResetsPageOnFilterChange;
use App\Models\CommunicationMeeting;
use App\Models\Room;
use App\Models\User;
use App\Services\Calls\CallInfrastructureUnavailable;
use App\Services\Calls\MeetingPlanningService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

class Meetings extends Component
{
    use WithoutUrlPagination;
    use ResetsPageOnFilterChange;
    use WithPagination;

    public string $name = '';

    public bool $video = true;

    public string $search = '';

    public string $filter = 'upcoming';

    public string $pane = 'list';

    public string $formMode = 'plan';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $timezone = 'Europe/Berlin';

    public array $participantIds = [];

    public string $participantSearch = '';

    #[Locked]
    public ?int $selectedMeetingId = null;

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user->status && ($user->isAdmin() || $user->hasRbacPermission('calls.join')), 403);
        $this->setPage(1, 'meetingsPage');
    }

    public function getListeners(): array
    {
        return ['communication:call-ready' => '$refresh', 'communication:call-released' => '$refresh'];
    }

    protected function pageResetProperties(): array
    {
        return ['search', 'filter'];
    }

    protected function filterPageName(): string
    {
        return 'meetingsPage';
    }

    public function updatedFilter(): void
    {
        $this->filter = in_array($this->filter, ['upcoming', 'past', 'all'], true) ? $this->filter : 'upcoming';
    }

    public function showCreate(string $mode = 'plan'): void
    {
        abort_unless(auth()->user()->isAdmin() || auth()->user()->hasRbacPermission('calls.start'), 403);
        $this->resetValidation();
        $this->reset(['name', 'participantIds', 'participantSearch', 'selectedMeetingId']);
        $this->formMode = $mode === 'instant' ? 'instant' : 'plan';
        if (! in_array($this->timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            $this->reset('timezone');
        }
        $this->startsAt = now()->addHour()->setTimezone($this->timezone)->format('Y-m-d\TH:i');
        $this->endsAt = now()->addHours(2)->setTimezone($this->timezone)->format('Y-m-d\TH:i');
        $this->pane = 'form';
    }

    public function showList(): void
    {
        $this->pane = 'list';
        $this->selectedMeetingId = null;
        $this->resetValidation();
    }

    /** Read-only: details never accept an invitation or join media. */
    public function openMeeting(int $meetingId): void
    {
        app(MeetingPlanningService::class)->visibleTo(auth()->user())->findOrFail($meetingId);
        $this->selectedMeetingId = $meetingId;
        $this->pane = 'details';
    }

    public function savePlan(MeetingPlanningService $meetings): void
    {
        if ($this->formMode === 'edit' && $this->selectedMeetingId) {
            $meetings->reschedule(CommunicationMeeting::findOrFail($this->selectedMeetingId), auth()->user(), [
                'title' => $this->name, 'starts_at' => $this->startsAt, 'ends_at' => $this->endsAt,
                'timezone' => $this->timezone, 'video' => $this->video,
            ]);
            $this->pane = 'details';

            return;
        }
        $meeting = $meetings->plan(auth()->user(), [
            'title' => $this->name, 'starts_at' => $this->startsAt, 'ends_at' => $this->endsAt,
            'timezone' => $this->timezone, 'participant_ids' => $this->participantIds, 'video' => $this->video,
        ]);
        $this->selectedMeetingId = $meeting->id;
        $this->pane = 'details';
        $this->reset(['name', 'participantIds']);
    }

    public function editMeeting(int $meetingId, MeetingPlanningService $meetings): void
    {
        $meeting = $meetings->visibleTo(auth()->user())->findOrFail($meetingId);
        abort_unless((int) $meeting->owner_id === (int) auth()->id()
            && (auth()->user()->isAdmin() || auth()->user()->hasRbacPermission('calls.start')), 403);
        abort_unless($meeting->status === 'scheduled', 410);
        abort_if($meeting->sessions()->whereIn('status', ['pending', 'active'])->exists(), 409);
        $this->selectedMeetingId = $meeting->id;
        $this->name = $meeting->title;
        $this->timezone = $meeting->timezone;
        $this->startsAt = $meeting->starts_at->setTimezone($meeting->timezone)->format('Y-m-d\TH:i');
        $this->endsAt = $meeting->ends_at->setTimezone($meeting->timezone)->format('Y-m-d\TH:i');
        $this->video = $meeting->video;
        $this->formMode = 'edit';
        $this->pane = 'form';
    }

    public function createMeeting()
    {
        $this->validate(['name' => ['required', 'string', 'min:3', 'max:80']]);
        try {
            $room = app(MeetingPlanningService::class)->startInstant(auth()->user(), $this->name, $this->video);
        } catch (CallInfrastructureUnavailable) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.calls_unavailable'));

            return null;
        }

        return $this->openCall($room);
    }

    public function startMeeting(int $meetingId)
    {
        try {
            $room = app(MeetingPlanningService::class)->start(CommunicationMeeting::findOrFail($meetingId), auth()->user());
        } catch (CallInfrastructureUnavailable) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.calls_unavailable'));

            return null;
        }

        return $this->openCall($room);
    }

    public function joinMeeting(int $meetingId)
    {
        return $this->openCall(app(MeetingPlanningService::class)->join(CommunicationMeeting::findOrFail($meetingId), auth()->user()));
    }

    public function join(int $roomId)
    {
        return $this->openCall(app(MeetingPlanningService::class)->joinRoom(Room::findOrFail($roomId), auth()->user()));
    }

    public function respond(int $meetingId, string $response, MeetingPlanningService $meetings): void
    {
        $meetings->respond(CommunicationMeeting::findOrFail($meetingId), auth()->user(), $response);
    }

    public function cancelMeeting(int $meetingId, MeetingPlanningService $meetings): void
    {
        $meetings->cancel(CommunicationMeeting::findOrFail($meetingId), auth()->user());
    }

    protected function openCall(Room $room)
    {
        $this->dispatch('communication:call', roomUuid: $room->uuid);

        return null;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['startsAt', 'endsAt', 'timezone'], true)) {
            $this->resetValidation(['starts_at', 'ends_at', 'timezone']);
        }
    }

    public function render()
    {
        $user = auth()->user();
        abort_unless($user->status && ($user->isAdmin() || $user->hasRbacPermission('calls.join')), 403);
        $service = app(MeetingPlanningService::class);
        $ready = $service->ready();
        $live = $this->filter === 'past' ? collect() : Room::query()->where('type', 'meeting')->accessibleMeetings($user)
            ->whereIn('status', ['pending', 'active'])
            ->when(trim($this->search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($this->search).'%'))
            ->with(['owner', 'participants' => fn ($query) => $query->where('user_id', $user->id)])
            ->latest('id')->limit(30)->get();
        $selected = $ready && $this->selectedMeetingId
            ? $service->visibleTo($user)->with(['participants.user', 'owner', 'sessions.participants' => fn ($query) => $query->where('user_id', $user->id)])->findOrFail($this->selectedMeetingId) : null;
        $plans = $ready ? $service->visibleTo($user)->with(['owner', 'participants', 'sessions.participants' => fn ($query) => $query->where('user_id', $user->id)])
            ->whereNotIn('id', $live->map(fn (Room $room) => $room->communicationMeetingId())->filter()->unique()->values())
            ->when(trim($this->search) !== '', fn ($query) => $query->where('title', 'like', '%'.trim($this->search).'%'))
            ->when($this->filter === 'upcoming', fn ($query) => $query->where('status', 'scheduled')
                ->where(fn ($schedule) => $schedule->where('ends_at', '>=', now('UTC'))
                    ->orWhereHas('sessions', fn ($room) => $room->whereIn('status', ['pending', 'active'])))
                ->orderBy('starts_at'))
            ->when($this->filter === 'past', fn ($query) => $query->where(fn ($past) => $past->where('ends_at', '<', now('UTC'))->orWhere('status', 'cancelled'))
                ->whereDoesntHave('sessions', fn ($room) => $room->whereIn('status', ['pending', 'active'])))
            ->orderByDesc('id')->paginate(20, ['*'], 'meetingsPage') : null;
        $candidates = $ready && $this->pane === 'form' && $this->formMode === 'plan'
            ? User::query()->where('status', true)->where('id', '!=', $user->id)
                ->when(trim($this->participantSearch) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($this->participantSearch).'%'))
                ->orderBy('name')->limit(40)->get()->filter(fn (User $candidate) => $candidate->isAdmin() || $candidate->hasRbacPermission('calls.join')) : collect();
        $duration = null;
        $timeErrors = [];
        if ($this->pane === 'form' && $this->formMode !== 'instant' && $this->startsAt !== '' && $this->endsAt !== '' && $this->timezone !== '') {
            try {
                [$start, $end] = $service->scheduledWindow($this->startsAt, $this->endsAt, $this->timezone);
                $duration = (int) $start->diffInMinutes($end);
            } catch (ValidationException $error) {
                $timeErrors = $error->errors();
            }
        }

        return view('livewire.calls.meetings', [
            'live' => $live, 'duration' => $duration, 'timeErrors' => $timeErrors,
            'planningReady' => $ready, 'plans' => $plans, 'selectedMeeting' => $selected, 'candidates' => $candidates,
            'canStart' => $user->isAdmin() || $user->hasRbacPermission('calls.start'),
        ]);
    }
}
