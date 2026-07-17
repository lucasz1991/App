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

## 2026-07-17 – Claude Code (Echtzeit-Benachrichtigungen / Laravel Reverb)

- `laravel/reverb` installiert (`BROADCAST_DRIVER=reverb`, Keys in `.env`); Client via `laravel-echo` + `pusher-js` im Vite-Bundle (`resources/js/app.js`), nur aktiv wenn `VITE_REVERB_APP_KEY` gesetzt ist.
- Neues Event `App\Events\MessageReceived` (ShouldBroadcastNow, privater Kanal `App.Models.User.{id}`, Kanal-Auth in `routes/channels.php`); wird zentral in `User::receiveMessage()`/`sendMessage()` gefeuert — Fehler beim Broadcast blockieren den Versand nie (try/catch, Fallback = 60s-Polling).
- Frontend: Echo-Listener zeigt bei neuer Nachricht einen Toast (übersetzt via `window.rtLang`, Meta `rt-user-id` im Master-Layout) und dispatcht `inbox:refresh` → HeaderInbox-Badge und Nachrichten-Seite aktualisieren live.
- Lokal starten: `php artisan reverb:start` (Port 8080) zusätzlich zu Serve/Queue-Worker. Ohne laufenden Reverb-Server funktioniert alles weiter über das bestehende Polling.
- End-to-End getestet: WebSocket verbunden, Kanal-Abo autorisiert, Toast + Badge-Update kamen live an.
- Hinweis: Umstieg auf Pusher-Cloud wäre reine `.env`-Änderung (identischer Client-Code), falls das Produktiv-Hosting keinen Daemon erlaubt.
