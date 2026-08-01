<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        // A production/development config cache must never override phpunit.xml.
        // Otherwise destructive schema tests can accidentally use the local
        // MySQL database instead of the isolated in-memory SQLite connection.
        $testingConfigCache = __DIR__.'/../bootstrap/cache/config.testing.php';
        putenv('APP_CONFIG_CACHE='.$testingConfigCache);
        $_ENV['APP_CONFIG_CACHE'] = $testingConfigCache;
        $_SERVER['APP_CONFIG_CACHE'] = $testingConfigCache;

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if ($app->environment('testing') && $app['config']->get('database.default') !== 'sqlite') {
            throw new \RuntimeException('RailTime tests must use the isolated SQLite database.');
        }

        return $app;
    }
}
