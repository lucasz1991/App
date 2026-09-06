# Zentraler Systemcheck

Stand: 6. September 2026. Umsetzung nach dem vom Nutzer freigegebenen Plan.

## Bedienung und Rechte

Unter Einstellungen → Übersicht sehen ausschließlich Superadmins mit `settings.manage` den Systemcheck. Die bisherige Übersicht bleibt für andere Administratoren erhalten. Alle Livewire-Aktionen einschließlich Snapshot und Poll prüfen beide Berechtigungen erneut; Ergebnisdaten sind `#[Locked]`.

Die Übersicht enthält 31 feste, gruppierte Prüfungen. Beim tatsächlichen Aktivieren des Übersicht-Tabs wird der aktuelle Snapshot geladen. Ergebnisse sind 900 Sekunden gültig. Nur fehlende, veraltete oder durch eine geänderte Konfiguration ungültige Ergebnisse werden dann neu geprüft. Die Seite aktualisiert sich danach nicht dauerhaft. Einzelprüfung und „Alle erneut prüfen“ können den Ergebnis-Cache umgehen; laufende Prüfungen werden dabei zwischen Tabs geteilt.

Ein Ergebnis unterscheidet Status und Aussageumfang: Konfiguration, Verbindung oder Verarbeitung. „OK / Konfiguration“ bedeutet ausdrücklich keinen vollständigen Funktionstest. Backups und Simulationen bleiben ohne tatsächlichen Beleg „Nicht geprüft“. Alte Ergebnisse sind sichtbar als veraltet gekennzeichnet.

## Technische Struktur

- `SystemCheckRegistry`: feste IDs, deutsche Beschriftungen, Gruppen und vorhandene Einstellungsziele. Konfiguration, Implementierungsversion und vorhandene kleine Credential-Dateien fließen ausschließlich serverseitig in den Fingerprint ein.
- `SystemHealthService`: Snapshot, Einzelprüfung, begrenztes Beobachten. Kurze sequenzielle Livewire-Requests; kein Gesamtprüfjob und kein neuer periodischer Scheduler-Eintrag.
- `SystemHealthStore`: eigener Laravel-Dateicache unter `storage/framework/cache/system-health`, getrennt vom geprüften Anwendungscache. Dateien mit Modus0600. PHP-Webprozess und Queue-Worker benötigen auf dem gemeinsamen Plesk-Host Zugriff als derselbe Hostingnutzer. Keine neue ENV-Variable, Tabelle oder Migration.
- Laravel-Dateilocks koordinieren Check und kurze atomare Ergebnismutation. UUID und Fingerprint verhindern, dass ein abgebrochener, alter oder anders konfigurierter Lauf neuere Ergebnisse überschreibt.
- `InfrastructureChecks`, `IntegrationChecks`, `DeviceChecks`: getrennte sichere Diagnosepfade. Keine Wiederverwendung der Geräte-Produktionsfreigabe-Diagnose oder der VAPID-Autogenerierung.
- `BoundedInfrastructureConnections`: eigene MySQL-/MariaDB-Verbindungen mit 2 Sekunden Verbindungs- und 3 Sekunden Lese-/SQL-Limits je Operation; request-lokale mysqlnd-Einstellung wird danach exakt wiederhergestellt. Datenbank-Cache und Sessionprüfung verwenden denselben begrenzten Pfad, Redis einen isolierten Manager. Ungeeignete Treiber, Replikat-/Clustertopologien oder nicht setzbare Grenzen werden ausdrücklich nicht geprüft. Dies ist keine pauschale Drei-Sekunden-Garantie für den gesamten Check.
- `QueueChecks` und `ProbeSystemHealthWorker`: isolierte No-op-Proben für die tatsächlich benötigten Connection-/Queue-Kombinationen. Identische Kombinationen teilen denselben Nachweis. Der Schlüssel berücksichtigt auch die aufgelöste Datenbankkonfiguration. Empfang zählt nur bei passender Nonce, gespeicherter Job-ID und einer tatsächlich reservierten Zeile der erwarteten Datenbankqueue.
- Queue-Rückstände, Jobanlage und Empfangsprüfung verwenden ebenfalls begrenzte Diagnoseverbindungen. Die regulären Connection-/Queue-Namen bleiben erhalten. Scheitert das Schreiben des privaten Probe-Belegs, wird nur die eigene neue Jobanlage transaktional zurückgerollt. Ein noch wartender Probejob wird bei erneutem Prüfen nicht dupliziert.
- Der Browser beobachtet offene Proben höchstens120Sekunden. Fehlende Bestätigung ist eine Warnung, kein Beweis eines ausgefallenen Workers. Direkter `handle()`-Aufruf, `sync` und bloßes Einplanen werden nie als Workerbestätigung gewertet. Fremde Queue-Treiber werden ausdrücklich nicht als verarbeitet ausgegeben.

Bei defektem Diagnosespeicher bleibt eine sichere Fehlermeldung sichtbar; zustandslose Einzelprüfungen sind möglich, neue Queueproben nicht. Keine Rohantworten, Tokens, Empfängerdaten oder Geräteinventare werden an den Browser ausgegeben.

## Sicherheitsgrenzen

SMTP prüft Verbindung, Zertifikat/TLS und gegebenenfalls beobachtete Authentifizierung, niemals `MAIL FROM`, Empfänger oder Nachricht. Realtime prüft WebSocket-Upgrade und die Pusher/Reverb-Anwendungsbestätigung ohne Kanäle oder Veröffentlichung. LiveKit nutzt nur `ListRooms`; Raum- und Teilnehmerdaten werden nicht übernommen. Microsoft liest höchstens ein Ergebnis pro aktiviertem Inventar-Endpunkt, ohne Paging, Zuordnung oder Synchronisierung.

