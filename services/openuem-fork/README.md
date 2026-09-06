# OpenUEM in RailTime – ein gemeinsames App-Repository

Alle Bestandteile liegen als normale Quellverzeichnisse im bestehenden `App`-
Repository. Es gibt hier keine eigenen Git-Repositories, Gitlinks oder Submodule.
Die bisherigen Git-/Quellbestände wurden vor der Umstellung vollständig als ZIP
gesichert und Datei für Datei geprüft. Kein Commit, Push oder Deployment gehört
zu dieser Umstrukturierung.

```text
App/
  app/Services/DeviceManagement/OpenUemFork/   Laravel-Anbindung
  services/openuem-fork/
    native/worker/                          erweiterter OpenUEM-Server
    native/agent/                           erweiterter Windows-Agent
    extension/                              gemeinsamer Ausführungsvertrag
    integration/                            HTTP/PG/NATS/Journal-Test
    reference/console/                      erhaltener Referenzcode
    reference/nats/                         erhaltener Referenzcode
    maintenance/verify-layout.ps1            lesende Strukturprüfung
    upstream-lock.json                      Herkunft, Rollen und Ausgangsstände
```

Die Unterordner bleiben eigenständige **Go-Module**, weil Worker, Windows-Dienst
und Tests getrennt gebaut werden. Das sind keine eigenständigen Git-Repositories.
Worker und Agent beziehen die gemeinsame Erweiterung lokal über `../../extension`.
Die NATS-Abhängigkeit bleibt auf ihren bestehenden Modulstand gepinnt; die
Referenzkopie wird nicht zusätzlich in den Ausführungspfad eingebunden.

## Bearbeiten und prüfen

Quelländerungen werden direkt hier bearbeitet und zusammen mit RailTime geprüft.
Die alten Clone-/Patch-Restore-Skripte sind entfernt. Ursprüngliche Ignore- und
leere Submodule-Dateien sind als `UPSTREAM.gitignore` beziehungsweise
`UPSTREAM.gitmodules` erhalten, ohne den App-Git-Index zu beeinflussen.

Von `App` aus:

```powershell
& ./services/openuem-fork/maintenance/verify-layout.ps1
```

Mit dem geprüften Go-Toolchainstand 1.26.8 jeweils im angegebenen Modulordner:

| Ordner | Prüfung |
|---|---|
| `extension` | `go test ./...` |
| `native/worker` | `go test ./...` |
| `native/agent` (Windows) | `go test ./internal/agent ./internal/service/windows` |
| `integration` | Socket-Test mit ausdrücklich angegebenem privatem PostgreSQL-Testfixture |

Die Datenbanktests benötigen eine isolierte PostgreSQL-Instanz; ohne Fixture
werden sie ausdrücklich übersprungen. Private Testdaten liegen ausschließlich
unter dem Git-ignorierten `App/storage/app/private/openuem-test-runtime`, nicht
im Quellbaum. Vite beobachtet diesen Go-/Referenzbereich nicht.

## Funktionsstand und Grenzen

Lokal implementiert sind signierte, eindeutig gebundene Windows-Profilaufträge,
ein transaktionales Serverjournal, ein dauerhaftes Agentjournal und die
RailTime-Statusabfrage. Eine Annahmequittung ist kein Ausführungserfolg. Ungewisse
Ausgänge führen nicht zur automatischen Wiederholung und halten die
Geräteübergabe gesperrt. Die bestehenden Microsoft-/Google-Kontenprofile sind
von diesen ausführbaren Profilen getrennt.

Einzelheiten: [Worker-Einrichtung](extension/server/README.md) und
[Windows-Einrichtung](native/agent/RAILTIME-EXECUTION.md).
Die produktive Installation, echte Agentregistrierung, Schlüsselbereitstellung
und der tatsächliche Windows-Pilot sind weiterhin gesondert abzunehmen.
Referenzcode ist kein zusätzlich installierter Serverdienst.

Upstream-Updates erfolgen bewusst als geprüfte Dateiänderungen mit anschließendem
Update von `upstream-lock.json`; kein automatisches Überschreiben lokaler
Erweiterungen. Lizenzen und Hinweise bleiben in allen Quellverzeichnissen erhalten.
Vor einem produktiven Release außerdem Dateieigentümer im `services`-Bereich
prüfen: Das bestehende allgemeine Deploymentskript deckt deren Korrektur bislang
nicht vollständig ab.
