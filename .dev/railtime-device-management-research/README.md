# RailTime Geräteverwaltung

Stand: 6. September 2026

## Ergänzung: Microsoft Entra und Windows

Der direkte, lesende Microsoft-Graph-Abgleich ist implementiert. Windows-Geräte
werden regelmäßig aus Entra inventarisiert und optional mit Intune-Daten
ergänzt. Mitarbeiter werden über explizite Tenant-/Objekt-ID-Bindungen
wiedererkannt. Einstellungen, Verbindungstest, Kontenzuordnungsmodal,
Konflikthinweise, dauerhafte Auftragsnachweise und eine eigene Datenbank-Queue benötigen keine neuen
ENV-Variablen. Die bestehende Outlook-Microsoft-Anmeldung kann einen fälligen
Abgleich auslösen; neue Windows-Entra-Einträge werden über den regelmäßigen
Abruf erfasst, nicht über einen direkten Windows-Login-Hook.

Die neue Runtime-Migration `2026_09_06_030000_create_microsoft_device_runs`
ergänzt die Importmigration `020000`. Das Setup trennt Schema, Queue,
Schedulerkontakt, Workerbeleg und Auftragsstatus vom Graph-Verbindungstest.
**Hintergrundverarbeitung testen** sowie
`php artisan devices:microsoft-status --json --probe-worker` prüfen den
echten Queueweg ohne Microsoft-Zugriff. Ein grüner Verbindungstest, CLI-Exitcode 0
oder nur eingeplanter Auftrag ist keine Produktionsfreigabe.

Ziel nach dem Queue-Namenskorrekturrelease: Plesk 8 betreut genau einen
zusätzlichen Worker für `microsoft_devices`
(Timeout 240 Sekunden, Max Time 3600 Sekunden); die Connection
`microsoft_devices` wird isoliert durch RailTime gewählt. Bestehende Queues
bleiben erhalten. Kein zusätzlicher app-eigener Worker und keine neuen
RailTime-ENV-Einrichtungsvariablen; Plesk verwaltet seine `.env.plesk` selbst.
Die sichtbare Geräteübersicht beobachtet einen gestarteten Lauf höchstens
60-mal alle fünf Sekunden, das Setup höchstens zwei Minuten alle zehn Sekunden.

**Live-Gate noch offen:** Die separate App **„RailTime Geräteinventar“** ist
registriert; ausschließlich Application `Device.Read.All` mit
Administratorzustimmung **„Gewährt für RailTime“** ist im Portal bestätigt.
Das unnötige automatisch ergänzte `User.Read` wurde nur dort entfernt,
andere Apps nicht verändert. Tenant-/Client-ID sind im RailTime-Setup
gespeichert und nach erneutem Laden bestätigt. Der Nutzer muss das noch
leere Client-Geheimnis erstellen und direkt im geschützten Setup speichern;
Automatik und Intune bleiben aus. Bestehendes Schema und `pcntl` sind lesend
bestätigt. Release `9bf712dd`, Runtime-Migration `030000`, Paket v8.0.0 und
Cacheaufbau sind live erfolgt. Der Microsoft-Worker läuft noch nicht, weil
die Plesk-UI Bindestriche im Queuenamen ablehnt. Die Korrektur auf kanonisch
`microsoft_devices` (Queue und Connection) benötigt einen Folgerelease;
echte Workerprobe, Graphnachweis und Windows-Pilot bleiben offen.

Einrichtung, Berechtigungen, Worker und Grenzen:
[Microsoft Entra & Windows](microsoft-entra-windows.md).
Die übrigen unten dokumentierten MDM-/Fernsupport-Grenzen bestehen weiter.

Dieser Ordner dokumentiert die recherchierte Zielarchitektur, den Rollout der
bereits deutschlandweit ausgegebenen Geräte und die Umsetzung in der
RailTime-App. Die vier UI-Referenzen liegen unverändert unter `mockups/`.

## Entscheidung in einem Satz

RailTime wird die fachliche Control Plane für Inventar, virtuelles Lager,
Mitarbeiterzuordnung, Enrollment, Kontovorbereitung, Freigaben und Audit. Die
technischen Aktionen werden capability-basiert an austauschbare Geräte- und
Remote-Support-Backends delegiert.

