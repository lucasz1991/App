<?php

namespace App\Support\Operations;

use App\Livewire\Operations\CaseWorkspace;
use App\Livewire\Operations\CustomerWorkspace;
use App\Livewire\Operations\OperationsEnhancements;
use App\Livewire\Operations\PersonalPageWorkspace;
use App\Models\User;
use App\Services\Operations\PersonnelProcessService;
use App\Services\Operations\WorkforceAccountService;
use Illuminate\Http\Request;

/** Navigation adapters only: each mounted feature still authorizes its own actions. */
final class OperationsPages
{
    public static function definitions(): array
    {
        return [
            'attention' => ['title' => 'Arbeitsliste', 'icon' => 'inbox', 'segment' => 'Disposition'],
            'cases' => ['title' => 'Vorgänge & Aufträge', 'icon' => 'clipboard', 'segment' => 'Disposition'],
            'shifts' => ['title' => 'Schichtplan', 'icon' => 'calendar', 'segment' => 'Disposition', 'group' => 'Planung'],
            'planning' => ['title' => 'Bedarf & Planung', 'icon' => 'layers', 'segment' => 'Disposition'],
            'duty' => ['title' => 'Leitstelle', 'icon' => 'activity', 'segment' => 'Disposition'],
            'customers' => ['title' => 'Kundenübersicht', 'icon' => 'briefcase', 'segment' => 'Kunden'],
            'people' => ['title' => 'Mitarbeiter & Nachweise', 'icon' => 'users', 'segment' => 'Personal', 'group' => 'Personalverwaltung'],
            'personnel-processes' => ['title' => 'Personalprozesse', 'icon' => 'check-square', 'segment' => 'Personal', 'group' => 'Personalverwaltung'],
            'leave' => ['title' => 'Urlaub & Konten', 'icon' => 'calendar', 'segment' => 'Personal', 'group' => 'Zeitwirtschaft'],
            'time-review' => ['title' => 'Zeitprüfung', 'icon' => 'check-circle', 'segment' => 'Personal', 'group' => 'Zeitwirtschaft'],
            'payroll' => ['title' => 'Monatsabschluss & Export', 'icon' => 'download', 'segment' => 'Personal', 'group' => 'Zeitwirtschaft'],
            'documents' => ['title' => 'Dateien & Unterlagen', 'icon' => 'folder', 'segment' => 'Dokumente'],
        ];
    }

    public static function views(User $actor, string $page): array
    {
        if (! $actor->status) {
            return [];
        }
        if ($page === 'cases') {
            return CaseWorkspace::availableViews($actor);
        }
        if ($page === 'customers') {
            return CustomerWorkspace::availableViews($actor);
        }
        if (in_array($page, ['people', 'personnel-processes', 'leave', 'time-review', 'payroll'], true)) {
            return PersonalPageWorkspace::availableViews($actor, $page);
        }
        if ($page === 'documents') {
            return $actor->isAdmin() && $actor->can('files.manage') ? ['files' => 'Dateien', 'managed' => 'Verbindliche Unterlagen'] : [];
        }
        if (! OperationsAccess::ready()) {
            return [];
        }

        return match ($page) {
            'attention' => $actor->can('operations.inbox.view') ? ['inbox' => 'Arbeitsliste'] : [],
            'shifts' => $actor->can('operations.manage') ? ['plan' => 'Schichtplan', 'calendar' => 'Kalender'] : [],
            'planning' => $actor->can('operations.manage') ? array_filter([
                'capacity' => PlanningEnhancementSchema::ready() ? 'Bedarf & Kapazität' : null,
                'staff' => WorkforcePlanningSchema::ready() ? 'Personalangebot' : null,
                'tools' => WorkforcePlanningSchema::ready() || PlanningEnhancementSchema::ready() ? 'Planungswerkzeuge' : null,
                'logistics' => OperationsEnhancementsSchema::ready() ? 'Reisen & Partner' : null,
            ]) : [],
            'duty' => $actor->can('operations.manage') ? array_filter([
                'board' => OperationsNavigation::enhancementReady('duty-monitor') ? 'Dienststand' : null,
                'cases' => WorkforcePlanningSchema::ready() ? 'Störungen' : null,
                'transfers' => WorkforcePlanningSchema::ready() ? 'Übernahmen & Tausch' : null,
            ]) : [],
            default => [],
        };
    }

    public static function sections(User $actor, string $page): array
    {
        if ($page === 'cases') {
            return CaseWorkspace::availableSections($actor);
        }

        return in_array($page, ['people', 'personnel-processes', 'leave', 'time-review', 'payroll'], true)
            ? PersonalPageWorkspace::availableSections($actor, $page) : [];
    }

    /** The three planning workspaces shared by the sidebar and topbar shortcuts. */
    public static function planningViews(User $actor): array
    {
        return array_intersect_key(self::views($actor, 'cases'), array_flip(['inbox', 'orders', 'shifts']));
    }

    public static function availableFor(User $actor): array
    {
        return array_filter(self::definitions(), fn ($definition, $page) => $page !== 'shifts' && (self::views($actor, $page) || self::sections($actor, $page)), ARRAY_FILTER_USE_BOTH);
    }

    public static function url(string $page, array $parameters = []): string
    {
        abort_unless(isset(self::definitions()[$page]), 404);

        if ($page === 'shifts') {
            $section = $parameters['view'] ?? 'plan';
            abort_unless(in_array($section, ['plan', 'calendar'], true), 403);
            unset($parameters['view'], $parameters['section'], $parameters['page']);
            $page = 'cases';
            $parameters = ['view' => 'shifts', 'section' => $section] + $parameters;
        }

        return route('operations.page', ['page' => $page] + array_filter($parameters, fn ($value) => $value !== null && $value !== ''));
    }

