# Microsoft Entra und Windows-Geräte in RailTime

Stand: 6. September 2026. Der native Graph-Abruf, die dauerhafte
Auftragsverwaltung und die getrennte Betriebsanzeige sind lokal implementiert.
Ein echter Mandantenabruf und die produktive Verarbeitung sind damit noch
nicht abgenommen. Die separate Entra-App **„RailTime Geräteinventar“** ist
registriert; das Portal bestätigt ausschließlich das Application-Recht
`Device.Read.All` mit Administratorzustimmung **„Gewährt für RailTime“**.
Das automatisch hinzugefügte, unnötige `User.Read` wurde nur aus dieser App
entfernt; andere Apps blieben unverändert. Tenant- und Client-ID sind im
RailTime-Setup gespeichert und nach erneutem Laden bestätigt. Das
Client-Geheimnis ist trotz Nutzerbestätigung noch nicht als gespeichert
nachgewiesen (`secret_configured=false`); ein Wert gehört ausschließlich in
das geschützte Setup, niemals in diese Dokumentation. Automatik und Intune
sind ausgeschaltet. Release `9bf712dd`, Runtime-Migration `030000`, Plesk-Paket
v8.0.0 und Cacheaufbau sind live bestätigt. Der Microsoft-Worker läuft noch
nicht: Plesk lehnt den bisherigen Bindestrich-Queuenamen ab. Die nachfolgend
dokumentierte Korrektur auf `microsoft_devices` muss erst bereitgestellt und
danach mit echter Workerprobe geprüft werden. Graphnachweis bleibt offen.

## Was automatisch passiert

1. Ein Windows-Gerät wird bei Microsoft Entra registriert, Entra joined oder
   hybrid joined. Eine reine Anmeldung bei einer Office-App muss keinen
   Geräteeintrag erzeugen.
2. RailTime liest standardmäßig alle 15 Minuten die Geräte des konfigurierten
   Mandanten. Es übernimmt Windows-Geräte einschließlich Entra-IDs, OS-Version,
   Registrierungsart und der verfügbaren Inventardaten.
3. Bei aktivierter Intune-Ergänzung werden Seriennummer, Modell,
   Compliance-Meldung, letzter Intune-Kontakt und Hauptbenutzer gelesen.
4. Eine eindeutige Microsoft-Benutzer-ID wird ausschließlich über eine
   bestehende Bindung `Tenant-ID + Objekt-ID + RailTime-Mitarbeiter` zugeordnet.
   Eine gleiche E-Mail-Adresse allein reicht nicht.
5. Neue, noch nicht zugeteilte Geräte können automatisch zugeordnet werden.
   Intune-Hauptbenutzer hat Vorrang vor dem Entra-Registrierungsbesitzer.
   Bereits bestehende andere Zuteilungen, Rückgaben oder Bereitstellungsaufträge
   führen zur manuellen Prüfung. Eine automatische Zuordnung bestätigt keine
   physische Übergabe und keine vollständige Betriebsbereitschaft.

Der vorhandene verifizierte Microsoft-Bootstrap im RailTime-Outlook-Add-in
stößt zusätzlich einen fälligen Abgleich an. Wiederholte Bootstrap-/304-Aufrufe
werden gedrosselt. Ein Windows-Login ruft RailTime nicht direkt auf; dafür
erfasst der periodische Abgleich neue Entra-Einträge. Ein zusätzlicher
Microsoft-Weblogin für die RailTime-Anmeldeseite wurde nicht eingeführt.

## Einrichtung in Microsoft und RailTime

1. In Microsoft Entra eine separate Anwendung für **diesen einen Mandanten**
   registrieren. Tenant-ID, Anwendungs-/Client-ID und ein gültiges
   Client-Geheimnis werden benötigt. Das vorhandene Outlook-Add-in-Token ist
   für die RailTime-API bestimmt und wird nicht als Graph-Token verwendet.
