<?php

namespace App\Support\Rbac;

class RbacCatalog
{
    /**
     * @return array<string, string>
     */
    public static function roles(): array
    {
        return [
            'team_access' => 'Team Access',
        ];
    }

    /**
     * @return array<string, array<int, array{key: string, label: string}>>
     */
    public static function permissionGroups(): array
    {
        return [
            'System' => [
                ['key' => 'settings.manage', 'label' => 'Einstellungen verwalten'],
            ],
            'Betrieb' => [
                ['key' => 'operations.manage', 'label' => 'Kunden, Aufträge und Schichten verwalten'],
                ['key' => 'operations.inquiries.manage', 'label' => 'Anfragen und Angebote bearbeiten'],
                ['key' => 'operations.qualifications.manage', 'label' => 'Qualifikationen und Einsatznachweise prüfen'],
                ['key' => 'operations.absences.review', 'label' => 'Abwesenheiten prüfen'],
                ['key' => 'operations.time.review', 'label' => 'Zeitmeldungen prüfen und freigeben'],
                ['key' => 'operations.time.export', 'label' => 'Freigegebene Zeitnachweise exportieren'],
                ['key' => 'operations.rules.manage', 'label' => 'Betriebliche Prüfregeln verwalten'],
            ],
            'Assistenz' => [
                ['key' => 'assistant.use', 'label' => 'Chatbot-Assistent verwenden'],
            ],
            'Mitarbeiter' => [
                ['key' => 'employees.view', 'label' => 'Mitarbeiter anzeigen'],
                ['key' => 'employees.create', 'label' => 'Mitarbeiter erstellen & bearbeiten'],
                ['key' => 'employees.master-data.view', 'label' => 'Mitarbeiter-Stammdaten anzeigen'],
                ['key' => 'employees.master-data.edit', 'label' => 'Mitarbeiter-Stammdaten bearbeiten'],
                ['key' => 'employees.compensation.view', 'label' => 'Lohn- und Sozialdaten anzeigen'],
                ['key' => 'employees.compensation.edit', 'label' => 'Lohn- und Sozialdaten bearbeiten'],
                ['key' => 'roles.manage', 'label' => 'Rollen verwalten'],
            ],
            'Benutzer' => [
                ['key' => 'users.view', 'label' => 'Benutzer anzeigen'],
                ['key' => 'users.edit', 'label' => 'Benutzer bearbeiten'],
                ['key' => 'users.profiles.view', 'label' => 'Benutzerprofile anzeigen'],
            ],
            'Dateien' => [
                ['key' => 'files.manage', 'label' => 'Zentrale Dateiverwaltung'],
            ],
            'Kommunikation' => [
                ['key' => 'support.manage', 'label' => 'IT-Supportfälle bearbeiten'],
                ['key' => 'manage.messages', 'label' => 'Nachrichten & Mails verwalten'],
                ['key' => 'users.messages.view', 'label' => 'Benutzer-Nachrichten anzeigen'],
                ['key' => 'users.messages.create', 'label' => 'Benutzer-Nachrichten erstellen'],
                ['key' => 'users.messages.delete', 'label' => 'Benutzer-Nachrichten löschen'],
            ],
            'Anrufe' => [
                ['key' => 'calls.start', 'label' => 'Videoanrufe starten'],
                ['key' => 'calls.join', 'label' => 'Videoanrufen beitreten'],
                ['key' => 'calls.moderate', 'label' => 'Videoanrufe moderieren'],
            ],
            'Geräteverwaltung' => [
                ['key' => 'devices.view', 'label' => 'Geräte anzeigen'],
                ['key' => 'devices.manage', 'label' => 'Geräte und Lager verwalten'],
                ['key' => 'devices.assign', 'label' => 'Geräte zuweisen und zurücknehmen'],
                ['key' => 'devices.enrollment.manage', 'label' => 'Geräteregistrierung verwalten'],
                ['key' => 'devices.accounts.manage', 'label' => 'Gerätekonten und Profile verwalten'],
                ['key' => 'devices.support', 'label' => 'Fernwartung starten'],
                ['key' => 'devices.commands.execute', 'label' => 'Freigegebene Gerätebefehle ausführen'],
                ['key' => 'devices.lock', 'label' => 'Geräte sperren und entsperren'],
                ['key' => 'devices.audit.view', 'label' => 'Geräteprotokoll anzeigen'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allPermissions(): array
    {
        $all = [];
        foreach (self::permissionGroups() as $permissionItems) {
            foreach ($permissionItems as $item) {
                $key = (string) ($item['key'] ?? '');
                if ($key !== '') {
                    $all[] = $key;
                }
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * @return array<string, string>
     */
    public static function permissionLabels(): array
    {
        $labels = [];
        foreach (self::permissionGroups() as $permissionItems) {
            foreach ($permissionItems as $item) {
                $key = (string) ($item['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                $labels[$key] = (string) ($item['label'] ?? $key);
            }
        }

        return $labels;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function defaultRolePermissions(): array
    {
        return [
            'team_access' => self::allPermissions(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultTeamPermissions(): array
    {
        $defaults = [];
        foreach (self::allPermissions() as $permission) {
            $defaults[$permission] = false;
        }

        return $defaults;
    }
}