    public static function context(Request $request): array
    {
        $result = [];
        foreach (['inquiry', 'order', 'customer', 'user', 'shift', 'revision', 'record'] as $name) {
            $value = $request->query($name, $request->query($name.'_id'));
            if ($value === null || $value === '') {
                continue;
            }
            abort_unless((is_int($value) || is_string($value) && ctype_digit($value)) && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 422);
            $result[$name] = (int) $value;
        }
        foreach (['source', 'record_type', 'search', 'from', 'until', 'status', 'filter', 'detail'] as $name) {
            $value = $request->query($name);
            if ($value !== null && $value !== '') {
                abort_unless(is_string($value) && mb_strlen($value) <= 100, 422);
                $result[$name] = $value;
            }
        }
        if (isset($result['user'])) {
            $result['user_id'] = $result['user'];
        }
        if (isset($result['record'])) {
            $result['record_id'] = $result['record'];
        }

        return $result;
    }

    public static function legacyTarget(string $module, array $query = []): array
    {
        $tab = is_string($query['tab'] ?? null) ? $query['tab'] : '';
        $target = match ($module) {
            'inquiries' => ['cases', 'inbox'], 'orders' => ['cases', 'orders'],
            'shift-management' => ['cases', 'shifts', 'plan'], 'calendar' => ['cases', 'shifts', 'calendar'],
            'customers' => ['customers', 'master'],
            'customer-portal' => in_array($tab, ['requests', 'messages', 'capacity'], true) ? ['cases', 'inbox', 'portal'] : ['customers', 'portal', $tab === 'contacts' ? 'access' : ($tab ?: 'access')],
            'qualifications' => ['people', 'qualifications'], 'absences' => ['leave', 'requests'],
            'times' => ['time-review', 'times'], 'exports' => ['payroll', 'export'], 'rules' => ['time-review', '', 'rules'],
            'workforce-accounts' => match ($tab) {
                'tasks' => ['personnel-processes', 'tasks'], 'training' => ['people', 'training'],
                'absences' => ['leave', 'requests'], '', 'account', 'accounts' => ['leave', 'leave-accounts'],
                default => ['leave', 'leave-accounts', $tab],
            },
            'personnel-processes' => ['personnel-processes', 'tasks'],
            'workforce-planning' => in_array($tab, ['cases', 'transfers'], true) ? ['duty', $tab] : ['planning', 'staff', $tab ?: 'pools'],
            'plan-variants' => ['planning', 'tools', 'variants'],
            'planning-enhancements' => ['planning', $tab === '' || $tab === 'capacity' ? 'capacity' : 'tools', $tab ?: 'capacity'],
            'personnel-enhancements' => match ($tab) {
                'documents' => ['people', '', 'signatures'], 'emergency' => ['people', '', 'emergency'],
                'sickness' => ['leave', 'requests', 'sickness'],
                'approvals', 'calendars' => ['leave', '', $tab],
                'recruiting', 'development', 'workflows' => ['personnel-processes', $tab],
                '' => ['personnel-processes', 'workflows'],
                default => ['personnel-processes', 'tasks', $tab],
            },
            'operations-enhancements' => match ($tab) {
                'imports' => ['cases', 'inbox', 'imports'], 'travel', 'partners' => ['planning', 'logistics', $tab],
                'rules', 'terminal' => ['time-review', '', $tab], 'payroll' => ['payroll', 'closing'],
                default => ['cases', 'orders', $tab ?: 'proofs'],
            },
            'attention-center' => ['attention', 'inbox'], 'duty-monitor' => ['duty', 'board', $tab === 'profiles' ? 'profiles' : ''],
            default => abort(404),
        };
        unset($query['module'], $query['tab'], $query['page'], $query['view'], $query['section']);

        return ['page' => $target[0], 'view' => $target[1], 'section' => $target[2] ?? ''] + $query;
    }

    public static function moduleUrl(string $module, array $query = []): string
    {
        $target = self::legacyTarget($module, $query);
        $page = $target['page'];
        unset($target['page']);

        return self::url($page, $target);
    }

    public static function authorizeLegacy(User $actor, string $module): void
    {
        $definition = OperationsNavigation::modules()[$module] ?? null;
        abort_unless($definition, 404);
        OperationsAccess::authorize($actor, $definition['ability']);
        OperationsAccess::requireReady();
        abort_unless(OperationsNavigation::enhancementReady($module), 503, 'Arbeitsbereich nicht verfügbar.');
        if (in_array($module, ['workforce-planning', 'plan-variants'], true)) {
            abort_unless(WorkforcePlanningSchema::ready(), 503, 'Die Planungsmodule sind noch nicht eingerichtet.');
        }
        if ($module === 'workforce-accounts') {
            abort_unless(app(WorkforceAccountService::class)->ready(), 503, 'Die Personalkonten sind noch nicht eingerichtet.');
        }
        if ($module === 'personnel-processes') {
            abort_unless(app(PersonnelProcessService::class)->ready(), 503, 'Die Personalprozesse sind noch nicht eingerichtet.');
        }
    }

    public static function legacyUrlFor(User $actor, string $module, array $query = []): string
    {
        self::authorizeLegacy($actor, $module);
        if ($module === 'operations-enhancements' && empty($query['tab'])) {
            $query['tab'] = array_key_first((new OperationsEnhancements)->tabs()) ?? '';
        }

        return self::moduleUrl($module, $query);
    }
}
