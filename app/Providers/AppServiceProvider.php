<?php

namespace App\Providers;

use App\Contracts\Calls\CallEgressGateway;
use App\Services\Calls\LiveKitEgressGateway;
use App\Support\Calls\CallSettings;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CallEgressGateway::class, LiveKitEgressGateway::class);
        $this->app->scoped(PublishedMailDocumentSnapshotStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Administrierte Anruf-Betriebswerte ueber die .env-Vorgaben legen,
        // damit jedes bestehende config('livekit.…') sie ohne Anpassung sieht.
        CallSettings::apply();
    }
}
