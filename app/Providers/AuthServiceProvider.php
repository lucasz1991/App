<?php

namespace App\Providers;

use App\Auth\UserAuthProvider;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(static function ($user): ?bool {
            if (! $user) {
                return null;
            }

            return $user->isAdmin() ? true : null;
        });

        // Benutzerkonten loeschen ist bewusst keine delegierbare Team-
        // Berechtigung. Diese Aktion bleibt ausschliesslich globalen Admins
        // vorbehalten, auch wenn ein Team sonst Benutzer bearbeiten darf.
        Gate::define('employees.delete', static function ($user): bool {
            return $user->isAdmin();
        });

        foreach (RbacCatalog::allPermissions() as $permission) {
            Gate::define($permission, static function ($user) use ($permission): bool {
                return $user->hasRbacPermission($permission);
            });
        }

        // Destructive wipe and provider configuration are deliberately absent
        // from the delegable team RBAC catalog. Even if similarly named team
        // values exist, only a global RailTime administrator may pass them.
        Gate::define('devices.wipe', static function ($user): bool {
            return $user->isAdmin();
        });
        Gate::define('devices.providers.manage', static function ($user): bool {
            return $user->isAdmin();
        });

        Auth::provider('user_auth', function ($app, array $config) {
            return new UserAuthProvider($app['hash'], $config['model']);
        });
    }
}
