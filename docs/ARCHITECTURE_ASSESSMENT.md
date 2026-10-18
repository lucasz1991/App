# RailTime App – Architektur- und Qualitätsbewertung

**Bestandsaufnahme:** 18.10.2026  
**Umfang:** ausschließlich die Laravel-Anwendung `App/`  
**Ergebnis:** priorisierte Befunde und ein inkrementeller Optimierungsplan

## Zusammenfassung

RailTime ist keine leere CRUD-Anwendung: Die geprüften Kernpfade enthalten bereits
zentrale Scheduling-Services, Transaktions- und Sperrregeln, Revisionsprüfungen,
idempotente Arbeitszeitereignisse, abgesicherte Portalgrenzen und vorsichtige
Migrationsreparaturen. Ein pauschaler Neuaufbau oder eine Umstellung auf eine
andere Architektur ist durch die Stichprobe nicht begründet.

Die wichtigsten konkreten Verbesserungsfelder sind:

1. Der V2-Arbeitszeitexport kann pro Eintrag eine weitere Aktivitätenabfrage
   auslösen und ist auf bis zu 5.000 Datensätze begrenzt.
2. `work_time_entries.order_id` und `training_session_id` werden als
   indexierte Zahlenfelder, aber ohne Fremdschlüssel angelegt.
3. Die Testsuite verwendet SQLite; die gefundenen MySQL-Prüfungen kompilieren
   SQL mit einer synthetischen Verbindung, führen DDL und Locking aber nicht auf
   einem echten MySQL-Server aus.
4. Zwei Assertions in `OrderPlanningLinksTest` schlagen fehl, weil sie
   Planungslinks bereits im initialen Detailzustand erwarten. Die aktuelle
   Ansicht führt die Links in den Planungsdetails. Ob das eine beabsichtigte
   UX-Änderung oder ein verlorener Schnellzugriff ist, sollte fachlich
   entschieden und dann im Test abgebildet werden.
5. Einige Anwendungsklassen sind sehr groß und bündeln viele Abläufe. Das ist
   ein Wartungs- und Änderungsrisiko, aber kein ausreichender Grund für einen
   ungetesteten Großumbau.

Die nachfolgende Stichprobe hat keinen bestätigten P0/P1-Ausfall ergeben. Sie
ist keine vollständige Sicherheitsprüfung, kein Lasttest und keine Prüfung
gegen eine produktive Datenbank. „Vibecoding“ lässt sich außerdem nicht als
Urheberschaft aus Quellcode beweisen; bewertet werden nur belegbare
Architektur- und Qualitätsrisiken.

## Umfang und Baseline

Die Bestandsaufnahme hat Projektregeln, README/Projektübersicht,
Composer-/npm-Konfiguration, Migrationen, Modelle, Services, Livewire,
Controller, Routen und ausgewählte Feature- und Migrationstests geprüft.
Die Codebasis ist umfangreich (unter anderem 129 Migrationen, 177 Modeldateien,
267 Service-Dateien, 139 Livewire-Dateien und 61 Controller-Dateien in der
Inventur). Dateizahlen sind ein Umfangsindikator, keine Qualitätsbewertung.

### Geprüfte positive Kontrollen

- Auftrags- und Schichtänderungen laufen in den geprüften Pfaden über
  `OrderSchedulingService` und `ShiftSchedulingService`; diese validieren
  Zeitfenster und erwartete Revisionen. `ShiftAssignmentService` und
  `PlanningLocks` koordinieren Zuweisungen und Sperrreihenfolge.
- `OperationsTransaction` grenzt Wiederholungen bei erkannten
  Datenbank-Concurrency-Fehlern ein; die Tests decken Isolation und unbekannte
  äußere MySQL-Transaktionen explizit ab.
- Kundenportalzugriffe werden über eigene Identitäten, Memberships,
  Berechtigungsprüfungen und revisionsgebundene Einladungen gesteuert.
- Reparaturmigrationen prüfen Schema und vorhandene Daten vor DDL und
  verweigern automatische Reparaturen bei widersprüchlichen Constraints,
  Duplikaten oder verwaisten Referenzen.
- Fachregeln wie Zeitüberschneidungen, Auftragszeitraum und Schichtzuweisung
  besitzen gezielte Featuretests.

## Befunde

Priorität bezeichnet die empfohlene Reihenfolge, nicht die Sicherheit eines
Ausfalls: **P1** zuerst absichern, **P2** zeitnah verbessern, **P3** geordnet
modernisieren. Die Konfidenz bewertet den konkreten Befund.

