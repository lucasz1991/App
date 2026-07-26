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
                'Hier finden Sie die Funktionen und Aktionen, die zu diesem Bereich gehören.',
                'This area contains the functions and actions for the current page.',
            ),
            'points' => [
                $this->text('Nutzen Sie die Aktionen rechts oben für die wichtigsten Arbeitsschritte.', 'Use the actions at the top right for the most important tasks.'),
                $this->text('Ihre Änderungen werden direkt in RailTime übernommen.', 'Your changes are applied directly in RailTime.'),
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
            $this->entry('Startseite', 'Dashboard', 'Orientierung und Schnellzugriffe auf die wichtigsten RailTime-Bereiche.', 'Overview and shortcuts to the most important RailTime areas.', ['Kennzahlen geben einen schnellen Überblick.', 'Kacheln und Aktionen führen direkt in die jeweiligen Arbeitsbereiche.'], ['KPIs provide a quick overview.', 'Cards and actions lead directly to the relevant work areas.'], 'Grundlagen', 'home', ['dashboard', 'admin.dashboard', 'admin.index']),
            $this->entry('Mitarbeiter', 'Employees', 'Mitarbeiterkonten, Stammdaten, Rollen und Unterlagen zentral verwalten.', 'Manage employee accounts, master data, roles and documents in one place.', ['Öffnen Sie einen Eintrag für das vollständige Profil.', 'Einladungen und Änderungen sind nur mit der passenden Berechtigung möglich.'], ['Open an entry to view the complete profile.', 'Invitations and changes require the appropriate permission.'], 'Administration', 'users', ['employees.index', 'employees.show', 'admin.employees', 'admin.user-profile'], true),
            $this->entry('Dateimanager', 'File manager', 'Ordner und Dateien hochladen, verschieben, anzeigen und freigeben.', 'Upload, move, preview and share folders and files.', ['Ordner stehen im Raster vor den Dateien.', 'Dateien lassen sich per Drag-and-drop in Ordner oder Breadcrumbs verschieben.', 'Rechtsklick öffnet die Bearbeiten- und Löschen-Aktionen der Datei.'], ['Folders appear before files in the grid.', 'Drag files onto folders or breadcrumbs to move them.', 'Right-click opens the file edit and delete actions.'], 'Dateien', 'folder', ['files', 'admin.files']),
            $this->entry('Verbindliche Dokumente', 'Managed documents', 'Unternehmensweite Dokumente veröffentlichen und deren Kenntnisnahme nachverfolgen.', 'Publish company-wide documents and track acknowledgements.', ['Neue Versionen ersetzen die bisherige Fassung nachvollziehbar.', 'Der Lesestatus zeigt, wer ein Dokument bereits bestätigt hat.'], ['New versions replace the previous revision transparently.', 'The read status shows who has acknowledged a document.'], 'Dateien', 'file-text', ['admin.managed-documents'], true),
            $this->entry('Nachrichten', 'Messages', 'Ankündigungen und interne Mitteilungen lesen oder verwalten.', 'Read or manage announcements and internal messages.', ['Filter und Suche grenzen die Liste ein.', 'Öffnen Sie eine Nachricht, um Inhalt und Anhänge zu sehen.'], ['Filters and search narrow the list.', 'Open a message to view its content and attachments.'], 'Kommunikation', 'mail', ['messages', 'admin.messages']),
            $this->entry('Chat', 'Chat', 'Direkt- und Gruppenchats mit Texten, Dateien und Sprachnachrichten.', 'Direct and group chats with text, files and voice messages.', ['Neue Nachrichten erscheinen automatisch am Ende des Verlaufs.', 'Das Mikrofon startet eine Sprachnachricht, sobald kein Text eingegeben ist.'], ['New messages automatically appear at the bottom.', 'The microphone starts a voice message while the text field is empty.'], 'Kommunikation', 'message-circle', ['chat']),
            $this->entry('Wagenliste', 'Wagon list', 'Wagenlisten als geführten Vorgang erfassen, fortsetzen und exportieren.', 'Create, resume and export wagon lists in a guided flow.', ['Vorhandene Entwürfe bleiben bis zum Löschen erhalten.', 'Der Vollbildmodus führt ohne Unterbrechung durch die Erfassung.'], ['Existing drafts remain until deleted.', 'Fullscreen mode guides you through uninterrupted entry.'], 'Betrieb', 'clipboard', ['operations.wagon-list', 'admin.operations.wagon-list']),
            $this->entry('Betriebsvorschau', 'Operations preview', 'Betriebsmodule und ihren aktuellen Arbeitsstand kompakt überblicken.', 'Review operational modules and their current status at a glance.', ['Wählen Sie ein Modul, um direkt in dessen Arbeitsansicht zu wechseln.', 'Status und nächste Aktion werden pro Modul zusammengefasst.'], ['Select a module to open its workspace.', 'Status and next action are summarized per module.'], 'Betrieb', 'activity', ['admin.operations.preview'], true),
            $this->entry('Einstellungen', 'Settings', 'Zentrale Vorgaben und Funktionen der Anwendung konfigurieren.', 'Configure central application settings and functions.', ['Änderungen wirken je nach Bereich sofort oder nach dem Speichern.', 'Administrationsfunktionen sind nur für berechtigte Konten sichtbar.'], ['Changes take effect immediately or after saving, depending on the section.', 'Administration functions are only visible to authorized accounts.'], 'Administration', 'settings', ['admin.settings'], true),
            $this->entry('E-Mail-Verwaltung', 'Email management', 'Ausgehende E-Mails, Vorlagen und Versandinformationen verwalten.', 'Manage outgoing email, templates and delivery information.', ['Prüfen Sie Empfänger und Inhalt vor dem Versand.', 'Vorlagen sorgen für konsistente Nachrichten.'], ['Check recipients and content before sending.', 'Templates keep messages consistent.'], 'Administration', 'send', ['admin.mail-management'], true),
            $this->entry('Profil und Sicherheit', 'Profile and security', 'Persönliche Daten, Kennwort, Sicherheit und Push-Benachrichtigungen verwalten.', 'Manage personal data, password, security and push notifications.', ['Unter Einstellungen können Push-Benachrichtigungen für dieses Gerät erlaubt oder deaktiviert werden.', 'Sicherheitsänderungen können eine Kennwortbestätigung verlangen.'], ['Push notifications for this device can be allowed or disabled under Settings.', 'Security changes may require password confirmation.'], 'Mein Bereich', 'user', ['profile.show']),
            $this->entry('E-Mail-Signaturen', 'Email signatures', 'Persönliche Signaturen anzeigen, kopieren oder herunterladen.', 'View, copy or download personal email signatures.', ['Nutzen Sie die Vorschau vor dem Einfügen in Ihr Mailprogramm.', 'Der Download enthält die für das jeweilige Programm geeignete Fassung.'], ['Preview the signature before adding it to your email client.', 'The download contains the suitable version for the selected client.'], 'Mein Bereich', 'at-sign', ['email-templates.index']),
            $this->entry('Support', 'Support', 'Eine Supportanfrage mit den nötigen Angaben direkt an das RailTime-Team senden.', 'Send a support request with the required details to the RailTime team.', ['Beschreiben Sie den Ablauf und das erwartete Ergebnis möglichst genau.', 'Screenshots und Fehlermeldungen beschleunigen die Bearbeitung.'], ['Describe the steps and expected result as precisely as possible.', 'Screenshots and error messages speed up resolution.'], 'Hilfe', 'life-buoy', ['support']),
        ];
    }

    /**
     * @param  array<int, string>  $pointsDe
     * @param  array<int, string>  $pointsEn
     * @param  array<int, string>  $routes
     * @return array<string, mixed>
     */
    private function entry(string $titleDe, string $titleEn, string $summaryDe, string $summaryEn, array $pointsDe, array $pointsEn, string $groupDe, string $icon, array $routes, bool $admin = false): array
    {
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
