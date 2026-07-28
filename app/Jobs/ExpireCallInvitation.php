<?php

namespace App\Jobs;

use App\Models\RoomInvitation;
use App\Services\Calls\CallInvitationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Laeuft verzoegert nach dem Ring-Fenster: Ist die Einladung dann noch offen,
 * gilt der Anruf als verpasst.
 */
class ExpireCallInvitation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $invitationId)
    {
    }

    public function handle(CallInvitationService $invitations): void
    {
        $invitation = RoomInvitation::find($this->invitationId);

        if (! $invitation || ! $invitation->isPending()) {
            return;
        }

        // Schutz vor zu fruehem Lauf (z. B. Sync-Queue ignoriert delay()):
        // Nur nach tatsaechlich abgelaufenem Ring-Fenster als verpasst werten.
        if ($invitation->expires_at->isFuture()) {
            return;
        }

        $invitations->expire($invitation, 'missed');
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Ring-Timeout konnte nicht verarbeitet werden.', [
            'invitation_id' => $this->invitationId,
            'error_class' => $exception ? $exception::class : null,
        ]);
    }
}
