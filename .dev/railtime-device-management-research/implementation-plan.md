# Integrations- und Umsetzungsplan

Stand: 6. September 2026

## Ergänzung umgesetzt: Microsoft-Entra-Inventar

Ein nativer lesender Graph-Adapter synchronisiert Windows-Geräte des explizit
konfigurierten Mandanten und ergänzt optional Intune-Hauptbenutzer und
Inventardaten. Die automatische Erstzuordnung erfolgt über Tenant-ID und
Benutzer-Objekt-ID; bestehende Zuteilungen/Rückgaben bleiben erhalten.
Microsoft-Einstellungen, Kontenmodal, Statusfilter, Hintergrundqueue und
gedrosselter Outlook-Anmeldeauslöser sind implementiert. Dauerhaftes Runledger,
atomare Queuebindung, Graph-freie Workerprobe und Betriebsanzeige ergänzen
diesen Stand. Der [Einrichtungsweg](microsoft-entra-windows.md)
enthält die minimalen Graph-Rechte und die Windows-/Intune-Voraussetzungen.
Dieser Abruf ersetzt nicht den weiterhin offenen schreibenden Identity-/MDM-
Connectorbetrieb aus den folgenden Phasen.

Der Ablauf ist bewusst in prüfbare Gates unterteilt. RailTime-Code kann sofort
lokal und gegen einen Simulationsconnector getestet werden; ein echter
Provider wird erst nach seinem eigenen Labor-Gate für Mutationen freigeschaltet.

## Aktueller Freigabestatus

- **Microsoft-Hintergrundbetrieb, Inventar und ausgewählter Einzelpilot live bestätigt.**
  Runtime-Migration `2026_09_06_030000_create_microsoft_device_runs`, getrennte
  Schema-/Queue-/Scheduler-/Workeranzeige, sichere Abbruchzustände und
  `devices:microsoft-status --json [--probe-worker]` sind vorhanden. Plesk 8
  routet ausschließlich die Queue `microsoft_devices` auf die gleichnamige
  Connection. Namenskorrekturrelease `8880cf96`, Migration `030000`, Paket
  v8.0.0 und Cacheaufbau sind live bestätigt. M1/M2: echte Workerprobe und
  automatischer Schedulerkontakt um **13:05:02**, getrennt in CLI/Web belegt.
  Der zuvor abgewiesene Bindestrichname ist damit korrigiert. Die
  separate App **„RailTime Geräteinventar“** ist real registriert; das Portal
  bestätigt ausschließlich Application `Device.Read.All` und Adminzustimmung
  **„Gewährt für RailTime“**. Unnötiges automatisch ergänztes `User.Read` nur
  aus dieser App entfernt, keine andere App verändert. Tenant-/Client-ID im
  RailTime-Setup gespeichert und nach erneutem Laden bestätigt. Frische
  Webansicht bestätigt inzwischen verschlüsseltes Secret und aktive
  15-Minuten-Synchronisierung; Graph-Test **14:15 erfolgreich**. Automatischer
  Lauf geplant 14:15:02 / Worker 14:15:05 / fertig 14:15:08: **98 gefunden, 98 neu,
  0 aktualisiert**. Wiederholung geplant 14:16:20 / Worker 14:16:23 / fertig 14:16:26:
  **98 gefunden, 0 neu, 98 aktualisiert**. Beide jeweils 0 zugeordnet,
  0 Konflikte, 0 übersprungen; historische Übersicht um 14:16:
  98 Geräte / 98 Treffer / 0 aktive Ausgaben.
  Schema, Queue, Scheduler und Worker bereit; Intune aus, keine Schreibrechte.
  Frühere Secret-false-/Nullbestandsflags sind ausschließlich Historie vor
  diesem erfolgreichen Abruf. Der Nutzer wählte anschließend genau einen
  lokalen Bestands-PC und geeigneten Mitarbeiter, kein System-/Superadmin.
  Geräte-/Tenantidentität exakt verifiziert; Gerät mit Notiz manuell zugewiesen
  und im Sync 14:52 unverändert erhalten. Nach direkter lesender Entra-Prüfung
  von Gerät und bestehender identischer Kontoidentität wurde ausdrücklich
  `tenant_initial_binding` über die UI bestätigt. Kein neues Konto/Passwort,
  kein Microsoft-Schreibaufruf, kein E-Mail-only-Matching. Sync 14:55 und Wiederholung 14:57
  zeigten „Microsoft-Konto und Mitarbeiter stimmen überein“; frische produktive
  Detailseite bestätigte denselben gewählten Mitarbeiter und 98 / 1 / 97
  (gesamt / aktive Ausgabe / unzugeordnet). Diese drei Zeiten sind nur minutengenau.
  Damit ist dieser manuelle Einzelpilot abgenommen, keine automatische
  Erstzuweisung durch Windows-Login. Windows-Sitzung/Workplace-Registrierung
  unverändert, kein neuer Windows-Logintest. Standort, vollständige Inventar-
  und echte Seriennummernprüfung sowie MDM-/Intune-/Fernwartung bleiben offen.
  Die Geräte-Kommandotabelle ist leer; allgemeines Activity-Log nicht geprüft.
  Doppelte Anzeigenamen allein sind kein
  Duplikatbeleg. „Virtuelles Lager“ bezeichnet nur nicht zugewiesene Geräte,
  keinen bestätigten physischen Lagerbestand.
  Lokaler Gitstand `9bb35ee6` stammt aus anderem Task; letzter expliziter
  Plesk-Dashboardbeleg bleibt `8880cf96`. Ein neuer Livecommit wurde nicht
  geprüft; dieser Nachlauf änderte weder Quellcode noch Deployment.
