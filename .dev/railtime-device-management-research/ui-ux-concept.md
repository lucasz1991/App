# UI/UX-Konzept

Die Oberfläche übernimmt die Informationshierarchie der Referenzbilder, wird
aber nativ mit den bestehenden RailTime-Livewire-/Blade-/Tailwind-Komponenten
umgesetzt.

## 1. Geräteübersicht und virtuelles Lager

- Kennzahlen: Gesamt, zugewiesen, Lager, Handlungsbedarf.
- Filter: Plattform, Formfaktor, Lebenszyklus, Compliance, Standort und Suche.
- Liste: Asset/Gerät, Mitarbeiter, Plattform, deklarierter Standort,
  Compliance, Managementstatus und letzte Synchronisierung.
- Rechte Seite/Detail: Standort ist standardmäßig gemeldeter Arbeitsstandort,
  nicht permanente Live-Ortung.
- Unzugewiesene Geräte bilden das virtuelle Lager; Ausgabe, Rücknahme,
  Reparatur und Ausmusterung sind Lebenszyklusaktionen.

Referenz: `mockups/01-devices-overview.png`

## 2. Mitarbeiterprofil

Ein Abschnitt „Geräte“ zeigt aktive und historische Zuweisungen, Readiness,
letzte Synchronisierung und Supportaktion. Die bestehende Mitarbeiterseite
bleibt führend; Geräteverwaltung wird nicht als getrennte Personendatenbank
dupliziert.

Referenz: `mockups/02-employee-devices.png`

## 3. Gerätedetail

Tabs: Überblick, Bereitstellung & Konten, Sicherheit/Compliance, Software &
Dateien, Kommandos/Audit.

Die Aktionsleiste ist capability-basiert:

- Support/Terminal/Datei nur bei erreichbarem Remote-Backend.
- Skript/Paket nur mit freigegebenem Artefakt und SHA-256.
- Lock nur mit Recht und Providerfähigkeit.
- Wipe nur globaler Admin, Grund, zweiter anderer Admin und Labor-/Produktions-
  Sicherheitskontext.
- Nicht verfügbare Funktionen werden mit konkretem Grund erklärt, nicht als
  wirkungsloser Button gezeigt.

Referenz: `mockups/03-device-detail.png`

## 4. Meine Geräte einrichten

- Persönliche, auth-gebundene Einmal-Einladung.
- Pro Gerät aktuelle Stufe, genaue Nutzeraktion und Datenschutzumfang.
- Bestehendes Mobilgerät erklärt eingeschränkten Modus und geplante
  Full-Management-Neueinrichtung.
- Microsoft-/Google-Anmeldung führt ausschließlich in offizielle OAuth-/MFA-
  Dialoge.
- Hilfe/Remote-Support ist auf derselben Seite erreichbar.

Referenz: `mockups/04-self-enrollment.png`

## Statussprache

- `Im Lager`, `Vorbereitung`, `Zugewiesen`, `Im Einsatz`, `Reparatur`,
  `Verloren`, `Ausgemustert` – fachlicher Lebenszyklus.
- `Nicht verwaltet`, `Einladung offen`, `Eingeschränkt verwaltet`,
  `Verwaltet`, `Fehler` – technischer Enrollmentstatus.
- `Unbekannt`, `Konform`, `Warnung`, `Nicht konform` – Compliance.
- `Wartet auf Provider`, `Wartet auf Gerät`, `Nutzeraktion nötig`, `Angewendet`,
  `Bereit`, `Fehler` – Provisionierung.

Diese Ebenen werden nicht in eine einzige irreführende Ampel vermischt.

