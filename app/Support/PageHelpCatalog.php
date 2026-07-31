<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;

final class PageHelpCatalog
{
    /**
     * @return array{title:string, summary:string, points:array<int, string>, group:string, icon:string, route:?string}
     */
    public function forRoute(?string $routeName, ?string $fallbackTitle = null): array
    {
        $entry = collect($this->entries())
            ->first(fn (array $item): bool => in_array($routeName, $item['routes'], true));

        if ($entry) {
            return Arr::except($entry, ['routes', 'admin']);
        }

        $title = filled($fallbackTitle) ? (string) $fallbackTitle : $this->text('Diese Seite', 'This page');

        return [
            'title' => $title,
            'summary' => $this->text(
                'Diese Seite bündelt die zu diesem Arbeitsschritt gehörenden Informationen, Eingaben und Aktionen.',
                'This page combines the information, inputs and actions for the current workflow.',
            ),
            'points' => [
                $this->text('Der Seitenkopf zeigt den aktuellen Bereich; rechts liegen die wichtigsten Aktionen.', 'The page header identifies the current area; its primary actions are on the right.'),
                $this->text('Tabs und aufklappbare Abschnitte strukturieren zusammengehörende Inhalte.', 'Tabs and expandable sections group related content.'),
                $this->text('Änderungen werden je nach Feld automatisch oder nach einer Bestätigung übernommen.', 'Changes are applied automatically or after confirmation, depending on the field.'),
                $this->text('Über den Zurück-Knopf wechseln Sie auf die vorherige echte Seite, ohne offene Menüs mitzunehmen.', 'Use Back to return to the previous real page without carrying over open menus.'),
            ],
            'group' => $this->text('Weitere Bereiche', 'More areas'),
            'icon' => 'info',
            'route' => $routeName,
        ];
    }

