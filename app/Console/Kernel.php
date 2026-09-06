<?php

namespace App\Console;

use App\Jobs\PurgeExpiredCallRecordings;
use App\Jobs\ReconcileCallRecordings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Entfernt stuendlich abgelaufene Dateien/Ordner, die auf
        // automatisches Loeschen gesetzt sind.
        $schedule->command('files:purge-expired')
            ->hourly()
            ->withoutOverlapping();

        $schedule->job(new ReconcileCallRecordings)
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->job(new PurgeExpiredCallRecordings)
            ->dailyAt('02:15')
            ->withoutOverlapping();

        // Read-only probes keep the short-lived production evidence current.
        // A failed or changed connector closes the mutation gate; this task
        // never enables it and never sends a device/account mutation.
        $schedule->command('devices:probe-providers')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // Durable Identity-Outbox rows can outlive a queue outage. Recovery
        // only runs behind the same fresh production gate as normal dispatch.
        $schedule->command('devices:recover-identity-outbox')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        $schedule->command('devices:sync-microsoft')->everyFiveMinutes()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
