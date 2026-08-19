# RailTime – Projektanalyse, Optimierungs- und Aufräumplan

**Repository:** `lucasz1991/App`  
**Analysierter Branch:** `master`  
**Stand:** 19. August 2026  
**Status:** Planungsdokument – keine Datenbank- oder Strukturänderung durch dieses Dokument

---

## 1. Ziel des Dokuments

Dieses Dokument beschreibt einen sicheren, schrittweisen Plan zur technischen Bereinigung und Weiterentwicklung der RailTime-Anwendung. Im Mittelpunkt stehen:

- Projektstruktur und fachliche Modulgrenzen
- Laravel-, Livewire- und Service-Architektur
- Datenmodelle und Beziehungen
- Migrationen und Datenmigrationen
- Berechtigungen und Rollen
- Datei-, Chat-, Operations- und Geräteverwaltung
- Tests, CI, Monitoring und Repository-Hygiene
- konkrete Umsetzungsphasen mit Abnahmekriterien

Die Empfehlung ist ausdrücklich **kein kompletter Rewrite**. Das Projekt besitzt bereits eine tragfähige fachliche und technische Basis. Die vorhandene Anwendung sollte durch kontrollierte, additive Refactorings stabilisiert werden.

---

## 2. Gesamtbewertung

### 2.1 Positive Ausgangslage

Das Projekt enthält bereits mehrere gute Grundlagen:

- fachliche Services für Operations, Chat, Calls, Marketing und Geräteverwaltung
- Eloquent-Enums für zentrale Statuswerte
- UTC-basierte Zeitplanung mit Speicherung der ursprünglichen IANA-Zeitzone
- Verschlüsselung sensibler Mitarbeiterdaten
- Verschlüsselung von Chat-Inhalten
- Provider- und Connector-Abstraktionen für die Geräteverwaltung
- umfangreiche Feature- und Frontendtests
- Transaktionen bei mehreren kritischen Abläufen
- klare Sicherheitsmaßnahmen bei Geräteenrollment und Gerätebefehlen
- getrennte Admin- und Benutzeroberflächen

Diese Basis sollte weiterverwendet werden.

### 2.2 Hauptprobleme

Das Projekt ist in kurzer Zeit stark gewachsen. Dadurch sind mehrere technische Kopplungen entstanden:

1. Migrationen enthalten teilweise Schemaänderungen, Datenkorrekturen, Inhaltsversionierungen, Dateiumzüge und Aufrufe aktueller Anwendungservices gleichzeitig.
2. Einzelne Models und Livewire-Komponenten übernehmen zu viele Verantwortlichkeiten.
3. Tests existieren, werden aber durch die aktuelle `.gitignore`-Strategie und fehlerhafte NPM-Testpfade teilweise ausgebremst.
4. PHPUnit verwendet standardmäßig SQLite, obwohl die Produktion relevante MySQL-/MariaDB-Eigenschaften besitzt.
5. Rollen, Teamtypen und Superadmin-Logik hängen teilweise von IDs oder Anzeigenamen ab.
6. Datei-Zugriffsrechte werden über mehrere parallele Mechanismen gesteuert.
7. Generische Datei-Models sind direkt mit dem Marketing-Modul gekoppelt.
8. Audit-Logging, Online-Status und technisches Request-Logging sind nicht klar getrennt.
9. Das Datenmodell für spätere automatische Disposition benötigt normalisierte Qualifikations-, Verfügbarkeits- und Standortdaten.
10. Repository-Ausgaben und Playwright-Screenshots liegen teilweise versioniert im Quellcode.

---

## 3. Prioritäten

| Priorität | Ziel |
|---|---|
| **P0** | Produktionsdaten und Migrationen absichern |
| **P1** | Test- und CI-Basis reparieren |
| **P2** | Migrationen, Datenmigrationen und Inhalts-Releases trennen |
| **P3** | große Klassen und fachliche Kopplungen zerlegen |
| **P4** | Datenmodelle für Operations, Mitarbeiter, Dateien und Geräte optimieren |
| **P5** | Performance, Monitoring und langfristige Wartbarkeit verbessern |

---

# Teil A – Sicherheitsnetz und unmittelbare Maßnahmen

## 4. Migrationen vorerst nicht löschen oder verändern

Zum Analysezeitpunkt enthält das Repository rund 69 Migrationen. Darunter befinden sich:

- Laravel-Basistabellen
- Benutzer-, Team- und Profiletabellen
- Chat-, Call- und Live-Location-Erweiterungen
- Datei- und Dokumenttabellen
- Assistentenwissen und Prompt-Backfills
- Operations-Tabellen
- Marketing-Katalogversionen und Inhaltskorrekturen
- Dateiumzüge aus dem Marketingbereich
- Geräteverwaltung mit mehreren abhängigen Tabellen

Bereits produktiv ausgeführte Migrationen dürfen nicht nachträglich umgeschrieben, umbenannt oder entfernt werden. Andernfalls können zwei Installationen denselben Eintrag in der Tabelle `migrations` besitzen, aber ein unterschiedliches tatsächliches Schema haben.

### Vor jeder Bereinigung sichern

```text
Produktivdatenbank
├── vollständiges Backup
├── Export der Tabelle migrations
├── SHOW CREATE TABLE aller Tabellen
├── vorhandene Fremdschlüssel und Indizes
├── Tabellen- und Zeilenanzahlen
├── Liste verwaister Fremdschlüsselbeziehungen
└── Restore-Test in separater Umgebung
```

