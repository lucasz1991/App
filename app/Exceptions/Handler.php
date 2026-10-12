<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'code',
        'recovery_code',
        'two_factor_secret',
        'two_factor_pending_secret',
        'two_factor_recovery_codes',
        'latitude',
        'longitude',
        'position',
        'altitude',
        'heading',
        'speed',
        'permit_id',
        'session_id',
        'token',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if ($this->isLocationRequest(request())) {
                // Database/transport errors can contain bound location values
                // or device credentials. Keep only the failure type.
                Log::error('Standortanfrage fehlgeschlagen.', ['error_class' => $e::class]);

                return false;
            }
            Log::error($e->getMessage(), ['exception' => $e]);
        });

        // Die HTML-Darstellung uebernimmt bewusst Laravel selbst: es loest
        // errors/{status} und danach errors/4xx bzw. errors/5xx auf (siehe
        // Foundation\Exceptions\Handler::getHttpExceptionView). Deshalb geben
        // diese Closures fuer HTML null zurueck — dann laeuft die
        // Standardbehandlung weiter. Vorher zeigte jede Closure explizit auf
        // eine eigene View; bei 403 existierte diese View gar nicht, was aus
        // einem 403 einen 500 gemacht hat.
        //
        // JSON-Antworten behalten ihre deutschen Meldungen.

        // 404 (Seite nicht gefunden)
        $this->renderable(function (NotFoundHttpException $e, $request) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Seite nicht gefunden. Die angeforderte Seite existiert nicht.'], 404)
                : null;
        });

        // 419 (Sitzung/Token abgelaufen). Laravel bildet TokenMismatchException
        // selbst auf HttpException(419) ab, wodurch errors/419 greift.
        $this->renderable(function (TokenMismatchException $e, $request) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'], 419)
                : null;
        });

        // 403 (keine Berechtigung)
        $this->renderable(function (AuthorizationException $e, $request) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Sie haben keine Berechtigung, auf diese Ressource zuzugreifen.'], 403)
                : null;
        });

        // 405 (Methode nicht erlaubt)
        $this->renderable(function (HttpException $e, $request) {
            if ($e->getStatusCode() === 405 && $request->expectsJson()) {
                return response()->json(['message' => 'Die verwendete Methode ist nicht erlaubt.'], 405);
            }

            return null;
        });
    }

    public function render($request, Throwable $e)
    {
        if (! $this->isLocationRequest($request)) {
            return parent::render($request, $e);
        }

        // Location endpoints are JSON-only, even when an invalid caller omits
        // Accept. Never flash submitted points to a browser session.
        $request->headers->set('Accept', 'application/json');
        $response = parent::render($request, $e);
        if ($response->getStatusCode() >= 500) {
            $response = response()->json(['message' => 'Die Standortanfrage konnte nicht verarbeitet werden.'], $response->getStatusCode());
        }
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function isLocationRequest(Request $request): bool
    {
        return $request->is('chat/live-locations/*', 'chat/*/live-locations', 'api/employee-location/*', 'employee-location/browser-*');
    }
}
