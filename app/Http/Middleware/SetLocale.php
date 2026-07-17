<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

class SetLocale
{
    /**
     * Set the locale for the incoming request based on session value.
     */
    public function handle(Request $request, Closure $next)
    {
        $supportedLocales = Config::get('app.supported_locales', []);
        $defaultLocale = Config::get('app.locale');
        $fallbackLocale = Config::get('app.fallback_locale', $defaultLocale);

        $locale = session('locale', $defaultLocale);

        if (!in_array($locale, $supportedLocales, true)) {
            $locale = in_array($fallbackLocale, $supportedLocales, true)
                ? $fallbackLocale
                : $defaultLocale;

            session(['locale' => $locale]);
        }

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