### Grundregel

Bestehende Migrationen bleiben historische Artefakte. Bereinigungen erfolgen durch **neue additive Migrationen**.

---

## 5. MySQL-/MariaDB-Testumgebung ergänzen

Die aktuelle PHPUnit-Konfiguration verwendet SQLite `:memory:`. Das ist für schnelle Featuretests sinnvoll, deckt aber mehrere produktionsrelevante Eigenschaften nicht zuverlässig ab:

- maximale Länge von Index- und Constraintnamen
- nullable Spalten in Unique-Indizes
- MySQL-spezifisches JSON-Verhalten
- Unterschiede zwischen `TIMESTAMP`, `DATETIME` und Zeitzonenkonvertierungen
- implizite Indexerstellung durch Fremdschlüssel
- DDL-Verhalten bei teilweise ausgeführten Migrationen
- Sperren und Parallelität
- MySQL-/MariaDB-spezifische SQL-Anweisungen

### Zielstruktur der Tests

```text
Schneller Testpfad
└── SQLite :memory:
    ├── Unit-Tests
    ├── normale Featuretests
    └── Livewire-Tests

Verbindlicher Schema-Test
└── gleiche MySQL-/MariaDB-Version wie Produktion
    ├── migrate:fresh
    ├── Upgrade ab Produktiv-Snapshot
    ├── Fremdschlüsselprüfung
    ├── Indexprüfung
    ├── Constraintprüfung
    └── ausgewählte Parallelitätstests
```

### Abnahmekriterium

Eine leere MySQL-Datenbank sowie ein anonymisierter letzter Produktivstand müssen automatisiert und reproduzierbar auf den aktuellen Stand migrierbar sein.

---

## 6. Testverwaltung reparieren

### Aktuelles Problem

Die Root-`.gitignore` blendet zunächst den gesamten Ordner `tests` aus und erlaubt danach nur eine manuell gepflegte Liste einzelner Testdateien. Dadurch können neue Tests lokal bestehen, ohne im Repository eingecheckt zu werden.

Zusätzlich verweist das Script `npm run test:pwa` derzeit auf Testdateien, die im aktuellen `master` nicht vorhanden sind:

```text
tests/Frontend/pwa.test.js
tests/Frontend/microphone-stream.test.js
tests/Frontend/keyboard-viewport.test.js
```

### Ziel

Der vollständige Testordner wird normal versioniert. Nur Testausgaben werden ignoriert.

Empfohlene `.gitignore`-Einträge:

```gitignore
/vendor/
/node_modules/
/output/
/.playwright-cli/
/coverage/
/playwright-report/
/test-results/
/storage/logs/
/storage/framework/cache/
/storage/framework/sessions/
/storage/framework/views/
```

### Zusätzlich

- `test:pwa` entweder auf vorhandene Dateien korrigieren oder vorerst entfernen
- Composer- und NPM-Testbefehle vereinheitlichen
- ein zentrales `composer check` und `npm run check` einführen

---

## 7. CI-Pipeline einführen

Im Repository sollte eine verbindliche CI-Pipeline eingerichtet werden.

### PHP-Pipeline

```text
composer validate
composer install --no-interaction --prefer-dist
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

### Datenbank-Pipeline

```text
MySQL-Service starten
php artisan migrate:fresh --force
php artisan db:seed --force
Schema-Integrität prüfen
Upgrade-Test ab Snapshot ausführen
```

### Frontend-Pipeline

```text
npm ci
npm run test:frontend
npm run build
```

### Empfohlene Ergänzungen

- Larastan/PHPStan
- Deptrac oder einfache Architekturtests
- Prüfung unversionierter beziehungsweise ignorierter Tests
- Prüfung auf veraltete oder nicht vorhandene Scriptpfade
- Prüfung, dass Migrationen keine Anwendungservices importieren

---

# Teil B – Migrationen und Datenpflege

## 8. Aktuelles Migrationsproblem

Mehrere Migrationen lösen aktuelle Eloquent-Models und Services aus dem Container auf. Beispiele sind insbesondere Marketing-Katalog- und Marketing-Dateimigrationen.

Damit hängt das Ergebnis einer historischen Migration vom **heutigen** Stand folgender Komponenten ab:

- Models
- Casts
- Events und Observer
- Sanitizer
- Binder
- Template-Factories
- Services
- Konfiguration

Wird später ein Service verändert, kann eine alte Migration bei einer frischen Installation ein anderes Ergebnis liefern oder vollständig fehlschlagen.

## 9. Zieltrennung

Künftig werden drei unterschiedliche Änderungstypen verwendet.

### 9.1 Schema-Migrationen

Schema-Migrationen enthalten ausschließlich strukturelle Datenbankänderungen:

```php
Schema::create(...);
Schema::table(...);
DB::statement(...);
```

Regeln:

- keine Eloquent-Models
- keine Anwendungservices
- keine HTTP-Aufrufe
- keine Dateisystemverschiebungen
- keine aktuellen Business-Events
- keine inhaltlichen Katalogupdates

### 9.2 Datenmigrationen

Für fachlich notwendige Umstellungen wird ein eigener Runner eingeführt:

```text
app/DataMigrations/
├── V2026_09_NormalizeDirectChats.php
├── V2026_10_BackfillFileOwnership.php
├── V2026_11_NormalizeDeviceAssignments.php
└── V2026_12_EncryptCustomerContacts.php
```

Möglicher Command:

```bash
php artisan railtime:data-migrate
php artisan railtime:data-migrate --dry-run
php artisan railtime:data-migrate --only=V2026_11_NormalizeDeviceAssignments
```

Dazu eine Tabelle:

```text
data_migrations
├── id
├── key
├── version
├── checksum
├── status
├── cursor
├── started_at
├── completed_at
├── failed_at
└── error
```

Große Datenmigrationen werden dadurch:

- wiederaufnehmbar
- chunkweise ausführbar
- prüfbar
- protokolliert
- unabhängig vom Schema-Deployment

### 9.3 Inhalts-Releases

Assistentenwissen, Promptregeln, Marketingmotive, Mailvorlagen und Starterkataloge sind Inhalts-Releases und keine Schemaänderungen.

Zielmodell:

```text
content_releases
├── id
├── release_key
├── version
├── content_hash
├── status
├── installed_at
├── installed_by
└── result
```

Mögliche Commands:

```bash
php artisan railtime:content-release marketing-v5
php artisan railtime:content-release assistant-rules-v3
php artisan railtime:content-release mail-templates-v2 --dry-run
```

Vorteile:

- Inhaltsupdates benötigen kein `php artisan migrate`
- idempotente Installation
- Checksummenprüfung
- fachliche Versionierung
- Dry-Run vor Produktion
- gezielte Rollbacks oder Ersatzreleases

---

## 10. Geräte-Migration als Sonderfall

Die Migration `2026_08_17_070000_create_device_management_tables.php` erstellt mehrere abhängige Tabellen und besitzt einen eigenen Mechanismus zur Erkennung einer abgebrochenen, noch leeren Installation.

Dieser Reparaturmechanismus ist für den konkreten Fall nachvollziehbar, sollte aber kein allgemeines Muster werden.

### Zukünftiges Muster

```text
Migration
└── deterministische Schemaänderung

