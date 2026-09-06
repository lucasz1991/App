# Produktions-Testlauf

## Microsoft-Entra-Inventar und Windows-Abgleich

Stand: 6. September 2026. Dieses Dokument ist das bestehende Betriebsrunbook;
eine zusätzliche `production-runbook.md` ist nicht erforderlich. Der direkte
Graph-Abruf hat einen [Einrichtungs- und Vertragsweg](microsoft-entra-windows.md).
Lokale Regressionen und ein isolierter echter Workerprobe-Test ersetzen
keinen Nachweis auf dem Zielserver.

Bestätigter Einrichtungsstand: Die separate App **„RailTime Geräteinventar“**
ist registriert. Das Portal zeigt ausschließlich Application `Device.Read.All`
mit Adminzustimmung **„Gewährt für RailTime“**; unnötiges automatisch ergänztes
`User.Read` wurde nur aus dieser App entfernt, andere Apps blieben unverändert.
Tenant- und Client-ID sind im RailTime-Setup gespeichert und nach erneutem
Laden bestätigt. Secret-Speichern ist trotz Nutzerbestätigung technisch noch
nicht nachgewiesen; Werte gehören ausschließlich ins geschützte Setup.
Automatik und Intune bleiben aus. **M1/M2 live bestätigt:** Release `8880cf96`,
Runtime-Migration `030000`, Plesk v8.0.0, kanonische Queue `microsoft_devices`
und Cacheaufbau; echte Workerprobe und automatischer Schedulerkontakt um
**13:05:02** durch normalen Plesk-Betrieb, zusätzlich in der Webansicht belegt.
Finale sichere Liveflags: `device_count=0`, `bound_microsoft_identities=0`,
`secret_configured=false`, `sync_enabled=false`, `maintenance=false`.
Der Identitätszähler umfasst aktive Microsoft-365-Bindungen im aktuellen
Tenant mit externer ID. Kontobindung über explizite Tenant-/Objekt-ID oder
einen passenden verifizierten Microsoft-Bootstrap ist ebenfalls Pilot-Gate;
kein E-Mail-only-Matching. Graph-Verbindung, Erstimport und Idempotenz bleiben
offen. Die folgenden Gates dienen weiterhin als wiederholbares Runbook,
nicht als Aufforderung, bereits bestätigte Liveänderungen erneut auszuführen.

### Gate M1 – gemeinsamer Release und Plesk-Betrieb

1. Deploymentfenster mit parallelen RailTime-Releases abstimmen; bisherige
   Queue-Liste, Workeranzahl und Parameter sowie Datenbank sichern. Keine
   Migration, Cacheerneuerung oder Workerumstellung in eine fremde Release-Lane.
2. Plesk-Paket v8.0.0 (`^8.0`), Queueadapter, Runtime und UI zusammen
   bereitstellen. Mit der für die Domain ausgewählten Plesk-PHP-Version
   `php artisan migrate:status` prüfen, danach nur im freigegebenen
   Deployment die ausstehenden Migrationen ausführen. Erforderlich sind
   `2026_09_06_020000_create_microsoft_device_links` und neu
   `2026_09_06_030000_create_microsoft_device_runs`. Bestehende Daten erhalten;
   kein `migrate:fresh` auf dem Zielserver.
3. Konfigurationscache abgestimmt erneuern. `php artisan plesk-ext-laravel:list-env`
   muss `PLESK_EXT_LARAVEL_QUEUE_MULTIPLE_SUPPORTED=true` ausweisen.
   `php artisan schedule:list` muss die Plesk-Workerplanung und den internen
   `devices:sync-microsoft --scheduled`-Fünfminuten-Eintrag zeigen.
   Der bestehende äußere Scheduler läuft weiterhin jede Minute.
4. In **Plesk → Domain → Laravel → Queue** die bestehenden Queues, insbesondere
   `default`, `calls`, `devices`, mit allen bisherigen Parametern erhalten.
   Nach Bereitstellung der Namenskorrektur zusätzlich `microsoft_devices`
   aktivieren: **1 Worker**, Timeout **240**,
   Max Jobs **0**, Max Time **3600**, **Stop Worker When Empty aus**.
   Die Plesk-UI hat kein eigenes Connectionfeld; der RailTime-Adapter routet
   exakt diese Queue zur gleichnamigen Connection mit `retry_after=300`.
   Plesk erlaubt nur lateinische Buchstaben, Ziffern und Unterstriche; den
   früheren, live abgewiesenen Bindestrichnamen nicht erneut verwenden.
   Keine Queue-Mischliste und keine globale Änderung von `retry_after`.
