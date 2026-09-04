<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use Illuminate\Console\Command;
use Throwable;

final class RefreshOutlookAddinSnapshots extends Command
{
    protected $signature = 'outlook-addin:snapshots:refresh
        {--user=* : Eine oder mehrere interne Mitarbeiter-IDs statt aller aktiven Benutzer}';

    protected $description = 'Erzeugt aktuelle verschluesselte Outlook-Abzuege fuer Mitarbeiter';

    public function handle(OutlookAddinUserSnapshotStore $snapshots): int
    {
        $requestedIds = collect((array) $this->option('user'));
        $invalidIds = $requestedIds->reject(
            static fn (mixed $id): bool => ctype_digit((string) $id) && (int) $id > 0,
        );

        if ($invalidIds->isNotEmpty()) {
            $this->error('Jede --user-Angabe muss eine positive interne Mitarbeiter-ID sein. Es wurde nichts ausgefuehrt.');

            return self::INVALID;
        }

        $ids = $requestedIds
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($requestedIds->isEmpty()) {
            $result = $snapshots->rebuildAll();
            $this->info(sprintf(
                'Outlook-Abzuege: %d verarbeitet, %d aktuell, %d fehlgeschlagen.',
                $result['processed'],
                $result['refreshed'],
                $result['failed'],
            ));

            return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $failed = 0;
        foreach ($ids as $userId) {
            try {
                $user = User::query()->find($userId);
                if (! $user instanceof User
                    || ! $user->isActive()
                    || ! in_array($user->role, ['admin', 'staff'], true)) {
                    $snapshots->forgetForUser($userId);
                    $this->warn("Mitarbeiter {$userId} fehlt, ist inaktiv oder besitzt keine Mitarbeiterrolle; vorhandener Abzug wurde entfernt.");
                    $failed++;

                    continue;
                }

                $snapshots->rebuildForUser($user);
                $this->line("Mitarbeiter {$userId}: aktuell.");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Mitarbeiter {$userId}: {$exception->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
