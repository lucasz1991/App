<?php

namespace App\Console\Queue;

use App\Jobs\SyncMicrosoftDevices;
use Illuminate\Queue\Console\WorkCommand;

/** Route Plesk's named Microsoft worker without changing any other connection. */
class MicrosoftDeviceWorkCommand extends WorkCommand
{
    public function handle()
    {
        $queue = $this->option('queue');
        $connection = $this->argument('connection');
        $containsMicrosoftQueue = in_array(
            SyncMicrosoftDevices::QUEUE,
            array_map('trim', explode(',', (string) $queue)),
            true,
        );

        if ($containsMicrosoftQueue) {
            if ($queue !== SyncMicrosoftDevices::QUEUE) {
                $this->components->error('Die Microsoft-Geraetequeue muss als eigener Worker ohne weitere Queues laufen.');

                return self::FAILURE;
            }

            if ($connection !== null && $connection !== '' && $connection !== SyncMicrosoftDevices::CONNECTION) {
                $this->components->error('Die Microsoft-Geraetequeue benoetigt die Connection microsoft_devices.');

                return self::FAILURE;
            }

            // handle() runs after Symfony's final input binding. An earlier
            // CommandStarting mutation would be discarded by that binding.
            $this->input->setArgument('connection', SyncMicrosoftDevices::CONNECTION);
        } elseif ($connection === SyncMicrosoftDevices::CONNECTION && $queue !== null && $queue !== '') {
            $this->components->error('Die Connection microsoft_devices darf nur die Microsoft-Geraetequeue verarbeiten.');

            return self::FAILURE;
        }

        return parent::handle();
    }
}
