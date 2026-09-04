<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class RefreshAllOutlookAddinUserSnapshots implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 20;

    public int $maxExceptions = 3;

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('outlook-addin-all-user-snapshots'))
                ->releaseAfter(15)
                ->expireAfter(900),
        ];
    }

    public function handle(): void
    {
        User::query()
            ->where('status', true)
            ->whereIn('role', ['admin', 'staff'])
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, static function ($users): void {
                foreach ($users as $user) {
                    RefreshOutlookAddinUserSnapshot::dispatch((int) $user->getKey());
                }
            });
    }
}
