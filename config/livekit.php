<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LiveKit-Media-Server
    |--------------------------------------------------------------------------
    |
    | Reverb uebernimmt ausschliesslich Signalisierung und Benachrichtigungen;
    | saemtliche Medienstroeme laufen ueber den LiveKit-Server (SFU). `url` ist
    | die Server-API (Twirp/HTTP) fuer Laravel, `ws_url` der WebSocket-Endpunkt
    | fuer die Browser-Clients. Details: LIVEKIT_INTEGRATION_PLAN.md.
    |
    */

    'url' => env('LIVEKIT_URL', 'https://livekit.rail-time.de'),
    'ws_url' => env('LIVEKIT_WS_URL', 'wss://livekit.rail-time.de'),
    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),

    // Gueltigkeit der Beitritts-Tokens in Sekunden (Standard: 6 Stunden).
    'token_ttl' => (int) env('LIVEKIT_TOKEN_TTL', 21600),

    // Klingeldauer in Sekunden, danach gilt der Anruf als verpasst.
    'ring_timeout' => (int) env('CALL_RING_TIMEOUT', 45),

    // Leere Raeume raeumt LiveKit nach dieser Zeit selbststaendig ab.
    'empty_timeout' => (int) env('LIVEKIT_EMPTY_TIMEOUT', 120),

    'max_participants' => (int) env('LIVEKIT_MAX_PARTICIPANTS', 15),

    /*
    |--------------------------------------------------------------------------
    | TURN-Fallback
    |--------------------------------------------------------------------------
    |
    | `embedded`: das in LiveKit eingebaute TURN wird genutzt, Clients brauchen
    | keine zusaetzlichen ICE-Server. `coturn`: der Token-Endpoint liefert die
    | hier hinterlegten coturn-Zugangsdaten als ICE-Server an die Clients aus.
    |
    */

    'turn' => [
        'mode' => env('LIVEKIT_TURN_MODE', 'embedded'),
        'url' => env('TURN_URL'),
        'username' => env('TURN_USERNAME'),
        'credential' => env('TURN_CREDENTIAL'),
    ],

];
