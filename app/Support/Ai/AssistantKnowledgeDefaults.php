<?php

namespace App\Support\Ai;

final class AssistantKnowledgeDefaults
{
    public const REVISION = '2026-08-01.1';

    /** @var array<int, string> */
    public const SOURCE_HOSTS = ['rail-time.de', 'www.rail-time.de'];

    public static function isAllowedSourceUrl(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || $value !== trim($value) || strlen($value) > 2048) {
            return false;
        }

        if (
            preg_match('/[\x00-\x20\x7F]/', $value) === 1
            || preg_match('/%(?:0a|0d)/i', $value) === 1
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }

        $parts = parse_url($value);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        return $scheme === 'https'
            && in_array($host, self::SOURCE_HOSTS, true)
            && ($port === null || $port === 443)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    /**
     * Curated from the versioned RailTime portal and WebsiteFinal sources.
     * Public facts deliberately exclude unfinished legal placeholders.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function topics(): array
    {
        return [
            [
                'source_key' => 'defaults.assistant',
                'name' => 'RailTime Assist',
                'description' => 'Bedienung, Grenzen, Sprache, Dateien und Zusammenspiel von Prompt, Regeln und Wissenspool.',
                'sort_order' => 10,
                'entries' => [
                    [
                        'source_key' => 'defaults.assistant.usage',
                        'title' => 'Chatbot verwenden',
                        'summary' => 'RailTime Assist beantwortet kurze Bedienfragen, erklärt Abläufe, unterstützt bei der Navigation und kann erlaubte Anhänge analysieren. Diktate werden nur in das Eingabefeld übernommen und nie automatisch gesendet.',
                        'content' => "RailTime Assist steht als rotes Pet unten rechts zur Verfügung. Ein Klick öffnet den Assistenten; Text, Mikrofon und Dateianhang liegen im Eingabebereich.\n\nDie Spracheingabe überträgt das erkannte Diktat ausschließlich in den Entwurf. Erst die bewusste Sendeaktion startet eine Chat-Anfrage. Automatisches Vorlesen und automatisches Hören sind gerätebezogene Optionen und standardmäßig ausgeschaltet.\n\nErlaubte Anhänge sind begrenzte Text-, PDF-, Bild- und moderne Office-Dateien. Inhalte werden als nicht vertrauenswürdige Benutzerdaten behandelt. RailTime Assist erklärt und unterstützt, darf aber keine nicht freigegebenen Aktionen oder Live-Daten behaupten.",
                        'keywords' => ['chatbot', 'assist', 'sprache', 'diktieren', 'vorlesen', 'anhang', 'datei', 'bedienung'],
                        'include_in_baseline' => true,
                        'sort_order' => 10,
                    ],
                    [
                        'source_key' => 'defaults.assistant.knowledge-rules',
                        'title' => 'Default-Prompt, Regeln und Wissenspool',
                        'summary' => 'Der Default-Prompt legt Ton und Kürze fest, verbindliche Regeln steuern das Verhalten und der Wissenspool liefert redaktionelle Referenzinformationen. Plattform-Sicherheitsregeln haben immer Vorrang.',
                        'content' => "Der Superadmin pflegt im Systembereich den Default-Prompt und die verbindlichen Regeln. Der Standard verlangt möglichst knappe Antworten mit höchstens zehn Zeilen und bevorzugt drei bis vier kurze Sätze.\n\nDer kompakte Basiskontext enthält nur ausgewählte Kurzinfos. Ausführliche Einträge ruft RailTime Assist bei Bedarf über die serverseitig freigegebene Wissenssuche ab. Wissenseinträge sind Referenzdaten und können weder Systemregeln überschreiben noch eigenständig Aktionen auslösen.\n\nBei fehlendem oder unsicherem Wissen muss der Assistent dies offen sagen und auf das passende Modul oder den Support verweisen. Er darf keine internen Abläufe, Zuständigkeiten oder Live-Daten erfinden.",
                        'keywords' => ['default prompt', 'standardprompt', 'regeln', 'wissenspool', 'wissen', 'systemprompt', 'kurz'],
                        'include_in_baseline' => true,
                        'sort_order' => 20,
                    ],
                    [
                        'source_key' => 'defaults.assistant.speech-privacy',
                        'title' => 'Sprachdienst und Datenschutz',
                        'summary' => 'Sprache läuft standardmäßig über den gemeinsamen lokalen Dienst; ein externer Fallback ist nur im freigegebenen Routingmodus und bei technischen Ausfällen zulässig.',
                        'content' => "STT und TTS werden standardmäßig über den gemeinsamen, nur serverseitig erreichbaren lokalen Whisper-/Piper-Dienst verarbeitet. Der Superadmin kann alternativ nur lokal, lokal mit technischem OpenRouter-Fallback oder nur extern wählen.\n\nEin externer Fallback ist nicht bei Berechtigungs-, Validierungs-, Größen-, Rate-Limit- oder Zugangsdatenfehlern erlaubt. Wenn OpenRouter verwendet wird, können das aufgenommene Audio beziehungsweise der vorzulesende Text an diesen Dienst übertragen werden.\n\nMikrofonaufnahmen enden nach spätestens 45 Sekunden. Schließen, Navigation, ein verborgener Tab oder das Abschalten der Funktion bricht Aufnahme, Upload und geplante automatische Starts ab.",
                        'keywords' => ['stt', 'tts', 'whisper', 'piper', 'openrouter', 'datenschutz', 'mikrofon'],
                        'include_in_baseline' => false,
                        'sort_order' => 30,
                    ],
                ],
            ],
            [
                'source_key' => 'defaults.portal',
                'name' => 'RailTime Portal',
                'description' => 'Rollenbezogene Navigation und die wichtigsten Arbeitsbereiche des internen Portals.',
                'sort_order' => 20,
                'entries' => [
                    [
                        'source_key' => 'defaults.portal.navigation',
                        'title' => 'Navigation und Seitensteuerung',
                        'summary' => 'Der Assistent kann freigegebene RailTime-Seiten öffnen und den sicheren Seitenstatus erklären. Er verwendet ausschließlich serverseitig erlaubte Ziele und behauptet keinen Zugriff auf unbekannte Live-Daten.',
                        'content' => "Das Dashboard ist der rollenbezogene Einstieg. Weitere freigegebene Ziele umfassen je nach Konto unter anderem Nachrichten, Chat, Dateien, Anrufe, Meetings, Wagenliste, E-Mail-Vorlagen, Hilfe, Support und administrative Betriebsbereiche.\n\nSeitentools akzeptieren nur stabile RailTime-Schlüssel aus einer serverseitigen Allowlist. Freie URLs, Skripte, externe Ziele und nicht berechtigte Adminseiten werden abgelehnt. Der Seitenstatus beschreibt Route, Verfügbarkeit und den redaktionellen Hilfetext; er ist kein Beleg für aktuelle Betriebs- oder Personendaten.\n\nEine Navigation wird erst im Browser ausgeführt. Der Assistent darf sie vor dem bestätigten Toolergebnis nicht als abgeschlossen darstellen.",
                        'keywords' => ['navigation', 'seite öffnen', 'seitenstatus', 'dashboard', 'tool', 'allowlist'],
                        'include_in_baseline' => true,
                        'sort_order' => 10,
                    ],
                    [
                        'source_key' => 'defaults.portal.communication-files',
                        'title' => 'Kommunikation und Dateien',
                        'summary' => 'Nachrichten, Chat, Anrufe, Meetings und Dateien sind getrennte Portalbereiche und folgen den jeweiligen Rollen- und Teamrechten.',
                        'content' => "Nachrichten dienen Ankündigungen und internen Mitteilungen. Der Chat unterstützt Direkt- und Gruppengespräche mit Medien und Sprachnachrichten. Anrufe und Meetings verwenden eigene geschützte Räume; Mikrofon und Kamera werden dort erst nach Freigabe genutzt.\n\nDer Dateimanager organisiert Ordner und Dateien, Vorschau und Download bleiben bewusste Aktionen. Verbindliche Dokumente werden versioniert und können eine Kenntnisnahme verlangen.\n\nErstellen, Ändern, Löschen, Senden und Freigeben bleiben durch die vorhandenen Rollen-, Team- und Serverprüfungen geschützt. Der Assistent darf diese Rechte nicht umgehen.",
                        'keywords' => ['nachrichten', 'chat', 'anruf', 'meeting', 'dateien', 'dokumente', 'rechte'],
                        'include_in_baseline' => false,
                        'sort_order' => 20,
                    ],
                    [
                        'source_key' => 'defaults.portal.help-support',
                        'title' => 'Hilfe und Support',
                        'summary' => 'Die Hilfeseite erklärt RailTime-Funktionen nach Bereichen. Bleibt ein Ablauf unklar oder tritt ein Fehler auf, führt der Supportbereich zu einer nachvollziehbaren Anfrage ohne Kennwörter oder Zugangsdaten.',
                        'content' => "Die Hilfeseite bündelt Einführung, Suchthemen und Schritt-für-Schritt-Hinweise für die vorhandenen Portalbereiche. Der Assistent kann diese Seite öffnen oder eine passende Kurzbeschreibung zur aktuellen Route liefern.\n\nEine Supportanfrage sollte Ziel, betroffenen Arbeitsschritt, erwartetes Ergebnis, beobachtetes Ergebnis, genaue Fehlermeldung und Zeitpunkt enthalten. Kennwörter, API-Schlüssel oder andere vertrauliche Zugangsdaten gehören niemals in Chat oder Supporttext.",
                        'keywords' => ['hilfe', 'support', 'fehler', 'einführung', 'anleitung'],
                        'include_in_baseline' => true,
                        'sort_order' => 30,
                    ],
                    [
                        'source_key' => 'defaults.portal.operations',
                        'title' => 'Kunden, Aufträge, Schichten und Kalender',
                        'summary' => 'RailTime verwaltet Kunden, Aufträge, Schichten, Mitarbeiterzuweisungen und den internen Wochenkalender als gemeinsame betriebliche Quelle.',
                        'content' => "Der Kundenstamm ist die Grundlage für Aufträge. Statuswechsel eines Auftrags werden mit Zeitpunkt und auslösender Person protokolliert. Schichten gehören zu Aufträgen und verwenden UTC-Zeitpunkte mit erhaltener IANA-Zeitzone.\n\nAngefragte und bestätigte Mitarbeiterzuweisungen verhindern zeitliche Überschneidungen derselben Person. Der interne Wochenkalender liest die gespeicherten RailTime-Schichten. Externe Google-, Apple- oder ICS-Synchronisation ist im aktuellen Grundgerüst nicht enthalten.",
                        'keywords' => ['kunden', 'aufträge', 'schichten', 'kalender', 'einsatzplanung', 'zuweisung'],
                        'include_in_baseline' => false,
                        'sort_order' => 40,
                    ],
                ],
            ],
            [
                'source_key' => 'defaults.wagon-list',
                'name' => 'Wagenlisten',
                'description' => 'Lokale Entwürfe, geführte Datenerfassung, Sprachsteuerung, Prüfung und Export.',
                'sort_order' => 30,
                'entries' => [
                    [
                        'source_key' => 'defaults.wagon-list.workflow',
                        'title' => 'Wagenliste erfassen',
                        'summary' => 'Wagenlisten werden als lokale Entwürfe erfasst. Der geführte Ablauf umfasst Zugdaten, bis zu 40 Wagen, Bremsangaben, besondere Informationen, Prüfung und Excel-Export.',
                        'content' => "Neue und bestehende Wagenlisten liegen geräte- und benutzerbezogen im Browser. Während der Bearbeitung wird der Entwurf automatisch lokal gespeichert; Speichern und Schließen hält den aktuellen Stand fest.\n\nDie Erfassung beginnt mit Zugnummer, Datum, Start, Ziel und Referenz. Danach folgen je Wagen die zwölfstellige Wagennummer, Gattung, Achsen, Länge, Gewichte, Bremsdaten, Versand- und Zielbahnhof sowie Bemerkungen. Das Bremsblatt ergänzt Triebfahrzeug-, Bremsprozent- und Sonderangaben.\n\nVor Abschluss werden Zugdaten und ausgefüllte Wagen geprüft. Der Excel-Export verwendet den aktuellen validierten Entwurf; er ist keine zentrale Datenbankfreigabe.",
                        'keywords' => ['wagenliste', 'zugdaten', 'wagen', 'bremszettel', 'entwurf', 'export'],
                        'include_in_baseline' => true,
                        'sort_order' => 10,
                    ],
                    [
                        'source_key' => 'defaults.wagon-list.voice-guide',
                        'title' => 'Sprachgeführte Wagenlisteneingabe',
                        'summary' => 'Auf der Wagenlistenseite kann RailTime Assist den Mitarbeiter Schritt für Schritt befragen, ausdrücklich genannte Werte in erlaubte Formularfelder übernehmen und danach den nächsten fehlenden Schritt anbieten.',
                        'content' => "Ist Automatisches Helfen aktiv, bietet das Pet beim Öffnen des Wagenlistenformulars die geführte Eingabe an. Der Mitarbeiter kann antworten oder diktieren; ein Diktat wird weiterhin nie selbstständig als Chatnachricht gesendet.\n\nDer Assistent darf nur Werte übernehmen, die der Mitarbeiter in der aktuellen Unterhaltung eindeutig genannt oder bestätigt hat. Freie Skripte, unbekannte Felder und Daten außerhalb des aktuellen Wagenlistenentwurfs sind nicht zulässig. Nach jeder Übernahme meldet der Browser den lokalen Formularstatus zurück.\n\nDie Führung fragt zuerst nach den Zugdaten, dann nach den Daten des ausgewählten Wagens und anschließend nach Bremsblatt und Abschluss. Der Mitarbeiter kann jederzeit überspringen, korrigieren, speichern oder die Führung beenden.",
                        'keywords' => ['wagenliste', 'sprachsteuerung', 'diktieren', 'geführt', 'formular', 'automatisch helfen'],
                        'include_in_baseline' => true,
                        'sort_order' => 20,
                    ],
                    [
                        'source_key' => 'defaults.wagon-list.validation',
                        'title' => 'Wagenfelder und Prüfgrenzen',
                        'summary' => 'Formularwerte werden nur über bekannte Feldschlüssel und begrenzte Datentypen übernommen; die Prüfziffer und berechnete Summen bleiben Aufgabe des Wagenlisten-Controllers.',
                        'content' => "Der Assistent kann Zugkopfdaten, die vollständige Wagennummer, Wagen-Gattung, Achszahlen, Längen und Gewichte, Bremsart und Bremsgewichte, Bahnhöfe, Geschwindigkeit, Feststellbremse und Bemerkung an den Browser übergeben.\n\nNumerische Werte dürfen nicht negativ sein. Textwerte sind längenbegrenzt, Ja/Nein-Felder akzeptieren nur definierte Werte und der Wagenindex muss innerhalb der sichtbaren Wagen liegen. Die Wagennummer wird in die vorhandenen Gruppen zerlegt; die bestehende Prüfziffernlogik bewertet sie anschließend.\n\nAutomatische Berechnungen, Validierungen und Local-Storage-Speicherung bleiben im vorhandenen Wagenlisten-Controller. Der Assistent umgeht diese Logik nicht und behauptet bei einer abgelehnten Eingabe keine Speicherung.",
                        'keywords' => ['wagenfelder', 'prüfung', 'prüfziffer', 'validierung', 'localstorage'],
                        'include_in_baseline' => false,
                        'sort_order' => 30,
                    ],
                ],
            ],
            [
                'source_key' => 'defaults.company',
                'name' => 'RT Rail Time GmbH',
                'description' => 'Kuratierte öffentliche Unternehmens- und Leistungsinformationen aus dem versionierten Website-Paket.',
                'sort_order' => 40,
                'entries' => [
                    [
                        'source_key' => 'defaults.company.profile',
                        'source_url' => 'https://www.rail-time.de/ueber-uns',
                        'title' => 'Unternehmensprofil',
                        'summary' => 'RT Rail Time GmbH ist ein bundesweit tätiger Partner für Wagenmeister-Dienstleistungen im Eisenbahngüterverkehr. Das redaktionelle Website-Paket nennt mehr als 60 erfahrene Wagenmeister und einen 24/7-Notfalldienst.',
                        'content' => "Stand des kuratierten Website-Pakets: 1. August 2026. Die RT Rail Time GmbH beschreibt sich als kompetenten und flexiblen Partner für professionelle Wagenmeister-Dienstleistungen im Eisenbahngüterverkehr. Im Mittelpunkt stehen Sicherheit, Qualität, transparente Kommunikation und kurze Reaktionszeiten.\n\nDas Paket nennt mehr als 60 erfahrene Wagenmeister, bundesweite Einsätze und einen rund um die Uhr erreichbaren Notfalldienst. Diese Angaben sind öffentliche Unternehmensinformationen, keine Aussage zur aktuellen Verfügbarkeit eines bestimmten Mitarbeiters oder Einsatzes.",
                        'keywords' => ['unternehmen', 'rt rail time', 'wagenmeister', 'bundesweit', '60', '24/7'],
                        'include_in_baseline' => true,
                        'sort_order' => 10,
                    ],
                    [
                        'source_key' => 'defaults.company.services',
                        'source_url' => 'https://www.rail-time.de/leistungen',
                        'title' => 'Öffentliche Leistungen',
                        'summary' => 'Die fünf veröffentlichten Leistungsbereiche sind Wagenmeister-Dienstleistungen, Notfalldienst 24/7, KV- und Trailer-Service, Sonderuntersuchungen sowie das Schmieren von Puffertellern.',
                        'content' => "Das redaktionelle Website-Paket führt fünf Leistungsbereiche auf:\n1. Wagenmeister-Dienstleistungen mit professionellen Prüfungen und dokumentierten Ergebnissen.\n2. Notfalldienst 24/7 für kurzfristige technische Unregelmäßigkeiten.\n3. KV- und Trailer-Service für Auffälligkeiten an verladenen LKW-Trailern.\n4. Sonderuntersuchungen mit technischer Befundaufnahme und abgestimmtem Vorgehen.\n5. Fachgerechtes Schmieren von Puffertellern am Einsatzort.\n\nKonkrete Einsatzfähigkeit, Preis, Termin und Leistungsumfang müssen für die jeweilige Anfrage bestätigt werden.",
                        'keywords' => ['leistungen', 'wagenmeister', 'notfalldienst', 'trailer', 'sonderuntersuchung', 'pufferteller'],
                        'include_in_baseline' => true,
                        'sort_order' => 20,
                    ],
                    [
                        'source_key' => 'defaults.company.process-contact',
                        'source_url' => 'https://www.rail-time.de/kontakt',
                        'title' => 'Einsatzablauf und Kontakt',
                        'summary' => 'Eine Einsatzanfrage nennt Einsatzort, Zeitraum und Aufgabe; danach werden Qualifikation, Ausrüstung und Verfügbarkeit abgestimmt. Das Website-Paket nennt kontakt@rail-time.de und 0160 1881848 für den 24/7-Notfalldienst.',
                        'content' => "Der veröffentlichte Standardablauf umfasst vier Schritte: Einsatzort, Zeitraum und Aufgabe aufnehmen; Qualifikation, Ausrüstung und Verfügbarkeit abstimmen; Auftrag vor Ort bearbeiten; Ergebnis und weiteres Vorgehen transparent kommunizieren.\n\nAls öffentliche Kontaktdaten nennt das redaktionelle Paket kontakt@rail-time.de, die Rufnummer 0160 1881848 und den Firmensitz Borsteler Weg 29–31, 21423 Winsen (Luhe). Bei einer dringenden technischen Unregelmäßigkeit ist die Rufnummer als 24/7-Notfalldienst ausgewiesen.\n\nVor einer verbindlichen Zusage müssen Termin, Einsatzort und Umfang durch RailTime bestätigt werden.",
                        'keywords' => ['einsatzanfrage', 'kontakt', 'notfall', 'telefon', 'email', 'winsen', 'ablauf'],
                        'include_in_baseline' => false,
                        'sort_order' => 30,
                    ],
                ],
            ],
        ];
    }
}