- **RailTime Control Plane: umgesetzt und lokal testbar.** Das umfasst
  Inventar/Lager, Mitarbeiterzuordnung, providerbezogene Geräteverknüpfungen,
  assignment-gebundene Enrollments, Identitätsreferenzen, versionierte Profile,
  Readiness, Audit, Kommandos, Vier-Augen-Wipe und Provider-Einstellungen.
- **Produktions-Gate: umgesetzt.** Externe mutierende Kommandos erfordern einen
  höchstens 15 Minuten alten erfolgreichen Health-Test mit unverändertem
  Ziel-, Secret- und Capability-Fingerprint. Simulation kann dieses Gate nicht
  öffnen; Konfigurationsänderung oder roter Health-Check schließen es wieder.
- **Identity-Sync: RailTime-Seite umgesetzt.** Eine persistente, idempotente
  Outbox kann die kontenbezogene Soll-Konfiguration an einen Connector
  übergeben und Ergebnisse nachführen. Reale Entra/Graph-, Google-Admin- und
  Apple-Föderationsaufrufe sind ohne externe Mandanten und Credentials nicht
  freigegeben.
- **MeshCentral-Connector: deploybarer, fail-closed begrenzter Baustein
  umgesetzt.** Health, Remote-Support für bereits gebundene native Node-IDs,
  kontrollierte Skript-/Artefaktausführung und Diagnose sind über den
  versionierten Connector-Vertrag vorgesehen. Mesh-Enrollment und Neustart
  werden ausdrücklich nicht gemeldet und vom Endpoint abgelehnt. Ein echter
  Plesk-/MeshCentral-/Laborgeräte-End-to-End-Test steht aus.
- **Externe Provider: NO-GO.** Native OpenUEM-, Headwind- und NanoMDM-Adapter
  sind noch nicht implementiert. Apple-Plattformdienste und Android EMM sind
  noch nicht qualifiziert beziehungsweise provisioniert.

Keine dieser Aussagen ist eine Freigabe für Wipe, Lock, Kontenbereitstellung
oder Skriptausführung auf produktiv verwendeten Mitarbeitergeräten.