## Implementierter Stand in RailTime

Der fachliche RailTime-Control-Plane-Scope ist im Anwendungscode umgesetzt und
lokal testbar. Dazu gehören:

- Gerätebestand, virtuelles Lager, Standorte, Lebenszyklus und historische
  Mitarbeiterzuordnungen,
- providerbezogene Geräteverknüpfungen, damit beispielsweise OpenUEM als
  primäres Inventar-Backend und MeshCentral als Support-Backend dasselbe
  RailTime-Gerät adressieren können. Die Verknüpfungs-UI unter
  `Gerät > Provider-Verknüpfungen > Verknüpfen` ist durch `devices.manage`
  geschützt; der Service prüft dieselbe Berechtigung und ersetzt eine
  abweichende bestehende ID niemals still,
- an eine konkrete aktive Mitarbeiterzuordnung gebundene Enrollment-
  Einladungen; Rückgabe oder Neuzuweisung widerruft die alte Einladung und
  zugehörige noch nicht ausgeführte Vorbereitungsschritte,
- versionierte Pflichtprofile und eine evidenzbasierte Readiness-Auswertung
  für Enrollment, Identität, Profile, Provider-Synchronisation, Compliance und
  Remote-Support,
- eine persistente, idempotente Identity-Sync-Outbox für serverseitige
  Connector-Aufträge. Sie überträgt nur fachliche Referenzen und Statusdaten,
  niemals Mitarbeiterpasswörter, OAuth-Tokens oder Recovery Codes,
- Queue-Kommandos, Artefakte, Audit, Vier-Augen-Freigabe für Wipe sowie der
  zentrale Mutations-Kill-Switch,
- Datenbankgestützte Provider-Einstellungen im RailTime-Einstellungsbereich
  einschließlich Diagnosefunktionen; für die RailTime-Anbindung werden keine
  zusätzlichen `DEVICE_*`-Umgebungsvariablen benötigt,
- ein striktes Produktions-Gate: Mutierende Kommandos an einen externen
  Provider werden nur nach einem aktuellen erfolgreichen Verbindungstest mit
  unverändertem Ziel-, Secret- und Capability-Fingerprint freigegeben. Eine
  geänderte Konfiguration oder ein roter Health-Check entzieht die Freigabe,
- ein deploybarer MeshCentral-Connector für Health, bereits per nativer Node-ID
  gebundenen Remote-Support, freigegebene Skripte/Artefakte und Diagnose.
  Generische Mesh-Enrollment-Links und Neustarts werden fail-closed abgelehnt,
  weil weder Gerätebindung/Einzelwiderruf noch Abschluss belegbar sind.
  Zugangsdaten liegen nicht in
  dieser Dokumentation und gehören ausschließlich in den geschützten
  Connectorbetrieb.

Dieser Stand ist **keine Produktionsfreigabe für die reale Geräteflotte**. Der
RailTime-Code und der Connector-Vertrag sind vorbereitet; ein echter
End-to-End-Test auf dem vorgesehenen Plesk-Server und mit ausgewiesenen
Laborgeräten ist noch nicht erfolgt.

## Warum kein einzelnes kostenloses UEM gewählt wurde

Die Tiefenrecherche hat keine einzelne, aktiv gepflegte und vollständig freie
Lösung gefunden, die Windows, macOS, Linux, Android, iOS und iPadOS zugleich
mit Enrollment, App-/Datei-/Skriptverteilung, Lock/Wipe, Fernsteuerung, RBAC,
Audit und Account-Bootstrapping produktionsreif abdeckt. Deshalb gilt:

- Desktop-Pilot: OpenUEM für Inventar/Pakete/Richtlinien plus MeshCentral für
  Bildschirm, Terminal und Dateiübertragung.
- Apple-Pilot: NanoMDM, NanoDEP und SCEP als Protokollbausteine, gekapselt
  hinter einem RailTime-Connector; Apple Business Manager, APNs und
  Apps & Books bleiben Pflicht.
