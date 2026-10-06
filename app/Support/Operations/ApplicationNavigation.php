<?php

namespace App\Support\Operations;

use App\Models\User;

final class ApplicationNavigation
{
    public static function sections(User $user): array
    {
        $admin = $user->isAdmin();
        $sections = ['' => []];
        $add = static function (string $section, string $title, string $route, string $icon, array $parameters = [], bool $navigate = true, ?string $group = null) use (&$sections): void {
            $sections[$section][] = compact('title', 'route', 'icon', 'parameters', 'navigate', 'group');
        };
        $add('', 'Dashboard', $admin ? 'admin.dashboard' : 'dashboard', 'home');
        $sections += array_fill_keys($user->dashboardAudience() === 'employee'
            ? ['Mein Arbeitsplatz', 'Disposition', 'Kunden', 'Personal']
            : ['Disposition', 'Kunden', 'Personal', 'Mein Arbeitsplatz'], []);
        $ready = OperationsAccess::ready();
        if ($ready && OperationsAccess::isEmployee($user)) {
            $add('Mein Arbeitsplatz', 'Mein Arbeitstag', 'operations.mine', 'clock');
        }
        foreach (OperationsPages::availableFor($user) as $page => $definition) {
            $add($definition['segment'], $definition['title'], 'operations.page', $definition['icon'], ['page' => $page], true, $definition['group'] ?? null);
        }
        if (! $ready && $admin) {
            foreach (['orders' => 'Leistungen', 'shift-management' => 'Schichtplan', 'calendar' => 'Kalender', 'customers' => 'Kunden'] as $slug => $title) {
                $add($slug === 'customers' ? 'Kunden' : 'Disposition', $title, 'admin.operations.preview', 'calendar', ['module' => $slug]);
            }
        }
        if ($admin || in_array($user->dashboardAudience(), ['employee', 'management', 'administration'], true)) {
            $add('Mein Arbeitsplatz', 'Wagenliste', $admin ? 'admin.operations.wagon-list' : 'operations.wagon-list', 'list', [], true, 'Arbeitsmittel');
        }
        if ($user->can('employees.view')) {
            if (! $admin) {
                $add('Kommunikation', 'Anrufe', 'calls.index', 'phone');
            }
        }
        if ($user->can('devices.view')) {
            $add('Geräte', 'Geräte & Lager', $admin ? 'admin.devices' : 'devices.index', 'monitor');
        }
        if ($admin) {
            $add('Kommunikation', 'Mailverwaltung', 'admin.mail-management', 'send');
            $add('Marketing', 'Motive', 'admin.marketing.creatives.index', 'image');
        } else {
            $add('Dokumente', 'Download-Center', 'files', 'download-cloud');
        }

        $add('Persönlich', 'Meine Geräte', 'devices.mine', 'smartphone');
        $add('Persönlich', 'Profil', 'profile.show', 'user', [], false);

        return array_filter($sections, fn ($links) => count($links) > 0);
    }

    public static function managementGroups(array $links): array
    {
        return self::groups($links);
    }

    public static function groups(array $links): array
    {
        $groups = [];
        foreach ($links as $link) {
            $label = $link['group'] ?? '';
            $key = $label === '' ? '__direct_'.count($groups) : $label;
            $groups[$key] ??= ['label' => $label, 'icon' => match ($label) {
                'Planung' => 'calendar', 'Personalverwaltung' => 'users', 'Zeitwirtschaft' => 'clock', 'Arbeitsmittel' => 'tool', default => 'layers',
            }, 'links' => []];
            $groups[$key]['links'][] = $link;
        }

        return array_filter($groups, fn (array $group) => count($group['links']) > 0);
    }

    public static function active(array $link): bool
    {
        if ($link['route'] === 'operations.page') {
            $page = request()->route('page');
            if (request()->routeIs('operations.workspace')) {
                $page = OperationsPages::legacyTarget(request()->route('module'), request()->query())['page'];
            }

            return $page === $link['parameters']['page'];
        }
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