## Nächster Microsoft-Integrationsschritt – geprüfter Betriebsrollout

1. Ein gemeinsames Deploymentfenster mit anderen RailTime-Releases abstimmen.
   Änderungen, Datenbank und bisherige Plesk-Queuewerte sichern. Plesk-Paket
   v8.0.0 und isolierten Workeradapter zusammen ausliefern; Importmigration
   `020000` und neue Runtime-Migration `030000` prüfen beziehungsweise ausführen.
2. Bestehende Plesk-Queues vollständig erhalten. Genau einen zusätzlichen
   Nach dem Namenskorrekturrelease Worker `microsoft_devices` aktivieren:
   Timeout `240`, Max Jobs `0`,
   Max Time `3600`, Stop When Empty aus. Connectionzuordnung erfolgt durch
   RailTime; die bisherige Standardqueue wird nicht umgestellt. Plesk besitzt
   den Lifecycle; kein zusätzlicher app-eigener Worker oder zweiter Prozessplan.
3. Minutengenauen bestehenden Scheduler und den internen Fünfminuten-Eintrag
   prüfen. Mit **Hintergrundverarbeitung testen** oder
   `devices:microsoft-status --json --probe-worker` einen echten Workerbeleg
   erzeugen; danach lesend `devices:microsoft-status --json` ausführen.
   Ohne `completed` plus `acknowledged_at` ist das Worker-Gate offen.
4. Genehmigte separate Entra-App, minimales Application-Recht `Device.Read.All`
   und Administratorzustimmung einrichten. Secret ausschließlich im geschützten
   RailTime-Setup übernehmen; nicht in Plan, Report, Chat oder Repository.
   Intune nur nach separatem Lizenz-/Rechtecheck aktivieren.
5. Graph-Verbindung prüfen, Mitarbeiter explizit über Tenant-/Objekt-ID binden,
   danach **Jetzt synchronisieren**. Geräteübersicht folgt dem konkreten
   Lauf bis zu 60-mal alle fünf Sekunden; Setup folgt offenen Aufträgen
   maximal zwei Minuten alle zehn Sekunden. Danach bei Bedarf manuell
   aktualisieren. Polling ist keine Änderung am Graph-Abrufintervall.
6. Ein bekanntes Windows-Gerät, zweiten idempotenten Abruf und Konfliktfälle
   gegen Microsoft und RailTime nachweisen. Aktuelle Lauf-ID, Zeiten und
   sichere Zähler dokumentieren, keine Rohinventare oder Geheimnisse.
   Fehlenden Worker, fehlende Rechte und Offline-/Fehlerfälle zunächst
   isoliert prüfen, keine bestehenden produktiven Worker für einen Test stoppen.

Abnahmekriterium: Schema-/Queuecheck, echte Workerquittierung, frischer
Schedulerkontakt, Graph-Test und tatsächlicher Import werden einzeln belegt.
`completed` kann Klärungsbedarf enthalten; `overdue` ist keine automatische
Fehlerfreigabe und ein alter erfolgreicher Import überstimmt keinen neuen
Abbruch. Der [Produktions-Testlauf](production-test-runbook.md) ist das
verbindliche Betriebsrunbook. Die folgenden MDM-/Kontenbereitstellungsgates
bleiben unabhängig vom lesenden Microsoft-Inventar bestehen.

## Phase 1 – RailTime Control Plane (umgesetzt)

- Geräte, virtuelles Lager, deklarierte Standorte und Lebenszyklus modellieren.
- Aktive/historische Mitarbeiterzuweisungen und Übergabezustand modellieren.
- Providerbezogene Geräteverknüpfungen statt einer einzigen globalen externen
  Geräte-ID modellieren.
