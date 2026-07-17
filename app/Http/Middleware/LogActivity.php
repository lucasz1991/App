<?php

namespace App\Http\Middleware;

use App\Jobs\LogActivityJob;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LogActivity
{
    /**
     * Protokolliert jeden Web-Request asynchron ins Activity-Log (Spatie).
     * Livewire-Update-Requests werden uebersprungen, um Rauschen zu vermeiden.
     */
    public function handle(Request $request, Closure $next)
    {
        $pathSlug = Str::slug($request->path());
        $isLivewireUpdate = ($pathSlug === 'livewireupdate');

        if (config('activitylog.enabled', true) && ! $isLivewireUpdate) {
            dispatch(new LogActivityJob($request->user(), [
                'method' => $request->method(),
                'path' => $request->path(),
                'full_url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ]));
        }

        return $next($request);
    }
}