2. Microsoft Graph **Application permission `Device.Read.All`** erteilen und
   die Administratorzustimmung bestätigen. Für die optionale Intune-Abfrage
   zusätzlich **`DeviceManagementManagedDevices.Read.All`**; dafür muss eine
   aktive Intune-Lizenz vorhanden sein. Es werden keine Schreibrechte,
   `Directory.ReadWrite.All`, Mail- oder Sign-in-Log-Rechte benötigt.
3. Im abgestimmten Deploymentfenster die ausstehenden Migrationen mit
   `php artisan migrate --force` ausführen. Zur bisherigen Importmigration
   `2026_09_06_020000_create_microsoft_device_links` kommt
   `2026_09_06_030000_create_microsoft_device_runs` für die dauerhafte
   Auftrags-, Scheduler- und Worker-Evidenz. Beide sind additiv; bestehende
   Geräte bleiben erhalten, bislang unbekannte Tenant-Bindungen bleiben leer.
   Ein früherer grüner Migrationscheck belegt nicht, dass die neu hinzugekommene
   Runtime-Migration bereits auf dem Zielserver ausgeführt wurde.
4. Unter **Einstellungen → Geräte-Setup → Microsoft Entra & Windows** die
   IDs und das Geheimnis eintragen, speichern und **Verbindung testen**.
   Erst danach die automatische Synchronisierung aktivieren. Die Werte
   liegen in der Datenbank, das Geheimnis verschlüsselt; keine neuen ENV-Werte.
   Für Graph werden die festen Microsoft-HTTPS-Endpunkte verwendet. Die
   bisherigen lokalen Plesk-Subdomains/Ports der anderen Connectoren bleiben
   unabhängig davon.
5. Unter **Geräte → Microsoft-Konten** vorhandene Mitarbeiter mit ihrer
   Microsoft-Benutzer-Objekt-ID und ihrem UPN verbinden. Die Entra-Objekt-ID
   des Benutzers ist nicht die Client-ID der App oder die Geräte-ID.
   Eine bereits etablierte identische Kontobindung kann ihren bislang leeren
   Tenant ausdrücklich bestätigen; vorhandene andere Bindungen werden nicht
   überschrieben. Bei passender bereits verifizierter Outlook-Anmeldung kann
   der bisher leere Tenant auch über diesen authentifizierten Weg ergänzt werden.
6. Den bestehenden Plesk-Scheduler und den durch Plesk verwalteten dedizierten
   Worker wie unten einrichten. Zuerst **Hintergrundverarbeitung testen**
   verwenden und auf die tatsächliche Workerbestätigung warten. Danach
   **Verbindung testen** und **Jetzt synchronisieren** getrennt abnehmen.

## Hintergrundbetrieb mit Plesk 8

RailTime verwendet `plesk/ext-laravel-integration` v8.0.0 (`^8.0`). Unter
**Domain → Laravel → Queue** die bisherigen Queues samt Anzahl und Parametern
erhalten und ausschließlich die folgende Queue ergänzen:

| Einstellung | Wert |
| --- | --- |
| Queue | `microsoft_devices` |
| Aktiv / Anzahl Worker | Ja / `1` |
| Timeout | `240` Sekunden |
| Stop Worker When Empty | Aus |
| Max Jobs / Max Time | `0` / `3600` Sekunden |
| Connection | Kein Plesk-Eingabefeld; RailTime wählt `microsoft_devices` |

Plesk erlaubt im Queuenamen nur lateinische Buchstaben, Ziffern und Unterstriche.
Der frühere Name mit Bindestrich wurde in der Live-UI abgewiesen; kanonischer
Name ist deshalb `microsoft_devices` für Queue und Connection. Vor Aktivierung
muss der entsprechende Korrekturrelease ausgerollt sein; `9bf712dd` allein
enthält diese Namenskorrektur noch nicht.

