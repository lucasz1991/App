<?php

namespace App\Providers;

use App\Contracts\Calls\CallEgressGateway;
use App\Listeners\OutlookAddinSnapshotObserver;
use App\Models\EmployeeIdentityAccount;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Calls\LiveKitEgressGateway;
use App\Support\Calls\CallSettings;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
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
        $this->app->scoped(OutlookAddinUserSnapshotStore::class);
        $this->app->scoped(OutlookAddinSnapshotRefreshScheduler::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        User::observe(OutlookAddinSnapshotObserver::class);
        UserProfile::observe(OutlookAddinSnapshotObserver::class);
        EmployeeIdentityAccount::observe(OutlookAddinSnapshotObserver::class);
        Team::observe(OutlookAddinSnapshotObserver::class);

        // Administrierte Anruf-Betriebswerte ueber die .env-Vorgaben legen,
        // damit jedes bestehende config('livekit.…') sie ohne Anpassung sieht.
        CallSettings::apply();
    }
}
