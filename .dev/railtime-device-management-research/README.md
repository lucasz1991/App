# RailTime Geräteverwaltung

Stand: 17. August 2026

Dieser Ordner dokumentiert die recherchierte Zielarchitektur, den Rollout der
bereits deutschlandweit ausgegebenen Geräte und die Umsetzung in der
RailTime-App. Die vier UI-Referenzen liegen unverändert unter `mockups/`.

## Entscheidung in einem Satz

RailTime wird die fachliche Control Plane für Inventar, virtuelles Lager,
Mitarbeiterzuordnung, Enrollment, Kontovorbereitung, Freigaben und Audit. Die
technischen Aktionen werden capability-basiert an austauschbare Geräte- und
Remote-Support-Backends delegiert.

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

## Dokumente

- [Tiefenrecherche](deep-research.md)
- [Zielarchitektur](target-architecture.md)
- [Integrations- und Umsetzungsplan](implementation-plan.md)
- [Konto- und Übergabekonzept](account-and-handover.md)
- [Rollout bestehender Geräte](rollout-existing-devices.md)
- [UI/UX-Konzept](ui-ux-concept.md)
- [Produktions-Testlauf](production-test-runbook.md)
- [Plesk-Connectorbetrieb](plesk-connector-setup.md)
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
