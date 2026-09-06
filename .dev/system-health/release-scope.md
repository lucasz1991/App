# Abgegrenzter Releaseumfang

Stand: 6. September 2026. **Kein Deploymentnachweis und keine Freigabe des gesamten Checkouts.**

Die nachstehenden Dateien bilden den Systemcheck-Quellumfang. Bei bestehenden Dateien sind nur die zugehörigen Änderungen gegen den tatsächlich laufenden Serverstand zu übernehmen. Die Liste darf nicht als automatischer Uploadauftrag für ganze, inzwischen anderweitig bearbeitete Dateien verwendet werden.

## Neue Diagnosemodule

```text
app/Jobs/ProbeSystemHealthWorker.php
app/Livewire/Admin/SystemHealth.php
app/Services/SystemHealth/BoundedInfrastructureConnections.php
app/Services/SystemHealth/DeviceChecks.php
app/Services/SystemHealth/InfrastructureChecks.php
app/Services/SystemHealth/IntegrationChecks.php
app/Services/SystemHealth/QueueChecks.php
app/Services/SystemHealth/SystemCheckRegistry.php
app/Services/SystemHealth/SystemHealthService.php
app/Services/SystemHealth/SystemHealthStore.php
app/Services/SystemHealth/Transport/BoundedSocket.php
app/Services/SystemHealth/Transport/ProbeSmtpStream.php
app/Services/SystemHealth/Transport/SmtpProbe.php
app/Services/SystemHealth/Transport/SpeechStatusProbe.php
app/Services/SystemHealth/Transport/WebSocketProbe.php
resources/css/system-health.css
resources/js/system-health.js
resources/views/livewire/admin/system-health.blade.php
```

## Eng begrenzte Integrationsänderungen

| Datei | Zugehörige Änderung |
|---|---|
| `app/Livewire/Admin/Settings.php` | Sprachstatus beim Öffnen passiv lesen, keine Netzwerkprobe im versteckten Tab |
| `app/Services/Ai/AssistantSpeechRouter.php` | Optionaler passiver Statuspfad; explizite Aktualisierung bleibt bestehen |
| `app/Services/DeviceManagement/MicrosoftDeviceRuntime.php` | Sicherer Ergebniscode zur Unterscheidung Erfolg/Teilerfolg im vorhandenen Laufnachweis |
| `app/Services/DeviceManagement/MicrosoftGraphDeviceClient.php` | Optionaler kurzer Diagnosemodus; normaler Sync-Kontrakt bleibt erhalten |
| `app/Support/Push/WebPushConfiguration.php` | Lesender Diagnosepfad ohne VAPID-Erzeugung oder Konfigurationsänderung |
| `resources/css/app.css` | Import der Diagnosegestaltung |
| `resources/js/app.js` | Import und Alpine-Registrierung der Diagnosekomponente |
| `resources/views/livewire/admin/settings.blade.php` | Superadmin-Diagnose, kompakte Schnellzugriffe und vorhandene Tab-/Abschnittsnavigation |
| `resources/views/livewire/admin/settings/partials/assistant-runtime.blade.php` | Passiven Sprachstatus ausdrücklich als „Noch nicht geprüft“ anzeigen |

## Abnahmequellen, nicht öffentlich ausliefern

```text
tests/Feature/SystemHealthComponentTest.php
tests/Feature/SystemHealthDeviceChecksTest.php
tests/Feature/SystemHealthInfrastructureTest.php
tests/Feature/SystemHealthIntegrationsTest.php
tests/Feature/SystemHealthOrchestrationTest.php
tests/Frontend/system-health.test.js
```

Die eng begrenzten `.gitignore`-Ausnahmen halten diese Tests versionierbar. `.dev/system-health` und die LMZ-Abnahmeunterlagen sind Dokumentation. Lokale UI-Fixtures und Test-Builds unter `.lmzdev/artifacts/system-health` dürfen nicht öffentlich hochgeladen werden.

## Assets und Freigabe

Die CSS-/JS-Einstiegspunkte erfordern einen passenden Vite-Build. Dieser muss auf einer verifizierten Serverbaseline mit ausschließlich freigegebenen Änderungen entstehen. Der lokale Testbuild enthält den gemeinsamen Arbeitsstand und ist deshalb kein freigegebenes Produktivpaket. Manifest und referenzierte Dateien zusammen prüfen; vorhandene Assets für einen gezielten Rollback erhalten.

Keine neue Migration, Tabelle, ENV-Variable, Composer-/NPM-Abhängigkeit oder periodische Gesamtprüfung. Webprozess und Worker benötigen gemeinsamen Zugriff auf den privaten Diagnosespeicher. Bestehende Worker nur koordiniert aktualisieren; keine Warteschlange leeren.

Vor einem Rollback neu erzeugte Probejobs berücksichtigen: die kleine Jobklasse und ihre abhängigen Diagnoseklassen verfügbar halten, bis eigene Proben abgearbeitet sind. Nicht einfach die Jobklasse bei noch serialisierten Aufträgen entfernen. Fremde Aufträge, Cacheinhalte und Geschäftsdaten bleiben unangetastet.

Aktuell offen: Plesk-Anmeldung, tatsächliche Serverbaseline, abgestimmtes Releasefenster und Live-Abnahme. Zwischencommits des gemeinsam genutzten Checkouts sind keine Releasefreigabe.
