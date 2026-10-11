<?php

namespace App\Providers;

use App\Support\Privacy\EmployeeDatabaseSessionHandler;
use Illuminate\Support\ServiceProvider;

class SessionLifetimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make('session')->extend('database', function ($app) {
            return new EmployeeDatabaseSessionHandler(
                $app->make('db')->connection(config('session.connection')),
                config('session.table'),
                (int) config('session.ordinary_lifetime', config('session.lifetime', 120)),
                $app,
            );
        });
    }
}
