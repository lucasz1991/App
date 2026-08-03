<?php

return [
    'live_location' => [
        /*
        |------------------------------------------------------------------
        | Map tiles
        |------------------------------------------------------------------
        |
        | Leaflet only renders the map. The configured tile provider receives
        | ordinary tile requests for the visible map area, so installations
        | with stricter privacy requirements can point this at their own tile
        | service without changing the chat implementation.
        |
        */
        'tile_url' => env('CHAT_LIVE_LOCATION_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'tile_attribution' => env(
            'CHAT_LIVE_LOCATION_TILE_ATTRIBUTION',
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        ),
        'tile_max_zoom' => (int) env('CHAT_LIVE_LOCATION_TILE_MAX_ZOOM', 19),
    ],
];