Push prüft vorhandene VAPID-Daten ohne Erzeugung, Speicherung oder Konfigurations-Hydration. KI, Outlook und Render-/Aufzeichnungsvoraussetzungen sind Konfigurationsprüfungen. Der lokale Sprachdienst erhält nur einen Status-GET. Geräteconnectoren erhalten nur ihren festen Health-GET; Produktionsschalter, Diagnostikfreigaben und Gerätedatensätze bleiben unverändert.

Ausgeschlossen bleiben echte E-Mails, Pushnachrichten, Anrufe, Audioverarbeitung, kostenpflichtige Generierung, Fernwartungsstart, Enrollment, Gerätebefehle, Migrationen, Reparaturen und Schlüsselgenerierung. Nur eigene zufällige Cache-/Dateiproben werden bereinigt. Ein vorhandener Backupplan, Browserempfang, Mailzustellung oder vollständige Geräteverwaltung wird niemals aus Erreichbarkeit abgeleitet.

## Lokale Abnahme

- Abschließender gemeinsamer Lauf einschließlich Queue-Timeout-Härtung: **156 Tests / 904 Assertions bestanden**, einschließlich aller fünf SystemHealth-Suiten sowie Settings-, WebPush- und Microsoft-Regressionsprüfungen. Dauer: 61,64 Sekunden. Scoped Pint und Diffcheck bestanden.
- Belegt sind unter anderem unveränderte Gerätefreigaben, echte reservierte Queue-Nachweise in einer isolierten Testdatenbank, keine Sprachdienst-Anfrage beim Settings-Mount, abgewiesene Direktaufrufe ohne Berechtigung und Wiederherstellung der nur request-lokal veränderten Timeout-Einstellung. Keine Live-MySQL-/Redis-Störungssimulation durchgeführt.
- Frontend:11Tests bestanden (Tab-Aktivierung, Cache, Einzel-/Gesamtprüfung, Sequenzierung, späte Antworten, Seitenwechsel, Beobachtungsfrist und reduzierte Bewegung).
- Isolierter Vite-Build unter `.lmzdev/artifacts/system-health/build` bestanden. Kein `public/build` oder Outlook-Add-in-Build ersetzt. Vorhandene Warnungen: Browserslist-Alter, zwei bestehende Assetverweise und große Chunks.
- Visuelle Prüfung über den echten App-Browser mit der echten Blade-/Alpine-Komponente in einer klar markierten lokalen Fixture:1440,593,390Pixel, jeweils hell/dunkel. Kein horizontaler Seitenüberlauf. Tastatur-Details, Einzelprüfung, Einstellungssprung und Gesamtprüfung bedient. Reduced-Motion ergab0sButtontransition, keine Browserfehler. Dies sind UI-Nachweise mit Beispieldaten, keine Live-Dienstnachweise.

Der abschließende Teststand und die konkrete Releasefreigabe werden im LMZ-Abnahmebericht festgehalten. Die [abgegrenzte Dateiliste](release-scope.md) beschreibt den Quellumfang und die zusätzlichen Build-/Rollbackgrenzen.

## Plesk-Release und Live-Abnahme

**Noch nicht deployed.** Im gemeinsamen Checkout befinden sich parallele, noch nicht freigegebene V26-Mailänderungen; fremde Zwischencommits haben auch unfertige Systemcheck-Dateien aufgenommen. Niemals pauschal den aktuellen HEAD veröffentlichen. Plesk ist derzeit abgemeldet; erneute Anmeldung wurde beim Nutzer angefragt.

1. Release-Lane mit dem Mailtask abstimmen und den tatsächlich laufenden Serverstand feststellen; exakte Systemcheck-Dateiliste und bestehenden Buildmanifeststand sichern. Fremde Änderungen, Signaturen, Standards und Produktionswerte unangetastet lassen.
2. Abgegrenztes Releasepaket aus verifiziertem Server-Baselinecode plus Systemcheck-Änderungen bauen. Der lokale Vollcheckout-Build ist **keine** automatische Freigabe der parallel bearbeiteten Mailmodule.
3. Prüfen, dass Web-PHP und Worker dasselbe private Diagnoseverzeichnis erreichen; vorhandene Queue-/Connection-Zuordnungen beibehalten. Keine neue ENV, Migration, Seeder oder globale Cacheleerung erforderlich.
4. Koordiniert Quellcode und zusammenpassende Assets ausliefern; nur betroffenen Bladecache aktualisieren. Bestehende Worker bei Bedarf kontrolliert auf den Code aktualisieren, keine Queue leeren.
5. Live als Superadmin Übersicht aktivieren: echte Einzelresultate, gespeicherte Zeiten, Cachehit beim zweiten Aufruf, gezielte Einzelprüfung, echte Workerbelege und fehlende Dienste überprüfen. Keine excluded Aktionen als „zusätzlichen Test“ ausführen.
6. Normalen Administrator gegenprüfen. Ausführung und tatsächlich erreichte Dienstzustände separat dokumentieren; ein lokaler grüner Testlauf ist kein Livebereitschaftsnachweis.

Offizielle Grundlage für die verwendeten atomaren Locks: https://laravel.com/docs/12.x/cache#atomic-locks
