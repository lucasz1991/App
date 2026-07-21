# RailTime – interne Mitarbeiter- und Kommunikationsplattform

## 2026-07-21 - Codex (Sprachnachrichten und eigene Nachrichten löschen)

- Der Eingabebereich zeigt Textmodus und Sprachaufnahmemodus jetzt strikt abwechselnd. Die Alpine-Ausblendung verwendet eine priorisierte Display-Regel, damit globale Flex-Utilities nicht mehr beide Bereiche nebeneinander sichtbar machen können.
- Mikrofonaufnahmen mit dem browsertypischen Fehltyp `video/webm` werden als `audio/webm` normalisiert und ausschließlich im eigenen Sprachnachrichten-Player dargestellt; der schwarze native Video-Player wird dafür nicht mehr verwendet. Eine Datenmigration korrigiert auch vorhandene Recorder-Aufnahmen.
- Eigene Chatnachrichten können direkt in der Sprechblase gelöscht werden. Die Berechtigung wird serverseitig auf Absender und Chatmitgliedschaft geprüft; zugehörige private Dateien werden mit entfernt.
- Aufgenommene Sprachnachrichten werden als eigener Nachrichtentyp ohne Text und ohne normalen Anhangs-Chip gesendet. Die mobile Aufnahmeleiste ersetzt während der Aufnahme die Texteingabe und bietet animierte Pegel, Abbrechen, Senden und `Einmal anhören`.
- Die Wiedergabe verwendet einen eigenen responsiven Player mit Wellenform, Fortschritt, Zeit und Play/Pause statt nativer Browser-Steuerelemente. Einmal-Sprachnachrichten erhalten pro Empfänger nur einen kurzlebigen serverseitigen Wiedergabezugang und lassen sich nicht über Vorschau oder Download umgehen.
- Die additive Chat-Migration wurde erfolgreich ausgeführt. Alle 62 Tests mit 435 Assertions sowie der Vite-Produktionsbuild sind erfolgreich.

## 2026-07-21 - Codex (Globaler Dark-Mode-Vertrag fuer UI-Komponenten)

- Ursache der hellen Dark-Mode-Flaechen abgesichert: Das Legacy-Tailwind-Bundle nutzt `!important` und `body[data-mode=dark]`, waehrend Vite `html.dark` verwendet. Eine zentrale, spaet geladene `rt-ui-*`-Kompatibilitaetsschicht unterstuetzt beide Signale und gewinnt gezielt gegen alte Light-Utilities.
- Buttons, Disabled-Zustaende, Inputs, Selects, Textareas, Checkboxen, Toggles, Dropdowns samt semantischen Varianten, Modals, Tabellen, Pagination, Karten, Badges, Alerts, File-Pool-Elemente, Navigation und Toasts auf gemeinsame Theme-Hooks umgestellt.
- Absichtlich helle Inhalte wie Mail-Vorschauen, QR-Codes und Colorpicker-Regler bleiben ausgenommen. Das ausgelieferte Vite-CSS wird durch einen Regressionstest auf die neuen Theme-Selektoren geprueft.

## 2026-07-21 - Codex (Chat-Swipe-Richtung)

- Die mobile Swipe-Steuerung ist korrigiert: Nach rechts wischen öffnet aus einem Chat die Chatübersicht, nach links wischen kehrt aus der Übersicht zum zuletzt ausgewählten Chat zurück.
- Die seitliche Wechselanimation folgt jetzt derselben Bewegungsrichtung wie die Swipe-Geste. Zuordnung und Animation sind durch Regressionstests gegen erneutes Vertauschen abgesichert.

## 2026-07-21 - Codex (IT-Feedback und Support)

- Alle angemeldeten Benutzer erreichen über den letzten Sidebar-Eintrag ein responsives IT-Feedback- und Supportformular für Fragen, technische Probleme, Feedback und Funktionswünsche.
- Supportanfragen werden im bestehenden Mailprotokoll erfasst, über den Queue-Mailversand an die zentrale Admin-/Super-Admin-Adresse geschickt und können über die Absenderadresse direkt beantwortet werden.
- Die statischen Links zu Auftragsverwaltung, Schichtleitung, Kalender und Kundendatenbank werden in Sidebar und Dashboard ausschließlich bei der globalen Rolle `admin` ausgegeben; die Routen bleiben zusätzlich durch `role:admin` geschützt.

## 2026-07-21 - Codex (Mobile Einstellungen und Tab-Auswahl)

- Die gemeinsame Tabs-Komponente wechselt unter 768 Pixeln automatisch in einen vollbreiten Bereichswähler; alle Tabs bleiben ohne horizontalen Overflow erreichbar.
- Die Admin-Einstellungen verwenden mobil kompaktere Karten, volle Formularbreite, einspaltige Eingaben und vollbreite Speichern-Aktionen. Dekorative Abschnittsicons werden auf kleinen Displays ausgeblendet.
- Authentifizierte Livewire- und Responsive-Komponententests sowie der Vite-Produktionsbuild sind erfolgreich.

## 2026-07-21 - Codex (Mobile Formfelder und Toggle-Redesign)

- Alle gemeinsamen Text-, Datums-, Zeit- und Auswahlfelder verwenden mobil `text-base` und ab `sm` wieder die kompakte Schriftgröße. Eine zusätzliche mobile CSS-Regel schützt auch native und von Flatpickr erzeugte Eingabefelder vor dem automatischen iOS-Fokuszoom.
- Formfelder besitzen nun einheitliche 44-px-Touchflächen, ruhigere Innenabstände, klarere Light-/Dark-Fokuszustände und konsistente Disabled-/Readonly-Darstellungen.
- Beide Toggle-Komponenten verwenden denselben größeren Schalter mit sauber zentriertem Knob, sichtbarem Tastaturfokus, korrekten Disabled-Zuständen und echtem zugänglichem Switch-Status.

## 2026-07-21 - Codex (Verbindliche Dateien mit Versionierung)

- Die Dateiverwaltung besitzt nun den separaten Bereich `Verbindliche Dateien`: Administratoren pflegen dort dauerhaft benannte Dokumentzwecke wie Wagen- oder Meldelisten und veröffentlichen jeweils eine aktuelle Fassung.
- Neue Fassungen bleiben als wiederherstellbare Versionshistorie erhalten. Normale Benutzer sehen im Download-Center ausschließlich die aktuelle Version; Sichtbarkeit und automatische Benachrichtigungen können für alle Mitarbeiter oder ausgewählte Teams festgelegt werden.
- Der MySQL-Index verwendet einen kurzen expliziten Namen und kann auch nach einem zuvor abgebrochenen Migrationslauf verlustfrei ergänzt werden. PHPUnit läuft künftig isoliert auf SQLite-In-Memory statt auf der lokalen App-Datenbank.
- Alle 49 Tests mit 308 Assertions sowie der Vite-Produktionsbuild sind erfolgreich.

## 2026-07-21 - Codex (Betriebsvorschau und Dashboard-Animationen)