Plesk startet benannte Queues ohne positional Connection. Der RailTime-Adapter
ordnet exakt `--queue=microsoft_devices` der dedizierten Connection
`microsoft_devices` zu; andere Queues bleiben unverändert. Gemischte
Queue-Listen oder eine ausdrücklich falsche Connection werden abgewiesen.
Die Microsoft-Connection nutzt die vorhandene Datenbank und `jobs` mit
`retry_after=300`; die normale Datenbankqueue behält ihre bisherigen Werte.

**Keinen zusätzlichen app-eigenen oder manuell dauerhaft gestarteten Worker
parallel anlegen.** Plesk verwaltet den Lifecycle über Laravel Schedule.
Der bestehende Scheduler muss jede Minute laufen. Der interne
`devices:sync-microsoft --scheduled`-Eintrag wird alle fünf Minuten ausgeführt
und berücksichtigt das gespeicherte Abrufintervall (standardmäßig 15 Minuten).
`--scheduled` ist ein interner Marker; ihn nicht für einen vermeintlichen
manuellen Scheduler-Nachweis verwenden.

Vor Aktivierung Paketversion, `plesk-ext-laravel:list-env` mit
`PLESK_EXT_LARAVEL_QUEUE_MULTIPLE_SUPPORTED=true` und `schedule:list` prüfen.
Beim Wechsel auf die v8-Queue-Liste müssen insbesondere bestehende `default`,
`calls` und `devices` erhalten bleiben. Eine leere neue Liste deaktiviert
die bisherigen Worker. Alte Prozesse kontrolliert auslaufen lassen; keine
globale Queue-Leerung oder parallele zweite Workerbelegung.

Plesk speichert seine UI-Werte in `.env.plesk`; diese Datei nicht durch
RailTime oder einen zusätzlichen Scheduler parallel verwalten. Es werden
keine neuen RailTime-`.env`-Einrichtungsvariablen benötigt. Bestehende
Laravel-Basiswerte und die von Plesk verwaltete Betriebskonfiguration bleiben
notwendig. Konfigurationscache im abgestimmten Deployment aktualisieren.

## Betriebsnachweise und Diagnose

Unter **Einstellungen → Geräte-Setup → Microsoft Entra & Windows** stehen
Schema, Queuebereitschaft, Schedulerkontakt, tatsächlicher Workerbeleg und
Auftragsstatus getrennt vom Microsoft-Verbindungstest. **Hintergrundverarbeitung
testen** plant einen reinen Queueauftrag ohne Graph- oder Gerätedatenzugriff.
Er funktioniert auch vor Eintragung der Microsoft-Zugangsdaten, sofern
Runtime-Tabelle und Queue bereit sind. Ein eingeplanter Auftrag ist noch
keine Ausführungsbestätigung.

```bash
php artisan devices:microsoft-status --json
php artisan devices:microsoft-status --json --probe-worker
php artisan devices:microsoft-status --json
php artisan devices:sync-microsoft --force
```

`devices:microsoft-status` ist ohne `--probe-worker` lesend. Mit der Option
wird nur die Graph-freie Probe eingeplant; der nächste Statusabruf muss deren
`completed` **und** `acknowledged_at` zeigen. Das Kommando gibt keine Credentials
oder Mitarbeiterdaten aus. Sein Exitcode 0 bestätigt nur Schema-/Queuechecks,
nicht laufenden Scheduler, Worker, Graph-Rechte oder erfolgreiche Geräteimporte.
`devices:sync-microsoft --force` plant ebenfalls nur einen Auftrag; es erzwingt
keinen parallelen Lauf und umgeht keine deaktivierte Verbindung.

