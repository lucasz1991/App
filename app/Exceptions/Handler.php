<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Session\TokenMismatchException;
use Throwable;
use Illuminate\Support\Facades\Log;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            Log::error($e->getMessage(), ['exception' => $e]);
        });

        // 404 Fehler (Seite nicht gefunden)
        $this->renderable(function (NotFoundHttpException $e, $request) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Seite nicht gefunden. Die angeforderte Seite existiert nicht.'], 404)
                : response()->view('errors.404', [], 404);
        });

        // 419 Fehler (Token abgelaufen)
        $this->renderable(function (TokenMismatchException $e, $request) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'], 419)
                : response()->view('errors.419', [], 419);
        });

        // 403 Fehler (Keine Berechtigung)
        $this->renderable(function (AuthorizationException $e, $request) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Du hast keine Berechtigung, auf diese Ressource zuzugreifen.'], 403)
                : response()->view('errors.403', [], 403);
        });

        // 405 Fehler (Methode nicht erlaubt)
        $this->renderable(function (HttpException $e, $request) {
            if ($e->getStatusCode() === 405) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Die verwendete Methode ist nicht erlaubt.'], 405)
                    : response()->view('errors.405', [], 405);
            }
        });
    }
}
