# Konten, E-Mail und startklare Übergabe

## Führende Identität

Microsoft Entra ist die führende Mitarbeiteridentität. Der vorhandene
RailTime-Mitarbeiter wird über externe Objekt-ID und UPN referenziert.
Microsoft 365/Exchange Online, Google Workspace/Cloud Identity und Apple
Business werden nicht durch lokale RailTime-Passwörter ersetzt.

Empfohlener Ablauf:

1. Entra-Konto aktiv und passende Microsoft-365-/Exchange-Lizenz belegt.
2. Google-Konto aus Entra provisioniert; SSO-Domain aktiv.
3. Managed Apple Account aus Entra synchronisiert, soweit benötigt.
4. Gerät erhält Apps, E-Mail-/UPN-Vorbelegung, OAuth-Modus, SSO-Erweiterung,
   WLAN/VPN und Zertifikate.
5. Mitarbeiter bestätigt einmal Entra OAuth/MFA; Google und Apple nutzen danach
   die vorbereitete Föderation/SSO.

## Plattformen

| Plattform | Vorbereitet | Einmalige Mitarbeiteraktion |
|---|---|---|
| Windows | Entra Join/Registrierung, Microsoft 365 Apps, Outlook, Teams, OneDrive, WLAN/VPN | Windows-/Entra-Anmeldung mit MFA |
| macOS | Microsoft 365 Apps, Company Portal, Microsoft SSO/Platform SSO, Profile | Entra-Registrierung bestätigen |
| Android | Arbeitsprofil/Fully Managed, Outlook, Authenticator, Google-Apps, App-Konfiguration | Enrollment und OAuth/MFA; Full Management bei Bestand meist erst nach Reset |
| iPhone/iPad | Outlook, Authenticator, Exchange-/Google-Profil, Microsoft-SSO-Erweiterung, Apps | Profilannahme bei Bestand und OAuth/MFA; Supervision meist erst nach Löschen/ADE |

## Outlook- und Google-Konfiguration

Outlook erhält UPN, SMTP-Adresse, `ModernAuth`, erlaubtes Firmenkonto und – je
nach Plattform – die Microsoft-SSO-Erweiterung. Exchange-Mailinhalte bleiben in
Microsoft 365 und werden nicht nach RailTime kopiert.

Google Workspace wird über Entra SSO angebunden. Gmail/Drive/Chrome brauchen
ein echtes Managed Google Account; ein Managed-Google-Play-Konto allein reicht
nicht. Windows bleibt Entra-Anmeldung, weil ein fremder Credential Provider
den Entra Primary Refresh Token nicht zuverlässig ersetzt.

## Übergabe-Checkliste

Ein Gerät darf in RailTime erst als `bereit zur Übergabe` erscheinen, wenn:

- Asset und Seriennummer plausibel sind,
- aktiver Mitarbeiter und Standort zugewiesen sind,
- technisches Enrollment aktuell belegt ist,
- Identitätskonten aktiv und erforderliche Lizenzen vorhanden sind,
- Pflichtapps und Profile providerseitig angewendet wurden,
- OAuth/MFA entweder abgeschlossen oder klar als einzige offene Nutzeraktion
  ausgewiesen ist,
- WLAN/VPN/Zertifikat geprüft sind,
- Remote-Support-Agent erreichbar ist,
- kein blockierender Compliancebefund offen ist.

## E-Mail-Inhalte

Versionierte Nachrichten:

- Enrollment-Einladung und Ablauf-Erinnerung,
- Anmeldung für Microsoft/Google erforderlich,
- Gerät einsatzbereit,
- Einrichtung blockiert mit konkretem Hilfepfad,
- geplanter Reset für Full Management,
- Zertifikat/Profil läuft ab,
- Verlust, Rückgabe und Offboarding.

Jede Nachricht nennt Mitarbeiter, Gerät, Asset-Nummer, Verwaltungsumfang,
Zeitbedarf, Frist, Support und genau einen Link zur authentifizierten RailTime-
Einrichtung. Zugangsdaten werden nur in offiziellen Herstellerdialogen
eingegeben. Passwörter, OAuth-Codes, Recovery Codes, private Schlüssel und
dauerhafte Enrollment-QR-Codes gehören nie in eine E-Mail.

