# Tiefenrecherche: selbst gehostete Geräteverwaltung

## Bewertungsmaßstab

Bewertet wurden Quelloffenheit und Lizenzgrenzen, Eigenbetrieb, Plattformen,
Enrollment, Inventar, Profile, Software/Dateien/Skripte, Lock/Wipe,
Fernsupport, API/Automatisierung, RBAC/Audit und Projektaktivität. Eine bloße
Agent-Installation wurde nicht mit vollständigem MDM gleichgesetzt.

## Ergebnis-Matrix

| Baustein | Stärken | Belegte Grenze | Rolle im Zielbild |
|---|---|---|---|
| OpenUEM | Windows/macOS/Linux-Inventar, Pakete, Skripte und Profile; selbst hostbar | Keine öffentlich belastbar dokumentierte stabile REST-API; kein Mobile-MDM | Desktop-Backend hinter eigenem Connector |
| MeshCentral | Selbst gehosteter Remote-Desktop, Terminal, Dateiübertragung, Geräte-Gruppen und Automatisierung | Kein vollständiges UEM/MDM und keine mobile Apple-/Android-Verwaltung | Fernsupport und Notfallzugriff für Laptops |
| Headwind MDM Community | Android-Device-Owner-/Kiosk-Basis, Richtlinien und APK-Verteilung im Eigenbetrieb | Vollständige Lock/Wipe/unattended-Remote-Funktionen werden als Enterprise-Vorteile ausgewiesen; Full Device Owner bei Bestandsgeräten erfordert Reset | Begrenzter Android-Pilot, nicht als vollständige kostenlose UEM-Zusage |
| NanoMDM + NanoDEP + SCEP | Aktive freie Apple-Protokollbausteine, APNs-/ADE-fähige Grundlage | Kein fertiges UEM-Portal; Profile, Befehle, Apps & Books, Statusmodell und Orchestrierung müssen ergänzt werden | Apple-MDM hinter RailTime-Connector |
| RustDesk OSS | Selbst gehostete Remote-Sitzung | Freie Edition deckt zentrale Accounts/API/RBAC/Audit nicht vollständig ab | Nicht primärer Zielbaustein; MeshCentral ist für die RailTime-Orchestrierung geeigneter |
| Fleet Free | Gute API, Inventar und Osquery-Basis | App-Management, Lock/Wipe, feines RBAC und Audit liegen für den Zielumfang überwiegend in Premium | Gute kommerzielle Alternative, aber nicht das gewünschte vollständig freie Ziel |
| Microsoft Intune | Reifes plattformübergreifendes UEM und Microsoft-365-Integration | Laufende Lizenzkosten und Cloudbetrieb | Referenz/Fallback, nicht freie Zielarchitektur |

## Kritische Android-Feststellung

Die direkte Android Management API ist keine zulässige Abkürzung für eine
reine interne RailTime-Eigenlösung. Googles aktuelle Permissible-Usage-Regeln
beschränken die API auf kommerzielle EMM-/Device-Trust-Anbieter für externe
Kunden und schließen eine ausschließlich interne First-Party-Lösung aus.
RailTime darf deshalb einen qualifizierten EMM anbinden, aber nicht selbst als
internes AM-API-EMM auftreten.

Quelle: [Android Management API – Permissible Usage](https://developers.google.com/android/management/permissible-usage)

## Apple ist ein Ökosystem, kein einzelner Container

Für einen realen Apple-Test werden mindestens benötigt:

1. Apple Business Manager mit verifizierter Domain.
2. APNs-MDM-Push-Zertifikat.
3. Automated Device Enrollment beziehungsweise Account-/Profile Enrollment.
4. NanoMDM als MDM-Kern, NanoDEP für ADE und SCEP/PKI für Zertifikate.
5. Eigene versionierte Profile und ein Connector, der Kommandos sowie
   Rückmeldungen in das RailTime-Statusmodell übersetzt.
6. Apps-&-Books-Token für gerätebasierte App-Zuweisung.

Quellen: [Apple Device Enrollment](https://support.apple.com/guide/deployment/device-enrollment-and-device-management-depd1c27dfe6/web), [NanoMDM](https://github.com/micromdm/nanomdm), [NanoDEP](https://github.com/micromdm/nanodep), [SCEP](https://github.com/micromdm/scep)

## Kontoanmeldung und E-Mail

MDM darf UPN/E-Mail-Adresse, Server, OAuth-Modus, Apps und SSO-Erweiterungen
vorkonfigurieren. Es darf keinen Mitarbeiter durch gespeicherte Passwörter
„automatisch einloggen“. Der sichere Zielzustand ist ein einmaliger offizieller
OAuth-/SSO-/MFA-Dialog und anschließend Single Sign-on.

- Outlook/Exchange Online: Modern Authentication, vorbefüllte UPN/SMTP-Adresse,
  Microsoft-SSO-Erweiterung auf Apple und Entra-PRT auf Windows.
- Google: Entra-zu-Google SSO/Provisionierung; Workspace-/Cloud-Identity-Konto
  für Gmail/Drive/Chrome. Ein Managed-Google-Play-Konto ist kein Gmail-Konto.
- Apple: Managed Apple Accounts durch Entra-Föderation/Directory Sync;
  gerätebasierte Apps benötigen keinen persönlichen Apple-Account.

Quellen: [Outlook Modern Authentication](https://learn.microsoft.com/en-us/exchange/clients-and-mobile-in-exchange-online/outlook-for-ios-and-android/setup-with-modern-authentication), [Microsoft Enterprise SSO für Apple](https://learn.microsoft.com/en-us/entra/identity-platform/apple-sso-plugin), [Google SSO](https://support.google.com/a/answer/12032922), [Apple-Föderation](https://support.apple.com/guide/business/intro-to-federated-authentication-axmb19317543/1/web/1)

## Schlussfolgerung

Ein vollständig kostenloses, fertiges Gesamtprodukt wäre eine falsche Zusage.
Produktionsfähig ist stattdessen eine RailTime-Control-Plane, die Fähigkeiten
pro Provider explizit kennt, fehlende Fähigkeiten sperrt und freie Bausteine
nur dort nutzt, wo ihr Vertrag tatsächlich belegt ist. Für Android Full
Management bleibt vor dem Go-live eine Provider-/Lizenzentscheidung offen.

