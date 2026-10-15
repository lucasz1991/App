<?php

namespace App\Services\Calls;

use App\Models\Room;
use App\Models\User;

class CallAvailabilityService
{
    public function activeRoomFor(User $user, ?Room $target = null): ?Room
    {
        return Room::query()->whereIn('status', ['pending', 'active'])
            ->when($target, fn ($query) => $query->whereKeyNot($target->id))
            ->whereHas('participants', function ($participant) use ($user): void {
                $participant->where('user_id', $user->id)
                    ->where(function ($engaged): void {
                        $engaged->where('connection', 'joined')
                            ->orWhere(function ($joining): void {
                                $joining->where('connection', 'invited')->where(function ($eligible): void {
                                    $eligible->whereIn('role', ['host', 'moderator'])
                                        ->orWhereExists(function ($invitation): void {
                                            $invitation->selectRaw('1')->from('room_invitations as accepted_invitation')
                                                ->whereColumn('accepted_invitation.room_id', 'room_participants.room_id')
                                                ->whereColumn('accepted_invitation.invitee_id', 'room_participants.user_id')
                                                ->where('accepted_invitation.status', 'accepted')
                                                ->whereNotExists(function ($newer): void {
                                                    $newer->selectRaw('1')->from('room_invitations as newer_invitation')
                                                        ->whereColumn('newer_invitation.room_id', 'accepted_invitation.room_id')
                                                        ->whereColumn('newer_invitation.invitee_id', 'accepted_invitation.invitee_id')
                                                        ->whereColumn('newer_invitation.id', '>', 'accepted_invitation.id');
                                                });
                                        });
                                });
                            });
                    });
            })->latest('id')->first();
    }

    public function assertAvailable(User $user, ?Room $target = null): void
    {
        abort_if($this->activeRoomFor($user, $target) !== null, 409, 'Es läuft bereits ein anderes Gespräch. Bitte zuerst beenden.');
    }
}
