<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // LiveKit signiert seine Webhooks per JWT im Authorization-Header;
        // die Pruefung uebernimmt der LiveKitWebhookController.
        'webhooks/livekit',
        // Klingel-Rueckmeldung aus dem Service Worker: der hat kein CSRF-Token
        // (kein DOM, keine Blade-Ausgabe). Autorisiert wird ueber die signierte
        // URL, die ausschliesslich in der Push-Nutzlast steht.
        'calls/ring/*',
    ];
}