Recovery-Command
└── Diagnose und bewusste Reparatur

Runbook
└── dokumentierter Produktionsablauf
```

Eine Migration sollte nicht allgemein versuchen, halbfertige Tabellenbestände selbstständig zu interpretieren und zu entfernen.

---

## 11. Schema-Baseline einführen

Nach erfolgreicher Prüfung aller Migrationen auf der produktiven Datenbankengine sollte ein Laravel-Schema-Dump erzeugt werden:

```bash
php artisan schema:dump
```

Zunächst ausdrücklich **ohne** `--prune`.

### Ablauf

1. Alle Umgebungen auf denselben Migrationsstand bringen.
2. Produktivschema vollständig sichern.
3. `migrate:fresh` mit MySQL testen.
4. Upgrade ab anonymisiertem Produktivstand testen.
5. Schema-Dump erzeugen.
6. frische Installation über Schema-Dump testen.
7. alte Migrationen zunächst weiterhin behalten.
8. Archivierung erst nach mehreren stabilen Releases planen.

---

## 12. Richtlinie für neue Migrationen

Jede neue Migration muss folgende Bedingungen erfüllen:

```text
[ ] genau ein klarer Schema-Zweck
[ ] keine App\Models-Abhängigkeit
[ ] keine App\Services-Abhängigkeit
[ ] keine externen APIs
[ ] keine Dateisystemverschiebungen
[ ] kurze explizite Namen für MySQL-Constraints
[ ] auf produktiver DB-Engine getestet
[ ] additive Expand/Contract-Strategie
[ ] irreversible Schritte dokumentiert
[ ] passende Upgrade- und Fresh-Install-Tests vorhanden
```

---

# Teil C – Benutzer, Mitarbeiter und Berechtigungen

## 13. Aktueller Zustand

Das `User`-Model vereint unter anderem:

- globale Rolle
- Superadmin-Erkennung
- Jetstream-Teams
- Dashboard-Zielgruppe
- JSON-basierte Teamrechte
- Online-Erkennung
- Dateien
- Chats
- Push-Nachrichten
- Gerätezuweisungen
- Identitätskonten
- Activity-Log-Beziehungen

Zusätzlich wird die Benutzer-ID `1` als Superadmin interpretiert. Teamtypen werden teilweise anhand deutscher Anzeigenamen erkannt.

Diese Logik ist bei Importen, Umbenennungen, Mehrsprachigkeit und zukünftigen Mandantenstrukturen fehleranfällig.

## 14. Zielmodell für Rollen und Teams

```text
users
├── id
├── global_role
│   ├── super_admin
│   ├── admin
│   └── user
├── status
└── ...

teams
├── id
├── name
├── slug
├── kind
│   ├── administration
│   ├── management
│   ├── employee
│   └── guest
└── ...

team_user
├── team_id
├── user_id
├── membership_role
└── ...
```

### Regeln

- Superadmin nicht aus einer Datenbank-ID ableiten
- fachliche Entscheidungen niemals anhand eines Anzeigenamens treffen
- globale Rollen und Teamrechte trennen
- Policies und Gates als zentrale Wahrheit verwenden
- Middleware nur für grobe Bereichsgrenzen verwenden
- technische Endpunkte liefern bei fehlender Berechtigung `403`, keine HTML-Weiterleitung

## 15. Policies zentralisieren

Empfohlene Policies:

```text
DevicePolicy
FilePolicy
FolderPolicy
OrderPolicy
ShiftPolicy
ChatPolicy
RoomPolicy
MarketingCreativePolicy
ManagedDocumentPolicy
EmployeeProfilePolicy
```

Livewire-Aktionen, Controller und Downloads verwenden dieselben Policies. Dadurch entfallen parallele Berechtigungsprüfungen in Views, Komponenten und Services.

---

## 16. HR-Daten und Einsatzdaten trennen

`UserProfile` speichert und verschlüsselt hochsensible Daten wie:

- Anschrift
- Geburtsdaten
- Steuer-ID
- Sozialversicherungsnummer
- IBAN
- Krankenkasse
- Vergütung

Diese Daten dürfen nicht zur allgemeinen Grundlage der Disposition werden.

### Zielmodell

```text
employee_operational_profiles
├── id
├── user_id
├── active_for_dispatch
├── home_region_id
├── preferred_radius_km
├── maximum_radius_km
├── preferred_shift_types
├── operational_notes
├── created_at
└── updated_at