- NEU statische Admin-Vorschauen für Aufträge, Schichtleitung, Kalender und Kundendatenbank. Die Beispieldaten liegen bewusst in einem reinen Preview-Katalog; es gibt keine Models, Migrationen oder Schreibzugriffe.
- Die vier Bereiche erscheinen auf dem Admin-Dashboard und in der Sidebar unter einer eigenen Überschrift unterhalb der Administration als gemeinsames aufklappbares Untermenü.
- KPI-Zähler starten erst beim sichtbaren Kennzahlenbereich, werden bei Livewire-Navigation sauber beendet und laufen nur einmal. ECharts entsorgt Altinstanzen; Themewechsel aktualisieren die Darstellung ohne erneute Show-Animation.

## Information für die Auftraggeber

Mit RailTime steht inzwischen eine gemeinsame Grundlage bereit, über die Mitarbeiter, persönliche Informationen, interne Nachrichten, Chats und Dateien sicher und einheitlich gemanagt werden können. Statt Informationen auf verschiedene Messenger, E-Mail-Verläufe und einzelne Ablagen zu verteilen, werden die relevanten Bereiche zentral zusammengeführt. Dabei sieht jeder Mitarbeiter nur die Inhalte, die für ihn persönlich, seine Rolle oder sein Team freigegeben sind.

Ein wichtiger Schwerpunkt ist die Sicherheit der Daten und Kommunikation. Persönliche Nachrichten und Chat-Inhalte werden bereits serverseitig verschlüsselt gespeichert und liegen dadurch nicht als frei lesbarer Klartext in der Datenbank. Ergänzend schützen rollenbasierte Berechtigungen, private Echtzeitkanäle und die für den Produktivbetrieb vorgesehene verschlüsselte HTTPS-/WSS-Übertragung den Zugriff. Damit besitzt die Plattform bereits ein sehr hohes Sicherheitsniveau für die zentrale interne Kommunikation. Eine echte Ende-zu-Ende-Verschlüsselung nach dem Signal-Protokoll wäre eine zusätzliche, davon getrennte Ausbaustufe und wird daher nicht fälschlich als bereits umgesetzt dargestellt.

Auch die Echtzeit- und Push-Grundlage ist schon enthalten: Neue Nachrichten können direkt zugestellt, durch Badges und Hinweise sichtbar gemacht und ohne manuelles Neuladen aktualisiert werden. Diese Technik bildet gleichzeitig die Basis für die geplante App. Gemeinsam arbeiten wir nun weiter daran, die Handhabung im täglichen Einsatz so einfach, schnell und verständlich wie möglich zu gestalten.

Die E-Mail-Vorlagen und Signaturen stehen jedem Mitarbeiter einheitlich und mit seinen persönlichen Kontaktdaten sowie den zentral gepflegten Firmendaten zur Verfügung. Dadurch kann die Kommunikation nach außen unabhängig vom jeweiligen Mitarbeiter im gleichen RailTime-Auftritt erfolgen.

Als nächster größerer Arbeitsbereich befinden sich die Auftrags- und Schichtverwaltung in Umsetzung. Funktionen und Bedienung werden dabei gemeinsam anhand der tatsächlichen betrieblichen Abläufe abgestimmt, damit am Ende nicht nur einzelne Funktionen vorhanden sind, sondern ein Werkzeug entsteht, das im Arbeitsalltag wirklich schnell und unkompliziert genutzt werden kann.

Für die weitere Einrichtung müssen die Mitarbeiter anschließend angelegt beziehungsweise eingeladen und den passenden Teams und Firmenpositionen zugeordnet werden. Hierzu ist noch die Frage offen: Gibt es bereits eine aktuelle Mitarbeiterliste mit Namen, geschäftlichen E-Mail-Adressen, Teamzugehörigkeit und Firmenposition? Wenn eine solche Liste vorhanden ist, kann sie als Grundlage für die strukturierte Einladung und Einrichtung verwendet werden. Andernfalls können die Mitarbeiter schrittweise direkt über die Mitarbeiterverwaltung eingeladen werden.

RailTime bündelt die tägliche interne Zusammenarbeit in einer zentralen, rollenbasierten Webanwendung. Mitarbeitende erhalten einen persönlichen Bereich für ihre Daten, freigegebene Dokumente, Nachrichten, Chats und E-Mail-Vorlagen. Verwaltung und Administratoren steuern zusätzlich Mitarbeiter, Teams, Berechtigungen, Dateien, Vorlagen und betriebliche Einstellungen; ausschließlich globale Administratoren sehen den gesonderten Administratorbereich mit vollständigen Systemkennzahlen.

**Projektstatus (21. Juli 2026):** Die Kernanwendung ist als Laravel-/Livewire-System umgesetzt und in den zentralen Arbeitsabläufen funktionsfähig. Die Oberfläche unterstützt Desktop, Tablet und Mobilgeräte sowie Hell-/Dunkelmodus. Vor einem Produktivbetrieb müssen die Zielumgebung vollständig migriert, die Queue und – für sofortige Echtzeitaktualisierungen – Laravel Reverb eingerichtet werden.

## Nutzen für das Unternehmen

- Zentrale, nachvollziehbare Mitarbeiterverwaltung statt verteilter Einzelablagen.
- Rollen- und teamabhängige Informationen: Beschäftigte sehen nur persönliche bzw. freigegebene Inhalte, Administratoren erhalten die notwendigen Betriebsdaten.
- Schnellere interne Kommunikation durch Nachrichten, Echtzeit-Chats, Lesestatus, Tippanzeige sowie Datei-, Bild-, Video- und Sprachnachrichten.
- Kontrollierte Dokumentbereitstellung über persönliche, rollenbasierte und teambezogene Downloadbereiche mit Sichtbarkeits- und Ablaufregeln.
- Einheitliche Außendarstellung durch zentral gepflegte Firmendaten und personalisierte E-Mail-Vorlagen beziehungsweise Signaturen.

## Derzeit lauffähige Funktionen

- Getrennte Anmeldung und URL-Struktur für globale Administratoren sowie rollen-/teamgerechte Dashboards für Administratoren, Verwaltung, Mitarbeiter und Gäste.
- Mitarbeiter anlegen, bearbeiten und einladen, einschließlich Team, Firmenposition, persönlicher Stammdaten, Status und Berechtigungen.
- Persönliches Profil mit Kontakt- und Adressdaten, Sicherheit, Zwei-Faktor-Authentifizierung und Sitzungsverwaltung.
- Datei- und Downloadcenter mit Ordnern, Rollen-/Teamfreigaben, Zeitfenstern, Vorschau, Download und automatischer Löschung abgelaufener Inhalte.
- Interne Nachrichten und Chats mit Echtzeitaktualisierung, Tippstatus, Zustell-/Lesestatus, Anhängen, Bildvorschau, Video und Sprachnachrichten.
- Mailverwaltung sowie personalisierte E-Mail-Vorlagen und Signaturen auf Basis zentraler Firmendaten.
- Administrations-Einstellungen in den Bereichen Allgemein, Benutzer und System sowie ein Systemdashboard mit Benutzer-, Aktivitäts- und Betriebskennzahlen.
- Responsive Navigation mit mobilem Drawer, dauerhaft sichtbarem aktiven Untermenü und animiertem Burger-/Schließen-Schalter.

## Entwicklungs- und Übergabeprotokoll

Diese Datei ist das gemeinsame Übergabe- und Kommunikationsprotokoll für Coding-Agents (z. B. Codex und Claude Code).

## Aktueller Stand

