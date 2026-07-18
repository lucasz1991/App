# RailTime – AI-Protokoll

Diese Datei ist das gemeinsame Übergabe- und Kommunikationsprotokoll für Coding-Agents (z. B. Codex und Claude Code).

## Aktueller Stand

- Laravel-App unter `App/` mit Admin-Bereich (`/administrator`) und Nutzerbereich.
- Migrationen für eine Neuinstallation bereinigt: nachträgliche `add_...`-Migrationen wurden in die jeweiligen `create_...`-Migrationen integriert.
- Teams: `Mitarbeiter`, `Verwaltung`, `Administration`.
- Lucas (`lucas@zacharias-net.de`) ist der einzige globale und Team-Administrator.
- Alle Benutzer mit Rolle `staff` werden beim Seeding jedem Default-Team zugeordnet.
- `php artisan migrate:status` und PHP-Lint der geänderten Dateien waren erfolgreich.

## Letzter Verlauf

1. Migrationen und Seeder geprüft.
2. Foreign Keys für Teams, Team-Mitglieder und `current_team_id` ergänzt.
3. `current_team_id`, `shared_roles`, `event` und `batch_uuid` in die Basismigrationen verschoben; die separaten `add_...`-Dateien entfernt.
4. `AdminUserSeeder` auf Lucas als einzigen Admin reduziert.
5. `TeamSeeder` so angepasst, dass Mitarbeitende in allen Default-Teams landen.
6. Topbar, Sidebar und Logo erhalten konsistente Tailwind-Light-/Dark-Mode-Klassen.

## Zusammenarbeit

- Änderungen immer hier mit Datum, Agent und kurzer Begründung ergänzen.
- Vor Änderungen die betroffenen Dateien und den aktuellen Laufzeitfehler prüfen.
- Bei Datenbankänderungen beachten: Die Migrationen sind auf eine frische Installation ausgelegt. Für einen kompletten Neuaufbau ist `php artisan migrate:fresh --seed` erforderlich.
- Keine bestehenden, nicht zum Task gehörenden Änderungen zurücksetzen.

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

## 2026-07-18 - Claude Code (UI/UX-Redesign v2 "Signal", Einstellungsseite, Sidebar-Gliederung)

- NEU Designsprache v2 "Signal" ueber die gesamte App (Admin + User): Global-Font `Plus Jakarta Sans Variable` (@fontsource-variable, in `resources/css/app.css` + `tailwind.config.js` als `sans`). Neue Tailwind-Tokens: weiche getoente Schatten `shadow-rt-xs/sm/md/lg/glow` (statt hartem schwarzem shadow-md) und Feder-Motion `ease-rt-spring` (cubic-bezier(0.32,0.72,0,1)). Karten jetzt `bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60` (weiche Hairline statt harter 1px-Border), Hero-/Stat-Flaechen als Double-Bezel (aeusserer `rounded-2xl bg-rt-surface-muted p-1.5 ring-1` + innerer `rounded-[calc(1rem-2px)] bg-rt-surface`), Eyebrow-Pill ueber H1, Buttons mit `active:scale-[0.98]` + Primaer-Hover `shadow-rt-glow`.
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