| # | Priorität | Befund und Nachweis | Auswirkung | Empfohlene Maßnahme | Konfidenz |
|---|---|---|---|---|---|
| 1 | P2 | **N+1-Abfragen beim Arbeitszeitexport.** `WorkTimeEntry::creditedSeconds()` lädt bei bewerteten Einträgen die Aktivitäten über eine neue Relationabfrage. `OperationsReportService::ownTimesV2()` ruft die Methode innerhalb eines `map()` über bis zu 5.000 Einträge auf. | Für Einträge mit Bewertungsdaten wächst die Zahl der Abfragen mit der Exportmenge; bei großen Exporten drohen unnötige Datenbanklast und lange Antwortzeiten. | Aktivitäten gesammelt eager-loaden beziehungsweise die Berechnung auf bereits geladene Daten ausrichten. Einen Query-Count-Test für kleine und größere Exportmengen hinzufügen und die CSV-Ausgabe unverändert verifizieren. | 9/10 |
| 2 | P2 | **Fehlende Fremdschlüssel für Arbeitszeit-Kontexte.** Die Migration [2026_10_04_123000_extend_work_time_contexts.php](../database/migrations/2026_10_04_123000_extend_work_time_contexts.php) ergänzt `order_id` und `training_session_id` als `unsignedBigInteger` und jeweils mit Index, aber ohne FK. Die Anwendung validiert den Bezug beim Start über `Order::findOrFail()` beziehungsweise `assertTrainingClock()`, die Datenbank erzwingt ihn nicht. | Direkte oder spätere Schreibpfade können verwaiste Bezüge hinterlassen; Löschen eines Auftrags/einer Schulung kann den fachlichen Bezug aufbrechen. Historische Plan-Snapshots mindern den Informationsverlust, ersetzen aber keine klare Referenzpolitik. | Vor einer Schemaänderung Bestand auf Orphans prüfen und Aufbewahrungsregeln festlegen. Danach FK-Verhalten (wahrscheinlich `RESTRICT` oder bewusst nullable mit Snapshot-Erhalt) wählen und eine additive Migration plus Lösch-/Referenztests ergänzen. | 8/10 |
| 3 | P2 | **Produktionsdatenbank nicht in der Testlaufzeit vertreten.** [phpunit.xml](../phpunit.xml) setzt `DB_CONNECTION=sqlite` und `:memory:`. Die MySQL-bezogenen Prüfungen in [EmployeeLocationMigrationTest.php](../tests/Feature/EmployeeLocationMigrationTest.php) und [CustomerWorkflowMigrationRecoveryTest.php](../tests/Feature/CustomerWorkflowMigrationRecoveryTest.php) kompilieren Grammatik mit einer synthetischen MySQL-Verbindung; sie öffnen ausdrücklich keine MySQL-Verbindung. | SQLite-Erfolg beweist weder MySQL-DDL-/Upgrade-Kompatibilität noch das Verhalten der `lockForUpdate()`-Regeln unter parallelen Transaktionen. Das ist besonders relevant für die produktiven Scheduling- und Migrationspfade. | Einen isolierten MySQL-CI-Job ergänzen: saubere Installation, repräsentativer Upgrade-/Repair-Pfad und ausgewählte Parallelitätsfälle. SQLite bleibt für schnelle Tests erhalten. Keine produktiven Daten verwenden. | 9/10 |
| 4 | P2 | **Zwei Navigationstests sind aktuell rot.** Der Lauf ausgewählter Feature-/Migrationstests endete mit **41 bestanden, 2 fehlgeschlagen, 330 Assertions**. Beide Fehler sind in [OrderPlanningLinksTest.php](../tests/Feature/OrderPlanningLinksTest.php): Direkt nach `openDetails()` werden Planungslinks erwartet. In [service-details.blade.php](../resources/views/livewire/admin/operations/partials/service-details.blade.php) werden diese Links im Planungsbereich angezeigt; der Test wechselt nicht in diesen Bereich. | Der Testvertrag bildet den derzeitigen tab-basierten Ablauf nicht ab. Gleichzeitig ist nicht geklärt, ob die Links bewusst aus der ersten Detailansicht entfernt wurden oder als Schnellzugriff fehlen. | UX-Vertrag entscheiden. Falls Tabs beabsichtigt sind, Test auf Öffnen des Planungsbereichs umstellen; falls der direkte Einstieg wichtig bleibt, Schnellzugriff wiederherstellen. Danach die einzelne Testdatei und die Operations-Tests ausführen. | 9/10 für den Testfehler; 7/10 für die UX-Ursache |
| 5 | P3 | **Große Ablaufklassen erhöhen Änderungsrisiko.** Beispiele aus der Zeileninventur: `MailDocumentController` ca. 1.970 Zeilen / 43 Methoden, `Chatbot` ca. 1.842 Zeilen / rund 60 Treffer auf Methoden/Konstanten und `DeviceManagementSettings` ca. 1.487 Zeilen. Der Mailcontroller behandelt unter anderem Import, Medienspeicherung, Bereinigung, Kompatibilitätsprüfung, Veröffentlichung und Versionsverwaltung. | Änderungen an voneinander unabhängigen Abläufen erfordern breite Regressionstests und erschweren lokales Verständnis. Die Größen allein belegen weder fehlerhafte Implementierung noch dringenden Änderungsbedarf. | Nur bei konkreter Änderung schrittweise nach Use Case extrahieren (zuerst Import/Medien und Freigabe in `MailDocumentController`); bestehende Tests als Charakterisierung behalten. Keine rein mechanische Aufteilung nach Zeilenzahl. | 8/10 |

## Priorisierter Optimierungsplan

