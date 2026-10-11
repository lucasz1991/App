<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Privacy\SessionPermissionService;
use App\Support\Privacy\SessionActivity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TouchSessionPermissions
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() instanceof User && SessionActivity::isInteraction($request)) {
            app(SessionPermissionService::class)->touch($request->user());
        }

        return $next($request);
    }
}
