<?php

namespace App\Support\Operations;

use App\Models\User;

final class ApplicationNavigation
{
    public static function sections(User $user): array
    {
        $admin = $user->isAdmin();
        $sections = ['' => []];
        $add = static function (string $section, string $title, string $route, string $icon, array $parameters = [], bool $navigate = true, ?string $group = null, array $excludedSections = []) use (&$sections): void {
            $sections[$section][] = compact('title', 'route', 'icon', 'parameters', 'navigate', 'group', 'excludedSections');
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
            if ($page === 'cases') {
                $views = OperationsPages::planningViews($user);
                // Keep the direct intake/order links before the grouped planning tools.
                foreach (['inbox', 'orders', 'offers', 'shifts'] as $view) {
                    if (! isset($views[$view])) {
                        continue;
                    }
                    $entry = $views[$view];
                    $parameters = ['page' => 'cases', 'view' => $view];
                    if ($view === 'shifts') {
                        $parameters['section'] = 'plan';
                    }
                    $icon = match ($view) {
                        'inbox' => 'inbox', 'orders' => 'briefcase', 'offers' => 'file-text', 'shifts' => 'clipboard',
                    };
                    $add($definition['segment'], $entry['label'], 'operations.page', $icon, $parameters, true, in_array($view, ['offers', 'shifts'], true) ? 'Planung' : null);
                    if ($view === 'shifts') {
                        $add($definition['segment'], 'Kalender', 'operations.page', 'calendar', ['page' => 'cases', 'view' => 'shifts', 'section' => 'calendar'], true, 'Planung');
                    }
                }

                continue;
            }
            if ($page === 'people') {
                // The workspace chooses the first authorized view. Specialist roles
                // must not need employees.view to reach their existing records.
                $add('Personal', 'Mitarbeiter', 'operations.page', 'users', ['page' => $page]);

                continue;
            }

            $shortcuts = match ($page) {
                'time-review' => ['rules' => ['Regelprofile', 'shield']],
                default => [],
            };
            $availableSections = $shortcuts ? OperationsPages::sections($user, $page) : [];
            $shortcuts = array_intersect_key($shortcuts, $availableSections);
            foreach ($shortcuts as $section => [$label, $icon]) {
                $add('Personal', $label, 'operations.page', $icon, ['page' => $page, 'section' => $section]);
            }
            if (! $shortcuts || OperationsPages::views($user, $page) || array_diff_key($availableSections, $shortcuts)) {
                $group = match ($page) {
                    'planning' => 'Planung',
                    'personnel-processes' => null,
                    default => $definition['group'] ?? null,
                };
                $add($page === 'attention' ? '' : $definition['segment'], $definition['title'], 'operations.page', $definition['icon'], ['page' => $page], true, $group, array_keys($shortcuts));
            }
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

        if (! empty($sections['Personal'])) {
            // The compact menu and sidebar must use the same workflow order.
            $sections['Personal'] = array_merge(...array_column(self::groups($sections['Personal']), 'links'));
        }

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
                'Planung' => 'calendar', 'Personalakte' => 'users', 'Nachweise & Schulungen' => 'award',
                'Zeitwirtschaft' => 'clock', 'Arbeitsmodelle & Regeln' => 'sliders', 'Arbeitsmittel' => 'tool', default => 'layers',
            }, 'links' => []];
            $groups[$key]['links'][] = $link;
        }

        // Keep configuration after everyday personnel work without changing any link contract.
        if (isset($groups['Arbeitsmodelle & Regeln'])) {
            $rules = $groups['Arbeitsmodelle & Regeln'];
            unset($groups['Arbeitsmodelle & Regeln']);
            $groups['Arbeitsmodelle & Regeln'] = $rules;
        }

        return array_filter($groups, fn (array $group) => count($group['links']) > 0);
    }

    public static function active(array $link): bool
    {
        if ($link['route'] === 'operations.page') {
            if ($link['parameters']['page'] === 'people' && request()->routeIs('admin.employees', 'employees.index', 'admin.user-profile', 'employees.show')) {
                return true;
            }
            $page = request()->route('page');
            $view = request()->query('view');
            $section = request()->query('section');
            if ($page === 'shifts') {
                $page = 'cases';
                $section = $view ?? 'plan';
                $view = 'shifts';
            }
            if (request()->routeIs('operations.workspace')) {
                $target = OperationsPages::legacyTarget(request()->route('module'), request()->query());
                $page = $target['page'];
                $view = $target['view'];
                $section = $target['section'];
            }

            if ($page !== $link['parameters']['page']) {
                return false;
            }
            $actor = auth()->user();
            if (($view === null || $view === '') && $actor instanceof User) {
                $view = array_key_first(OperationsPages::views($actor, $page));
                if ($view === null && ! $section) {
                    $section = array_key_first(OperationsPages::sections($actor, $page));
                }
            }
            if (in_array($section, $link['excludedSections'] ?? [], true)) {
                return false;
            }
            if (isset($link['parameters']['view'])) {
                if ($view !== $link['parameters']['view'] || ($page === 'people' && filled($section))) {
                    return false;
                }
            }

            return ! isset($link['parameters']['section'])
                || ($section ?: ($view === 'shifts' ? 'plan' : '')) === $link['parameters']['section'];
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