qualifications
├── id
├── code
├── name
├── category
├── active
└── timestamps

employee_qualifications
├── id
├── user_id
├── qualification_id
├── level
├── valid_from
├── valid_until
├── verification_status
├── document_id
└── timestamps

employee_availability_rules
employee_absences
employee_workload_limits
```

Dadurch bleibt die automatische Disposition von privaten Personal-, Steuer- und Lohndaten getrennt.

---

# Teil D – Kunden, Aufträge und Schichten

## 17. Bestehende Stärken

Das Operations-Modul besitzt bereits:

- Status- und Prioritäts-Enums
- zentrale Scheduling-Services
- UTC-Zeitpunkte
- Speicherung der ursprünglichen Zeitzone
- Statushistorie
- Transaktionen für kritische Zuweisungen
- Soft Deletes für Kunden, Aufträge und Schichten

Diese Architektur sollte beibehalten und erweitert werden.

## 18. Kundenkontakte und Einsatzorte normalisieren

Aktuell besitzt ein Kunde im Wesentlichen einen Kontakt und eine Adresse. Für RailTime werden voraussichtlich mehrere Ansprechpartner und mehrere Einsatzorte benötigt.

### Zielmodell

```text
customers
├── id
├── public_id
├── customer_number
├── company_name
├── billing_data
├── is_active
└── timestamps

customer_contacts
├── id
├── customer_id
├── name
├── department
├── email
├── phone
├── preferred_channel
├── is_primary
├── active
└── timestamps

customer_sites
├── id
├── customer_id
├── name
├── street
├── postal_code
├── city
├── country
├── latitude
├── longitude
├── operational_notes
└── timestamps
```

Ein Auftrag sollte zusätzlich einen historischen Standort-Snapshot besitzen. Änderungen am Kundenstandort dürfen alte Aufträge nicht rückwirkend verändern.

## 19. Datenschutz und Suche

Die aktuelle `%LIKE%`-Suche ist mit einer einfachen Verschlüsselung von E-Mail und Telefon nicht vereinbar.

Vor dem Produktivbetrieb muss je Feld entschieden werden:

- geschäftlicher Firmenname: dokumentiert unverschlüsselt
- öffentlicher Firmenstandort: gegebenenfalls unverschlüsselt
- Ansprechpartner: verschlüsselt
- E-Mail und Telefon: verschlüsselt
- exakte Suche: normalisierter Blindindex oder Hash
- Teilstringsuche: nur für ausdrücklich freigegebene Felder

Indizes sollten erst nach realer Query-Analyse und `EXPLAIN` ergänzt werden. Ein normaler B-Tree-Index hilft bei `LIKE '%begriff%'` kaum.

---

## 20. Qualifikationsanforderungen normalisieren

Aktuell können Anforderungen in `orders.requirements` als JSON liegen und `shifts.role_name` ist Freitext.

Für eine automatische Disposition müssen alle abfragbaren Anforderungen relational vorliegen.

```text
service_types
├── id
├── code
├── name
└── active

shift_roles
├── id
├── code
├── name
└── active

shift_requirements
├── id
├── shift_id
├── qualification_id
├── minimum_level
├── required_count
├── mandatory
└── timestamps
```

JSON bleibt nur für unstrukturierte Zusatzinformationen bestehen.

## 21. Datenbank-Constraints ergänzen

Nach Prüfung und Bereinigung bestehender Daten sollten zusätzliche Invarianten abgesichert werden:

```text
orders.starts_at < orders.ends_at
shifts.starts_at < shifts.ends_at
required_staff >= 1
valid_from <= valid_until
expires_at > invited_at
```

Die Laravel-Validierung bleibt zusätzlich bestehen. Die Datenbank schützt gegen Imports, Skripte und spätere Programmfehler.

---

# Teil E – Chat und Kommunikation

## 22. Doppelte Direktchats verhindern

`Chat::directBetween()` sucht zuerst einen vorhandenen Chat und erstellt andernfalls einen neuen. Zwischen Prüfung und Erstellung besteht derzeit kein eindeutiger Datenbankschlüssel.

Bei zwei parallelen Requests können theoretisch zwei Direktchats für dasselbe Benutzerpaar entstehen.

### Ziel

```text
chats.direct_key
```

Berechnung:

```text
min(user_id):max(user_id)
```

Danach:

```text
UNIQUE(direct_key)
```

`direct_key` bleibt für Gruppen- und Call-Chats `NULL`.

## 23. `chat_user` als Fachmodell behandeln

Die Pivot-Tabelle besitzt inzwischen mehrere Lebenszyklusfelder:

- `last_read_at`
- `last_opened_at`
- `joined_at`
- `hidden_at`
- `cleared_at`

Damit ist sie keine einfache Pivot-Tabelle mehr.

Empfohlenes Model:

```text
ChatParticipant
```

Darüber werden folgende Abläufe zentralisiert:

- beitreten
- verstecken
- wieder öffnen
- Verlauf leeren
- Lesestand aktualisieren
- Sichtbarkeitsgrenze berechnen

## 24. `ChatBox` zerlegen

`app/Livewire/ChatBox.php` steuert derzeit unter anderem:

- Chatliste
- Nachrichtenansicht
- Nachrichtenerstellung
- Uploads
- Sprachnachrichten
- Antworten
- Reaktionen
- Gruppenverwaltung
- Calls
- Live-Standorte
- Caching
- Broadcasting

### Zielstruktur

```text
app/Livewire/Chat/
├── ChatWorkspace.php
├── ChatList.php
├── MessagePane.php
├── MessageComposer.php
├── GroupSettings.php
└── LiveLocationPanel.php

