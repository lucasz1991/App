# Aktuelle lokale Testpläne aus Excel-Importen

`operations:seed-local-demo` ergänzt die lokale Datenbank um intern markierte Testleistungen und Dienste. Die sichtbaren Titel tragen kein `[TEST]`-Label. Vorlagen sind die bereits abgeschlossenen lokalen Excel-Importe: Kunden, Einsatzorte und Tätigkeiten werden aus deren Schichten übernommen. Zugewiesen werden ausschließlich aktive, ausdrücklich zugeordnete `excel-test-…@railtime.invalid`-Konten aus der Kompetenzmatrix. Originalimporte, Originaldateien, Konten und gemeldete Kompetenzdaten werden nicht verändert.

```powershell
php artisan operations:seed-local-demo --dry-run
php artisan operations:seed-local-demo
```

Standardmäßig wird die vorherige Woche, die aktuelle Woche und vier kommende Wochen in `Europe/Berlin` gefüllt. Es gibt 16 unterschiedliche Vorlagen, werktags mehr Dienste als am Wochenende und Früh-, Spät- sowie Nachtdienste. Nachtdienste können in den folgenden Tag reichen. Auch die Zeitumstellung wird als tatsächlich verstrichene Dienstdauer behandelt.

Optional: `--date=2026-10-02`, `--weeks-before=1`, `--weeks-after=4`, `--templates=16`, `--actor=45`. Ohne `--actor` wird der zuletzt angelegte aktive lokale Administrator verwendet. Die Vorschau zeigt die Anzahl neuer Leistungen und Schichten; Zuweisungszahlen stehen erst nach den tatsächlichen Einsatzprüfungen fest.

## Testfälle

- Bestätigte und angefragte Einsätze, abgelehnte Rückmeldungen, Teilbesetzung, offene Stellen, Entwürfe und Stornierungen.
- Laufende Dienste sowie abgeschlossene synthetische Vergangenheit; historische Pläne werden vor Dienstbeginn veröffentlicht und bestätigt. Die temporäre Prozessuhr für diesen historischen Ablauf wird danach wiederhergestellt.
- Genehmigter Testurlaub und beantragte Nichtverfügbarkeit, ohne Gesundheitsangaben.
- Exemplarische historische Zeitmeldungen als abgeschlossen, eingereicht oder freigegeben sowie laufende Zeiterfassungen für bereits begonnene Dienste.
- Veröffentlichung, Rückmeldungen, Zeitmeldungen und Abwesenheiten verwenden die vorhandenen Operations-Dienste und deren Prüfungen/Audits. Der abschließende Status historischer Testschichten wird als Initialisierung der Testhistorie gesetzt, ohne das bereits veröffentlichte Zeitfenster oder dessen Revision zu ändern.

Fehlt ein aktives Regelprofil, wird einmal `Lokale Planungsregeln` erstellt (660 Minuten Ruhezeit, maximal 600 Minuten Dienst, 30 Minuten Pause ab mehr als 360 Minuten). Dies sind lokale Testparameter. Ein vorhandenes Regelprofil wird beibehalten. Gemeldete Sperren, abgelaufene Dokumente, genehmigte Abwesenheiten und Termin-/Ruhezeitkonflikte bleiben wirksam; solche Mitarbeiter werden nicht zugewiesen. Gemeldete Excel-Kompetenzen werden nicht in echte Nachweisfreigaben umgewandelt.

## Grenzen und Wiederholung

Der Befehl ist serverseitig auf `local`/isoliertes `testing`, lokale App-URLs und lokale Datenbankverbindungen begrenzt. Alle Datenbankänderungen erfolgen in einer gemeinsamen Transaktion und mit einem exklusiven Generator-Lock. Der externe Sync-Outbox-Pfad ist im Generator deaktiviert; es werden keine Einladungen oder Nachrichten verschickt.

Testleistungen tragen weiterhin eine Nummer `TEST-<Wochenbeginn>-<Vorlagenkennung>` und Herkunftsmetadaten für die Wiederholbarkeit; die sichtbaren Leistungs-, Schicht- und Regelprofiltitel sind ohne `[TEST]`-Präfix. Wiederholungen behalten bestehende Testleistungen einschließlich manueller Bearbeitungen und gelöschter Datensätze bei. Späteres Ausführen ergänzt neue Wochen. Es gibt keinen automatischen Reset und keine geplante Hintergrundausführung.

Ein privater Laufbericht mit Mengen und den neu erzeugten Leistungs-IDs liegt unter `storage/app/private/local-demo`. Er enthält keine Passwörter oder Personennamen. Schichtplan und Kalender zum aktuellen Zeitraum öffnen bzw. neu laden; ein zuvor ausgewählter historischer Zeitraum bleibt sonst weiterhin ausgewählt.
