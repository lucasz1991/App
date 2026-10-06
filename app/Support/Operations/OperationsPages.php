<?php

namespace App\Support\Operations;

use App\Livewire\Operations\CaseWorkspace;
use App\Livewire\Operations\CustomerWorkspace;
use App\Livewire\Operations\PersonalPageWorkspace;
use App\Models\User;
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
            'planning' => ['title' => 'Bedarf & Planung', 'icon' => 'layers', 'segment' => 'Disposition', 'group' => 'Planung'],
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
            return $actor->isAdmin() ? ['files' => 'Dateien', 'managed' => 'Verbindliche Unterlagen'] : [];
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
        return in_array($page, ['people', 'personnel-processes', 'leave', 'time-review', 'payroll'], true)
            ? PersonalPageWorkspace::availableSections($actor, $page) : [];
    }

    public static function availableFor(User $actor): array
    {
        return array_filter(self::definitions(), fn ($definition, $page) => self::views($actor, $page) || self::sections($actor, $page), ARRAY_FILTER_USE_BOTH);
    }

    public static function url(string $page, array $parameters = []): string
    {
        abort_unless(isset(self::definitions()[$page]), 404);
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
            abort_unless(is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 422);
            $result[$name] = (int) $value;
        }
        foreach (['source', 'record_type', 'search', 'from', 'until', 'status'] as $name) {
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
            'shift-management' => ['shifts', 'plan'], 'calendar' => ['shifts', 'calendar'],
            'customers' => ['customers', 'master'],
            'customer-portal' => in_array($tab, ['requests', 'messages', 'capacity'], true) ? ['cases', 'inbox', 'portal'] : ['customers', 'portal', $tab ?: 'access'],
            'qualifications' => ['people', 'qualifications'], 'absences' => ['leave', 'requests'],
            'times' => ['time-review', 'times'], 'exports' => ['payroll', 'export'], 'rules' => ['time-review', 'times', 'rules'],
            'workforce-accounts' => ['leave', 'leave-accounts', $tab ?: ''],
            'personnel-processes' => ['personnel-processes', 'tasks'],
            'workforce-planning' => in_array($tab, ['cases', 'transfers'], true) ? ['duty', $tab] : ['planning', 'staff', $tab ?: 'pools'],
            'plan-variants' => ['planning', 'tools', 'variants'],
            'planning-enhancements' => ['planning', $tab === '' || $tab === 'capacity' ? 'capacity' : 'tools', $tab ?: 'capacity'],
            'personnel-enhancements' => match ($tab) {
                'documents' => ['people', 'documents'], 'emergency' => ['people', 'employees', 'emergency'],
                'approvals', 'calendars' => ['leave', 'requests', $tab],
                'recruiting', 'development', 'workflows' => ['personnel-processes', $tab],
                default => ['personnel-processes', 'tasks', $tab],
            },
            'operations-enhancements' => match ($tab) {
                'imports' => ['cases', 'inbox', 'imports'], 'travel', 'partners' => ['planning', 'logistics', $tab],
                'rules', 'terminal' => ['time-review', 'times', $tab], 'payroll' => ['payroll', 'closing'],
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
}