- Laravel-App unter `App/` mit Admin-Bereich (`/administrator`) und Nutzerbereich.
- Migrationen für eine Neuinstallation bereinigt: nachträgliche `add_...`-Migrationen wurden in die jeweiligen `create_...`-Migrationen integriert.
- Teams: `Administratoren`, `Verwaltung`, `Mitarbeiter`, `Gäste`.
- Lucas (`lucas@zacharias-net.de`) ist der einzige globale und Team-Administrator.
- Teamzuordnungen werden über Einladung beziehungsweise Mitarbeiterverwaltung gepflegt; nur der globale Administrator wird beim Seeding automatisch dem Team `Administratoren` zugeordnet.
- Der aktuelle Frontend-Build sowie die gezielten Dashboard-, Kommunikations-, Datei- und E-Mail-Vorlagentests sind erfolgreich.

## Letzter Verlauf

1. Migrationen und Seeder geprüft.
2. Foreign Keys für Teams, Team-Mitglieder und `current_team_id` ergänzt.
3. `current_team_id`, `shared_roles`, `event` und `batch_uuid` in die Basismigrationen verschoben; die separaten `add_...`-Dateien entfernt.
4. `AdminUserSeeder` auf Lucas als einzigen Admin reduziert.
5. `TeamSeeder` auf die vier fachlichen Teams und die automatische Zuordnung ausschließlich des globalen Administrators ausgerichtet.
6. Topbar, Sidebar und Logo erhalten konsistente Tailwind-Light-/Dark-Mode-Klassen.

## Zusammenarbeit

- Änderungen immer hier mit Datum, Agent und kurzer Begründung ergänzen.
- Vor Änderungen die betroffenen Dateien und den aktuellen Laufzeitfehler prüfen.
- Bei Datenbankänderungen beachten: Die Migrationen sind auf eine frische Installation ausgelegt. Für einen kompletten Neuaufbau ist `php artisan migrate:fresh --seed` erforderlich.
- Keine bestehenden, nicht zum Task gehörenden Änderungen zurücksetzen.

## 2026-07-21 - Codex (Schnellzugriffe mit verbindlichem Dark Mode)

- Alle vier Dashboard-Schnellzugriffe verwenden nun dieselben priorisierten `html.dark`- und `body[data-mode="dark"]`-Regeln wie Live-Betrieb und Demo-Vorschau.
- Dunkle Grundfläche, Rahmen, Schrift und roter Hover-Zustand können nicht mehr durch den hellen Kartenhintergrund überschrieben werden.

## 2026-07-21 - Codex (Verbindlicher Dark-Mode-Hintergrund im Dashboard)

- Live-Betrieb und Betrieb-Demo-Vorschau reagieren nun auf beide Laufzeitsignale `html.dark` und `body[data-mode="dark"]`.
- Spezifische Hintergrund-, Rahmen- und Hover-Regeln mit gezielter Priorität verhindern, dass später geladene Kartenstile einen weißen Hintergrund unter bereits weißer Dark-Mode-Schrift erzeugen.

## 2026-07-21 - Codex (Dashboard- und Auftragsmodule im Dark Mode)

- Die im Screenshot hell zurückfallenden Dashboard-Flächen besitzen nun eigene stabile Dark-Mode-Zustände für Live-Betrieb, Sekundäraktion, Diagramm-Badge, Betriebssteuerung, Nutzerliste, Schnellzugriffe und Systemzellen.
- Der Hero wurde auf eine kompaktere, begrenzte Live-Betriebsspalte umgestellt; dunkle Akzenttexte und Trennlinien verwenden kontraststärkere zentrale RailTime-Farben.
- Alle vier Betriebs-Dummymodule einschließlich Aufträge verwenden dieselben dunklen Kopf-, Statistik-, Listen-, Navigation- und Hinweisflächen; die aktive Desktop-Sidebar bleibt ebenfalls themegerecht.

## 2026-07-21 - Codex (Desktop-Diagramme und Betriebssteuerung im Dark Mode)

- Nutzerentwicklung und Kontostatus stehen bereits ab der normalen Desktop-Breite im Verhältnis 8:4 nebeneinander; auf besonders breiten Ansichten ergänzt der Aktivitätstrend die gemeinsame Diagrammzeile.
- Die Betriebssteuerung verwendet im Dark Mode klar getrennte Kopf-, Status-, Raster- und Modulkartenflächen mit sichtbaren Rahmen und kontrastreichen Hover-Zuständen.

## 2026-07-21 - Codex (Kompaktes Administrator-Dashboard)

- Begrüßungsbereich, Kennzahlen, Listen und Schnellzugriffe wurden deutlich verdichtet und auf ein konsistentes kompaktes Raster abgestimmt.
- Die drei ECharts-Diagramme stehen jetzt direkt unter den KPIs und sind auf üblichen Laptop-Ansichten ohne vorgeschaltete Betriebsvorschau sichtbar.
- Betriebsvorschau und Systemdaten bleiben vollständig erhalten. Hero, Panels, Tooltips und Aktivitätsdiagramm wechseln jetzt vollständig zwischen klar abgestimmten Light- und Dark-Mode-Flächen.

## 2026-07-21 - Codex (Administrator Command Center)

- Das Administrator-Dashboard wurde als responsives RailTime Command Center mit animierter Streckengrafik, KPI-Zählern sowie realen Diagrammen für Nutzerentwicklung, Kontostatus und Aktivität neu gestaltet.
- Technische Systemdaten werden serverseitig ausschließlich für das aktuell gewählte Team `Administratoren` geladen; das Team `Verwaltung` behält seine operative Übersicht ohne technische Informationen.
- Die Laravel-Versionsanzeige wurde entfernt und durch den Entwickler `Lucas M. Zacharias` ersetzt; Rollen-, Render-, Responsive- und Produktions-Build-Prüfungen sind erfolgreich.

## 2026-07-21 - Codex (ECharts-6-Dashboard und klare Oberflächen)

- Die schweren ApexCharts im Administrator-Dashboard wurden durch modular und nur auf dieser Seite geladenes Apache ECharts 6 mit SVG-Renderer ersetzt.
- Nutzerentwicklung, Kontostatus und Aktivität verwenden nun feine Linien, schmale Marker, einen reduzierten Statusring und kompaktere Beschriftungen.
- Kaum erkennbare `opacity-10`-Glasflächen wurden durch solide RailTime-Panels, sichtbare Rahmen und kontrastreiche Dark-Mode-Flächen ersetzt.

## 2026-07-21 - Codex (KPI-Animation und themefähiger Dashboard-Banner)

- KPI-Zähler starten nun stabil bei null, zählen koordiniert bis zum echten Wert und springen nicht mehr nachträglich vom bereits sichtbaren Endwert zurück.
- Die Aktivquote wird per GPU-beschleunigtem `scaleX` animiert; reduzierte Bewegung, Cleanup und `wire:navigate` werden über `gsap.matchMedia()` berücksichtigt.
- Alle vier Admin-KPIs stehen in einer durchgehenden, responsiv verdichteten Zeile. Der Dashboard-Banner besitzt getrennte helle und dunkle Oberflächen statt eines fest verdrahteten Dark-Designs.

## 2026-07-21 - Codex (Automatische teambezogene Willkommensnachrichten)