- Identitätsreferenzen für Microsoft 365, Google und Apple ohne Passwörter.
- Persistente idempotente Identity-Sync-Outbox für Connector-Aufträge.
- Versionierte Pflichtprofile und evidenzbasierte Readiness Checks.
- Gehashte Einmal-Enrollment-Einladungen samt E-Mail, gebunden an die konkrete
  aktive Mitarbeiterzuordnung.
- Capability-basierte Provider-Registry und Queue-Kommandos.
- Upload-Artefakte mit SHA-256, privatem Storage und expliziter Freigabe.
- Rechte in bestehende Team-RBAC integrieren; Wipe/Providerkonfiguration nicht
  delegieren.
- Adminseite, Gerätedetail, Mitarbeiterbezug und „Meine Geräte einrichten“.
- Datenbankgestützte Provider-Konfiguration und Funktionstests im
  Einstellungsbereich statt zusätzlicher `DEVICE_*`-Umgebungsvariablen.
- Striktes, zeitlich begrenztes Produktions-Gate mit Konfigurationsfingerprint;
  ein Health-Test aktiviert externe Kommandos nicht automatisch.

Abnahme des Code-Scope: automatisierte Migration-, Route-, Gate-, Token-,
Readiness-, Identity-, Provider- und Command-Tests. Die responsive
Browserprüfung und der reale Provider-Test bleiben getrennte Abnahmen.

## Phase 2 – Desktop-Labor (Connector umgesetzt, Labor-Gate offen)

- OpenUEM und MeshCentral auf separaten TLS-Hosts installieren.
- Den deploybaren MeshCentral-Connector mit minimalem Servicekonto und
  geschützter dateibasierter Laufzeitkonfiguration auf Plesk bereitstellen.
- MeshAgent per qualifiziertem UEM/MDM oder kontrolliertem Adminvorgang auf dem
  Laborgerät vorinstallieren, seine native Node-ID in MeshCentral prüfen und
  sie im autorisierten RailTime-Dialog `Provider-Verknüpfungen > Verknüpfen`
  als aktiven Support-Link zum eindeutigen RailTime-Gerät binden. Keine
  automatische Enrollment-Completion behaupten.
- Einen nativen OpenUEM-Adapter erst nach Prüfung einer belastbaren
  Schnittstelle implementieren; bis dahin keine OpenUEM-Fähigkeit freigeben.
- Ein Windows-, ein macOS- und ein Linux-Laborgerät registrieren.
- Inventar, Datei, freigegebenes Skript, Paket, Remote-Sitzung und Offline-
  Nachlauf testen.
- RailTime Outlook-/Signaturpaket als versioniertes Artefakt ausrollen; der
  vorhandene Classic-Outlook-Installer bleibt getrennt vom neuen Outlook.

Abnahme: Jeder Vorgang besitzt Korrelations-ID, Providerbeleg, RailTime-Audit
und eindeutigen Fehlerzustand. Keine Schaltfläche behauptet Erfolg vor dem
Providerbeleg.

Aktuelles Gate: **NO-GO**, solange kein realer Plesk-/MeshCentral-Endpunkt und
kein ausgewiesenes Laborgerät erfolgreich über denselben Connector-Vertrag
getestet wurden.

## Phase 3 – Identität und startklare Übergabe (RailTime-Outbox umgesetzt,
externe Provisionierung offen)

- Entra/Microsoft-365-Domain, MFA und Lizenzen prüfen.
- Google Workspace/Cloud Identity über Entra SSO und Provisionierung verbinden.
- Apple Business direkt mit Entra föderieren.
- Outlook, Microsoft 365 Apps, Teams, OneDrive, Authenticator/Company Portal,
  Google-Apps, WLAN/VPN und SCEP-Profile versionieren.
- Mitarbeiter führt beim ersten Start genau den offiziellen OAuth-/MFA-Schritt
  aus; RailTime zeigt diesen als offene Nutzeraktion.

