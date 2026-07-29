<?php

namespace App\Support\Calls;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Betriebswerte der Anruffunktion — administrierbar statt in der .env.
 *
 * Trennlinie, bewusst gezogen:
 *
 *  - HIER (aenderbar in den Admin-Einstellungen): reine Betriebsgroessen wie
 *    Klingeldauer oder Teilnehmerzahl. Sie wirken sofort, koennen nichts
 *    kaputtmachen und aendern sich im Alltag.
 *
 *  - IN DER .env (nur lesbar in der Oberflaeche): Adresse und Zugangsdaten des
 *    Media-Servers. Die muessen mit der `livekit.yaml` auf dem Server
 *    uebereinstimmen. Waeren sie hier editierbar, koennte ein Tippfehler die
 *    Telefonie lahmlegen — und die Korrektur ginge nur noch per SSH.
 *
 * Gelesen wird als ein einziger, gecachter Datensatz; `config/livekit.php`
 * bleibt die Fallback-Ebene, damit die App auch ohne DB-Eintraege laeuft.
 */
class CallSettings
{
    public const GROUP = 'calls';

    private const CACHE_KEY = 'settings.calls.values';

    /**
     * Einstellbare Werte: Schluessel => [config-Pfad, Minimum, Maximum].
     *
     * Die Grenzen sind keine Schikane: Eine Klingeldauer von 5 Sekunden ist
     * unbedienbar, eine von einer Stunde blockiert den Chat. Ein Token, das
     * kuerzer gilt als der Verbindungsaufbau dauert, macht Anrufe unmoeglich.
     */
    public const FIELDS = [
        'ring_timeout' => ['livekit.ring_timeout', 10, 600],
        'token_ttl' => ['livekit.token_ttl', 300, 21600],
        'empty_timeout' => ['livekit.empty_timeout', 15, 3600],
        'max_participants' => ['livekit.max_participants', 2, 100],
        'http_connect_timeout' => ['livekit.http_connect_timeout', 1, 30],
        'http_timeout' => ['livekit.http_timeout', 1, 60],
    ];

    /**
     * Gespeicherte Werte, auf die erlaubten Grenzen begrenzt.
     *
     * @return array<string, int>
     */
    public static function all(bool $uncached = false): array
    {
        if ($uncached) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            $stored = [];

            try {
                $stored = (array) (Setting::getValueUncached(self::GROUP, 'livekit') ?? []);
            } catch (Throwable) {
                // Vor der ersten Migration oder bei Wartungsarbeiten an der DB
                // gilt schlicht die .env — Anrufe bleiben funktionsfaehig.
            }

            $values = [];

            foreach (self::FIELDS as $key => [$configPath, $min, $max]) {
                $value = array_key_exists($key, $stored)
                    ? (int) $stored[$key]
                    : (int) config($configPath);

                $values[$key] = max($min, min($max, $value));
            }

            return $values;
        });
    }

    /** @param array<string, mixed> $values */
    public static function save(array $values): void
    {
        $clean = [];

        foreach (self::FIELDS as $key => [, $min, $max]) {
            if (array_key_exists($key, $values)) {
                $clean[$key] = max($min, min($max, (int) $values[$key]));
            }
        }

        Setting::setValue(self::GROUP, 'livekit', $clean);
        Cache::forget(self::CACHE_KEY);

        self::apply();
    }

    /**
     * Werte in die Laufzeitkonfiguration legen.
     *
     * Wird beim Booten aufgerufen, damit jeder bestehende
     * `config('livekit.…')`-Zugriff die administrierten Werte sieht, ohne dass
     * die Aufrufstellen angefasst werden muessten.
     */
    public static function apply(): void
    {
        foreach (self::all() as $key => $value) {
            config([self::FIELDS[$key][0] => $value]);
        }
    }

    /**
     * Infrastrukturwerte aus der .env — nur zur Anzeige.
     *
     * @return array<string, string>
     */
    public static function infrastructure(): array
    {
        $secret = (string) config('livekit.api_secret');

        return [
            'url' => (string) config('livekit.url'),
            'ws_url' => (string) config('livekit.ws_url'),
            'api_key' => (string) config('livekit.api_key'),
            // Nie vollstaendig anzeigen: verrieten wir das Secret in der
            // Oberflaeche, koennte jeder mit Bildschirmzugriff Tokens faelschen.
            'api_secret' => $secret === '' ? '' : substr($secret, 0, 4).str_repeat('•', 12),
            'turn_mode' => (string) config('livekit.turn.mode'),
        ];
    }
}
