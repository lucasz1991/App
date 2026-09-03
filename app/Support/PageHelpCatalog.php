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
                'Ihr rollenbezogener Einstieg bündelt genau die Informationen und direkten Wege, die für Ihren Bereich relevant sind.',
                'Your role-based home screen combines the information and direct routes that are relevant to your area.',
                [
                    'Mitarbeiter und Gäste sehen eine ruhige persönliche Übersicht ohne Verwaltungsanalysen.',
                    'Nachrichten, Dateien, Profilstatus und eigene Geräte bleiben direkt erreichbar.',
                    'Verwaltungsrollen erhalten zusätzlich die für ihre Berechtigungen freigegebenen Kennzahlen und Werkzeuge.',
                    'Die Einführung lässt sich über den Info-Knopf jederzeit erneut starten.',
                ],
                [
                    'Employees and guests see a calm personal overview without management analytics.',
                    'Messages, files, profile status and assigned devices remain directly accessible.',
                    'Management roles additionally receive the metrics and tools allowed by their permissions.',
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
                'Geräte und virtuelles Lager',
                'Devices and virtual inventory',
                'Firmenbestand erfassen, Mitarbeitern zuordnen, bestehende Geräte registrieren und ihre belegte Übergabebereitschaft steuern.',
                'Capture company inventory, assign it to employees, enrol existing devices and control their evidenced handover readiness.',
                [
                    'Filter und CSV-Import übernehmen vorhandene Geräte, ohne sie physisch an das Hauptlager zurückzuschicken.',
                    'Das Gerätedetail verbindet Mitarbeiter, Standort, Enrollment, Microsoft-/Google-/Apple-Profile und Readiness-Nachweise.',
                    'Dateien, Skripte, Fernsupport und Gerätebefehle erscheinen nur bei passender Berechtigung und belegter Providerfähigkeit.',
                    'Fernlöschung bleibt irreversibel und benötigt die Freigabe eines zweiten globalen Administrators.',
                ],
                [
                    'Filters and CSV import capture existing devices without returning them physically to headquarters.',
                    'The device detail links employee, location, enrolment, Microsoft/Google/Apple profiles and readiness evidence.',
                    'Files, scripts, remote support and device commands appear only with matching permission and verified provider capability.',
                    'Remote wipe remains irreversible and requires approval from a second global administrator.',
                ],
                'Administration',
                'monitor',
                ['devices.index', 'admin.devices'],
            ),
            $this->entry(
                'Meine Geräte einrichten',
                'Set up my devices',
                'Eigene zugewiesene Geräte, offene Registrierungen und die wenigen noch erforderlichen persönlichen Schritte anzeigen.',
                'Review assigned devices, pending enrolments and the few personal steps that are still required.',
                [
                    'Jede Karte zeigt nur die eigene aktive Gerätezuweisung und deren aktuellen Einrichtungsstand.',
                    'Persönliche Einmal-Links sind an das angemeldete RailTime-Konto gebunden und laufen automatisch ab.',
                    'Kennwörter werden ausschließlich in offiziellen Microsoft-, Google- oder Apple-Dialogen eingegeben.',
                    'Bei Bestandsmobilgeräten kann die erste Verwaltung eingeschränkt sein; volle Supervision benötigt häufig einen geplanten Reset.',
                ],
                [
                    'Each card shows only your active device assignment and current setup state.',
                    'Personal one-time links are bound to the signed-in RailTime account and expire automatically.',
                    'Passwords are entered only in official Microsoft, Google or Apple dialogs.',
                    'Existing mobile devices may start with limited management; full supervision often requires a planned reset.',
                ],
                'Mein Bereich',
                'smartphone',
                ['devices.mine', 'devices.enrollment'],
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
                ['calls.index', 'calls.window', 'calls.history'],
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
                'Betriebsplanung',
                'Operations planning',
                'Kunden, Aufträge und Schichten erfassen, besetzen und gemeinsam im Wochenkalender koordinieren.',
                'Capture customers, orders and shifts, assign staff and coordinate the week in one workspace.',
                [
                    'Der Kundenstamm bildet die Grundlage für neue Aufträge und deren Historie.',
                    'Statuswechsel am Auftrag werden mit Zeitpunkt und auslösender Person protokolliert.',
                    'Die Schichtplanung zeigt Besetzungsstand und verhindert überschneidende Zuweisungen.',
                    'Der interne Kalender fasst die gespeicherten Schichten wochenweise zusammen.',
                ],
                [
                    'Customer records provide the basis for new orders and their history.',
                    'Order status changes record their time and the person who initiated them.',
                    'Shift planning shows staffing coverage and prevents overlapping assignments.',
                    'The internal calendar groups stored shifts into a weekly overview.',
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
                    'Geräteprovider, maskierte Zugangsdaten und rein lesende Verbindungstests stehen ausschließlich dem Superadmin zur Verfügung.',
                ],
                [
                    'Tabs separate major areas and accordions keep large groups manageable.',
                    'Changed fields show an orange ring and successful saves briefly show green.',
                    'Saving occurs after inactivity, when leaving a field or before tab and page navigation.',
                    'User, permission and system settings remain restricted to explicitly authorised accounts.',
                    'Device providers, masked credentials and read-only connection tests are available only to the super administrator.',
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
                    'Prüfen Sie zuerst den Profilstatus. Signatur und Mailvorlage werden automatisch mit Ihren Kontaktdaten und den zentral gepflegten Firmendaten erstellt.',
                    'Für das neue Outlook und Outlook im Web wählen Sie „Direkt öffnen und kopieren“. Fügen Sie die Signatur anschließend unter Einstellungen → Konten → Signaturen ein und weisen Sie sie dem richtigen Konto zu.',
                    'Für Classic Outlook laden Sie ausschließlich den geprüften Installer herunter, entpacken das ZIP vollständig und starten die CMD-Datei nicht direkt aus der ZIP-Ansicht.',
                    '„Mailvorlage herunterladen“ liefert die freigegebene, personalisierte HTML-Nachrichtenschale. Öffnen Sie sie im Browser und übernehmen Sie den sichtbaren Inhalt in Ihr Mailprogramm.',
                    'Apple Mail, Gmail und Thunderbird können die direkt kopierte HTML-Signatur ebenfalls verwenden.',
                    'Starten Sie die animierte Vorschau bei Bedarf mit „Neu starten“ und senden Sie nach der Einrichtung eine Testmail an sich selbst.',
                ],
                [
                    'Check profile status first. The signature and mail template are generated automatically with your contact details and the centrally maintained company details.',
                    'For new Outlook and Outlook on the web, choose “Open and copy directly”. Then paste the signature under Settings → Accounts → Signatures and assign it to the correct account.',
                    'For classic Outlook, download only the verified installer, fully extract the ZIP and do not run the CMD file from inside the ZIP view.',
                    '“Download mail template” provides the approved personalised HTML message shell. Open it in a browser and transfer the visible content to your mail client.',
                    'Apple Mail, Gmail and Thunderbird can use the directly copied HTML signature as well.',
                    'Use “Replay” to restart the animated preview when needed, then send yourself a test email after setup.',
                ],
                'Mein Bereich',
                'at-sign',
                ['email-templates.index'],
            ),
            $this->entry(
                'Marketing-Motiv-Dateiablage',
                'Marketing creative file library',
                'RailTime-Motive als übersichtliche Einträge mit direkt zugeordneten Dateien anlegen, prüfen, herunterladen und verwalten.',
                'Create, review, download and manage RailTime creatives as clear records with directly attached files.',
                [
                    'Die Übersicht bündelt alle Marketing-Motive. Öffnen Sie ein Motiv, um dessen zugeordnete Dateien zentral zu verwalten.',
                    'Ziehen Sie Dateien in die Uploadfläche oder wählen Sie sie über den Dateidialog aus. Prüfen Sie die Auswahl vor dem endgültigen Hochladen.',
                    'Vorschau und Download stehen direkt an der jeweiligen Datei bereit; nicht mehr benötigte Dateien lassen sich dort gezielt entfernen.',
                    'Die Dateiablage verändert keine E-Mail-Vorlagen oder Signaturen. Diese behalten ihren eigenen spezialisierten Editor.',
                ],
                [
                    'The overview brings all marketing creatives together. Open a creative to manage its attached files centrally.',
                    'Drop files onto the upload area or select them through the file picker. Review the selection before completing the upload.',
                    'Preview and download actions are available on each file; files that are no longer needed can be removed individually.',
                    'The file library does not alter email templates or signatures. They retain their own specialised editor.',
                ],
                'Marketing',
                'folder',
                ['admin.marketing.creatives.index', 'admin.marketing.creatives.files'],
                true,
            ),
            $this->entry(
                'E-Mail- und Signatur-Editor',
                'Email and signature editor',
                'Mailvorlagen und Signaturen im gemeinsamen LMZ-Vollbildeditor bearbeiten, prüfen und als sicheren Arbeitsstand speichern.',
                'Edit, validate and save email templates and signatures in the shared LMZ full-screen editor.',
                [
                    'Die Kartenansicht dient als Vorschau; die eigentliche Bearbeitung beginnt im Vollbildmodus.',
                    'Text, erlaubte E-Mail-Stile, Abstände und sichere Inhaltsblöcke können angepasst werden.',
                    'Personalisierungs-Platzhalter, Outlook-/MSO-Struktur und Signatur-Grundstruktur bleiben unveränderlich geschützt.',
                    'Speichern ändert nur den Arbeitsstand. Veröffentlichen und Versenden sind keine Assistenten-Aktionen.',
                ],
                [
                    'The card view is a preview; editing starts in full-screen mode.',
                    'Text, allowed email-safe styles, spacing and safe content blocks can be adjusted.',
                    'Personalisation tokens, Outlook/MSO structure and the signature skeleton remain immutable.',
                    'Saving changes only the working draft. Publishing and sending are not assistant actions.',
                ],
                'Administration',
                'mail',
                ['admin.mail-documents.editor'],
                true,
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
                'Marketing' => 'Marketing',
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
