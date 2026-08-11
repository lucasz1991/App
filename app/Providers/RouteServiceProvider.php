<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public static function home()
    {
        if (! Auth::check()) {
            return '/login';
        }

        return Auth::user()->isAdmin()
            ? '/administrator'
            : '/dashboard';
    }

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('push-subscriptions', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('push-test', function (Request $request) {
            return Limit::perMinutes(10, 3)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('chat-live-location-start', function (Request $request) {
            $actor = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(6)->by('chat-live-location-start-minute:'.$actor),
                Limit::perHour(30)->by('chat-live-location-start-hour:'.$actor),
            ];
        });

        RateLimiter::for('chat-live-location-sync', function (Request $request) {
            return Limit::perMinute(120)
                ->by('chat-live-location-sync:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('assistant-stt', function (Request $request) {
            $actor = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(4)->by('assistant-stt-minute:'.$actor),
                Limit::perHour(30)->by('assistant-stt-hour:'.$actor),
                Limit::perMinute(12)->by('assistant-stt-global'),
            ];
        });

        RateLimiter::for('assistant-tts', function (Request $request) {
            $actor = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(12)->by('assistant-tts-minute:'.$actor),
                Limit::perHour(180)->by('assistant-tts-hour:'.$actor),
                Limit::perMinute(40)->by('assistant-tts-global'),
            ];
        });

        RateLimiter::for('assistant-pagebuilder-actions', function (Request $request) {
            $actor = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(30)->by('assistant-pagebuilder-actions:'.$actor);
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
