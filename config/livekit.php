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
    | fuer die Browser-Clients. Details:
    | .lmzdev/media-server-livekit-integration/UMSETZUNGSPLAN.md.
    |
    */

    'url' => env('LIVEKIT_URL', 'https://livekit.rail-time.de'),
    'ws_url' => env('LIVEKIT_WS_URL', 'wss://livekit.rail-time.de'),
    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),

    // Gueltigkeit der Beitritts-Tokens in Sekunden (Standard: 1 Stunde).
    // Bewusst kurz: die SFU kann unsere DB nicht befragen, ein einmal
    // ausgestelltes Token bleibt also bis zum Ablauf gueltig – auch fuer
    // Teilnehmer, die inzwischen entfernt wurden. Der Client holt sich
    // unmittelbar vor room.connect() ohnehin immer ein frisches Token.
    'token_ttl' => (int) env('LIVEKIT_TOKEN_TTL', 3600),

    // Klingeldauer in Sekunden, danach gilt der Anruf als verpasst.
    // 3 Minuten: genug Zeit, das Telefon aus der Tasche zu holen — der
    // Web-Push-TTL folgt diesem Fenster automatisch.
    'ring_timeout' => (int) env('CALL_RING_TIMEOUT', 180),

    // Leere Raeume raeumt LiveKit nach dieser Zeit selbststaendig ab.
    'empty_timeout' => (int) env('LIVEKIT_EMPTY_TIMEOUT', 120),

    'max_participants' => (int) env('LIVEKIT_MAX_PARTICIPANTS', 15),

    // HTTP-Grenzen fuer die Server-API. Ohne sie erbt der Twirp-Client einen
    // Guzzle ohne jedes Timeout – ein haengender Media-Server wuerde dann
    // PHP-FPM-Worker bis zum PHP-Timeout binden (siehe
    // App\Services\Calls\TimeoutAwareRoomServiceClient).
    'http_connect_timeout' => (float) env('LIVEKIT_HTTP_CONNECT_TIMEOUT', 2.0),
    'http_timeout' => (float) env('LIVEKIT_HTTP_TIMEOUT', 5.0),

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
