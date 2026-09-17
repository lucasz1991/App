<?php

namespace App\Support\Operations;

use App\Models\User;

final class ApplicationNavigation
{
    public static function sections(User $user): array
    {
        $admin = $user->isAdmin();
        $sections = [];
        $add = static function (string $section, string $title, string $route, string $icon, array $parameters = [], bool $navigate = true) use (&$sections): void {
            $sections[$section][] = compact('title', 'route', 'icon', 'parameters', 'navigate');
        };
        $add('Übersicht', 'Dashboard', $admin ? 'admin.dashboard' : 'dashboard', 'home');
        $ready = OperationsAccess::ready();
        if ($ready && OperationsAccess::isEmployee($user)) {
            $add('Mein Arbeitsplatz', 'Mein Arbeitstag', 'operations.mine', 'clock');
        }
        $add('Persönlich', 'Meine Geräte', 'devices.mine', 'smartphone');
        $add('Persönlich', 'Profil', 'profile.show', 'user', [], false);

        $icons = ['inquiries' => 'inbox', 'orders' => 'clipboard', 'shift-management' => 'clock', 'calendar' => 'calendar', 'customers' => 'briefcase', 'qualifications' => 'award', 'absences' => 'calendar', 'times' => 'check-circle', 'exports' => 'download', 'rules' => 'shield'];
        $opsModules = $ready ? OperationsNavigation::forUser($user) : [];
        foreach ($opsModules as $slug => $module) {
            $section = match ($slug) {
                'rules' => 'System',
                default => 'Management',
            };
            $add($section, $module['title'], 'operations.workspace', $icons[$slug], ['module' => $slug]);
        }
        if (! $ready && $admin) {
            foreach (['orders' => 'Leistungen', 'shift-management' => 'Schichtplan', 'calendar' => 'Kalender', 'customers' => 'Kunden'] as $slug => $title) {
                $add('Management', $title, 'admin.operations.preview', $icons[$slug], ['module' => $slug]);
            }
        }
        if ($admin || in_array($user->dashboardAudience(), ['employee', 'management', 'administration'], true)) {
            // Ohne jede weitere Management-Berechtigung waere "Management"
            // fuer diese Person eine Ueberschrift mit nur diesem einen
            // Eintrag. Die Wagenliste gehoert dann zum eigenen Arbeitsplatz.
            $hasManagementCapability = $admin || $user->can('employees.view') || $user->can('devices.view') || count($opsModules) > 0;
            $add($hasManagementCapability ? 'Management' : 'Mein Arbeitsplatz', 'Wagenliste', $admin ? 'admin.operations.wagon-list' : 'operations.wagon-list', 'list');
        }
        if ($user->can('employees.view')) {
            $add('Management', 'Mitarbeiter', $admin ? 'admin.employees' : 'employees.index', 'users');
            if (! $admin) {
                $add('Kommunikation', 'Anrufe', 'calls.index', 'phone');
            }
        }
        if ($user->can('devices.view')) {
            $add('Management', 'Geräte & Lager', $admin ? 'admin.devices' : 'devices.index', 'monitor');
        }
        if ($admin) {
            $add('Kommunikation', 'Mailverwaltung', 'admin.mail-management', 'send');
            $add('Kommunikation', 'E-Mail-Vorlagen', 'admin.mail-documents.editor', 'mail', [], false);
            $add('Marketing', 'Motive', 'admin.marketing.creatives.index', 'image');
            $add('Dateien', 'Dateiverwaltung', 'admin.files', 'folder');
            $add('Dateien', 'Arbeitsmittel', 'admin.managed-documents', 'tool');
        } else {
            $add('Dateien', 'Download-Center', 'files', 'download-cloud');
        }

        return array_replace(array_fill_keys(['Übersicht', 'Mein Arbeitsplatz', 'Management', 'Kommunikation', 'Marketing', 'Dateien', 'System', 'Persönlich'], []), $sections);
    }

    public static function managementGroups(array $links): array
    {
        $groups = [
            'Disposition' => ['icon' => 'calendar', 'links' => []],
            'Personal' => ['icon' => 'users', 'links' => []],
            'Zeiten & Freigaben' => ['icon' => 'clock', 'links' => []],
            'Stammdaten & Geräte' => ['icon' => 'briefcase', 'links' => []],
        ];
        foreach ($links as $link) {
            $module = $link['parameters']['module'] ?? null;
            $group = match (true) {
                in_array($module, ['qualifications', 'absences'], true),
                in_array($link['route'], ['admin.employees', 'employees.index'], true) => 'Personal',
                in_array($module, ['times', 'exports'], true) => 'Zeiten & Freigaben',
                $module === 'customers',
                in_array($link['route'], ['admin.devices', 'devices.index'], true) => 'Stammdaten & Geräte',
                default => 'Disposition',
            };
            $groups[$group]['links'][] = $link;
        }

        return array_filter($groups, fn (array $group) => count($group['links']) > 0);
    }

    public static function active(array $link): bool
    {
        $patterns = match ($link['route']) {
            'admin.dashboard' => ['admin.dashboard', 'admin.index'],
            'admin.employees', 'employees.index' => [$link['route'], 'employees.show'],
            'devices.mine' => ['devices.mine', 'devices.enrollment'],
            'admin.devices', 'devices.index' => ['admin.devices', 'devices.index'],
            'admin.marketing.creatives.index' => ['admin.marketing.creatives.*'],
            default => [$link['route']],
        };

        return request()->routeIs(...$patterns)
            && (! isset($link['parameters']['module']) || request()->route('module') === $link['parameters']['module']);
    }
}