### Phase 1 – Verifizierbare Baseline herstellen

- UX-Entscheidung zu den zwei roten Planungstests treffen und deren
  Testablauf beziehungsweise beabsichtigte Schnellzugriffe konsistent machen.
- Die ausgewählten Featuretests wiederholen; anschließend die vollständige
  SQLite-Suite über `php artisan test` als bestehende Projektbaseline ausführen.
- Testergebnisse inklusive Umgebungsangaben dokumentieren, damit später
  Fehlerursachen nicht mit Schema- oder Datenbankunterschieden verwechselt
  werden.

**Abnahmekriterien:** Planungstest und relevante Operations-Tests grün;
vollständige Testbaseline mit reproduzierbarem Ergebnis; kein stilles Ignorieren
fehlgeschlagener Tests.

### Phase 2 – MySQL- und Migrationstauglichkeit absichern

- Einen isolierten MySQL-Testdienst in CI ergänzen.
- Frische Migration, einen repräsentativen Upgrade-/Repair-Pfad und
  wiederholbare Migrationstests auf echter MySQL-Version ausführen.
- Scheduling- und Zuweisungsregeln, die `lockForUpdate()` voraussetzen, unter
  zwei konkurrierenden Transaktionen testen.
- `work_time_entries.order_id` und `training_session_id` auf Orphans prüfen,
  Aufbewahrungssemantik klären und erst dann die passende FK-Migration planen.

**Abnahmekriterien:** SQLite- und MySQL-Testjobs grün; Repair-Pfad erhält
vorhandene Geschäftsdatensätze; invalide Referenzen sind entsprechend der
beschlossenen Löschregel blockiert oder explizit nullbar.

### Phase 3 – Exportleistung messbar verbessern

- `creditedSeconds()` so ausrichten, dass bereits geladene Aktivitäten
  wiederverwendet werden können, oder eine Batchberechnung im Exportpfad
  einsetzen.
- CSV-Werte und Rundungs-/Bewertungsregeln mit vorhandenen Work-Time-Tests
  absichern.
- Abfragezahl bei mehreren bewerteten Einträgen messen.

**Abnahmekriterien:** gleiche Exportwerte wie zuvor; Aktivitäten werden für
einen Export gesammelt statt pro Eintrag einzeln gelesen; Query-Count-Test
verhindert erneutes lineares Wachstum.

### Phase 4 – Hotspots risikoarm entkoppeln

- Vor einer Änderung am Maildokument-Editor vorhandene Import-, Sanitizer-,
  Kompatibilitäts- und Publicationstests als Sicherheitsnetz ausführen.
- Fachliche Schritte in kleine, benannte Use Cases extrahieren; HTTP-Controller
  und Livewire-Komponenten auf Validierung/Orchestrierung begrenzen.
- Gleiches Verfahren nur dort wiederholen, wo eine konkrete Änderung oder
  Testschwierigkeit den Nutzen belegt.

**Abnahmekriterien:** keine Änderung am beobachtbaren API-/UI-Verhalten ohne
bewusste Freigabe; Tests bleiben grün; jede neue Grenze hat klare Ein-/Ausgaben
und einen zugehörigen Test.

### Phase 5 – Leichte dauerhafte Guardrails

- PHP-Formatprüfung und gezielte statische Analyse für geänderte Bereiche in
  wiederholbare CI-Schritte aufnehmen; zunächst bestehende Befunde erfassen und
  nicht pauschal alle Legacydateien blockieren.
- Migrationserweiterungen nur additiv und mit Upgrade-/Datenbestandstests
  zulassen.
- Dokumentation zu fachlichen Constraints, Statusübergängen und
  Aufbewahrungsregeln nahe den jeweiligen Domänenentscheidungen halten.

**Abnahmekriterien:** lokale und CI-Prüfschritte sind dokumentiert und
reproduzierbar; neue Fehler werden verhindert, ohne bestehende Tests oder
Produktionsdaten durch automatische Bereinigung zu umgehen.

## Validierung und Grenzen

- Ausgewählte Testdateien wurden über `php artisan test` ausgeführt. Ergebnis:
  **41 bestanden, 2 fehlgeschlagen, 330 Assertions**. Die beiden Fehler gehören
  zum Planungslink-Test; siehe Befund 4.
- Der Versuch, `runTests` mit absoluten Pfaden unter dem verschachtelten
  `App/`-Projekt aufzurufen, erkannte dort keine Tests; deshalb wurde der
  Laravel-eigene Runner im Projektverzeichnis verwendet.
- Die Testsuite nutzt eine in-memory SQLite-Datenbank. Es wurde keine
  Entwicklungs- oder Produktionsdatenbank verändert.
- Es wurde keine Lastmessung mit produktionsähnlichen Daten durchgeführt. Der
  Exportbefund ist aus dem Abfragepfad abgeleitet und sollte in Phase 3 durch
  Query-Count-Messung quantifiziert werden.
- Es liegt in dieser Workspace-Ansicht kein Git-Repository vor. Aussagen über
  Urheberschaft, Entstehungsgeschichte oder konkrete KI-generierte Änderungen
  sind daher nicht möglich.
