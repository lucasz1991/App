<?php

namespace App\Http\Controllers\OutlookAddin;

use App\Http\Controllers\Controller;
use App\Services\DeviceManagement\Desktop\OutlookDesktopPairingService;
use Illuminate\Http\Response;

/**
 * Brücke vom Outlook-Add-in zur RailTime-App: öffnet `railtime-client://pair?n=…` im Browser.
 * Die Seite verrät nichts über Konto oder Gerät und verbraucht die Anfrage nicht; entschieden
 * wird erst, wenn die App am PC bestätigt und der Server die Gerätezuweisung prüft.
 */
final class DesktopPairingPageController extends Controller
{
    public function __invoke(string $nonce, OutlookDesktopPairingService $pairings): Response
    {
        $valid = $pairings->pending($nonce);
        $cspNonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $response = response()->view('outlook-addin.desktop-pairing', [
            'valid' => $valid,
            'appLink' => $valid ? 'railtime-client://pair?n='.$nonce : null,
            'installUrl' => route('desktop-app.install'),
            'cspNonce' => $cspNonce,
        ], $valid ? 200 : 410);

        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'nonce-{$cspNonce}'; style-src 'nonce-{$cspNonce}'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

        return $response;
    }
}
