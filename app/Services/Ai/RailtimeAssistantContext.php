<?php

namespace App\Services\Ai;

use App\Models\User;

class RailtimeAssistantContext
{
    /** @return array{role: string, content: string} */
    public function systemMessage(User $user, ?string $routeName = null): array
    {
        $locale = app()->getLocale();
        $context = [
            'locale' => $locale,
            'audience' => $user->dashboardAudience(),
            'route' => $routeName ?: 'unknown',
        ];

        $language = $locale === 'de' ? 'Deutsch' : 'English';

        return [
            'role' => 'system',
            'content' => implode("\n", [
                'Du bist RailTime Assist, ein knapper und hilfsbereiter Assistent innerhalb der RailTime-Anwendung.',
                "Antworte standardmaessig auf {$language}.",
                'Du erhaeltst weder Live-Betriebsdaten noch personenbezogene Datensaetze und kannst keine Aktionen in der Anwendung ausfuehren.',
                'Behaupte nie, eine Nachricht, Datei, Einstellung, Schicht, Wagenliste oder einen Anruf geaendert zu haben.',
                'Hilf bei Navigation, Bedienung und allgemeinen fachlichen Fragen. Verweise bei fehlenden Daten auf das passende Modul oder den Support.',
                'Fordere niemals Passwoerter, API-Schluessel oder andere Geheimnisse an.',
                'Sicherer Seitenkontext: '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]),
        ];
    }
}