- Neu angelegte Mitarbeiter erhalten automatisch eine interne RailTime-Willkommensnachricht und dieselbe Kommunikation als E-Mail mit zentraler Firmensignatur.
- Die Teams `Administratoren`, `Verwaltung`, `Mitarbeiter` und `Gäste` verwenden jeweils eigene Hinweise zu ihren Bereichen und Berechtigungen; unbekannte Teamnamen erhalten einen allgemeinen Fallbacktext.
- Der Versand erfolgt nach abgeschlossener Teamzuordnung sowohl bei direkt angelegten Mitarbeitern als auch nach Annahme einer Einladung und wird als `both` in der Mailverwaltung protokolliert.

## 2026-07-21 - Codex (Mobile Admin- und Kommunikationsoptimierung)

- Admin- und Verwaltungsdashboard zeigen die vier Haupt-KPIs mobil kompakt nebeneinander; Inhaltskarten, Schnellzugriffe und Betriebskennzahlen verwenden kleinere Abstände und mobile Raster.
- Chatliste und Unterhaltung wechseln mobil in getrennte Vollbreitenansichten. Sprachnachrichten besitzen einen kompakten Player; Bilder öffnen über die berechtigungsgeprüfte Dateivorschau inklusive Download.
- Nachrichtenzeilen sind mobil gestapelt, das aktive Sidebar-Untermenü bleibt geöffnet und Tabellen-Dropdowns liegen nicht mehr hinter den Action-Buttons nachfolgender Zeilen.
- Benutzer-E-Mail-Vorlagen enthalten kein oberes Bild mehr. Die Downloadseite zeigt eine personalisierte Miniatur, die sich in einem großen Modal öffnen und direkt herunterladen lässt.
- Regressionstests für Mobile-UI, Vorlagen und geschützte Chat-Anhänge sowie der Vite-Produktionsbuild sind erfolgreich.

## 2026-07-21 - Codex (Mitarbeiterlöschung nur für Administratoren)

- Mitarbeiter können direkt über das Aktionsmenü der Mitarbeiterliste gelöscht werden; die bestehende Profilaktion verwendet dieselbe vollständige Jetstream-Löschroutine.
- Die Löschberechtigung `employees.delete` ist absichtlich nicht an Teams delegierbar und serverseitig ausschließlich für globale Administratoren freigegeben.
- Eigenlöschung und das Löschen des primären Systemadministrators bleiben sowohl in der Oberfläche als auch in der Livewire-Aktion gesperrt.

## 2026-07-21 - Codex (Toast-, Dropdown- und mobile Tabellenbasis)

- Der globale Toast-Handler ersetzt bei Livewire-Navigation alte Event-Listener und unterdrückt unmittelbar doppelte identische Events, damit eine Aktion genau eine Rückmeldung erzeugt.
- Alle bisherigen Dropdown-Aufrufe verwenden zentral die anchor-basierte Komponente; Breiten, Ausrichtung, Fokus, Scrollbereich und Schließen nach einer Aktion sind vereinheitlicht.
- Gemeinsame Tabellen zeigen mobil eine gekürzte Datenzusammenfassung statt fast alle Spalten auszublenden; Zeilenaktionen bleiben unabhängig vom Inhalt immer rechts fixiert.
- Mehrere Seitenaktionen über der Mitarbeitertabelle werden mobil in einem einzigen Aktionen-Dropdown gebündelt.

## 2026-07-21 - Codex (Zentrales Nachrichtenmodal und E-Mail-Vorlagen)

- Nachrichten aus Topbar und Nachrichten-Seite öffnen jetzt dasselbe, einmalig im Master-Layout gemountete Livewire-Lesemodal über `message-viewer:open`; Lesestatus, Berechtigungsprüfung und Anhang-Downloads liegen zentral in der Komponente.
- Die alte `AdminMessageBox` und das doppelt eingebundene Blade-Modal wurden entfernt. Der Nachrichtentext wird escaped dargestellt, Topbar-Badge und Liste aktualisieren sich direkt über `inbox:refresh`.
- E-Mail-Vorlagen sind unter `/email-templates` eine eigene Seite in Nutzer- und Admin-Sidebar; der bisherige Profil-Tab wurde entfernt und die Downloadroute entsprechend ausgelagert.
- Isolierte Livewire-/Featuretests prüfen eigene und fremde Nachrichten, XSS-sichere Ausgabe, genau eine Modal-Instanz in beiden Layouts, Sidebar/Routen sowie gültige und ungültige Vorlagen-Downloads.

## 2026-07-21 - Codex (Sie-Ansprache, Firmendaten und Speicherbuttons)

- Einladungen, Konto-/Profiltexte und mitarbeitergerichtete Systemnachrichten verwenden durchgängig die formelle Sie-Ansprache.
- Unter Einstellungen gibt es einen eigenen Bereich für zentrale Firmendaten inklusive Anschrift, Kontakt, Geschäftsführung, Register, USt-IdNr. und Steuernummer.
- E-Mail-Vorlagen, Signaturen sowie Footer und sichtbarer Absendername von Systemmails verwenden diese Firmendaten aus einer gemeinsamen Settings-Quelle.
- Speicherbuttons nutzen das zum eingebundenen Font Awesome 5 passende Icon; gemeinsame Button-Komponenten stabilisieren Icon-Zentrierung, Größe und Textabstand.

## 2026-07-21 - Codex (Mitarbeiter-Stammdaten, Einladungen und Einstellungs-Tabs)

- Mitarbeiter anlegen/bearbeiten nutzt jetzt die Tabs "Team und Sicherheit" und "Persoenliche Daten". Firmenposition, Personalnummer, Kontakt-, Adress- und Geburtsdaten werden im `user_profiles`-Datensatz gespeichert.
- Mitarbeiter-Einladungen haben fest die Rolle `staff`; Team und Firmenposition werden in der Einladung gespeichert und bei der Registrierung als Teamzuordnung bzw. Profilposition uebernommen.
- Die Admin-Einstellungen sind oben in die Tabs "Allgemein", "Benutzer" und "System" gegliedert; bestehende E-Mail-, Einladungs- und Wartungsoptionen wurden passend einsortiert.
- Mobile Layouts zeigen nun das RailTime-Logo und einen echten SVG-Burgerbutton. Die Sidebar oeffnet als begrenzter Drawer mit Hintergrund-Overlay, schliesst per Aussenklick/Escape und synchronisiert ihren ARIA-Status.
- Migration `2026_07_21_000002_add_team_and_position_to_staff_invitations_table` lokal ausgefuehrt. PHP-/Blade-Syntax sowie Livewire-Persistenztests fuer Mitarbeiter, Einladung und Einstellungs-Tabs erfolgreich.

## 2026-07-17 – Codex

- Migrationen, Seeder und Admin-Navigation angepasst (siehe letzter Verlauf).
- UI-Anpassung: helle Flächen nutzen `bg-white`/`bg-slate-50`, dunkle Flächen `dark:bg-slate-900`/`dark:bg-slate-950`; Rahmen, Text und Hover-Zustände sind ebenfalls mit `dark:`-Varianten versehen.

## 2026-07-17 – Codex (Design-System)

