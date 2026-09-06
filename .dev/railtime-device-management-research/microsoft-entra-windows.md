# Microsoft Entra und Windows-Geräte in RailTime

Stand: 6. September 2026, nach der Liveprüfung um 14:57. Geräteintegrations-
Release `8880cf96` ist der letzte explizit im Plesk-Dashboard bestätigte Stand;
der lokale Gitstand ist `9bb35ee6` aus einem anderen Task und wurde nicht als
neuer Livecommit geprüft. Für diesen Prüfnachlauf erfolgte kein
weiteres Deployment. Runtime-Migration
`030000`, Plesk-Paket v8.0.0, kanonische Queue `microsoft_devices` und
Cacheaufbau sind bestätigt. M1/M2 sind für Release und Hintergrundbetrieb
belegt: echte Graph-freie Workerprobe und automatischer Schedulerkontakt
um **13:05:02**, zusätzlich in der Webansicht bestätigt. Inzwischen sind
auch Graph-Verbindung, echter Erstimport und die wiederholte Verarbeitung
ohne neue Inventardatensätze live nachgewiesen.
Die separate Entra-App **„RailTime Geräteinventar“** ist
registriert; das Portal bestätigt ausschließlich das Application-Recht
`Device.Read.All` mit Administratorzustimmung **„Gewährt für RailTime“**.
Das automatisch hinzugefügte, unnötige `User.Read` wurde nur aus dieser App
entfernt; andere Apps blieben unverändert. Tenant- und Client-ID sind im
RailTime-Setup gespeichert und nach erneutem Laden bestätigt. Die neue
Webansicht bestätigt nun ausdrücklich das verschlüsselt gespeicherte
Client-Geheimnis und die aktivierte Synchronisierung im 15-Minuten-Intervall.
Der Microsoft-Graph-Verbindungstest war um **14:15** erfolgreich. Schema,
Queue, Scheduler und Worker werden als bereit angezeigt. Intune bleibt
ausgeschaltet; keine Microsoft-Schreibrechte wurden ergänzt.

| Lauf | Eingeplant / Workerstart / Abschluss | Gefunden | Neu | Aktualisiert | Zugeordnet | Konflikte | Übersprungen |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Automatischer Erstimport | 14:15:02 / 14:15:05 / 14:15:08 | 98 | 98 | 0 | 0 | 0 | 0 |
| Bewusste Wiederholung | 14:16:20 / 14:16:23 / 14:16:26 | 98 | 0 | 98 | 0 | 0 | 0 |

Die nach diesen beiden Inventarläufen um 14:16 frisch geladene Geräteübersicht
bestätigte historisch **98 Geräte insgesamt, 98 Treffer und 0 aktive Ausgaben**.
Nach dem folgenden Einzelpilot gilt aktuell **98 insgesamt / 1 aktive Ausgabe /
97 unzugeordnet**. Die Anzeige „virtuelles Lager“ bedeutet
nur nicht zugewiesen, nicht physisch bestätigten Lagerbestand. Gleiche Anzeigenamen sind allein kein
Duplikatbeleg; entscheidend sind stabile externe Geräteidentitäten.

**Historie, nicht aktueller Status:** Vor dieser erfolgreichen Einrichtung
waren `device_count=0`, `bound_microsoft_identities=0`,
`secret_configured=false` und `sync_enabled=false` dokumentiert. Der damalige
Identitätszähler umfasste aktive Microsoft-365-Bindungen im aktuellen Tenant
mit vorhandener externer ID. Secret- und Nullbestandsblocker sind inzwischen
überholt. Auch die damalige offene Pilotauswahl und der Kontenstatus
„Objekt-ID / Mandant offen“ sind für den nachfolgend geprüften Einzelpilot Historie.

### Einzelpilot: manuelle Ausgabe und bestätigte Kontobindung

Der Nutzer wählte ausdrücklich den aktuellen lokalen PC und einen geeigneten
Mitarbeiterdatensatz, kein System-/Superadmin-Konto. Die lokale Windows-
Workplace-Gerätekennung wurde exakt mit dem bereits importierten PC im
konfigurierten Tenant abgeglichen. Genau dieses zuvor unzugewiesene Gerät
wurde mit Notiz manuell in RailTime zugewiesen; es wurde kein Gerät neu angelegt.

Eine direkte lesende Entra-Prüfung ergab genau einen Treffer für das Gerät
und in der ungefilterten Geräteliste des betreffenden Kontos ausschließlich
diesen Pilot-PC. UPN, Objekt-ID und Tenant stimmten mit der bereits vorhandenen
RailTime-Identität überein. Die initiale Tenant-Bindung (`tenant_initial_binding`)
wurde daraufhin ausdrücklich über die UI bestätigt; Status „Verknüpft“.
Kein neues Konto, Passwort oder Microsoft-Schreibaufruf wurde erzeugt.

| Nachweis (nur minutengenau belegt) | Ergebnis |
| --- | --- |
| Sync 14:52 nach manueller Ausgabe | Bestehende Zuweisung erhalten; 98 / 1 / 97 |
| Sync 14:55 nach Tenant-Bindung | „Microsoft-Konto und Mitarbeiter stimmen überein“; 98 / 1 / 97 |
| Bewusste Wiederholung 14:57 | Gleiche Übereinstimmung und unverändert 98 / 1 / 97 |
| Frisch neu geladene produktive Detailseite | Gleicher gewählter Mitarbeiter, Übereinstimmung von 14:57 und 98 / 1 / 97 bestätigt |

Dies belegt die manuelle Einzelzuweisung, deren Erhalt und die verifizierte
Identitätsübereinstimmung, nicht eine automatische Erstzuweisung durch eine
Windows-Anmeldung. Windows-Workplace-Registrierung und Sitzung blieben
unverändert; kein neuer Windows-Logintest. Vollständige Inventarprüfung,
Standort und echte Seriennummer bleiben offen. MDM-, Intune- und
Fernwartungsbereitschaft sind unverändert nicht abgenommen. Die Geräte-
Kommandotabelle zeigte „Noch keine Geräteaktion protokolliert“; dies ist
kein Nachweis einer Prüfung des allgemeinen Activity-Logs.

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
Name ist deshalb `microsoft_devices` für Queue und Connection. Der notwendige
Korrekturrelease `8880cf96` ist live und mit echter Workerprobe geprüft;
der vorherige Release `9bf712dd` allein enthielt diese Namenskorrektur nicht.

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
