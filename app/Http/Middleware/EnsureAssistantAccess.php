<?php

namespace App\Http\Middleware;

use App\Support\Ai\AssistantAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAssistantAccess
{
    public function __construct(
        private readonly AssistantAccess $assistantAccess,
        private readonly ThrottleRequests $throttle,
    ) {}

    public function handle(Request $request, Closure $next, string $limiterName): Response
    {
        $this->assistantAccess->authorize($request->user());

        // Der benannte Limiter wird bewusst erst nach der Assistenzfreigabe
        // ausgefuehrt. Andernfalls koennten nicht berechtigte Konten die
        // globalen STT-/TTS-Kontingente fuer berechtigte Nutzer verbrauchen.
        return $this->throttle->handle($request, $next, $limiterName);
    }
}