app/Services/Chat/
├── ChatCreationService.php
├── ChatMessageService.php
├── ChatAttachmentService.php
├── ChatReadStateService.php
└── ChatQueryService.php
```

Die Hauptkomponente koordiniert nur noch die Teilbereiche.

## 25. Chat-Performance

Methoden wie `unreadCountFor()` können in einer Chatliste leicht eine Query pro Chat erzeugen.

Optimierungsmaßnahmen:

- aggregierte Subqueries
- `withCount`
- `withMax`
- vorberechnete letzte Nachricht
- Eager Loading der Teilnehmer
- Cursor-Pagination statt Offset-Pagination
- Query-Logging und `EXPLAIN`

Konkrete Indizes werden erst nach Messung ergänzt.

---

# Teil F – Dateien und Dateiordner

## 26. `File` besitzt zu viele Verantwortlichkeiten

Das Model `app/Models/File.php` übernimmt derzeit unter anderem:

- Eloquent-Datenmodell
- Dateitypenkatalog
- MIME-Erkennung
- Storage-Löschung
- HTTP-Streaming
- Cache
- Authentifizierung
- Marketing-Abhängigkeiten
- Lifecycle-Events

Auch `FileFolder` enthält direkte Aufrufe des Marketing-Moduls.

Ein generisches Datei-Modul sollte keine Kenntnis über Marketing besitzen.

## 27. Zielstruktur der Datei-Domain

```text
app/Models/
├── StoredFile.php
├── FilePool.php
└── FileFolder.php

app/Services/Files/
├── FileStorageService.php
├── FileDeletionService.php
├── FileDownloadService.php
├── FileAccessService.php
├── FileTypeCatalog.php
└── FileMoveService.php

app/Events/Files/
├── FileContentChanged.php
├── FileMoved.php
└── FileDeleted.php

app/Listeners/Marketing/
└── InvalidateMarketingSources.php
```

Marketing reagiert auf Datei-Events. Das Datei-Modul kennt Marketing nicht.

## 28. Besitzverhältnisse vereinheitlichen

Die Tabelle `files` besitzt parallel:

- `fileable_type` / `fileable_id`
- `filepool_id`
- `folder_id`
- `user_id`

Diese Bedeutungen müssen klar getrennt werden.

### Empfohlene Semantik

```text
file_pool_id
└── fachlicher Dateicontainer

folder_id
└── optionale Position im Container

fileable_type / fileable_id
└── optionales angehängtes Fachobjekt, z. B. ChatMessage oder Order

