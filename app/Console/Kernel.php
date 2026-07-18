<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;


class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Arbeitet die Datenbank-Queue ab (u. a. verzoegerte DeleteTempFile-Jobs
        // fuer ablaufende Vorschau-Kopien und den Mailversand), ohne dass ein
        // dauerhafter Worker laufen muss.
        $schedule->command('queue:work --stop-when-empty --tries=3')
            ->everyMinute()
            ->withoutOverlapping();

        // Entfernt stuendlich abgelaufene Dateien/Ordner, die auf
        // automatisches Loeschen gesetzt sind.
        $schedule->command('files:purge-expired')
            ->hourly()
            ->withoutOverlapping();
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