RailTime speichert dabei nur externe Identitätsreferenzen, Sollzuweisungen und
Providerbelege. Passwörter, OAuth-Codes, Recovery Codes und dauerhafte Login-
Tokens sind ausdrücklich ausgeschlossen.

Abnahme: `bereit` nur bei vorhandener aktiver Identität, angewendeten
Pflichtprofilen, sichtbaren Pflichtapps, aktuellem Enrollment, erreichbarem
Remote-Agent und ohne blockierende Compliance-Abweichung.

Aktuelles Gate: **NO-GO**, bis Entra/Graph, Google Admin und Apple-Föderation in
freigegebenen Testmandanten mit minimalen Serviceberechtigungen end-to-end
geprüft wurden.

## Phase 4 – Apple-Labor (offen / NO-GO)

- Apple Business, APNs, ADE, Apps & Books, NanoMDM, NanoDEP und SCEP einrichten.
- Ein vorhandenes unsupervised iPhone per Nutzeraktion enrollen.
- Ein gelöschtes Labor-iPhone/iPad per ADE supervised einrichten.
- Ein macOS-Gerät mit Platform/Microsoft SSO prüfen.
- Lock/Wipe ausschließlich am ausgewiesenen Laborgerät nach Vier-Augen-
  Freigabe testen.

Voraussetzungen: Apple Business Manager, APNs, ADE, Apps & Books und SCEP mit
realen Zertifikaten und dokumentiertem Erneuerungsbetrieb. Der native NanoMDM-
Connector ist noch zu implementieren; NanoMDM allein ersetzt diese Apple-
Dienste nicht.

## Phase 5 – Android-Entscheidung und Labor (offen / NO-GO)

- Community-Pilot für Arbeitsprofil/Kiosk/APK-Verteilung separat bewerten.
- Für Full Device Owner, Managed Google Play, Lock/Wipe und unattended Remote
  einen nachweislich qualifizierten EMM auswählen; keine direkte interne
  Android-Management-API-Nutzung.
- Ein Bestandsgerät per Arbeitsprofil ohne Reset und ein Laborgerät nach Reset
  als Fully Managed testen.

Der native Headwind-Adapter ist noch nicht implementiert. Eine
Produktionsfreigabe setzt zusätzlich die dokumentierte Qualifizierung des
gewählten Android-EMM und seiner tatsächlich verfügbaren Community- oder
Lizenzfunktionen voraus.

## Phase 6 – Produktionshärtung (offen)

- Getrennte Test-/Produktionscredentials und Provider-Servicekonten.
- Queue-Worker für `devices`, Monitoring, Backups, Restore-Test und Upgradeplan.
- Datenschutz-/Betriebsvereinbarung, Aufbewahrung und Standortzweck festhalten.
- Notfall- und Offboarding-Runbooks testen.
- Erst danach Mutations-Kill-Switch pro geprüftem Provider aktivieren.

Die RailTime-seitige Freigabelogik ist bereits implementiert, ersetzt aber
nicht diese betriebliche Abnahme. Ohne aktuelle Health-Evidenz und passenden
Konfigurationsfingerprint bleiben externe Mutationen technisch gesperrt.

## Definition of Done

Produktiv ist nicht „der Container läuft“, sondern:

1. mindestens ein echtes Gerät je Zielmodus wurde end-to-end geprüft,
2. alle angebotenen UI-Aktionen besitzen eine belegte Providerfähigkeit,
3. Audit, Freigabe, Offlinezustand, Retry und Fehlerszenario funktionieren,
4. Restore, Zertifikatserneuerung und Offboarding sind getestet,
5. Mitarbeiter sehen Verwaltungsumfang und offene eigene Schritte verständlich.

Zusätzlich gilt für den Stand vom 23. August 2026: Die lokale Simulation, ein
grüner Unit-/Feature-Test oder ein startender Connector-Prozess sind kein
Produktionsnachweis. Erst reale Providerbelege und Laborgeräte-Tests schließen
die jeweiligen NO-GO-Gates.