uploaded_by
└── Benutzer, der die Datei hochgeladen hat
```

Das mehrdeutige Feld `user_id` sollte nach einer Datenmigration durch `uploaded_by` beziehungsweise einen klareren Namen ersetzt werden.

Vor dem Hinzufügen neuer Fremdschlüssel müssen verwaiste Datensätze geprüft werden.

## 29. Berechtigungsmodelle konsolidieren

Aktuell bestehen mehrere parallele Mechanismen:

- `shared_roles`
- Ordner-`permissions`
- Datei-`visible_teams`
- Ordner-`visible_teams`

Langfristig sollte ein kanonisches System bestehen.

### Relationale Variante

```text
file_access_grants
├── id
├── resource_type
├── resource_id
├── team_id
├── can_view
├── can_download
├── can_delete
├── valid_from
├── valid_until
└── timestamps
```

Alternativ kann JSON erhalten bleiben, aber dann mit genau einem dokumentierten Schema und einer zentralen Zugriffsklasse.

## 30. Morph-Map vor Namespace-Umbauten

Polymorphe Beziehungen speichern derzeit Modeltypen in der Datenbank. Bevor Models verschoben oder `File` in `StoredFile` umbenannt werden, muss eine stabile Morph-Map eingeführt werden.

```php
Relation::enforceMorphMap([
    'user' => User::class,
    'team' => Team::class,
    'chat_message' => ChatMessage::class,
    'order' => Order::class,
]);
```

Danach werden bestehende vollqualifizierte Klassennamen auf stabile Aliase migriert.

Ohne diesen Zwischenschritt würden Namespaceänderungen bestehende Beziehungen beschädigen.

---

# Teil G – Geräteverwaltung

## 31. Bestehende Architektur

Die Geräteverwaltung ist bereits vergleichsweise sauber getrennt:

- Providerregistry
- Connector-Sicherheitslogik
- Inventory-Service
- Enrollment-Service
- Command-Service
- Readiness-Prüfungen
- Artefakt-Service
- Providerdiagnosen

Diese Struktur sollte erhalten werden.

## 32. Genau eine aktive Gerätezuweisung

`device_assignments` speichert die vollständige Historie. Die Datenbank verhindert derzeit jedoch nicht zuverlässig mehrere gleichzeitig aktive Zuweisungen desselben Geräts.

### Robuste Zielvariante

```text
devices.current_assignment_id
```

- optionaler eindeutiger FK auf die aktuelle Zuweisung
- `device_assignments` bleibt vollständige Historie
- Wechsel erfolgt in einer Transaktion mit `lockForUpdate`

Das ist in MySQL zuverlässiger als ein partieller Unique-Index auf `status = active`.

## 33. Nullable Unique-Index prüfen

`device_account_assignments` besitzt einen Unique-Index über:

```text
device_id
employee_identity_account_id
device_provisioning_profile_id
```

Da `employee_identity_account_id` nullable ist, erlaubt MySQL mehrere Zeilen mit `NULL`. Die fachlich beabsichtigte Eindeutigkeit ist dadurch für noch nicht gebundene Konten nicht vollständig garantiert.

Mögliche Lösungen:

1. Identitätskonto früher verpflichtend machen.
2. separaten nicht-nullbaren Assignment-Key einführen.
3. getrennte Eindeutigkeitslogik für vorbereitete Zuweisungen verwenden.

## 34. Provider-Jobs eindeutig machen

Für `device_commands` sollte nach Datenprüfung erwogen werden:

```text
UNIQUE(provider, provider_job_id)
```

`provider_job_id = NULL` bleibt für noch nicht ausgelieferte Befehle erlaubt. Doppelte Provider-Callbacks können danach nicht versehentlich mehreren Commands zugeordnet werden.

## 35. `DeviceManagementSettings` zerlegen

Die Klasse behandelt aktuell unter anderem:

- Deployment-Topologie
- Domains und Subdomains
- Providerports
- Endpunkte
- Tokens
- Webhook-Secrets
- Laufzeitlimits
- Identity-Domains
- Formulardaten
- Persistenz
- Audit

### Zielstruktur

```text
DeviceTopologySettings
DeviceProviderSettings
DeviceSecuritySettings
DeviceRuntimeLimits
IdentityDomainSettings
DeviceSettingsRepository
ConnectorEndpointPolicy
```

---

# Teil H – Allgemeine Einstellungen

## 36. `Setting` modernisieren

Das aktuelle `Setting`-Model arbeitet mit statischen, untypisierten Methoden und direktem Cachezugriff. `clearTypeCache()` setzt implizit einen Redis-kompatiblen Cache voraus.

### Zielabstraktion

```php
interface SettingsRepository
{
    public function get(string $namespace, string $key): mixed;

    public function put(string $namespace, string $key, mixed $value): void;
}
```

Darüber fachliche Klassen:

```text
CallSettings
DeviceSettings
CompanySettings
MailSettings
MarketingSettings
AssistantSettings
```

Secrets werden nicht als beliebige allgemeine Settings behandelt, sondern über eine klar verschlüsselte Secret-Schicht.

---

# Teil I – Audit, Aktivität und Datenschutz

## 37. Logging-Zwecke trennen

Derzeit werden normale Web-Requests asynchron mit folgenden Daten protokolliert:

- vollständige URL
- Methode
- IP-Adresse
- User-Agent

Gleichzeitig dient die Activity-Tabelle teilweise zur Online-Erkennung. Superadmin-ID `1` wird vollständig vom Logging ausgenommen.

### Zieltrennung

| Zweck | Zielsystem |
|---|---|
| Sicherheits- und Fachaudit | unveränderliche fachliche Audit-Events |
| Online-Präsenz | `last_seen_at` oder kurzer Cache-Heartbeat |
| technische Requestmetriken | Monitoring/Logs mit kurzer Aufbewahrung |
| Produktanalyse | datensparsame aggregierte Events |

### Änderungen

- Superadmins nicht vom Sicherheits-Audit ausnehmen
- Query-Parameter filtern oder entfernen
- Tokens, Suchbegriffe und sensible Parameter nie in vollständigen URLs speichern
- klare Aufbewahrungsfristen definieren
- technische Requestlogs regelmäßig bereinigen
- fachliche Änderungen mit Objekt, Aktion, vorherigem und neuem Zustand protokollieren

---

# Teil J – Klassen- und Modulstruktur

## 38. Größte Hotspots

Besonders große beziehungsweise stark gekoppelte Dateien sind:

| Datei/Bereich | Problem |
|---|---|
| `app/Models/File.php` | Storage, HTTP, Auth, Marketing und Typkatalog in einem Model |
| `app/Models/User.php` | Rollen, Teams, Präsenz, Dateien, Push und Geräte |
| `app/Livewire/ChatBox.php` | fast vollständiger Chat-Workflow |
| `app/Livewire/Devices/DeviceManagement.php` | Inventar, Zuordnung, Enrollment, Artefakte und Commands |
| `app/Services/DeviceManagement/DeviceManagementSettings.php` | komplette Provider-, Topologie- und Secret-Konfiguration |
| `routes/web.php` | nahezu alle Fachbereiche in einer Datei |

## 39. Schrittweise Domainstruktur

Kein sofortiger vollständiger DDD-Umbau. Neue Funktionen werden korrekt einsortiert, bestehende Klassen bei ohnehin notwendigen Änderungen schrittweise verschoben.

```text
app/
├── Domain/
│   ├── Identity/
│   ├── Communication/
│   ├── Files/
│   ├── Operations/
│   ├── Devices/
│   └── Marketing/
├── Application/
│   ├── Actions/
│   ├── Queries/
│   └── Data/
├── Infrastructure/
│   ├── Storage/
│   ├── Providers/
│   └── Integrations/
└── Livewire/
```

## 40. Routen aufteilen

```text
routes/
├── web.php
├── auth.php
├── admin.php
├── chat.php
├── calls.php
├── files.php
├── devices.php
├── operations.php
└── marketing.php
```

URLs und Routennamen bleiben zunächst unverändert. Im ersten Schritt ändert sich nur die Organisation.

---

# Teil K – Laravel-12-Struktur

## 41. Legacy-Skeleton bewusst behandeln

Composer fordert Laravel 12, das Projekt verwendet aber weiterhin die ältere Kernel-/Bootstrap-Struktur mit:

- `App\Http\Kernel`
- `App\Console\Kernel`
- klassischem `bootstrap/app.php`

Das ist nicht automatisch defekt. Es sollte jedoch bewusst entschieden werden:

1. Legacy-Skeleton dokumentiert weiterverwenden, oder
2. kontrolliert auf die aktuelle Laravel-12-Anwendungsstruktur migrieren.

Diese Umstellung darf nicht gleichzeitig mit großen Datenmodell- und Berechtigungsänderungen erfolgen.

---

# Teil L – Tests ausbauen

## 42. Teststruktur verbessern

Es existieren bereits viele umfangreiche Feature- und Frontendtests. Der echte Unit-Testbereich ist dagegen klein.

### Zielverteilung

```text
Unit
├── Enums
├── ValueObjects
├── StateTransitions
├── PermissionResolvers
├── Zeit- und Schedulinglogik
└── Reconciliation-Logik