    /**
     * @return array<int, array{title:string, summary:string, points:array<int, string>, group:string, icon:string, route:?string}>
     */
    public function forUser(?User $user): array
    {
        return collect($this->entries())
            ->reject(fn (array $entry): bool => $entry['admin'] && ! $user?->usesAdminLayout())
            ->map(fn (array $entry): array => Arr::except($entry, ['routes', 'admin']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function entries(): array
    {
        return [
            $this->entry(
                'Dashboard',
                'Dashboard',
                'Ihr rollenbezogener Einstieg zeigt Prioritäten, echte Kennzahlen, Trends und direkte Wege in die wichtigsten Arbeitsbereiche.',
                'Your role-based home screen shows priorities, real KPIs, trends and direct routes into the most important work areas.',
                [
                    'Die oberen Fokusbereiche zeigen, was heute Aufmerksamkeit benötigt.',
                    'Diagramme fassen Entwicklung und Aktivität zusammen; Vorschauen sind ausdrücklich als solche gekennzeichnet.',
                    'Schnellaktionen öffnen Mitarbeiter, Kommunikation, Dateien oder betriebliche Werkzeuge mit einem Schritt.',
                    'Die Einführung lässt sich über den Info-Knopf jederzeit erneut starten.',
                ],
                [
                    'The top focus areas show what needs attention today.',
                    'Charts summarise trends and activity; previews are explicitly labelled.',
                    'Quick actions open employees, communication, files or operational tools in one step.',
                    'The introduction can be replayed at any time from the info button.',
                ],
                'Grundlagen',
                'home',
                ['dashboard', 'admin.dashboard', 'admin.index'],
            ),
            $this->entry(
                'Mitarbeiterübersicht',
                'Employee overview',
                'Mitarbeiter finden, filtern, einladen und mit Status, Rolle sowie Kontaktdaten überblicken.',
                'Find, filter and invite employees while reviewing status, role and contact details.',
                [
                    'Suche, Filter und Sortierung grenzen die Liste ein, ohne den aktuellen Arbeitsstand zu verlieren.',
                    'Der Name enthält Foto und E-Mail; der Präsenzpunkt zeigt die tatsächliche Online-Aktivität.',
                    'Die Auswahl links am Profilbild steuert Massenaktionen, der Drei-Punkte-Knopf die Aktionen einer Person.',
                    'Neue Konten und Änderungen stehen nur mit den passenden Teamrechten zur Verfügung.',
                ],
                [
                    'Search, filters and sorting narrow the list without losing the current working state.',
                    'The name cell includes photo and email; the presence dot represents actual online activity.',
                    'The selector next to the photo controls bulk actions and the three-dot button controls one employee.',
                    'Creating accounts and making changes requires the appropriate team permissions.',
                ],
                'Administration',
                'users',
                ['employees.index', 'admin.employees'],
                true,
            ),
            $this->entry(
                'Mitarbeiterprofil',
                'Employee profile',
                'Stammdaten, Kontakte, Teams, Rechte, Dokumente und interne Hinweise einer Person an einem Ort bearbeiten.',
                'Edit one person’s master data, contacts, teams, permissions, documents and internal notes in one place.',
                [
                    'Ein Doppelklick auf einen freigegebenen Wert startet die Inline-Bearbeitung.',
                    'Beim Verlassen des Feldes wird automatisch gespeichert; der temporäre Farbring zeigt den Status.',
                    'Teams und Rechte verwenden dieselben Regeln wie die zentrale Benutzerverwaltung.',
                    'Sensible Bereiche bleiben durch Berechtigungen und serverseitige Prüfungen geschützt.',
                ],
                [
                    'Double-click an allowed value to start inline editing.',
                    'Leaving the field saves automatically; the temporary coloured ring shows the state.',
                    'Teams and permissions follow the same rules as central user management.',
                    'Sensitive sections remain protected by permissions and server-side checks.',
                ],
                'Administration',
                'user-check',
                ['admin.employees', 'employees.show', 'admin.user-profile'],
                true,
            ),
            $this->entry(
                'Dateimanager',
                'File manager',
                'Ordner und Dateien hochladen, strukturieren, verschieben, in der Vorschau öffnen und gezielt freigeben.',
                'Upload, organise, move, preview and selectively share folders and files.',
                [
                    'Breadcrumbs zeigen den aktuellen Ordner und dienen zugleich als Ziel beim Verschieben.',
                    'Ordner stehen vor Dateien; Drag-and-drop verschiebt Elemente direkt in einen Ordner oder Breadcrumb.',
                    'Klick öffnet unterstützte Dateien in der Vorschau, der Download bleibt eine bewusste Aktion.',
                    'Rechtsklick oder der Drei-Punkte-Knopf öffnet Umbenennen, Verschieben und Löschen.',
                ],
                [
                    'Breadcrumbs show the current folder and also act as move targets.',
                    'Folders appear before files; drag and drop moves items into a folder or breadcrumb.',
                    'A click opens supported files in the preview while downloading remains an explicit action.',
                    'Right-click or the three-dot button opens rename, move and delete actions.',
                ],
                'Dateien',
                'folder',
                ['files', 'admin.files'],
            ),
            $this->entry(
                'Verbindliche Dokumente',
                'Managed documents',
                'Unternehmensweite Dokumente veröffentlichen, versionieren und ihre Kenntnisnahme nachvollziehen.',
                'Publish and version company-wide documents and track acknowledgements.',
                [
                    'Eine neue Version ersetzt die bisherige Fassung, ohne die Historie zu löschen.',
                    'Änderungshinweise erklären, was sich gegenüber der letzten Version geändert hat.',
                    'Der Lesestatus zeigt, welche Personen die aktuelle Fassung bestätigt haben.',
                    'Ältere Versionen können geprüft und bei Bedarf kontrolliert wiederhergestellt werden.',
                ],
                [
                    'A new version replaces the current revision without deleting its history.',
                    'Change notes explain what changed from the previous revision.',
                    'Acknowledgement status shows who confirmed the current revision.',
                    'Older revisions can be reviewed and deliberately restored when required.',
                ],
                'Dateien',
                'file-text',
                ['admin.managed-documents'],
                true,
            ),
            $this->entry(
                'Nachrichten',
                'Messages',
                'Ankündigungen und interne Mitteilungen lesen, filtern und – mit Berechtigung – erstellen oder verwalten.',
                'Read and filter announcements and internal messages and, with permission, create or manage them.',
                [
                    'Suche, Status und Empfängerfilter grenzen die Liste ein.',
                    'Eine Nachricht öffnet sich mit Inhalt, Absender, Zeit und Anhängen in der gemeinsamen Vorschau.',
                    'Ungelesene Einträge werden in Topbar und App-Badge berücksichtigt.',
                    'Erstellen, Bearbeiten oder Löschen ist nur mit den jeweiligen Kommunikationsrechten möglich.',
                ],
                [
                    'Search, status and recipient filters narrow the list.',
                    'A message opens with content, sender, time and attachments in the shared viewer.',
                    'Unread items contribute to the topbar and app badge.',
                    'Creating, editing or deleting requires the corresponding communication permission.',
                ],
                'Kommunikation',
                'mail',
                ['messages', 'admin.messages'],
            ),
            $this->entry(
                'Chat',
                'Chat',
                'Direkt- und Gruppenchats mit Text, Dateien, Bildern, Videos und Sprachnachrichten führen.',
                'Use direct and group chats with text, files, images, video and voice messages.',
                [
                    'Die Chatliste und der Nachrichtenverlauf scrollen unabhängig voneinander.',
                    'Dateien öffnen in der Vorschau; ein Download erfolgt erst über die dortige Aktion.',
                    'Das Mikrofon nimmt bei leerem Textfeld eine Sprachnachricht auf, Wiedergabe und Fortschritt bleiben im Chat.',
                    'Anruf- und Videoaktionen verwenden die Teilnehmer des aktuellen Chats.',
                ],
                [
                    'The chat list and message history scroll independently.',
                    'Files open in the preview; downloads are started explicitly from there.',
                    'With an empty text field, the microphone records a voice message that plays inside the chat.',
                    'Call and video actions use the participants of the current chat.',
                ],
                'Kommunikation',
                'message-circle',
                ['chat'],
            ),
            $this->entry(
                'Anrufe und Meetings',
                'Calls and meetings',
                'Sprach- und Videoanrufe überblicken, Besprechungsräume vorbereiten und weitere Personen einladen.',
                'Review voice and video calls, prepare meeting rooms and invite additional people.',
                [
                    'Status und Zeit zeigen, ob ein Anruf angenommen, verpasst oder beendet wurde.',
                    'Weitere Personen können vor oder während eines Meetings eingeladen werden.',
                    'Die Raumansicht ordnet aktive Videos automatisch und hält die Steuerung am unteren Rand erreichbar.',
                    'Mikrofon und Kamera werden erst im Anruffenster und nach Ihrer Freigabe verwendet.',
                ],
                [
                    'Status and time show whether a call was answered, missed or ended.',
                    'Additional people can be invited before or during a meeting.',
                    'The room arranges active videos automatically and keeps controls accessible at the bottom.',
                    'Microphone and camera are only used inside the call window after permission is granted.',
                ],
                'Kommunikation',
                'video',
                ['calls.index', 'calls.window', 'meetings'],
            ),
            $this->entry(
                'Wagenliste',
                'Wagon list',
                'Wagenlisten als geführten betrieblichen Vorgang erfassen, lokal fortsetzen, prüfen und exportieren.',
                'Create, locally resume, validate and export wagon lists in a guided operational workflow.',
                [
                    'Vorhandene Entwürfe stehen am Anfang und bleiben gerätebezogen erhalten, bis sie gelöscht werden.',
                    'Die Erfassung führt schrittweise durch Kopfdaten, Wagen, Bremsdaten und Abschluss.',
                    'Validierungen markieren fehlende oder widersprüchliche Angaben vor dem Export.',
                    'Der Export erzeugt den betrieblichen Nachweis aus dem aktuellen geprüften Stand.',
                ],
                [
                    'Existing drafts appear first and remain on the device until deleted.',
                    'The flow guides you through header data, wagons, brake data and completion.',
                    'Validation highlights missing or conflicting values before export.',
                    'The export creates the operational record from the current validated state.',
                ],
                'Betrieb',
                'clipboard',
                ['operations.wagon-list', 'admin.operations.wagon-list'],
            ),
            $this->entry(
                'Betriebsvorschau',
                'Operations preview',
                'Die geplanten Module für Aufträge, Schichten, Kalender und Kunden mit ihrem Umsetzungsstand erkunden.',
                'Explore planned modules for orders, shifts, calendar and customers with their implementation status.',
                [
                    'Vorschauwerte sind keine produktiven Live-Daten und werden deutlich gekennzeichnet.',
                    'Jedes Modul erklärt Ziel, geplante Kennzahlen und den nächsten Umsetzungsschritt.',
                    'Die Wagenliste ist bereits als nutzbarer Prototyp erreichbar.',
                    'Produktive Verknüpfungen folgen erst mit Datenmodell, Rechten und Benutzer-Routen.',
                ],
                [
                    'Preview values are not production live data and are clearly labelled.',
                    'Each module explains its goal, planned KPIs and next implementation step.',
                    'The wagon list is already available as a usable prototype.',
                    'Production links will follow once data models, permissions and user routes exist.',
                ],
                'Betrieb',
                'activity',
                ['admin.operations.preview'],
                true,
            ),
            $this->entry(
                'Einstellungen',
                'Settings',
                'Anwendungsweite Vorgaben für Darstellung, Firma, Benutzerrechte, Kommunikation und Systemdienste konfigurieren.',
                'Configure application-wide rules for appearance, company data, permissions, communication and system services.',
                [
                    'Tabs trennen die Hauptbereiche; Akkordeons halten umfangreiche Gruppen übersichtlich.',
                    'Geänderte Felder zeigen einen orangefarbenen Ring, erfolgreiche Speicherung kurz einen grünen.',
                    'Gespeichert wird nach Inaktivität, beim Verlassen des Feldes oder vor Tab- und Seitennavigation.',
                    'Benutzer-, Rechte- und Systemeinstellungen bleiben auf ausdrücklich berechtigte Konten begrenzt.',
                ],
                [
                    'Tabs separate major areas and accordions keep large groups manageable.',
                    'Changed fields show an orange ring and successful saves briefly show green.',
                    'Saving occurs after inactivity, when leaving a field or before tab and page navigation.',
                    'User, permission and system settings remain restricted to explicitly authorised accounts.',
                ],
                'Administration',
                'settings',
                ['admin.settings'],
                true,
            ),
            $this->entry(
                'E-Mail-Verwaltung',
                'Email management',
                'Ausgehende E-Mails, Vorlagen, Empfänger und Versandinformationen kontrolliert verwalten.',
                'Manage outgoing email, templates, recipients and delivery information in a controlled workflow.',
                [
                    'Empfänger und Inhalt sollten vor jedem Versand in der Vorschau geprüft werden.',
                    'Vorlagen verwenden zentrale Firmen- und Profildaten für konsistente Nachrichten.',
                    'Testmails helfen bei der Prüfung von Layout, Signatur und Zustellung.',
                    'Versandaktionen sind berechtigt und werden nachvollziehbar protokolliert.',
                ],
                [
                    'Review recipients and content in the preview before sending.',
                    'Templates use central company and profile data for consistent messages.',
                    'Test messages help verify layout, signature and delivery.',
                    'Send actions require permission and are recorded for traceability.',
                ],
                'Administration',
                'send',
                ['admin.mail-management'],
                true,
            ),
            $this->entry(
                'Profil und Sicherheit',
                'Profile and security',
                'Persönliche Daten, Profilbild, Kennwort, Zwei-Faktor-Schutz, Geräte und Benachrichtigungen verwalten.',
                'Manage personal data, profile photo, password, two-factor protection, devices and notifications.',
                [
                    'Name, E-Mail und Profilbild lassen sich direkt im Profilkopf bearbeiten.',
                    'Persönliche Felder speichern beim Verlassen automatisch und kehren anschließend zur Textanzeige zurück.',
                    'Unter Sicherheit liegen Kennwort, Zwei-Faktor-Authentifizierung und aktive Sitzungen in eigenen Akkordeons.',
                    'Push-Einstellungen gelten für das aktuelle installierte Gerät und benötigen Betriebssystemfreigaben.',
                ],
                [
                    'Name, email and profile photo can be edited directly in the profile header.',
                    'Personal fields save automatically when leaving and then return to text display.',
                    'Security contains password, two-factor authentication and active sessions in separate accordions.',
                    'Push settings apply to the current installed device and require operating-system permission.',
                ],
                'Mein Bereich',
                'user',
                ['profile.show'],
            ),
            $this->entry(
                'E-Mail-Vorlagen & Signaturen',
                'Email templates & signatures',
                'Personalisierte Vorlagen und Signaturen herunterladen, im Mailprogramm einrichten und vor dem ersten Einsatz sicher testen.',
                'Download personalised templates and signatures, set them up in your mail client and test them safely before first use.',
                [
                    'Öffnen Sie zuerst den Profilstatus. Telefon oder Mobilnummer pflegen Sie im eigenen Profil; die Funktion wird durch die Verwaltung gepflegt.',
                    'Wählen Sie Hell oder Dunkel passend zum geplanten Nachrichtenhintergrund. Die Dateien haben feste Farben und wechseln später nicht automatisch das Design.',
                    'EML herunterladen und alle {{…}}-Platzhalter ersetzen. Thunderbird: Datei → Speichern als → Vorlage. Klassisches Outlook: Datei → Speichern unter → Outlook-Vorlage (*.oft). Neues Outlook: den Inhalt in einen neuen Entwurf übernehmen und – sofern verfügbar – über E-Mail-Vorlagen speichern.',
                    'HTML-Vorlage: Datei im Browser öffnen und den sichtbaren Inhalt in eine neue Nachricht übernehmen. In Gmail müssen Vorlagen zuvor unter Einstellungen → Erweitert aktiviert werden.',
                    'Signatur in Outlook: HTML im Browser öffnen und die sichtbare Signatur kopieren. Neues Outlook: Einstellungen → Konten → Signaturen; klassisch: Neue E-Mail → Signatur → Signaturen.',
                    'Apple Mail oder Gmail: die sichtbare HTML-Signatur in den jeweiligen Signatur-Editor einfügen. Thunderbird kann die HTML-Datei unter Konten-Einstellungen direkt als Signaturdatei verwenden.',
                    'Senden Sie abschließend eine Testmail an sich selbst. Falls ein Mailprogramm das eingebettete Logo entfernt, fügen Sie es dort einmal über die Bildfunktion des Editors ein.',
                ],
                [
                    'Open profile status first. Add a phone or mobile number in your own profile; the role is maintained by administration.',
                    'Choose light or dark for the intended message background. Each file has a fixed palette and will not switch theme automatically later.',
                    'Download the EML file and replace every {{…}} placeholder. Thunderbird: File → Save As → Template. Classic Outlook: File → Save As → Outlook Template (*.oft). New Outlook: transfer the content to a new draft and, where available, save it through My Templates.',
                    'HTML template: open the file in a browser and transfer the visible content into a new message. Gmail templates must first be enabled under Settings → Advanced.',
                    'Outlook signature: open the HTML file in a browser and copy the visible signature. New Outlook: Settings → Accounts → Signatures; classic: New Email → Signature → Signatures.',
                    'Apple Mail or Gmail: paste the visible HTML signature into the respective signature editor. Thunderbird can use the HTML file directly under Account Settings.',
                    'Finally, send yourself a test email. If a client removes the embedded logo, add it once with that editor’s image function.',
                ],
                'Mein Bereich',
                'at-sign',
                ['email-templates.index'],
            ),
            $this->entry(
                'Teams',
                'Teams',
                'Fachliche Teams als Grundlage für Zuständigkeiten, Mitglieder und gemeinsame Rechte anlegen oder verwalten.',
                'Create or manage functional teams as the basis for responsibilities, members and shared permissions.',
                [
                    'Der Teamname sollte den betrieblichen Verantwortungsbereich eindeutig beschreiben.',
                    'Mitglieder und Einladungen werden in der Teamverwaltung gepflegt.',
                    'Rechte werden zentral je Team vergeben und gelten für alle zugeordneten Mitglieder.',
                    'Entfernen oder Rollenwechsel erfordern die passende Verwaltungsberechtigung.',
                ],
                [
                    'The team name should clearly describe its operational responsibility.',
                    'Members and invitations are maintained in team management.',
                    'Permissions are assigned centrally per team and apply to all assigned members.',
                    'Removing members or changing roles requires the appropriate management permission.',
                ],
                'Administration',
                'users',
                ['teams.create', 'teams.show'],
                true,
            ),
            $this->entry(
                'Hilfe und Einführung',
                'Help and introduction',
                'RailTime-Funktionen nach Bereichen durchsuchen, Einführungsthemen nachlesen und die App installieren.',
                'Search RailTime functions by area, revisit introduction topics and install the app.',
                [
                    'Die Suche berücksichtigt Titel, Beschreibung und alle Schritt-für-Schritt-Hinweise.',
                    'Aufklappbare Themen erklären den jeweiligen Arbeitsbereich ausführlich.',
                    'Installationsanleitungen decken iOS, Android, Windows und macOS ab.',
                    'Wenn ein Ablauf unklar bleibt, führt der Support-Link direkt zur Anfrage.',
                ],
                [
                    'Search covers titles, descriptions and every step-by-step hint.',
                    'Expandable topics explain each work area in detail.',
                    'Installation guides cover iOS, Android, Windows and macOS.',
                    'If a workflow remains unclear, the support link opens a request directly.',
                ],
                'Hilfe',
                'help-circle',
                ['help'],
            ),
            $this->entry(
                'Support',
                'Support',
                'Eine nachvollziehbare Supportanfrage mit Ablauf und automatisch ergänztem Kontokontext an das RailTime-Team senden.',
                'Send a traceable support request with workflow details and automatically added account context to the RailTime team.',
                [
                    'Beschreiben Sie zuerst, was Sie tun wollten und an welcher Stelle das Problem auftrat.',
                    'Nennen Sie das erwartete und das tatsächlich beobachtete Ergebnis.',
                    'Genaue Fehlermeldungen und der Zeitpunkt beschleunigen die Analyse.',
                    'Vermeiden Sie Kennwörter oder andere vertrauliche Zugangsdaten im Nachrichtentext.',
                ],
                [
                    'Describe what you wanted to do and where the problem occurred.',
                    'State the expected result and what actually happened.',
                    'Exact error messages and the time of occurrence speed up analysis.',
                    'Do not include passwords or other confidential credentials in the message text.',
                ],
                'Hilfe',
                'life-buoy',
                ['support'],
            ),
        ];
    }

    /**
     * @param  array<int, string>  $pointsDe
     * @param  array<int, string>  $pointsEn
     * @param  array<int, string>  $routes
     * @return array<string, mixed>
     */
    private function entry(
        string $titleDe,
        string $titleEn,
        string $summaryDe,
        string $summaryEn,
        array $pointsDe,
        array $pointsEn,
        string $groupDe,
        string $icon,
        array $routes,
        bool $admin = false,
    ): array {
        $routeName = collect($routes)->first(fn (string $name): bool => Route::has($name));

        return [
            'title' => $this->text($titleDe, $titleEn),
            'summary' => $this->text($summaryDe, $summaryEn),
            'points' => app()->getLocale() === 'de' ? $pointsDe : $pointsEn,
            'group' => $this->text($groupDe, match ($groupDe) {
                'Grundlagen' => 'Basics',
                'Dateien' => 'Files',
                'Kommunikation' => 'Communication',
                'Betrieb' => 'Operations',
                'Administration' => 'Administration',
                'Mein Bereich' => 'My area',
                default => 'Help',
            }),
            'icon' => $icon,
            'route' => $routeName,
            'routes' => $routes,
            'admin' => $admin,
        ];
    }

    private function text(string $de, string $en): string
    {
        return app()->getLocale() === 'de' ? $de : $en;
    }
}