- Zentrale semantische Farbpalette in `tailwind.config.js` ergänzt: `rt-*` für Light Mode und `rt-dark-*` für Dark Mode.
- Topbar, Sidebar, Navigation und Hauptlayout verwenden diese Klassen statt fester Slate-/Gray-Farben.
- Text-, Neben- und aktive Navigationsfarben sind für beide Modi zentral definiert und kontraststärker gesetzt.

## 2026-07-17 – Codex (Topbar Dark Mode)

- Gemeinsame Komponente `x-topbar.control-button` für Sprachwahl, Theme-Schalter und Nachrichten-Button angelegt.
- Sidebar-Navigation nutzt im Dark Mode durchgehend weiße Schrift.
- Das Textlogo sowie das Monogramm werden im Dark Mode weiß dargestellt.
- Nachrichten-Dropdown nutzt nun zentrale Dark-Mode-Flächen, weiße Texte und passende Hover-/Unread-Zustände.
- Theme-Icon in der Topbar wird über den Alpine-Theme-Store mit `x-show` gerendert; dadurch gibt es keinen Konflikt zwischen `hidden` und Tailwind-Display-Klassen.

## 2026-07-17 – Codex (Admin-Navigation und Downloads)

- Admin- und Nutzer-Nachrichten verwenden nun dieselbe stabile MessageBox; die Rolle bestimmt explizit das passende Layout.
- Der Header-Link „Alle Nachrichten“ führt für Admins und Mitarbeiter zur Admin-Route.
- Dark-Mode-Navigation verwendet eigene Blauflächen für Hover und aktive Links.
- Admin-Dashboard: Begrüßungsband entfernt, Übersicht und Kennzahlen für Light/Dark vereinheitlicht.
- Nutzer-Dateien: persönliche, rollenbasierte und Team-Standard-Downloadbereiche ergänzt; Team-Dateipools prüfen die Mitgliedschaft serverseitig.

## 2026-07-17 – Codex (UI-Komponenten)

- Neue wiederverwendbare Komponenten: `x-ui.surface.card`, `x-ui.feedback.alert` und `x-ui.dashboard.stat-card`.
- Dashboard-Kennzahlen, Mitarbeiter-Hinweis und Mitarbeiter-Filter verwenden diese zentralen Bausteine.
- Kernkomponenten für Tabellen, Inputs, Selects, Labels, Buttons, Badges, Dropdowns, Modals und Datei-Karten auf die zentrale RailTime-Light-/Dark-Palette umgestellt.

## 2026-07-17 – Codex (Content und Tabellen)

- Der globale Content-Rahmen mit Hintergrund und Padding wurde aus `layouts.master` entfernt; jede Seite kann ihre benötigten Bereiche selbst als Komponenten setzen.
- Nachrichten und Mailverwaltung nutzen `x-tables.table`.
- Tabellenzeilen sind getrennt organisiert: `rows/employees`, `rows/messages` und `rows/mails`, jeweils mit eigener Row- und Action-Komponente; Mails besitzen zusätzlich eine Detail-Komponente.

## 2026-07-17 – Codex (Seitenstruktur und FilePool)

- Gemeinsame `x-ui.page-header`-Komponente ergänzt und in Nachrichten, Nutzer-Dateien sowie Dateimanager verwendet.
- Obere Tipp-/Hinweisblöcke aus den zentralen Nutzer- und Verwaltungsseiten entfernt.
- Selects nutzen die neue erhöhte `rt-control`-Fläche und heben sich damit in Light und Dark Mode sichtbar vom Seitenhintergrund, der Topbar und Sidebar ab.
- FilePool-Formulare, Dateimanager, FileCards und Dateivorschau auf die zentrale Dark-Mode-Palette umgestellt.

## 2026-07-17 – Codex (Formular-Komponenten)

- Sämtliche sichtbaren nativen Selects in Filtern und Formularen verwenden jetzt `x-ui.forms.select`.
- Login, Admin-Login, Registrierung, Passwort- und Zwei-Faktor-Seiten sowie Profil-, Team- und Verwaltungsformulare verwenden zentral `x-ui.forms.input`, `x-ui.forms.label` und `x-ui.forms.checkbox`.
- Input-, Select-, Label- und Checkbox-Komponenten nutzen einheitliche semantische `rt-*`-/`rt-dark-*`-Farben, gut erkennbare Kontrollflächen und konsistente Fokus-, Hover-, Readonly- und Disabled-Zustände.
- Native Inputs verbleiben nur innerhalb der zentralen UI-Komponenten sowie für technisch notwendige versteckte Token- und Datei-Felder.

## 2026-07-18 – Codex (Eigenes Profil)

- Die eigene Profilseite ist jetzt in die Tabs „Persönliche Daten“, „Sicherheit“ und „Sitzungen“ gegliedert.
- Ein kompakter Profilkopf zeigt Profilfoto, Name, E-Mail und Verifizierungsstatus; die Tab-Auswahl bleibt lokal erhalten und wird auf kleinen Bildschirmen zu einem kompakten Menü.
- Die gemeinsame Tabs-Komponente erhielt semantische Light-/Dark-Farben, Tastaturnavigation und Livewire-freundliche Panels.
- Formular- und Aktionsbereiche verwenden nun einheitlich die zentralen `rt-*`-/`rt-dark-*`-Flächen und Rahmen.

## 2026-07-17 – Claude Code (Echtzeit-Benachrichtigungen / Laravel Reverb)

- `laravel/reverb` installiert (`BROADCAST_DRIVER=reverb`, Keys in `.env`); Client via `laravel-echo` + `pusher-js` im Vite-Bundle (`resources/js/app.js`), nur aktiv wenn `VITE_REVERB_APP_KEY` gesetzt ist.
- Neues Event `App\Events\MessageReceived` (ShouldBroadcastNow, privater Kanal `App.Models.User.{id}`, Kanal-Auth in `routes/channels.php`); wird zentral in `User::receiveMessage()`/`sendMessage()` gefeuert — Fehler beim Broadcast blockieren den Versand nie (try/catch, Fallback = 60s-Polling).
- Frontend: Echo-Listener zeigt bei neuer Nachricht einen Toast (übersetzt via `window.rtLang`, Meta `rt-user-id` im Master-Layout) und dispatcht `inbox:refresh` → HeaderInbox-Badge und Nachrichten-Seite aktualisieren live.
- Lokal starten: `php artisan reverb:start` (Port 8080) zusätzlich zu Serve/Queue-Worker. Ohne laufenden Reverb-Server funktioniert alles weiter über das bestehende Polling.
- End-to-End getestet: WebSocket verbunden, Kanal-Abo autorisiert, Toast + Badge-Update kamen live an.
- Hinweis: Umstieg auf Pusher-Cloud wäre reine `.env`-Änderung (identischer Client-Code), falls das Produktiv-Hosting keinen Daemon erlaubt.

## 2026-07-18 - Claude Code (Explorer-Dateiverwaltung, Nachrichten-Verdrahtung, Fixes)