Feature
├── HTTP und Livewire
├── Datenbankintegration
├── Berechtigungen
├── Queue-Workflows
└── externe Provider-Fakes

Schema
├── MySQL Fresh Migration
├── Upgrade from Baseline
├── Constraints
├── Foreign Keys
└── Indexes

Frontend
├── kleine verhaltensbezogene Testdateien
└── keine monolithischen Testdateien über viele Fachbereiche
```

## 43. Factories ergänzen

Zum Analysezeitpunkt besteht im Factory-Ordner nur die `UserFactory`.

Mindestens erforderlich:

```text
CustomerFactory
OrderFactory
ShiftFactory
ShiftAssignmentFactory
ChatFactory
ChatMessageFactory
RoomFactory
FilePoolFactory
StoredFileFactory
DeviceFactory
DeviceAssignmentFactory
DeviceCommandFactory
MarketingCreativeFactory
```

## 44. Architekturtests einführen

Beispiele:

```text
Migrationen dürfen keine App\Services importieren.
Migrationen dürfen keine HTTP-Clients verwenden.
Generic File Models dürfen Marketing nicht importieren.
Livewire-Komponenten dürfen kritische Statuswechsel nicht direkt schreiben.
Alle fachlichen Statusfelder besitzen einen Enum-Cast.
Alle Public IDs werden über einen gemeinsamen Trait erzeugt.
Alle Admin-Endpunkte besitzen eine Policy- oder Gate-Prüfung.
```

---

# Teil M – Repository-Hygiene

## 45. Generierte Ausgaben entfernen

Im Repository liegen versionierte Playwright-Ausgaben und ein zeitgestempelter `.playwright-cli`-Snapshot.

Diese Dateien sollten:

- aus dem Repository entfernt werden
- in `.gitignore` aufgenommen werden
- bei CI-Läufen als zeitlich begrenzte Artefakte hochgeladen werden

Betroffene Bereiche:

```text
.playwright-cli/
output/playwright/
playwright-report/
test-results/
```

## 46. `.dev` strukturieren

Planungsunterlagen unter `.dev` können erhalten bleiben. Für neue Dokumente sollte eine klare Struktur gelten:

```text
.dev/
├── architecture/
├── decisions/
├── research/
├── runbooks/
└── plans/
```

Langfristig kann dieses Dokument nach folgendem Pfad verschoben werden:

```text
.dev/plans/PROJECT_ANALYSIS_OPTIMIZATION_CLEANUP_PLAN.md
```

## 47. Architekturentscheidungen dokumentieren

Empfohlene ADR-Dateien:

```text
ADR-001-authorization-model.md
ADR-002-migration-policy.md
ADR-003-file-access-model.md
ADR-004-employee-operational-data.md
ADR-005-content-release-system.md
ADR-006-audit-and-retention.md
```

---

# Teil N – Umsetzungsphasen

## Phase 0 – Sicherheitsnetz

### Aufgaben

1. Produktivbackup und Schemaexport erstellen.
2. `migrations`-Tabelle sichern.
3. Restore-Test durchführen.
4. `.gitignore` für Tests korrigieren.
5. fehlerhafte NPM-Testpfade korrigieren.
6. MySQL-Testdatenbank einrichten.
7. CI-Pipeline anlegen.
8. vollständigen Fresh-Migrate-Test durchführen.
9. Upgrade ab anonymisiertem Produktivstand testen.

### Abnahmekriterium

Eine frische MySQL-Datenbank und ein letzter Produktivstand können automatisiert auf den aktuellen Stand gebracht werden.

---

## Phase 1 – Migrationen stabilisieren

### Aufgaben

1. Migrationsrichtlinie dokumentieren.
2. keine neuen Inhaltsmigrationen mehr anlegen.
3. DataMigrationRunner erstellen.
4. ContentReleaseRunner erstellen.
5. Schema-Dump ohne Pruning erzeugen.
6. große Bestandsmigrationen durch Regressionstests absichern.
7. Produktions-Runbook für Migrationen schreiben.

### Abnahmekriterium

Neue Inhaltsversionen benötigen kein `php artisan migrate` mehr.

---

## Phase 2 – Technische Hotspots entkoppeln

### Reihenfolge

1. `Setting` und SettingsRepository
2. `File` und `FileFolder`
3. `DeviceManagementSettings`
4. Policies, Gates und `RoleMiddleware`
5. `routes/web.php`
6. `ChatBox`
7. `DeviceManagement`
8. Hilfslogik im `User`-Model

### Abnahmekriterium

Models enthalten primär Daten, Beziehungen und kleine fachliche Invarianten. Externe Nebenwirkungen liegen in Services oder Listenern.

---

## Phase 3 – Datenmodelle erweitern

### Aufgaben

1. explizite globale Rollen und Superadmin-Flag
2. stabile Team-Slugs und Teamtypen
3. `EmployeeOperationalProfile`
4. Qualifikationen und Mitarbeiterqualifikationen
5. Kundenkontakte und Kundenstandorte
6. Servicearten, Schichtrollen und Schichtanforderungen
7. `direct_key` für Direktchats
8. aktive Gerätezuweisung absichern
9. Datei-Berechtigungen konsolidieren
10. Software-Soll-/Ist-Modell für Geräte ergänzen

### Abnahmekriterium

Die zentralen Daten für automatische Disposition und Geräteverwaltung sind strukturiert, validierbar und effizient abfragbar.

---

## Phase 4 – Performance und Betrieb

### Aufgaben

1. Slow-Query-Logging aktivieren.
2. wichtigste Listen mit `EXPLAIN` untersuchen.
3. N+1-Regressionstests ergänzen.
4. Queue-Metriken und Fehlerraten erfassen.
5. Aufbewahrungsregeln definieren.
6. Activity-, Audit- und Presence-Daten trennen.
7. Storage-Orphan-Prüfung implementieren.
8. regelmäßige Restore-Tests automatisieren.
9. Tabellenwachstum überwachen.

### Abnahmekriterium

Performance, Datenwachstum und Fehlersituationen sind messbar und besitzen definierte Betriebsprozesse.

---

# Teil O – Erste konkrete Arbeitspakete

| Nr. | Arbeitspaket | Risiko | Nutzen |
|---:|---|---:|---:|
| 1 | Tests aus `.gitignore` befreien | niedrig | sehr hoch |
| 2 | `test:pwa` korrigieren | niedrig | mittel |
| 3 | MySQL-CI mit vollständigen Migrationen einrichten | mittel | sehr hoch |
| 4 | Produktionsschema und Migrationsstand dokumentieren | niedrig | sehr hoch |
| 5 | Data-/Content-Release-System planen | mittel | sehr hoch |
| 6 | Request-Logging und Superadmin-Audit korrigieren | mittel | hoch |
| 7 | `Setting` durch typisierte Repository-Schicht ersetzen | niedrig | hoch |
| 8 | Datei-Models von Marketing und Storage-Nebenwirkungen entkoppeln | mittel | sehr hoch |
| 9 | explizites Rollen- und Teammodell festlegen | mittel | sehr hoch |
| 10 | Operations-Sollmodell für Qualifikationen und Einsatzorte finalisieren | mittel | sehr hoch |

---

# Teil P – Was ausdrücklich noch nicht gemacht werden sollte

- keine alten Migrationen löschen
- keine bereits ausgeführten Migrationen nachträglich ändern
- keine produktiven Tabellen direkt umbenennen
- keine Namespace-Verschiebung polymorpher Models ohne Morph-Map
- keine gleichzeitige Laravel-Skeleton-, Datenbank-, Berechtigungs- und UI-Migration
- nicht jedes JSON-Feld pauschal normalisieren
- keine Indizes ohne reale Query-Analyse hinzufügen
- kein vollständiger Rewrite der Laravel-/Livewire-Anwendung
- keine großen Datenmigrationen ohne Dry-Run, Backup und Wiederaufnahmefunktion
- keine Lösch- oder Aufräumjobs ohne dokumentierte Aufbewahrungsregeln

---

# 48. Empfohlener unmittelbarer Start

Der erste technische Block sollte aus folgenden Punkten bestehen:

```text
1. Tests vollständig versionieren
2. NPM-Testbefehle reparieren
3. MySQL-CI aufbauen
4. Fresh- und Upgrade-Migrationen absichern
5. Migrationsrichtlinie festlegen
6. Inhalts-Releases aus Schema-Migrationen herauslösen
```

Erst wenn dieses Sicherheitsnetz steht, sollten Datenmodelle, Berechtigungen und große Komponenten schrittweise verändert werden.

---

## 49. Analysegrenzen

Diese Analyse basiert auf dem Quellcode des Branches `master`. Nicht geprüft wurden:

- tatsächliche Produktivdaten und Datenmengen
- aktuelle MySQL-/MariaDB-Version der Produktionsumgebung
- reale Query-Pläne und Slow Queries
- Queue-Durchsatz und Worker-Konfiguration
- tatsächliche Storage-Belegung und verwaiste Dateien
- externe Connector-Installationen
- vollständige Laufzeitkonfiguration der Plesk-Umgebung

Vor konkreten destruktiven Änderungen müssen diese Punkte in einer gesonderten Bestandsaufnahme ergänzt werden.