| Nachweis | Bedeutung und Grenze |
| --- | --- |
| `schema_ready`, `queue_ready` | Tabellen und dedizierte Queuekonfiguration geprüft; kein Prozessnachweis |
| `scheduler.state=fresh` | Interner Schedulerkontakt jünger als 10 Minuten; kein Graphnachweis |
| `worker.state=busy/seen` | Auftrag wurde tatsächlich durch einen reservierenden Worker begonnen; `seen` ist kein dauerhafter Prozessheartbeat |
| `worker_probe.status=completed` plus `acknowledged_at` | Ein echter Worker hat die Probe verarbeitet; Microsoft wurde dabei nicht getestet |
| `run.status=queued/running/completed/failed` | Geräteauftrag wartet, läuft, ist beendet oder fehlgeschlagen; `completed` kann Klärungsbedarf enthalten |
| `overdue=true` | Auftrag wartet mindestens 2 Minuten oder läuft mindestens 5 Minuten; auch Proben können betroffen sein |
| Erfolgreicher Verbindungstest | Microsoft-Endpunkte und konfigurierte Leserechte erreichbar; kein Worker-/Import-/MDM-Nachweis |

Der letzte Importlauf wird für den aktuellen Tenant und Konfigurationsfingerprint
angezeigt. Ein Konfigurationswechsel darf keinen alten Mandantenlauf als neuen
Erfolg darstellen. Workerprobe und technischer Workerbeleg sind dagegen
serverbezogen. Eine alte erfolgreiche Importzusammenfassung bleibt als
historisches Ergebnis getrennt von aktuellen Fehlern sichtbar.

Die Setup-Ansicht fragt wartende/laufende Aufträge alle 10 Sekunden maximal
zwei Minuten ab; **Status aktualisieren** öffnet ein neues begrenztes Fenster.
Die Geräteübersicht beobachtet den angeforderten Lauf höchstens 60-mal im
5-Sekunden-Takt (rund fünf Minuten bei sichtbarer Seite), aktualisiert die
Liste nach Abschluss und beendet die Abfrage auch bei Fehler oder Wechsel
der Lauf-ID. Diese Anzeigeintervalle verändern nicht das Graph-Abrufintervall.

## Oberfläche und Konflikte

- Geräteübersicht: Microsoft-Abgleich-Button, Kontozuordnungsmodal sowie
  Filter für Entra, Intune und zu prüfende Microsoft-Zuordnungen.
- Gerätedetail: Registrierungsart, Intune-Nachweis, erkannter Mitarbeiter,
  Quelle der Zuordnung, Kontaktdatum und getrennte Entra-/Intune-IDs.
- Ein bestehendes Inventargerät wird anhand einer eindeutigen Intune-
  Seriennummer wiedererkannt. Entra allein liefert keine verlässliche
  Seriennummer. Gleiche Gerätenamen werden nicht automatisch zusammengeführt.
- Seriennummern werden nachträglich ergänzt, sofern sie leer und eindeutig
  sind. Widersprüche erscheinen als Klärungsfall; lokale Kennungen werden
  nicht überschrieben.
- Fehlende oder mehrdeutige Besitzer, unbekannte Mitarbeiter, deaktivierte
  Entra-Geräte und widersprüchliche Zuteilungen bleiben sichtbar.
- Ein verschwundener Entra-Eintrag löscht kein RailTime-Gerät und verändert
  keine ausgegebene Hardware oder Zuteilung.

## Grenzen der Daten

Der Entra-Registrierungsbesitzer kann der IT-Mitarbeiter sein, der das Gerät
eingerichtet hat. Bei geteilten oder Hybridgeräten kann der Besitzer fehlen.
Intune-Hauptbenutzer ist deshalb, soweit vorhanden, die bevorzugte Quelle.
Fällt die konfigurierte Intune-Abfrage aus, erfolgt keine automatische
Zuordnung anhand eines vermeintlichen Ersatzbesitzers.

Entra-Registrierung ist keine MDM-Einschreibung. Graph-Inventardaten setzen
in RailTime weder Enrollment-/MFA-/Lizenz-/App-Profile auf erfolgreich noch
Fernsupport auf erreichbar. Der ungefähre Entra-Anmeldezeitpunkt ist kein
Live-Heartbeat. Die bestehende MeshCentral-/UEM-Verwaltung kann parallel
genutzt werden; deren Provider-IDs und Freigaben bleiben eigenständig.

