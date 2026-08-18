# Integrations- und Umsetzungsplan

Der Ablauf ist bewusst in prüfbare Gates unterteilt. RailTime-Code kann sofort
lokal und gegen einen Simulationsconnector getestet werden; ein echter
Provider wird erst nach seinem eigenen Labor-Gate für Mutationen freigeschaltet.

## Phase 1 – RailTime Control Plane

- Geräte, virtuelles Lager, deklarierte Standorte und Lebenszyklus modellieren.
- Aktive/historische Mitarbeiterzuweisungen und Übergabezustand modellieren.
- Identitätsreferenzen für Microsoft 365, Google und Apple ohne Passwörter.
- Versionierte Provisionierungsprofile und Readiness Checks.
- Gehashte Einmal-Enrollment-Einladungen samt E-Mail.
- Capability-basierte Provider-Registry und Queue-Kommandos.
- Upload-Artefakte mit SHA-256, privatem Storage und expliziter Freigabe.
- Rechte in bestehende Team-RBAC integrieren; Wipe/Providerkonfiguration nicht
  delegieren.
- Adminseite, Gerätedetail, Mitarbeiterbezug und „Meine Geräte einrichten“.

Abnahme: SQLite-In-Memory-Tests, PHP-Lint/Pint, Vite-Build, Route-/Gate-/Token-
und Command-Tests, responsive Browserprüfung.

## Phase 2 – Desktop-Labor

- OpenUEM und MeshCentral auf separaten TLS-Hosts installieren.
- Connector-Service mit minimalen Servicekonten anbinden.
- Ein Windows-, ein macOS- und ein Linux-Laborgerät registrieren.
- Inventar, Datei, freigegebenes Skript, Paket, Remote-Sitzung und Offline-
  Nachlauf testen.
- RailTime Outlook-/Signaturpaket als versioniertes Artefakt ausrollen; der
  vorhandene Classic-Outlook-Installer bleibt getrennt vom neuen Outlook.

Abnahme: Jeder Vorgang besitzt Korrelations-ID, Providerbeleg, RailTime-Audit
und eindeutigen Fehlerzustand. Keine Schaltfläche behauptet Erfolg vor dem
Providerbeleg.

## Phase 3 – Identität und startklare Übergabe

- Entra/Microsoft-365-Domain, MFA und Lizenzen prüfen.
- Google Workspace/Cloud Identity über Entra SSO und Provisionierung verbinden.
- Apple Business direkt mit Entra föderieren.
- Outlook, Microsoft 365 Apps, Teams, OneDrive, Authenticator/Company Portal,
  Google-Apps, WLAN/VPN und SCEP-Profile versionieren.
- Mitarbeiter führt beim ersten Start genau den offiziellen OAuth-/MFA-Schritt
  aus; RailTime zeigt diesen als offene Nutzeraktion.

Abnahme: `bereit` nur bei vorhandener aktiver Identität, angewendeten
Pflichtprofilen, sichtbaren Pflichtapps, aktuellem Enrollment, erreichbarem
Remote-Agent und ohne blockierende Compliance-Abweichung.

## Phase 4 – Apple-Labor

- Apple Business, APNs, ADE, Apps & Books, NanoMDM, NanoDEP und SCEP einrichten.
- Ein vorhandenes unsupervised iPhone per Nutzeraktion enrollen.
- Ein gelöschtes Labor-iPhone/iPad per ADE supervised einrichten.
- Ein macOS-Gerät mit Platform/Microsoft SSO prüfen.
- Lock/Wipe ausschließlich am ausgewiesenen Laborgerät nach Vier-Augen-
  Freigabe testen.

## Phase 5 – Android-Entscheidung und Labor

- Community-Pilot für Arbeitsprofil/Kiosk/APK-Verteilung separat bewerten.
- Für Full Device Owner, Managed Google Play, Lock/Wipe und unattended Remote
  einen nachweislich qualifizierten EMM auswählen; keine direkte interne
  Android-Management-API-Nutzung.
- Ein Bestandsgerät per Arbeitsprofil ohne Reset und ein Laborgerät nach Reset
  als Fully Managed testen.

## Phase 6 – Produktionshärtung

- Getrennte Test-/Produktionscredentials und Provider-Servicekonten.
- Queue-Worker für `devices`, Monitoring, Backups, Restore-Test und Upgradeplan.
- Datenschutz-/Betriebsvereinbarung, Aufbewahrung und Standortzweck festhalten.
- Notfall- und Offboarding-Runbooks testen.
- Erst danach Mutations-Kill-Switch pro geprüftem Provider aktivieren.

## Definition of Done

Produktiv ist nicht „der Container läuft“, sondern:

1. mindestens ein echtes Gerät je Zielmodus wurde end-to-end geprüft,
2. alle angebotenen UI-Aktionen besitzen eine belegte Providerfähigkeit,
3. Audit, Freigabe, Offlinezustand, Retry und Fehlerszenario funktionieren,
4. Restore, Zertifikatserneuerung und Offboarding sind getestet,
5. Mitarbeiter sehen Verwaltungsumfang und offene eigene Schritte verständlich.

