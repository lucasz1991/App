<?php

namespace App\Services\Calls;

use App\Models\Chat;
use App\Models\CommunicationMeeting;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MeetingPlanningService
{
    public function __construct(protected LiveKitService $livekit, protected CallConversationService $conversations, protected RoomEventRecorder $events) {}

    public function ready(): bool
    {
        return Schema::hasTable('communication_meetings')
            && Schema::hasTable('communication_meeting_participants')
            && Schema::hasColumn('rooms', 'communication_meeting_id');
    }

    public function visibleTo(User $user): Builder
    {
        $this->authorize($user);
        $this->requireReady();

        return CommunicationMeeting::query()->visibleTo($user);
    }

    public function plan(User $actor, array $input): CommunicationMeeting
    {
        $this->authorize($actor, start: true);
        $this->requireReady();
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'starts_at' => ['required', 'string', 'max:40'],
            'ends_at' => ['required', 'string', 'max:40'],
            'timezone' => ['required', 'timezone:all'],
            'participant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'participant_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'video' => ['boolean'],
            'chat_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $start = $this->localTime($data['starts_at'], $data['timezone'], 'starts_at');
        $end = $this->localTime($data['ends_at'], $data['timezone'], 'ends_at');
        if ($start->lte(now()) || $end->lte($start)) {
            throw ValidationException::withMessages(['ends_at' => 'Beginn muss in der Zukunft und Ende nach Beginn liegen.']);
        }
        $ids = collect($data['participant_ids'])->map(fn ($id) => (int) $id)->push((int) $actor->id)->unique()->values();

        return DB::transaction(function () use ($actor, $data, $start, $end, $ids): CommunicationMeeting {
            $people = User::whereKey($ids)->where('status', true)->get();
            if ($people->count() !== $ids->count() || $people->contains(fn (User $user) => ! $user->isAdmin() && ! $user->hasRbacPermission('calls.join'))) {
                throw ValidationException::withMessages(['participant_ids' => 'Teilnehmer sind nicht mehr für Anrufe verfügbar.']);
            }
            if (! empty($data['chat_id'])) {
                $chat = Chat::with('participants')->lockForUpdate()->findOrFail($data['chat_id']);
                $member = $chat->participantFor($actor);
                abort_unless(! $chat->isCall() && $member && ! $member->pivot?->hidden_at, 403);
                abort_if($ids->diff($chat->participants->pluck('id'))->isNotEmpty(), 422);
            } else {
                $chat = Chat::create(['type' => 'group', 'name' => trim($data['title']), 'created_by' => $actor->id]);
                foreach ($ids as $id) {
                    $chat->participants()->attach($id, ['joined_at' => now(), 'last_read_at' => null, 'hidden_at' => null, 'cleared_at' => null]);
                }
            }
            $meeting = CommunicationMeeting::create([
                'title' => trim($data['title']), 'owner_id' => $actor->id, 'team_id' => $actor->current_team_id,
                'chat_id' => $chat->id, 'starts_at' => $start, 'ends_at' => $end, 'timezone' => $data['timezone'],
                'video' => $data['video'] ?? true, 'status' => 'scheduled',
            ]);
            foreach ($ids as $id) {
                $meeting->participants()->create([
                    'user_id' => $id, 'response' => $id === (int) $actor->id ? 'accepted' : 'pending',
                    'responded_at' => $id === (int) $actor->id ? now() : null,
                ]);
            }

            return $meeting;
        });
    }

    public function respond(CommunicationMeeting $meeting, User $actor, string $response): void
    {
        $this->authorize($actor);
        abort_unless(in_array($response, ['accepted', 'declined', 'pending'], true), 422);
        DB::transaction(function () use ($meeting, $actor, $response): void {
            $current = CommunicationMeeting::lockForUpdate()->findOrFail($meeting->id);
            abort_unless($current->status === 'scheduled', 410);
            $participant = $current->participants()->where('user_id', $actor->id)->firstOrFail();
            abort_if((int) $current->owner_id === (int) $actor->id && $response !== 'accepted', 422);
            $participant->forceFill(['response' => $response, 'responded_at' => $response === 'pending' ? null : now()])->save();
        });
    }

    /** Change the plan only; existing attendance and call history stay intact. */
    public function reschedule(CommunicationMeeting $meeting, User $actor, array $input): void
    {
        $this->authorize($actor, start: true);
        $this->requireReady();
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'starts_at' => ['required', 'string', 'max:40'],
            'ends_at' => ['required', 'string', 'max:40'],
            'timezone' => ['required', 'timezone:all'],
            'video' => ['required', 'boolean'],
        ])->validate();
        $start = $this->localTime($data['starts_at'], $data['timezone'], 'starts_at');
        $end = $this->localTime($data['ends_at'], $data['timezone'], 'ends_at');
        if ($start->lte(now()) || $end->lte($start)) {
            throw ValidationException::withMessages(['ends_at' => 'Beginn muss in der Zukunft und Ende nach Beginn liegen.']);
        }
        DB::transaction(function () use ($meeting, $actor, $data, $start, $end): void {
            $current = CommunicationMeeting::lockForUpdate()->findOrFail($meeting->id);
            abort_unless((int) $current->owner_id === (int) $actor->id, 403);
            abort_unless($current->status === 'scheduled', 410);
            abort_if($current->sessions()->whereIn('status', ['pending', 'active'])->exists(), 409);
            $timeChanged = ! $current->starts_at->equalTo($start) || ! $current->ends_at->equalTo($end) || $current->timezone !== $data['timezone'];
            $current->forceFill(['title' => trim($data['title']), 'starts_at' => $start, 'ends_at' => $end, 'timezone' => $data['timezone'], 'video' => $data['video']])->save();
            if ($timeChanged) {
                $current->participants()->where('user_id', '!=', $actor->id)->update(['response' => 'pending', 'responded_at' => null]);
            }
        });
    }

    public function cancel(CommunicationMeeting $meeting, User $actor): void
    {
        $this->authorize($actor, start: true);
        DB::transaction(function () use ($meeting, $actor): void {
            $current = CommunicationMeeting::lockForUpdate()->findOrFail($meeting->id);
            abort_unless((int) $current->owner_id === (int) $actor->id, 403);
            abort_if($current->sessions()->whereIn('status', ['pending', 'active'])->exists(), 409, 'Laufendes Gespräch zuerst ausdrücklich beenden.');
            if ($current->status !== 'cancelled') {
                $current->forceFill(['status' => 'cancelled'])->save();
            }
        });
    }

    /** Explicit organizer action; scheduling/RSVP never creates a media room. */
    public function start(CommunicationMeeting $meeting, User $actor): Room
    {
        $this->authorize($actor, start: true);
        $this->requireReady();
        [$room, $created] = DB::transaction(function () use ($meeting, $actor): array {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $current = CommunicationMeeting::lockForUpdate()->findOrFail($meeting->id);
            abort_unless((int) $current->owner_id === (int) $actor->id, 403);
            abort_unless($current->status === 'scheduled', 410);
            $existing = $current->sessions()->whereIn('status', ['pending', 'active'])->latest('id')->first();
            if ($existing) {
                abort_if((bool) data_get($existing->settings, 'provisioning'), 409, 'Gespräch wird vorbereitet.');
                app(CallAvailabilityService::class)->assertAvailable($actor, $existing);

                return [$existing, false];
            }
            app(CallAvailabilityService::class)->assertAvailable($actor);
            $chat = $current->chat()->lockForUpdate()->firstOrFail();
            $member = $chat->participantFor($actor);
            abort_unless($member && ! $member->pivot?->hidden_at, 403);
            abort_if($chat->activeRoom()->exists(), 409, 'In dieser Unterhaltung läuft bereits ein Anruf.');
            $room = new Room;
            $room->forceFill([
                'name' => $current->title, 'type' => 'meeting', 'status' => 'pending', 'owner_id' => $actor->id,
                'chat_id' => $chat->id, 'team_id' => $current->team_id, 'communication_meeting_id' => $current->id,
                'scheduled_at' => $current->starts_at->setTimezone(config('app.timezone')),
                'settings' => ['video' => $current->video, 'communication_meeting_id' => $current->id, 'provisioning' => true],
            ])->save();
            $this->host($room, $actor);

            return [$room, true];
        });
        if ($created) {
            $this->provision($room);
        }

        return $room;
    }

    public function startInstant(User $actor, string $title, bool $video = true): Room
    {
        $this->authorize($actor, start: true);
        Validator::make(['title' => $title], ['title' => ['required', 'string', 'min:3', 'max:120']])->validate();
        $room = DB::transaction(function () use ($actor, $title, $video): Room {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(CallAvailabilityService::class)->assertAvailable($actor);
            $room = Room::create(['name' => trim($title), 'type' => 'meeting', 'status' => 'pending', 'owner_id' => $actor->id, 'team_id' => $actor->current_team_id, 'settings' => ['video' => $video]]);
            $this->host($room, $actor);

            return $room;
        });
        $this->provision($room);

        return $room;
    }

    public function join(CommunicationMeeting $meeting, User $actor): Room
    {
        $this->authorize($actor);
        $current = CommunicationMeeting::findOrFail($meeting->id);
        abort_unless($current->status === 'scheduled' && $current->involves($actor), 403);
        $room = $current->sessions()->whereIn('status', ['pending', 'active'])->latest('id')->firstOrFail();

        return $this->joinRoom($room, $actor);
    }

    public function joinRoom(Room $room, User $actor): Room
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($room, $actor): Room {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $current = Room::where('type', 'meeting')->lockForUpdate()->findOrFail($room->id);
            abort_unless($current->isActive(), 410);
            abort_unless($current->canAccessMeeting($actor), 403);
            app(CallAvailabilityService::class)->assertAvailable($actor, $current);
            abort_if((bool) data_get($current->settings, 'provisioning'), 409);
            $participant = $current->participantFor($actor);
            abort_if($participant?->isRemoved(), 403);
            if (! $participant) {
                $current->participants()->create(['user_id' => $actor->id, 'role' => 'speaker', 'connection' => 'invited', 'livekit_identity' => LiveKitService::identityFor($actor)]);
            } elseif ($participant->connectionState() === 'left') {
                $participant->forceFill(['connection' => 'invited', 'left_at' => null])->save();
            }
            $latest = $current->invitations()->where('invitee_id', $actor->id)->latest('id')->first();
            if ($latest && $latest->status !== 'accepted') {
                // Explicit join is a new accepted attempt, never rewriting old evidence.
                $current->invitations()->create(['inviter_id' => $current->owner_id, 'invitee_id' => $actor->id, 'status' => 'accepted', 'expires_at' => now(), 'responded_at' => now()]);
            }
            $current->unsetRelation('participants');
            abort_unless($current->mayJoin($actor), 403);
            $this->conversations->attachParticipant($current, $actor);

            return $current;
        });
    }

    protected function host(Room $room, User $actor): void
    {
        $room->participants()->create(['user_id' => $actor->id, 'role' => 'host', 'connection' => 'invited', 'livekit_identity' => LiveKitService::identityFor($actor)]);
        $this->conversations->createForRoom($room, $actor);
        $this->events->record($room, 'created', $actor, ['type' => 'meeting']);
    }

    protected function provision(Room $room): void
    {
        if (! $this->livekit->createRoom($room)) {
            $room->forceFill(['status' => 'cancelled', 'ended_at' => now(), 'ended_reason' => 'infrastructure_unavailable'])->save();
            $this->events->record($room, 'cancelled', $room->owner_id, ['reason' => 'infrastructure_unavailable']);
            throw new CallInfrastructureUnavailable('Meeting media room unavailable.');
        }
        if (data_get($room->settings, 'provisioning')) {
            $room->forceFill(['settings' => array_merge($room->settings, ['provisioning' => false])])->save();
        }
    }

    protected function authorize(User $actor, bool $start = false): void
    {
        $actor->refresh();
        abort_unless($actor->status && ($actor->isAdmin() || $actor->hasRbacPermission($start ? 'calls.start' : 'calls.join')), 403);
    }

    protected function requireReady(): void
    {
        abort_unless($this->ready(), 503, 'Die Meetingplanung benötigt die Kommunikations-Migration.');
    }

    protected function localTime(string $input, string $zone, string $field): CarbonImmutable
    {
        try {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/', $input)) {
                throw new \InvalidArgumentException;
            }
            $date = CarbonImmutable::parse($input, $zone);
            if ($date->setTimezone($zone)->format('Y-m-d\TH:i') !== substr($input, 0, 16)) {
                throw new \InvalidArgumentException;
            }
            if (! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $input)) {
                $wall = $date->setTimezone($zone)->format('Y-m-d\TH:i');
                // A repeated civil time needs an explicit offset to be unambiguous.
                if ($date->addHour()->setTimezone($zone)->format('Y-m-d\TH:i') === $wall
                    || $date->subHour()->setTimezone($zone)->format('Y-m-d\TH:i') === $wall) {
                    throw new \InvalidArgumentException;
                }
            }

            return $date->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Zeitpunkt und Zeitzone sind ungültig.']);
        }
    }
}