- NEU Ordnerstruktur im Dateipool: Tabelle `file_folders` (Baum via parent_id, Rechte-JSON je Rolle: view/download/delete) + `files.folder_id`; Model `FileFolder` (allowsForRole, breadcrumb, deleteRecursive). `ManageFilePools` ist jetzt ein Explorer: Breadcrumbs, Ordner anlegen/umbenennen/loeschen (rekursiv inkl. Datei-Blobs), Rechte-Matrix pro Ordner (nur im allowRoleSharing-Modus), Upload in aktuellen Ordner. Rollenmodus: Ordner nur sichtbar mit view-Recht; Dateien in Ordnern erben Ordner-Rechte, Wurzeldateien nutzen weiterhin shared_roles; ZIP/Download serverseitig geprueft.
- Nachrichten verdrahtet: Mitarbeiterliste hat "Nachricht verfassen" fuer Mehrfachauswahl UND je Zeile (dispatch openMailModal an MessageForm, payload int|int[]|{emails}); neue Komponente Admin\UserProfile\UserMessages aktiviert den Nachrichten-Tab im Benutzerprofil (Liste + Detail-Modal + Loeschen + Compose); MessageForm-Modal auf Profilseite gemountet; neues Recht `users.messages.delete`.
- Super-Admin (#1): aus Mitarbeiterliste ausgeschlossen und vom Activity-Logging ausgenommen (`User::isSuperAdmin()`).
- Fix Profilseite: doppelte Anfuehrungszeichen im x-data von `ui/accordion/tabs.blade.php` (querySelector-Template) zerrissen das HTML-Attribut, JS wurde als Text gerendert. Auf einfache Quotes umgestellt.
- Fix Tabellen-Dropdowns: `overflow-hidden` vom Tabellen-Wrapper entfernt (Aktionsmenues wurden abgeschnitten).
- `Team::filePool()` MorphOne ergaenzt (wird vom neuen "Team-Dateien"-Bereich der /files-Seite benoetigt).

## 2026-07-18 - Claude Code (UI/UX-Redesign v2, Einstellungsseite, Sidebar-Gliederung)

- NEU Designsprache v2 ueber die gesamte App (Admin + User): Global-Font `Plus Jakarta Sans Variable` (@fontsource-variable, in `resources/css/app.css` + `tailwind.config.js` als `sans`). Neue Tailwind-Tokens: weiche getoente Schatten `shadow-rt-xs/sm/md/lg/glow` (statt hartem schwarzem shadow-md) und Feder-Motion `ease-rt-spring` (cubic-bezier(0.32,0.72,0,1)). Karten jetzt `bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60` (weiche Hairline statt harter 1px-Border), Hero-/Stat-Flaechen als Double-Bezel (aeusserer `rounded-2xl bg-rt-surface-muted p-1.5 ring-1` + innerer `rounded-[calc(1rem-2px)] bg-rt-surface`), Eyebrow-Pill ueber H1, Buttons mit `active:scale-[0.98]` + Primaer-Hover `shadow-rt-glow`.
- NEU GSAP-Scroll-Reveals (`resources/js/gsap.js`): deklarativ ueber `data-anim="fade-up|fade|zoom|left|right"` (+`data-anim-delay`, Container `data-anim-stagger`), respektiert `prefers-reduced-motion` via `gsap.matchMedia`, re-init auf `livewire:navigated`. Sparsam auf Seitenkoepfen/Stat-Grids gesetzt (nie in Tabellenzeilen/Modals).
- Betroffen: Layouts (master/topbar Glas-Effekt/sidebar/guest/app/auth-brand-layout), Kern-Components (Buttons, Dropdown, Modals, alle Formular-Components, Tabelle, Stat-Card, Tabs, FilePool-Cards) sowie alle Admin-/User-Seiten. Regeln gewahrt: kein `overflow-hidden` am Tabellen-Wrapper, keine doppelten Anfuehrungszeichen im x-data, `persist/sort/anchor` weiter nicht selbst registriert; jede Farbaenderung mit `dark:`-Pendant.
- NEU Admin-Einstellungsseite `/administrator/settings` (`App\Livewire\Admin\Settings`, View `livewire/admin/settings.blade.php`, Gate `settings.manage`): verwaltet ueber das `Setting`-Model `system/maintenance_mode` (bool; invalidiert zusaetzlich den `maintenance_mode`-Cache der MaintenanceMode-Middleware), `invitations/expiry_days` (int, wird in `InviteEmployeeModal` statt hartem `addDays(7)` gelesen) und `mails/admin_email` (string). End-to-End getestet: Lesen (Feld zeigt DB-Wert) + Schreiben (`setValue` persistiert) verifiziert.
- Sidebars neu gegliedert: Admin = Dashboard + Sektion "Administration" (Einstellungen ganz oben, Mitarbeiter, Dateiverwaltung, Mailverwaltung) + Sektion "Persoenliche Daten" (Nachrichten, Profil); User = Dashboard + "Persoenliche Daten" (Dateien, Nachrichten, Profil). Aktiver Nav-Link hat einen Akzent-Balken (before-Pseudo) + rt-accent-Text.
- Eigene Profilseite `profile/show` auf volle Breite (kein `max-w-7xl` mehr), Identitaets-Hero als Double-Bezel.
- Neue lang-Keys (de/en): `administration`, `settings*`, `maintenance_mode*`, `invitation_expiry_*`, `admin_email*`.
- Verifiziert: `npm run build` ok (Plus-Jakarta-woff2 eingebettet, CSS 112 kB mit allen neuen Klassen), Smoke-Test durch Admin- (Dashboard/Mitarbeiter/Dateien/Mails/Einstellungen/Nachrichten) und User-Seiten (Dateien/Profil) ohne Konsolenfehler, Light + Dark Mode korrekt (rt-canvas #f3f6fa / rt-dark-canvas #0b1120).

## 2026-07-18 - Claude Code (Seiten-Vereinheitlichung + Datei-Sichtbarkeit/Ablauf/Auto-Löschen)

- NEU Seiten-Shell `<x-ui.page :title :eyebrow :description :count>` (resources/views/components/ui/page.blade.php) mit Aktions-Slot oben rechts — EINHEITLICHES Padding/Kopf/Actions fuer ALLE Seiten. `layouts/master.blade.php` fuehrt jetzt `@yield('content')` (fuer @extends wie profile/show) UND `$slot` (Livewire ->layout()) im selben Wrapper (`.main-content > .page-content > .container-fluid` im Gradient-`<main>`) zusammen — vorher 3 divergierende Muster (Doppel-Wrapper bei Einstellungen, kein Gradient bei @extends). Alle aktiven Seiten umgestellt: admin dashboard/employees/settings/file-manager/mail-management/user-profile, message-box, user-dashboard, user-files, profile/show. Mitarbeiter: Primaeraktionen (Neu/Einladen/Teams) + Zaehler-Badge im Kopf; Mailverwaltung: Anthrazit-Banner ENTFERNT, Super-Admin als Chip oben rechts; user-dashboard: Anthrazit-Band durch Standard-Kopf ersetzt. `x-ui.page-header` um optionales `count`-Badge erweitert.
- FIX `x-ui.badge` (components/ui/badge.blade.php) neu erstellt — wurde von tables/rows/mails/row.blade.php genutzt, existierte aber nicht (latenter Crash der Mailverwaltung bei vorhandenen Zeilen). Props: color = yellow/amber/blue/sky/purple/green/emerald/red/slate.
- NEU Datei-/Ordner-Sichtbarkeit: Migration ergaenzt `file_folders` + `files` um `visible_from` (date), `auto_delete` (bool), `visible_teams` (json Team-IDs); Ordner zusaetzlich `visible_until` (Dateien nutzen `expires_at`). Model-Helfer (beide): `isWithinVisibilityWindow()`, `isVisibleForTeams(?User)`, `isPubliclyVisible(?User)`, `isExpiredForDeletion()`. ManageFilePools: Ordner-Modal = volle Einstellungen (Name, Sichtbar-ab/-bis, Auto-Loeschen-Toggle, Team-Checkboxen), Datei-Bearbeiten + Upload analog. Nutzerseitiger Filter (roleFilter-Modus) verlangt zusaetzlich `isPubliclyVisible(auth)` in render/enterFolder/downloadFile/visibleFiles; Admin-Management sieht alles. Team-Sichtbarkeit leer = alle sichtbar.
- NEU Command `files:purge-expired` (app/Console/Commands/PurgeExpiredFiles.php), stuendlich im Kernel (`->hourly()->withoutOverlapping()`): loescht abgelaufene Eintraege mit aktivem `auto_delete` — Dateien via `delete()` (Blob-Hook), Ordner via `deleteRecursive()`, jede Loeschung in try/catch. End-to-End getestet: Ordner mit `visible_from`/`visible_until`/`auto_delete`/`visible_teams` angelegt + persistiert; abgelaufener Auto-Loesch-Ordner via Command entfernt; Blade+php -l sauber, Build ok, keine Konsolenfehler.

## 2026-07-18 - Claude Code (Rollen-Menue, dateiorientiertes User-Dashboard, Nav-Ladeanimation, Auth-Dark)

- Rollen-/Team-Menue: Admin-Sidebar-Sektion „Administration" jetzt in `@canany(['settings.manage','employees.view','files.manage','manage.messages'])` gekapselt — eingeschraenktes Personal sieht nur Dashboard + Persoenliche Daten, Verwaltung/Admins die volle Sektion. Zusammen mit Gate::before (Admins = alles) ergibt das: Superadmin/Admin voll, Verwaltung per-RBAC, Personal eingeschraenkt. (Superadmin-Dev-Links bewusst noch NICHT umgesetzt.)
- NEU dateiorientiertes Benutzer-Dashboard (`App\Livewire\UserDashboard`): `accessibleFiles()` sammelt persoenlichen Pool + rollen-/ordnerbasierte Firmendateien + Team-Pools (nur `isPubliclyVisible` + nicht abgelaufen, neueste zuerst). View zeigt Kennzahlen (verfuegbare Dateien / ungelesene Nachrichten / Teams), „Aktuelle Dateien" als file-cards (read-only) und Schnellzugriff. Download ueber `UserDashboard::downloadFile()` mit Zugriffspruefung (nur IDs aus accessibleFiles); Vorschau via globales `filepool-preview`-Event.
- NEU Seitenwechsel-Ladeanimation fuer `wire:navigate`: schlanker markenroter Fortschrittsbalken oben (`resources/js/app.js`), Events `livewire:navigate`/`navigating` (Start) + `navigated` (Ende). WICHTIG: `ensureBar()` haengt den Balken nach dem body-Swap neu an — sonst zeigt er sich nur bei der ersten Navigation.
- Auth-Bereich: `components/auth-brand-layout.blade.php` bekam einen Theme-Umschalter (fixed oben rechts, `$store.theme?.toggle()`) — gilt fuer Login UND Passwort-vergessen. `auth/forgot-password.blade.php` nutzt jetzt die Marken-Shell mit 3D-Logo (statt der alten `x-authentication-card`). Dark Mode auf beiden Auth-Seiten aktiv (Guest-Layout laedt app.js + FOUC-Dark-Script). Neue lang-Keys: forgot_password_title/_description, send_reset_link, back_to_login, available_files, unread_messages, my_teams, downloads, recent_files, all_files.
- Verifiziert im Browser: Login/Passwort-vergessen mit 3D-Logo + funktionierendem Dark-Toggle (Karte hell #fff / dunkel #111827); Testnutzer (role=user) landet auf dem neuen /dashboard mit „Aktuelle Dateien" + Kennzahlen, KEINE Administration-Sektion; Ladebalken erscheint bei zwei aufeinanderfolgenden `wire:navigate`-Wechseln; keine Konsolenfehler. Testnutzer: testuser@rail-time.de / Test1234!.

## 2026-07-18 - Claude Code (Feinschliff: Lade-Overlay, Explorer-Kontextmenue, Auth-/Main-Hintergrund, Profil-Redesign, Download-Bereich)

- Seitenwechsel-Anzeige umgestellt: Livewire-Default-Progressbar AUS (`config/livewire.php` -> navigate.show_progress_bar=false), stattdessen ein Lade-**Overlay** ueber dem Inhalt (`#rt-nav-overlay` + `.rt-nav-spinner` in resources/js/app.js + resources/css/app.css); 120ms-Verzoegerung gegen Flackern bei vorab geladenen Seiten, re-attach nach body-Swap, Hintergrund theme-abhaengig.
- Explorer (manage-file-pools): Ordner-⋮-Button jetzt IMMER sichtbar (Menuepunkt „Ordner-Einstellungen"). NEU **Rechtsklick-Kontextmenue** (`@contextmenu.prevent` an Ordner-/Datei-Raster + je Ordner) — auf einen Ordner: Oeffnen / Einstellungen / Berechtigungen / Loeschen; auf den Hintergrund: Neuer Ordner / Datei-Upload / Alle herunterladen. Alpine-State im Root-x-data (ctx/cx/cy/cf + openCtx), Aktionen via `$wire.*`; keine doppelten Quotes im x-data.
- Auth-Hintergrund (Login/Passwort-vergessen) jetzt light + dark: `public/rt-brand/rt-auth.css` mit `html:not(.dark)`-Overrides (heller Verlauf, dunkle Wortmarke via CSS-filter, sichtbare Ringe); Versionszeile theme-aware.
- Main-Content-Farbverlauf neu (`layouts/master.blade.php`): Light `from-white via-rose-50/70 to-slate-200` (rot/weiss, etwas mehr Tiefe), Dark `from-[#151a26] via-[#14131c] to-[#1d1117]` (etwas heller, rot/schwarz) + kraeftigerer roter Radial-Akzent.
- Mitarbeiterprofil (`livewire/admin/user-profile.blade.php`) grundlegend neu: sauberes horizontales Identitaets-Card (Avatar + Name + Rollen-Badge + Status/Online/Zuletzt-online/ID-Chips) statt Cover-Band mit ueberlappendem Avatar; Details-Tab = zwei klare Info-Sektionen (Persoenliche Daten / Kontakt & Anschrift) als saubere dl-Zeilen statt Gradient-Artikel. Tabs + Livewire-Kinder + wire/@can unveraendert.
- NEU **konsolidierter Download-Bereich** im Benutzerbereich (/files): `User::availableFilesGrouped()` sammelt ALLE bereitgestellten Dateien — 'personal' (vom Admin im Profil hinzugefuegt), 'company' (per Rolle ODER per Team sichtbar) und 'teams' (Team-Pools), nur `isPubliclyVisible` + nicht abgelaufen. `User::availableFileIds()` + `UserFiles::downloadFile()` (Zugriffspruefung). View gruppiert ueber neue Komponente `x-ui.filepool.download-group` (Kopf + file-cards, Download via wire:click, Vorschau via globales filepool-preview-Event). End-to-End verifiziert: eine vom Admin im Nutzerprofil hinzugefuegte Datei erscheint beim Nutzer unter „Fuer Sie bereitgestellt" neben den Firmen-Freigaben; Blade+php -l sauber, Build ok, keine Konsolenfehler.

## 2026-07-18 - Claude Code (Tab-Bug-Fix, Dark-Gradient-Fix, Beispiel-Nutzer-Dashboard)

- FIX Mitarbeiterprofil-Tabs (und alle `x-ui.accordion.tab-panel`): der erste Tab (Details) blieb dauerhaft sichtbar, andere Inhalte stapelten sich darunter. URSACHE: Tailwind laeuft mit `important: true` → das Display-Utility `grid` in der panelClass trug `!important` und schlug Alpines inline `display:none` von `x-show`. FIX: kein Display-Utility direkt auf dem x-show-Panel — das Grid liegt jetzt auf einem INNEREN Wrapper, panelClass nur `space-y-*`. Zusaetzlich `x-transition` aus tab-panel.blade.php entfernt (Leave-Uebergang wurde durch Livewire-Morph unterbrochen → Panels akkumulierten). MERKE: nie `grid`/`flex`/`block` direkt auf ein `x-show`-Element setzen.
- FIX Dark-Mode-Hintergrund war oben quasi weiss. URSACHE: die `dark:from-/via-/to-`-Gradient-Stops auf `<main>` griffen NICHT (blieben hell). FIX: statt `bg-gradient-to-br from-.. dark:from-..` ein einzelnes `bg-[linear-gradient(...)]` + `dark:bg-[linear-gradient(...)]` (zuverlaessig, wie der Radial). Main jetzt light `#f8fafc→#fff→#ffe4e6`, dark `#08080a→#0b090c→#1c0b12` (Fast-Schwarz mit Rot-Hauch) + roter Radial oben-links.
- NEU Beispiel-Nutzer-Dashboard (`UserDashboard`): KEINE Admin-Kennzahlen mehr (aktive Benutzer/Mitarbeiter/Teams). Stattdessen Kennzahlen Naechste Schicht / Verfuegbare Dateien / Ungelesene Nachrichten und Sektionen „Naechste Schichten" (Dummy-Dienstplan mit Zug-/Schichtbeispielen) + „Naechste Termine" (Dummy) + „Aktuelle Dateien" (echt, Download/Vorschau). Dummy-Daten hart im `UserDashboard::render()` — spaeter durch echte Dienstplanung ersetzbar.
- Verifiziert im Browser: Tabs schalten mutually-exclusive (je genau 1 Panel), Dark-Main oben-links = rgb(8,8,10), Nutzer-Dashboard ohne Admin-Statistiken mit Schichten/Terminen; php -l + Blade sauber, Build ok, keine Konsolenfehler.

## 2026-07-19 - Claude Code (Standard-Teams + Layout-Gating, WhatsApp-Chat, Windows-Kacheln, Download-Center)

- TEAMS: TeamSeeder legt jetzt die vier Basis-Teams **Gast, Mitarbeiter, Verwaltung, Administrator** an (historisches Team "Administration" wird automatisch zu "Administrator" umbenannt; Gast/Mitarbeiter ohne Verwaltungsrechte, Mitgliedschafts-Sync der Mitarbeiter nur noch in Verwaltung/Administrator). NEU `User::usesAdminLayout()`: Rolle admin/staff ODER Mitgliedschaft im Team Administrator/Verwaltung → Admin-Layout + Zugang zu /administrator (RoleMiddleware laesst Team-Mitglieder zusaetzlich zu, Details steuern die RBAC-Gates); Mitarbeiter/Gast → Nutzer-Layout. master.blade.php ($area), Home-Redirect, UserDashboard-Redirect und MessageBox-Area nutzen den Helper.
- NEU CHAT (WhatsApp-Stil) fuer ALLE angemeldeten Benutzer unter /chat (beide Layouts, Links in beiden Sidebars unter Persoenliche Daten): Tabellen `chats` (type direct|group, name, created_by), `chat_user` (Pivot + last_read_at), `chat_messages`; Models `Chat` (participants/messages/latestMessage, displayNameFor/avatarUrlFor/unreadCountFor, `Chat::directBetween()` mit Dedupe) + `ChatMessage`; `User::chats()`. Livewire `ChatBox` (Teilnehmer-Checks in openChat/send/pollTick; startDirect; createGroup mit Namens-/Teilnehmervalidierung; Suche). View `livewire/chat-box.blade.php`: 2-Spalten-Messenger (Chatliste mit Avataren/Unread-Badges/Letzte-Nachricht, Konversation mit Datums-Chips Heute/Gestern, eigene Bubbles rechts bg-rt-red / fremde links Surface, Sendernamen in Gruppen, Auto-Scroll, wire:poll.5s, rounded-full Eingabe + Senden-Button, Modal Neuer Chat/Neue Gruppe). ACHTUNG Pivot-Bulk-Attach: alle Zeilen brauchen identische Pivot-Spalten (last_read_at ueberall setzen, sonst SQL-Fehler). ACHTUNG Blade: inline `@php(...)` NICHT mit Block `@php ... @endphp` in einer Datei mischen (Compiler paart falsch).
- Windows-Explorer-Look: `ui/filepool/file-card.blade.php` + Ordner-Kacheln in manage-file-pools sind jetzt transparente Tiles (rounded-lg p-2) mit Windows-artiger Hover-Selektion (hover:bg-rt-accent/5 + ring), Icon gross zentriert, Name 2-zeilig zentriert; Datei-/Ordner-Raster einheitlich als Grid (3/4/6/8 Spalten). Alle Aktionen (Preview/Download/Edit/Delete, ⋮-Dropdown, Kontextmenue, data-anim-stagger) unveraendert.
- Download-Center: /files heisst jetzt ueberall "Download-Center" (Sidebar-Link mit download-cloud-Icon + Seitentitel).
- End-to-End verifiziert: Lucas ↔ Testnutzer Direktchat (senden/lesen/Unread-Badge), Gruppe "RailTime Team-Chat" erstellt via Modal, Gruppen-Nachricht + Antwort aus Testnutzer-Sicht; Nicht-Mitglieder sehen fremde Gruppen NICHT (Zugriffskontrolle geprueft); Windows-Kacheln + Download-Center-Titel im Browser bestaetigt; keine Konsolenfehler. Hinweis: `php artisan db:seed --class=TeamSeeder` nach Deployment ausfuehren.
- NACHTRAG (20.07.): Download-Center-Link auch in der ADMIN-Sidebar unter "Persoenliche Daten" ergaenzt (fehlte — Mitarbeiter/Admins im Admin-Layout hatten keinen Zugang). `UserFiles` erzwingt kein `area=user` mehr — Admins behalten im Download-Center ihre Admin-Sidebar. Leerfall verifiziert: Seite rendert auch ohne Dateien (Titel + "0 Dateien"-Chip + Hinweis "Aktuell stehen keine Dateien fuer Sie bereit.").
