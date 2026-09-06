<?php

namespace App\Console\Commands;

use App\Services\DeviceManagement\MicrosoftDeviceRuntime;
use App\Services\DeviceManagement\MicrosoftDeviceSyncScheduler;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class SyncMicrosoftDevices extends Command
{
    protected $signature = 'devices:sync-microsoft
        {--force : Das gespeicherte Intervall ueberspringen, nicht die laufende Synchronisierung}
        {--scheduled : Interne Kennzeichnung des Laravel-Scheduleraufrufs}';

    protected $description = 'Plant den lesenden Microsoft-Entra-/Intune-Geraeteabgleich in der Geraete-Queue ein.';

    public function handle(MicrosoftDeviceSyncScheduler $scheduler, MicrosoftDeviceRuntime $runtime): int
    {
        try {
            if ($this->option('scheduled')) {
                $runtime->recordSchedulerTick();
            }
            $queued = $scheduler->queue((bool) $this->option('force'));
            $this->info($queued
                ? 'Microsoft-Geraetesynchronisierung wurde fuer den Queue-Worker eingeplant.'
                : 'Kein neuer Abgleich: deaktiviert, noch nicht faellig oder bereits eingeplant.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            // Only our operational messages are safe; drivers may expose credentials.
            $message = $exception->getMessage();
            $this->error(str_starts_with($message, 'Die Microsoft-Geraetesynchronisierung benoetigt')
                || str_starts_with($message, 'Bitte zuerst eine gueltige Microsoft-Entra-Tenant-ID')
                    ? $message
                    : 'Microsoft-Geraetesynchronisierung konnte nicht eingeplant werden. Queue und Einstellungen pruefen.');
        } catch (Throwable) {
            $this->error('Microsoft-Geraetesynchronisierung konnte nicht eingeplant werden. Queue und Einstellungen pruefen.');
        }

        return self::FAILURE;
    }
}