5. Alte Prozesse beim Wechsel kontrolliert auslaufen lassen. Plesk verwaltet
   den gesamten Lifecycle und `.env.plesk`. **Keinen zusätzlichen app-eigenen
   Worker, keinen zweiten manuellen Dauerworker und keinen parallelen
   Konfigurationsschreiber ergänzen.** Eine leere v8-Queue-Liste würde den
   Legacybetrieb abschalten; bestehende Queues daher vor Speichern abgleichen.

### Gate M2 – echte Workerprobe ohne Microsoft

Unter **Einstellungen → Geräte-Setup → Microsoft Entra & Windows** Schema-
und Queueprobleme beheben. Die neue Runtime muss auch vor Microsoft-Einrichtung
eine reine Workerprobe ermöglichen; das Importschema wird für den späteren
Geräteabgleich benötigt. Optional dieselben Schritte über CLI:

```bash
php artisan devices:microsoft-status --json
php artisan devices:microsoft-status --json --probe-worker
```

Danach nicht selbst einen zweiten Worker starten, sondern den durch Plesk
verwalteten Prozess abwarten und erneut lesend prüfen:

```bash
php artisan devices:microsoft-status --json
```

Erforderlicher Nachweis: `worker_probe.status=completed` mit gesetztem
`acknowledged_at`. `queued`, ein Klick auf den Testbutton oder `probe_queued=true`
belegen nur die Einplanung. Der Test ruft weder Graph noch Mitarbeiter-/Gerätedaten
ab. Ein frischer `scheduler.checked_at` muss aus dem tatsächlichen geplanten
Eintrag stammen; den internen `--scheduled`-Marker nicht manuell als Ersatz
aufrufen. `schedule:list` allein beweist noch keine laufende Scheduler-Ausführung.

**Statusgrenze:** Exitcode 0 des Statuskommandos bedeutet nur, dass Schema und
Queue bereit sind. Microsoft-Konfiguration, Scheduler, Worker und echter Import
werden als separate Felder geprüft. `worker.state=seen` ist ein zeitlich
begrenzter Ausführungsbeleg, kein dauerhaftes Lebenszeichen eines Prozesses.

### Gate M3 – Microsoft-Verbindung und erster Import

1. Genehmigte separate Single-Tenant-App und Adminzustimmung für
   `Device.Read.All` nachweisen. Client-Geheimnis nur über den geschützten
   Einstellungsbereich übergeben; nicht in Terminalverlauf, Plan oder Bericht
   kopieren. Intune bleibt ohne gesonderte Lizenz und das minimale optionale
   Leserecht ausgeschaltet.
2. Tenant-/Client-Konfiguration speichern und **Verbindung testen**. Ein
   erfolgreiches Graph-Ergebnis ist kein Ersatz für Gate M2. Kontobindungen
   anhand `Tenant-ID + Benutzer-Objekt-ID` ausdrücklich herstellen, nicht nur
   anhand gleicher E-Mail-Adresse.
3. Automatik aktivieren und speichern, danach **Jetzt synchronisieren** oder
   `php artisan devices:sync-microsoft --force` verwenden. Der CLI-Befehl
   plant nur ein; er darf keine bestehende laufende Synchronisierung doppeln.
4. Konkrete Lauf-ID, `queued_at`, `started_at`, `finished_at`, Ergebnis und
   sichere Zähler prüfen. Der Lauf gilt nur für aktuellen Tenant und aktuellen
   Konfigurationsfingerprint. `completed` kann Klärungsbedarf enthalten;
   Intune-Rechte-/Lizenzfehler und Zuordnungskonflikte gesondert abnehmen.
5. Einen bekannten Windows-Rechner samt Entra-ID, optional Intune-Seriennummer
   und Hauptbenutzer mit dem Microsoft-Admin-Center vergleichen. Ein zweiter
   Abgleich darf keine doppelten Geräte/Zuteilungen anlegen. Rückgabe oder
   abweichende lokale Zuteilung muss erhalten bleiben; ein einfacher Entra-
   Eintrag darf keine bestandene MDM-/App-/Fernsupport-Prüfung vortäuschen.

### Gate M4 – Anzeige, Wiederanlauf und Fehlergrenzen

- Geräteübersicht: nach Start höchstens 60 Statusabrufe alle fünf Sekunden
  (rund fünf Minuten sichtbare Seite); bei Abschluss Liste aktualisieren und
  Polling beenden, bei Fehler oder Lauf-ID-Wechsel ebenfalls stoppen.
