<?php

namespace App\Support\Ai;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Zugangsdaten und Modellprofile der OpenRouter-Anbindung.
 *
 * Der Umfang der erfassten Werte ist bewusst identisch zur AiUserFactory
 * (FollowFlow), damit eine spaetere KI-Anbindung in RailTime dieselbe
 * Konfiguration vorfindet. In dieser Ausbaustufe werden die Werte
 * ausschliesslich erfasst und gespeichert — es findet noch kein Aufruf
 * gegen OpenRouter statt.
 *
 * Sichtbar und aenderbar nur fuer den Super-Admin: Der API-Key ist ein
 * abrechnungsrelevantes Geheimnis, das kein weiterer Administrator
 * einsehen oder austauschen koennen soll.
 */
class OpenRouterSettings
{
    public const GROUP = 'services';

    public const KEY = 'openrouter';

    private const CACHE_KEY = 'settings.services.openrouter.values';

    /** Platzhalter, der einen bereits gespeicherten Schluessel in der Oberflaeche vertritt. */
    public const SECRET_MASK = '••••••••••••';

    /**
     * Verbindungswerte: Schluessel => Standardwert.
     *
     * @var array<string, string>
     */
    public const CONNECTION_FIELDS = [
        'api_url' => 'https://openrouter.ai/api/v1/chat/completions',
        'referer_url' => '',
        'model_title' => '',
    ];

    /**
     * Modellprofile: Schluessel => Beispielmodell (dient als Platzhalter).
     *
     * @var array<string, string>
     */
    public const MODEL_FIELDS = [
        'text_model' => 'openai/gpt-4o-mini',
        'data_model' => 'openai/gpt-4o',
        'image_generation_model' => 'openai/gpt-image-1',
        'image_understanding_model' => 'openai/gpt-4o',
        'speech_to_text_model' => 'openai/whisper-1',
        'text_to_speech_model' => 'x-ai/grok-voice-tts-1.0',
    ];

    /**
     * Laufzeitgrenzen: Schluessel => [Standard, Minimum, Maximum].
     *
     * @var array<string, array{0: int|float, 1: int|float, 2: int|float}>
     */
    public const RUNTIME_FIELDS = [
        'timeout' => [120, 5, 600],
        'temperature' => [0.4, 0.0, 2.0],
        'max_completion_tokens' => [1500, 1, 200000],
    ];

    /**
     * Gespeicherte Werte inklusive Standardwerten.
     *
     * @return array<string, mixed>
     */
    public static function all(bool $uncached = false): array
    {
        if ($uncached) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            $stored = [];

            try {
                $stored = (array) (Setting::getValueUncached(self::GROUP, self::KEY) ?? []);
            } catch (Throwable) {
                // Vor der ersten Migration oder bei Wartungsarbeiten an der DB
                // gelten schlicht die Standardwerte.
            }

            $values = [];

            foreach (self::CONNECTION_FIELDS as $key => $default) {
                $values[$key] = trim((string) ($stored[$key] ?? $default));
            }

            $values['api_key'] = trim((string) ($stored['api_key'] ?? ''));

            foreach (self::MODEL_FIELDS as $key => $default) {
                $values[$key] = trim((string) ($stored[$key] ?? ''));
            }

            foreach (self::RUNTIME_FIELDS as $key => [$default, $min, $max]) {
                $value = $stored[$key] ?? $default;
                $value = $key === 'temperature' ? (float) $value : (int) $value;
                $values[$key] = max($min, min($max, $value));
            }

            $values['stream_enabled'] = (bool) ($stored['stream_enabled'] ?? true);

            return $values;
        });
    }

    /**
     * Werte fuer die Oberflaeche — der API-Key wird nie im Klartext ausgeliefert.
     *
     * @return array<string, mixed>
     */
    public static function forForm(): array
    {
        $values = self::all(uncached: true);
        $values['api_key'] = $values['api_key'] === '' ? '' : self::SECRET_MASK;

        return $values;
    }

    /** Ist eine nutzbare Verbindung hinterlegt? */
    public static function isConfigured(): bool
    {
        $values = self::all();

        return $values['api_url'] !== '' && $values['api_key'] !== '';
    }

    /**
     * Werte speichern. Ein leerer oder maskierter API-Key laesst den
     * gespeicherten Schluessel unveraendert — sonst wuerde jedes Speichern
     * eines anderen Feldes das Geheimnis loeschen.
     *
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        $existing = [];

        try {
            $existing = (array) (Setting::getValueUncached(self::GROUP, self::KEY) ?? []);
        } catch (Throwable) {
            // Erster Schreibvorgang: es gibt schlicht noch keinen Bestand.
        }

        $clean = $existing;

        foreach (array_keys(self::CONNECTION_FIELDS) as $key) {
            if (array_key_exists($key, $values)) {
                $clean[$key] = trim((string) $values[$key]);
            }
        }

        foreach (array_keys(self::MODEL_FIELDS) as $key) {
            if (array_key_exists($key, $values)) {
                $clean[$key] = trim((string) $values[$key]);
            }
        }

        foreach (self::RUNTIME_FIELDS as $key => [, $min, $max]) {
            if (array_key_exists($key, $values)) {
                $value = $key === 'temperature' ? (float) $values[$key] : (int) $values[$key];
                $clean[$key] = max($min, min($max, $value));
            }
        }

        if (array_key_exists('stream_enabled', $values)) {
            $clean['stream_enabled'] = (bool) $values['stream_enabled'];
        }

        $submittedKey = trim((string) ($values['api_key'] ?? ''));

        if ($submittedKey !== '' && $submittedKey !== self::SECRET_MASK) {
            $clean['api_key'] = $submittedKey;
        } else {
            $clean['api_key'] = (string) ($existing['api_key'] ?? '');
        }

        Setting::setValue(self::GROUP, self::KEY, $clean);
        Cache::forget(self::CACHE_KEY);
    }
}
