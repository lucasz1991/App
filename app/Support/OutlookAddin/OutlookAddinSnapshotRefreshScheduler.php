<?php

namespace App\Support\OutlookAddin;

use App\Enums\MailDocumentKind;
use App\Jobs\RefreshAllOutlookAddinUserSnapshots;
use App\Jobs\RefreshOutlookAddinUserSnapshot;
use App\Models\MailDocument;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Plant idempotente Neuaufbauten ueber die regulaere Queue nach DB-Commit ein. */
final class OutlookAddinSnapshotRefreshScheduler
{
    public function __construct(
        private readonly OutlookAddinUserSnapshotStore $snapshots,
    ) {}

    public function scheduleForUser(User|int $user): void
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;
        if ($userId < 1 || ! $this->canGenerate()) {
            return;
        }

        try {
            RefreshOutlookAddinUserSnapshot::dispatch($userId)->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('Outlook-Snapshotaktualisierung konnte nicht eingeplant werden.', [
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function scheduleForTeam(Team $team): void
    {
        $userIds = $team->users()->pluck('users.id');
        if ($team->user_id) {
            $userIds->push((int) $team->user_id);
        }

        foreach ($userIds->unique()->values() as $userId) {
            $this->scheduleForUser((int) $userId);
        }
    }

    public function scheduleAll(): void
    {
        if (! $this->canGenerate()) {
            return;
        }

        try {
            RefreshAllOutlookAddinUserSnapshots::dispatch()->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('Globale Outlook-Snapshotaktualisierung konnte nicht eingeplant werden.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function forgetForUser(User|int $user): void
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;
        if ($userId < 1) {
            return;
        }

        try {
            $this->snapshots->forgetForUser($userId);
        } catch (Throwable $exception) {
            // Eine Personenpflege darf nach erfolgreichem DB-Commit nicht an
            // einem abgeleiteten Cache scheitern. Der regulaere Benutzerjob
            // wiederholt auch reine Loeschungen und landet notfalls sichtbar
            // in failed_jobs, statt den Fehler als Erfolg zu verschlucken.
            Log::warning('Outlook-Snapshotloeschung wird erneut eingeplant.', [
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);

            try {
                RefreshOutlookAddinUserSnapshot::dispatch($userId)->afterCommit();
            } catch (Throwable $dispatchException) {
                Log::error('Outlook-Snapshotloeschung konnte nicht erneut eingeplant werden.', [
                    'user_id' => $userId,
                    'error' => $dispatchException->getMessage(),
                ]);
            }
        }
    }

    private function canGenerate(): bool
    {
        if (! (bool) config('outlook_addin.snapshots.auto_refresh', true)) {
            return false;
        }

        try {
            if (! Schema::hasTable('mail_documents')) {
                return false;
            }

            $publishedKinds = MailDocument::query()
                ->published()
                ->whereIn('kind', array_map(
                    static fn (MailDocumentKind $kind): string => $kind->value,
                    MailDocumentKind::cases(),
                ))
                ->pluck('kind')
                ->map(static fn (mixed $kind): string => $kind instanceof MailDocumentKind
                    ? $kind->value
                    : (string) $kind)
                ->unique();

            return $publishedKinds->count() === count(MailDocumentKind::cases());
        } catch (Throwable) {
            return false;
        }
    }
}