- Setup: nur bei wartendem/laufendem Auftrag alle zehn Sekunden, maximal zwei
  Minuten; danach **Status aktualisieren**. Diese UI-Fristen stoppen keinen
  Hintergrundauftrag und verändern nicht den üblichen 15-Minuten-Graphabruf.
- `overdue` nach zwei Minuten Warteschlange oder fünf Minuten Laufzeit
  signalisiert Prüfbedarf, auch bei einer Probe. Nicht blind erneut klicken
  oder die gesamte Queue leeren. Historische erfolgreiche Importzähler
  überstimmen einen aktuellen Abbruch nicht.
- Fehlt die zu einer Probe gehörende echte Queuezeile, wird sie als verloren/
  fehlgeschlagen sichtbar und der Test erneut möglich. Ein weiterhin real
  wartender, nur überfälliger Job bleibt dedupliziert; keinen zweiten Job erzwingen.
- Worker-/Scheduler-Ausfall, Cacheverlust, Timeout und geänderte Konfiguration
  zuerst isoliert prüfen. Keine produktiven Mail-/Call-/Device-Worker für
  Fehlerproben abschalten. Sichere Statusausgaben statt Rohjobs, Credentials
  oder vollständiger Inventare dokumentieren.

Gateabschluss: separate Belege für M1 bis M4, inklusive realem Worker,
Schedulerkontakt und idempotentem Windows-Pilot. Bis dahin keine Aussage
„produktionsbereit“ aus einem grünen Einzeltest ableiten.

Die Microsoft-Synchronisierung benötigt keine Freigabe des Mutationsschalters,
weil sie bei Microsoft ausschließlich liest und nur RailTime-Inventar schreibt.

## Stufe A – lokal ohne externe Mutation

1. Migration nur in einer frischen Testdatenbank ausführen.
2. Als Benutzer #1 unter **Einstellungen → Geräte-Setup** kontrollieren, dass
   der globale Schalter für produktive Gerätebefehle ausgeschaltet ist.
3. Der deterministische Simulationsprovider steht ausschließlich in
   `local`/`testing` zur Verfügung und benötigt keine `DEVICE_*`-Variable.
4. Gerät anlegen, Mitarbeiter zuweisen, Microsoft-/Google-Identitätsreferenz
   erfassen, Enrollment-E-Mail erzeugen und Link einmalig einlösen.
5. Readiness zeigt ehrlich offene Provider-/Nutzeraktionen.
6. Berechtigungs-, Token-, Audit-, Queue- und Wipe-Vier-Augen-Tests ausführen.

   ```bash
   php artisan migrate
   php vendor/bin/phpunit tests/Feature/DeviceManagementTest.php
   npm run build
   ```

## Stufe B – Connector-Labor

Voraussetzungen:

- eigene TLS-Hosts und minimale Servicekonten,
- getrennte Connector-Tokens und HMAC-Webhook-Secrets,
- Queue-Worker für `devices`,
- ausgewiesene Laborgeräte,
- Backup/Restore und Monitoring.
- implementierter und gegen
  [`connector-contract.openapi.yaml`](connector-contract.openapi.yaml)
  validierter Connector.

Einrichtung in RailTime:

1. Unter **Einstellungen → Geräte-Setup** die bestätigte Basisdomain eintragen.
2. Pro Connector entweder eine HTTPS-Subdomain oder den privaten
   RailTime-Adapterport auf `127.0.0.1` wählen. Die vorbelegten Ports gehören
   zum RailTime-Connectorvertrag und sind keine nativen Tool-Ports.
3. Token und Webhook-Secret eintragen, speichern und anschließend
   **Verbindung prüfen** ausführen. Der Test darf ausschließlich
   `GET /v1/health` aufrufen.
4. Erst nach einem erfolgreichen, protokollierten Health-Test den Provider
   aktivieren. Der globale Mutationsschalter bleibt weiterhin aus.

Ablauf pro Provider:

1. Nur Health/Inventar aktivieren.
2. Enrollment an einem Laborgerät.
3. harmlose Synchronisation.
4. Datei/Paket/freigegebenes Skript, soweit Fähigkeit belegt.
5. Remote-Sitzung mit sichtbarer Nutzerzustimmung, soweit Plattform verlangt.
6. Lock, danach Unlock.
7. Wipe zuletzt, ausschließlich am entbehrlichen Laborgerät nach Vier-Augen-
   Freigabe.
