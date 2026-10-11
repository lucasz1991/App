<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeeLocationPrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(strlen($request->getContent()) > 4096, 413, 'Standortmeldung ist zu groß.');
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
