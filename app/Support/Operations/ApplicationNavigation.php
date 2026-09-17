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
        $add('Mein Arbeitsplatz', 'Meine Geräte', 'devices.mine', 'smartphone');
        $add('Mein Arbeitsplatz', 'Profil', 'profile.show', 'user', [], false);

        $icons = ['inquiries' => 'inbox', 'orders' => 'clipboard', 'shift-management' => 'clock', 'calendar' => 'calendar', 'customers' => 'briefcase', 'qualifications' => 'award', 'absences' => 'calendar', 'times' => 'check-circle', 'exports' => 'download', 'rules' => 'shield'];
        foreach ($ready ? OperationsNavigation::forUser($user) : [] as $slug => $module) {
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
            $add('Management', 'Wagenliste', $admin ? 'admin.operations.wagon-list' : 'operations.wagon-list', 'list');
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

        return array_replace(array_fill_keys(['Übersicht', 'Mein Arbeitsplatz', 'Management', 'Kommunikation', 'Marketing', 'Dateien', 'System'], []), $sections);
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
