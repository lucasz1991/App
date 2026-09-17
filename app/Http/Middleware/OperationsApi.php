<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class OperationsApi
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        abort_unless(config('operations.api_enabled', false), 503, 'RailTime API ist nicht aktiviert.');
        abort_if(app()->environment('production') && ! $request->isSecure(), 400, 'HTTPS erforderlich.');
        abort_if(strlen($request->getContent()) > 65536, 413);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
