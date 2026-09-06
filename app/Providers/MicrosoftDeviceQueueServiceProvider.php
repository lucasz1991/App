<?php

namespace App\Providers;

use App\Console\Queue\MicrosoftDeviceWorkCommand;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\ServiceProvider;

class MicrosoftDeviceQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // An extender survives registration of Laravel's deferred Artisan
        // provider. A direct singleton replacement can be overwritten by it.
        $this->app->extend(WorkCommand::class, fn (WorkCommand $command, $app) => new MicrosoftDeviceWorkCommand(
            $app['queue.worker'],
            $app['cache.store'],
        ));
    }
}
