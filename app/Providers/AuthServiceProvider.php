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

        foreach (RbacCatalog::allPermissions() as $permission) {
            Gate::define($permission, static function ($user) use ($permission): bool {
                return $user->hasRbacPermission($permission);
            });
        }

        Auth::provider('user_auth', function ($app, array $config) {
            return new UserAuthProvider($app['hash'], $config['model']);
        });
    }
}
