<?php

namespace App\Providers;

use App\Contracts\Calls\CallEgressGateway;
use App\Http\Middleware\EnsureCustomerPortalAccess;
use App\Listeners\OutlookAddinSnapshotObserver;
use App\Models\EmployeeIdentityAccount;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Calls\LiveKitEgressGateway;
use App\Support\Calls\CallSettings;
use App\Support\Database\CachedSchemaBuilder;
use App\Support\Database\SchemaIntrospectionCache;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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
        // Schema-Existenzfragen je Anfrage/Job merken; Schema::connection(...) bleibt ungemerkt.
        $this->app->scoped(SchemaIntrospectionCache::class);
        $this->app->extend('db.schema', fn ($builder, $app) => new CachedSchemaBuilder(
            $builder, $app->make(SchemaIntrospectionCache::class), (string) $app['db']->getDefaultConnection(),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        // Auch rohe DDL (DB::statement, Migrationen anderer Verbindungen) macht gemerkte Antworten ungültig.
        Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (SchemaIntrospectionCache::isSchemaChange($query->sql)) {
                $this->app->make(SchemaIntrospectionCache::class)->flush();
            }
        });

        Livewire::addPersistentMiddleware([EnsureCustomerPortalAccess::class]);

        User::observe(OutlookAddinSnapshotObserver::class);
        UserProfile::observe(OutlookAddinSnapshotObserver::class);
        EmployeeIdentityAccount::observe(OutlookAddinSnapshotObserver::class);
        Team::observe(OutlookAddinSnapshotObserver::class);

        // Administrierte Anruf-Betriebswerte ueber die .env-Vorgaben legen,
        // damit jedes bestehende config('livekit.…') sie ohne Anpassung sieht.
        CallSettings::apply();
    }
}
