<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entfernt einen unbrauchbaren X-Socket-ID-Header.
 *
 * Laravel Echo schickt den Header mit, damit `broadcast(...)->toOthers()` den
 * ausloesenden Client ueberspringen kann. Ist Echo nicht verbunden – etwa weil
 * der WebSocket-Proxy fehlt, das Netz blockt oder die Verbindung gerade
 * abgerissen ist – sendet der Browser die Zeichenfolge "undefined" oder "null".
 * Der Pusher-Treiber validiert den Wert und wirft dann eine PusherException;
 * die Aktion endet fuer den Nutzer in einem 500er.
 *
 * Genau das hat den Anruf-Button lahmgelegt: `startCall()` broadcastet mit
 * `toOthers()`, und ohne erreichbaren Reverb war die Socket-ID "undefined".
 * Ein fehlender Header ist harmlos – dann erhaelt der Ausloeser sein eigenes
 * Ereignis zusaetzlich, was die Oberflaeche ohnehin vertraegt. Ein kaputter
 * Header darf dagegen nie eine Aktion abbrechen.
 */
class SanitizeBroadcastSocketId
{
    /** Pusher-Format: "<zahl>.<zahl>" */
    private const SOCKET_ID_PATTERN = '/^\d+\.\d+$/';

    public function handle(Request $request, Closure $next): Response
    {
        $socketId = $request->headers->get('X-Socket-ID');

        if ($socketId !== null && ! preg_match(self::SOCKET_ID_PATTERN, $socketId)) {
            $request->headers->remove('X-Socket-ID');
        }

        return $next($request);
    }
}
