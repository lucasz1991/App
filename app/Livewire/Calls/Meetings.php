<?php

namespace App\Livewire\Calls;

use App\Models\Room;
use App\Models\RoomParticipant;
use App\Services\Calls\CallInfrastructureUnavailable;
use App\Services\Calls\LiveKitService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Meetings: Besprechungsraeume ohne Chat-Bezug.
 *
 * Unterschied zum Chat-Anruf: Es klingelt niemand. Der Raum existiert, ein
 * Link wird geteilt, Teilnehmer kommen dazu wann sie wollen. Fuer geplante
 * Runden ist das der passende Weg – ein Klingeln waere dort nur stoerend.
 */
class Meetings extends Component
{
    public string $name = '';

    public bool $video = true;

    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $user->hasRbacPermission('calls.join'), 403);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:80'],
        ];
    }

    /** Offenen Besprechungsraum anlegen und direkt betreten. */
    public function createMeeting()
    {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $user->hasRbacPermission('calls.start'), 403);

        $this->validate();

        $livekit = app(LiveKitService::class);

        $room = DB::transaction(function () use ($user): Room {
            $room = Room::create([
                'name' => trim($this->name),
                'type' => 'meeting',
                'status' => 'pending',
                'owner_id' => $user->id,
                'settings' => ['video' => $this->video],
            ]);

            $room->participants()->create([
                'user_id' => $user->id,
                'role' => 'host',
                'connection' => 'invited',
                'livekit_identity' => LiveKitService::identityFor($user),
            ]);

            return $room;
        });

        // Gleiche Absicherung wie beim Chat-Anruf: Ohne Raum an der SFU darf
        // niemand in ein Fenster geleitet werden, das nie zustande kommt.
        if (! $livekit->createRoom($room)) {
            $room->forceFill(['status' => 'cancelled', 'ended_at' => now()])->save();

            $this->dispatch('swal:toast', type: 'error', text: __('app.calls_unavailable'));

            return null;
        }

        return redirect()->route('calls.window', $room);
    }

    /** Einem laufenden Meeting beitreten – Teilnahme wird dabei angelegt. */
    public function join(int $roomId)
    {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $user->hasRbacPermission('calls.join'), 403);

        $room = Room::where('type', 'meeting')->findOrFail($roomId);

        abort_unless($room->isActive(), 410, __('app.calls_ended'));

        RoomParticipant::firstOrCreate(
            ['room_id' => $room->id, 'user_id' => $user->id],
            [
                'role' => 'speaker',
                'connection' => 'invited',
                'livekit_identity' => LiveKitService::identityFor($user),
            ],
        );

        return redirect()->route('calls.window', $room);
    }

    public function render()
    {
        $userId = auth()->id();

        return view('livewire.calls.meetings', [
            // Laufende Meetings sieht jeder Berechtigte – sie sind bewusst offen.
            'live' => Room::query()
                ->with(['owner', 'participants'])
                ->where('type', 'meeting')
                ->whereIn('status', ['pending', 'active'])
                ->latest('id')
                ->get(),
            'recent' => Room::query()
                ->with('owner')
                ->where('type', 'meeting')
                ->whereIn('status', ['ended', 'cancelled'])
                ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
                ->latest('id')
                ->limit(8)
                ->get(),
            'canStart' => auth()->user()->isAdmin() || auth()->user()->hasRbacPermission('calls.start'),
        ])->layout('layouts.master');
    }
}
