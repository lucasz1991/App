<?php

namespace App\Support\Sound;

use App\Models\Setting;
use App\Models\User;

/**
 * Fuehrende Quelle fuer alle Ton-Schluessel.
 *
 * Die Signaturen werden in public/js/rt-sounds.js per Web Audio erzeugt (es
 * gibt bewusst keine Audiodateien). Diese Klasse haelt genau dieselben
 * Schluessel und ist dadurch die einzige Stelle, an der Ereignisse oder
 * Signaturen ergaenzt werden muessen. Ein Test vergleicht beide Listen.
 */
final class SoundLibrary
{
    /**
     * Zuordenbare Ereignisse mit ihrer Standardsignatur.
     *
     * @var array<string, string>
     */
    private const EVENTS = [
        // Rueckmeldungen der Oberflaeche (Toast-Typen aus resources/js/rt-alerts.js)
        'success' => 'success',
        'error' => 'error',
        'warning' => 'warning',
        'info' => 'info',
        // Eingaenge
        'message' => 'message',
        'chat' => 'chat',
        // Ein Anruf braucht ein vollstaendiges Klingelmotiv, keinen
        // Einzelanschlag – sonst ist er von einer Systemmeldung nicht zu
        // unterscheiden.
        'call' => 'ring',
    ];

    /**
     * Auswaehlbare Klangsignaturen.
     *
     * @var array<int, string>
     */
    private const SIGNATURES = [
        'success',
        'message',
        'chat',
        'error',
        'warning',
        'info',
        'bell',
        'ping',
        'marimba',
        'pulse',
        'doubleclick',
        'glass',
        'sweep',
        'descend',
        'ring',
        'ringback',
        'silent',
    ];

    /**
     * @return array<int, string>
     */
    public static function events(): array
    {
        return array_keys(self::EVENTS);
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return self::EVENTS;
    }

    /**
     * @return array<int, string>
     */
    public static function signatures(): array
    {
        return self::SIGNATURES;
    }

    public static function supportsEvent(string $event): bool
    {
        return array_key_exists($event, self::EVENTS);
    }

    public static function supportsSignature(string $signature): bool
    {
        return in_array($signature, self::SIGNATURES, true);
    }

    /** Uebersetzte Bezeichnung eines Ereignisses. */
    public static function eventLabel(string $event): string
    {
        return __('app.sound_event_'.$event);
    }

    /** Uebersetzte Bezeichnung einer Signatur. */
    public static function signatureLabel(string $signature): string
    {
        return __('app.sound_signature_'.$signature);
    }

    /**
     * Systemweite Standardzuordnung (Administrationsbereich).
     *
     * @return array<string, string>
     */
    public static function systemMap(): array
    {
        $map = [];

        foreach (self::EVENTS as $event => $fallback) {
            $stored = Setting::getValue('sounds', $event);
            $map[$event] = (is_string($stored) && self::supportsSignature($stored))
                ? $stored
                : $fallback;
        }

        return $map;
    }

    /**
     * Wirksame Zuordnung fuer einen Nutzer: Systemstandard, ueberschrieben von
     * den persoenlichen Einstellungen. Ohne Nutzer gilt der Systemstandard.
     *
     * @return array<string, string>
     */
    public static function mapFor(?User $user): array
    {
        $map = self::systemMap();

        if (! $user) {
            return $map;
        }

        foreach ($user->soundPreferences as $preference) {
            if (self::supportsEvent($preference->event) && self::supportsSignature($preference->signature)) {
                $map[$preference->event] = $preference->signature;
            }
        }

        return $map;
    }
}
