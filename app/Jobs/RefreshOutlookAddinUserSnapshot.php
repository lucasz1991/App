<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RefreshOutlookAddinUserSnapshot implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 45, 90];

    public function __construct(public readonly int $userId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('outlook-addin-user-snapshot-'.$this->userId))
                ->releaseAfter(5)
                ->expireAfter(120),
        ];
    }

    public function handle(OutlookAddinUserSnapshotStore $snapshots): void
    {
        try {
            $user = User::query()->find($this->userId);
            if (! $user instanceof User
                || ! $user->isActive()
                || ! in_array($user->role, ['admin', 'staff'], true)) {
                $snapshots->forgetForUser($this->userId);

                return;
            }

            $snapshots->rebuildForUser($user);
        } catch (Throwable $exception) {
            // Personenpflege und Freigabe duerfen nie an einem abgeleiteten
            // Cache scheitern. Der API-Pfad prueft den Fingerabdruck erneut
            // und erzeugt synchron oder antwortet fail-closed.
            Log::warning('Persoenlicher Outlook-Abzug konnte nicht im Hintergrund aktualisiert werden.', [
                'user_id' => $this->userId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
