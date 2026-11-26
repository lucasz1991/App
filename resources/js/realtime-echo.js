import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// ---------------------------------------------------------------
// Echtzeit (Laravel Reverb, Pusher-Protokoll). Nur aktiv, wenn ein
// Reverb-Key konfiguriert ist — ohne laufenden Reverb-Server faellt
// die App auf das 60s-Polling des Posteingangs zurueck.
// ---------------------------------------------------------------
function setupRealtimeEcho() {
    window.Pusher = Pusher;

    if (import.meta.env.VITE_REVERB_APP_KEY) {
        const configuredHost = String(import.meta.env.VITE_REVERB_HOST || '').trim();
        const browserHost = window.location.hostname;
        const loopbackHosts = new Set(['localhost', '127.0.0.1', '::1', '[::1]']);

        // `localhost` ist fuer Laravel auf dem Server korrekt, zeigt im Browser
        // eines Mitarbeiters aber auf dessen eigenen Rechner. Bei Zugriff ueber
        // LAN/Domain deshalb automatisch denselben Host wie die geoeffnete App
        // verwenden. So bleibt lokale Entwicklung unveraendert und entfernte
        // Clients landen nicht unbemerkt im langsameren Polling-Fallback.
        const reverbHost = loopbackHosts.has(configuredHost) && !loopbackHosts.has(browserHost)
            ? browserHost
            : (configuredHost || browserHost);

        window.Echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: reverbHost,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    }
}

export { setupRealtimeEcho };