8. Offline-, Timeout-, doppelter Webhook- und falsche-Signatur-Fälle prüfen.

### Konkreter MeshCentral-Laborlauf

Vor dem ersten echten Gerät muss der Adapter aus
[`services/device-connectors/meshcentral`](../../services/device-connectors/meshcentral/README.md)
folgende Gates bestehen:

1. `npm ci --ignore-scripts --no-audit --no-fund` und `npm test` sind grün;
   `node -p "require('./node_modules/meshcentral/package.json').version"`
   liefert exakt `1.2.5`.
2. JSON-Konfiguration, State und Temp liegen außerhalb des Webroots; Config und
   Secretdateien sind `0600`/`0400`, State und Temp `0700`.
3. `meshcentral.url` zeigt auf eine Loopback-WSS-Adresse derselben Maschine;
   die separate MeshCentral-Oberfläche ist per Plesk-TLS abgesichert.
4. Ein falscher Bearer-Token liefert `401`; ein richtiger Token liefert für
   `GET /v1/health` den Vertrag `1.0.0`, Provider `meshcentral`,
   `healthy=true` und einen authentifizierten Upstream.
5. Jeder `POST /v1/enrollments` liefert `409 enrollment_not_supported`, ohne
   `meshctrl.js` aufzurufen. Ein `restart`-Befehl liefert `422`; auch dabei darf
   kein `DevicePower` gestartet werden.
6. Einen separat per MDM/UEM oder kontrolliertem Adminvorgang installierten
   Laboragenten anhand seiner in MeshCentral geprüften nativen Node-ID über
   die mit `devices.manage` geschützte UI
   `Gerät > Provider-Verknüpfungen > Verknüpfen` als aktiven Support-Link zum
   eindeutigen RailTime-Gerät binden. Die Service-Schicht prüft dieselbe
   Berechtigung und ersetzt eine abweichende vorhandene ID nicht still. Keine
   automatische Enrollment-Completion eintragen.
7. `DeviceInfo` liefert nur die datensparsame Diagnosezusammenfassung. Rohes
   Hardware-/Netzwerkinventar wird nicht in RailTime gespiegelt.
8. Ein harmloses, in RailTime freigegebenes PS1- oder SH-Artefakt wird geladen.
   Prüfen: exakter HTTPS-Host, HMAC-Header, kein Redirect, passende Größe,
   passender Header-SHA und tatsächlicher SHA. Erst danach dürfen Upload und
   RunCommand stattfinden.
9. Ein Script gilt nur bei einem korrelationsgebundenen
   `RAILTIME_OK_<hash>`-Marker als `completed=true`. Fehlender Marker, CLI-
   Timeout, falscher Hash, falsche Größe und Offlinegerät müssen geschlossen
   fehlschlagen.
10. Einen laufenden Connector zwischen Journal und Antwort hart beenden. Der
    Retry derselben `correlation_id` darf den Seiteneffekt nicht wiederholen,
    sondern muss als unklarer Ausgang mit `409` zur fachlichen Prüfung landen.

Dieser Adapter stellt keine Lock-/Wipe- oder Accountbereitstellungsfähigkeit
bereit. Ein grüner MeshCentral-Lauf hebt die separaten Apple-, Android-,
OpenUEM- und Identity-Gates nicht auf.

## Stufe C – Pilot

- Zehn Mitarbeitende, schriftlich beschriebener Verwaltungsumfang.
- Keine erzwungenen Resets von Bestandsmobilgeräten.
- Support und Rollback während der Welle besetzt.
- Messgrößen: Abschlussquote, Zeit pro Plattform, Fehlerursache, offene
  Nutzeraktion, Providerlatenz, Supportfälle.

## Go-live-Gate

Der Mutationsschalter wird erst pro Provider freigegeben, wenn Connector,
Laborgerät, Audit, Rechte, Queue, Webhook, Restore und Zertifikatserneuerung
belegt sind. Android Full Management bleibt gesperrt, bis ein qualifizierter
EMM-/Lizenzweg entschieden und getestet wurde.

Die Gerätekonfiguration selbst liegt verschlüsselt in der RailTime-Datenbank;
`DEVICE_*`-Einträge in `.env` werden nicht benötigt. Das ersetzt nicht die
Laravel-Basiswerte wie `APP_KEY`/Datenbankzugang und nicht die jeweils eigene
Konfiguration der auf Plesk betriebenen Open-Source-Dienste.