- Android: Headwind Community kann einen begrenzten Pilot tragen. Vollständige
  Lock-/Wipe-/unbeaufsichtigte-Remote-Funktionen sind dort nicht als freie
  Community-Funktionen belegt. Ein produktiver Full-Management-Rollout braucht
  daher einen qualifizierten EMM beziehungsweise eine kommerzielle Erweiterung.
- Identität: Microsoft Entra ist führend; Google Workspace/Cloud Identity und
  Apple Business werden föderiert. RailTime speichert niemals Passwörter oder
  Mitarbeiter-Login-Tokens.

## Noch offene externe Gates (NO-GO für Produktion)

- Plesk, der reale MeshCentral-Dienst und mindestens ein ausgewiesenes
  Laborgerät je freizugebendem Zielmodus müssen end-to-end getestet werden.
- MeshAgents müssen vorab über ein qualifiziertes MDM/UEM oder administrativ
  kontrolliert installiert und anschließend mit der geprüften nativen Node-ID
  über `Gerät > Provider-Verknüpfungen > Verknüpfen` als aktiver Support-Link
  zum inventarisierten RailTime-Gerät gebunden werden. Abweichende bestehende
  IDs werden nicht still überschrieben; direkte DB-Eingriffe sind kein
  Produktionsweg.
  MeshCentral meldet kein RailTime-Enrollment und keine automatische
  Completion.
- Native Connector-Adapter für OpenUEM, Headwind und NanoMDM sind noch nicht
  implementiert. Bis dahin dürfen deren Fähigkeiten nicht als produktiv
  verfügbar angezeigt oder freigeschaltet werden.
- Apple benötigt reale Apple-Business-, APNs-, ADE-, Apps-&-Books- und SCEP-
  Einrichtung einschließlich Zertifikats- und Erneuerungsbetrieb.
- Entra/Graph, Google Admin und gegebenenfalls Apple-Föderation benötigen
  freigegebene Mandanten, Serviceidentitäten und minimale externe
  Berechtigungen. Diese Credentials werden nicht in RailTime-Dokumenten oder
  im Quellcode hinterlegt.
- Android Full Management benötigt die Auswahl und Qualifizierung eines
  geeigneten EMM. Headwind Community allein belegt den geforderten
  Gesamtumfang nicht.

Bis diese Gates mit Providerbelegen abgeschlossen sind, bleiben externe
Mutationen abgeschaltet. Simulation und lokale Vertragstests ersetzen keinen
Test am echten Gerät.

## Dokumente

- [Tiefenrecherche](deep-research.md)
- [Zielarchitektur](target-architecture.md)
- [Integrations- und Umsetzungsplan](implementation-plan.md)
- [Konto- und Übergabekonzept](account-and-handover.md)
- [Rollout bestehender Geräte](rollout-existing-devices.md)
- [UI/UX-Konzept](ui-ux-concept.md)
- [Produktions-Testlauf](production-test-runbook.md)
- [Plesk-Connectorbetrieb](plesk-connector-setup.md)
- [Microsoft Entra, Windows und Plesk-Queuebetrieb](microsoft-entra-windows.md)
- [Versionierter Connector-Vertrag (OpenAPI)](connector-contract.openapi.yaml)

## Nicht verhandelbare Sicherheitsgrenzen

- Keine Mitarbeiterpasswörter, OAuth-Codes, Recovery Codes, privaten Schlüssel,
  PFX-Dateien oder dauerhaften Enrollment-Tokens in RailTime oder E-Mails.
- Wipe benötigt einen zweiten, anderen globalen Administrator.
- Externe Mutationen bleiben durch einen zentralen Kill-Switch deaktiviert,
  bis Testorganisation, Connector, Zertifikate und Laborgerät geprüft sind.
- `bereit` bedeutet nachgewiesene Zustände und nicht nur „Mail verschickt“ oder
  „App angefordert“.
- Präzise Standortdaten werden nicht vorausgesetzt. Standard ist der deklarierte
  Arbeits-/Lagerstandort; Providerstandorte werden nur mit Zweck und Zeitstempel
  übernommen.