## Technische Umsetzung und Prüfung

- `MicrosoftGraphDeviceClient`: v1.0, Client Credentials, vollständige
  Folgeseiten, GET-Teilabfragen in 20er-Batches, feste Hosts und Ressourcenpfade,
  keine Redirects oder Weitergabe von Tokens an Paging-Fremdziele.
- `MicrosoftDeviceSyncService`: Konfiguration und Fingerprint aus einem
  Snapshot; Konfigurationsprüfung vor dem transaktionalen Import, stabile
  Entra-/Intune-Identitäten, gesperrte Konten/Zuweisungen, keine Teilimporte bei
  einem fehlerhaften Entra-/Besitzerabruf.
- `MicrosoftDeviceRuntime` / `MicrosoftDeviceSyncScheduler` /
  `SyncMicrosoftDevices`: dauerhafte Deduplizierung und atomare Bindung des
  Runledgers an die echte Datenbankqueue, erneute Konfigurationsprüfung,
  sichere Fehler-/Timeoutzustände; Cacheverlust erzeugt keinen neuen
  Doppelauftrag. Keine Passwörter oder Microsoft-Zugriffstokens in Jobs.
- `ProbeMicrosoftDeviceWorker`: Quittierung nur nach tatsächlicher
  Reservierung des zugehörigen Jobs durch einen Worker; kein direkter
  Methodenaufruf als Ersatz für eine echte Queueprobe.
- `MicrosoftDeviceWorkCommand`: isoliertes Plesk-8-Queue-Routing, kein
  zusätzlicher Worker-Lifecycle in der Anwendung.
- `MicrosoftEmployeeLinkService`: `devices.accounts.manage`, explizite
  administrative Kontobindung und Audit. Konfiguration benötigt weiterhin
  `settings.manage` und den RailTime-Superadmin.

Die lokalen Regressionen stehen in `MicrosoftDeviceSyncTest`,
`MicrosoftDeviceSyncTriggerTest`, `MicrosoftDeviceSettingsTest`,
`MicrosoftEmployeeLinkTest`, `MicrosoftDeviceQueueRoutingTest` und
`MicrosoftDeviceOperationsTest`. Echte Microsoft-Zugriffe sind in diesen
Tests gesperrt und werden bei Bedarf durch definierte Antworten ersetzt.
Der Workerprobe-Test verwendet einen echten Queueworker auf isolierter
SQLite, nicht auf der Produktionsdatenbank. Die konkrete Live-Abnahme steht
im [Produktions-Testlauf](production-test-runbook.md).

## Offizielle Verträge

- [Entra-Geräte lesen und minimale Rechte](https://learn.microsoft.com/en-us/graph/api/device-list?view=graph-rest-1.0)
- [Registrierungsbesitzer und deren Bedeutung](https://learn.microsoft.com/en-us/graph/api/device-list-registeredowners?view=graph-rest-1.0)
- [Intune-Geräte lesen und Lizenzvoraussetzung](https://learn.microsoft.com/en-us/graph/api/intune-devices-manageddevice-list?view=graph-rest-1.0)
- [Intune-Gerätefelder und Hauptbenutzerbeziehung](https://learn.microsoft.com/en-us/graph/api/resources/intune-devices-manageddevice?view=graph-rest-1.0)
- [Intune-Hauptbenutzer und Entra-Besitzer](https://learn.microsoft.com/en-us/intune/device-management/inventory-and-status/find-primary-user)
- [Graph-Batches](https://learn.microsoft.com/en-us/graph/json-batching)
- [Beschränkungen von Directory-Expand](https://learn.microsoft.com/en-us/graph/known-issues#some-limitations-apply-to-query-parameters)
- [Geschäftskonto auf einem Windows-Gerät](https://support.microsoft.com/en-us/windows/security/identity-signin/add-your-work-or-school-account-to-a-windows-device)
