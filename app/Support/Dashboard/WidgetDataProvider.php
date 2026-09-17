<?php

namespace App\Support\Dashboard;

use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeQualification;
use App\Models\Mail;
use App\Models\MarketingCreative;
use App\Models\Order;
use App\Models\OperationInquiry;
use App\Models\OperationsRuleProfile;
use App\Models\Room;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\DeviceManagement\DeviceFleetSnapshot;
use App\Services\DeviceManagement\PersonalDeviceSnapshot;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Facades\Gate;

/**
 * Liefert je Widget-Schluessel genau die Daten, die dessen Blade-Partial
 * (resources/views/dashboard/widgets/{key}.blade.php) braucht. Ein Aufruf
 * pro sichtbarem Widget - WidgetGrid ruft das ausschliesslich fuer Widgets
 * auf, die WidgetRegistry der Person bereits erlaubt hat; hier wird nicht
 * nochmal geprueft.
 */
class WidgetDataProvider
{
    public function __construct(
        private readonly DeviceFleetSnapshot $fleetSnapshot,
        private readonly PersonalDeviceSnapshot $personalDeviceSnapshot,
        private readonly SystemDashboardData $systemDashboardData,
    ) {}

    /**
     * @param  1|2  $rows  Hoehe in Zeileneinheiten. Steuert, wie viel
     *                     vertikaler Inhalt (Listenlaenge, Chart-Hoehe)
     *                     angezeigt wird - unabhaengig von $size, das nur
     *                     die Breite ist.
     * @return array<string, mixed>
     */
    public function data(string $key, User $user, string $size, int $rows = 1): array
    {
        return match ($key) {
            'my_work' => $this->myWork($user),
            'wagon_list' => $this->wagonList($user),
            'messages' => $this->messages($user, $rows),
            'files' => $this->files($user, $rows),
            'my_devices' => $this->myDevices($user),
            'profile_completion' => $this->profileCompletion($user),
            'operations_inquiries' => $this->operationsQueue('inquiries', 'Offene Anfragen', OperationInquiry::whereNull('order_id')->whereNull('duplicate_of_id')),
            'operations_orders' => $this->operationsOrders(),
            'operations_shift_coverage' => $this->operationsShiftCoverage(),
            'operations_next_shifts' => $this->operationsNextShifts($rows),
            'operations_customers' => $this->operationsCustomers(),
            'operations_qualifications' => $this->operationsQueue('qualifications', 'Nachweise prüfen', EmployeeQualification::where('status', 'pending')),
            'operations_absences' => $this->operationsQueue('absences', 'Abwesenheiten prüfen', AbsenceRequest::where('status', 'pending')),
            'operations_times' => $this->operationsQueue('times', 'Zeiten prüfen', WorkTimeEntry::where('status', 'submitted')),
            'operations_rules' => $this->operationsRules(),
            'fleet_devices' => $this->fleetDevices($user),
            'employees' => $this->employees($user),
            'recent_activity' => $this->recentActivity($rows),
            'account_growth' => $this->accountGrowth(),
            'mail_management' => $this->mailManagement($user),
            'calls' => $this->calls($user, $rows),
            'support_cases' => $this->supportCases($user, $rows),
            'marketing' => $this->marketing(),
            'system_status' => [],
            default => [],
        };
    }

    private function myWork(User $user): array
    {
        $activeTime = WorkTimeEntry::where('user_id', $user->id)->whereIn('status', ['running', 'paused'])->first();
        // Kleine, ungefaehrliche Menge (eine Person hat selten mehr als eine
        // Handvoll offener Zuweisungen) - in PHP sortiert statt per
        // Subquery-orderBy, um keine Annahme ueber die Tabellen-Alias-Form
        // von whereHas() zu machen.
        $nextAssignment = ShiftAssignment::where('user_id', $user->id)
            ->whereIn('status', ['requested', 'confirmed'])
            ->whereHas('shift', fn ($q) => $q->where('published_revision', '>', 0)->where('status', '!=', 'cancelled')->where('ends_at', '>', now()->utc()))
            ->with('shift.order.customer')
            ->get()
            ->sortBy(fn (ShiftAssignment $assignment) => $assignment->shift->starts_at)
            ->first();

        return [
            'activeTime' => $activeTime,
            'nextAssignment' => $nextAssignment,
            'href' => route('operations.mine'),
        ];
    }

    private function wagonList(User $user): array
    {
        return ['href' => route($user->isAdmin() ? 'admin.operations.wagon-list' : 'operations.wagon-list')];
    }

    private function messages(User $user, int $rows): array
    {
        $unread = $user->receivedMessages()->where('status', 1)->count();
        $latest = $rows === 2
            ? $user->receivedMessages()->with('sender:id,name')->latest()->limit(4)->get()
            : collect();

        return ['unread' => $unread, 'latest' => $latest, 'href' => route('messages')];
    }

    private function files(User $user, int $rows): array
    {
        $grouped = $user->availableFilesGrouped();
        $teamFiles = collect($grouped['teams'])->flatMap(fn (array $entry) => $entry['files']);
        $all = $grouped['personal']->merge($grouped['company'])->merge($teamFiles)->unique('id');

        return [
            'total' => $all->count(),
            'recent' => $rows === 2 ? $all->sortByDesc('created_at')->take(4)->values() : collect(),
            'href' => route('files'),
        ];
    }

    private function myDevices(User $user): array
    {
        $stats = $this->personalDeviceSnapshot->get($user);

        return ['stats' => $stats, 'href' => $stats['available'] ? route('devices.mine') : null];
    }

    private function fleetDevices(User $user): array
    {
        $stats = $this->fleetSnapshot->get();

        return ['stats' => $stats, 'href' => $stats['available'] ? route($user->isAdmin() ? 'admin.devices' : 'devices.index') : null];
    }

    private function profileCompletion(User $user): array
    {
        $profile = $user->profile;
        $checks = [
            'phone' => filled($profile?->phone),
            'mobile' => filled($profile?->mobile),
            'position' => filled($profile?->position),
            'profile_photo' => filled($user->profile_photo_path),
        ];
        $completion = (int) round(100 * count(array_filter($checks)) / count($checks));

        return ['completion' => $completion, 'checks' => $checks, 'href' => route('profile.show')];
    }

    /** Gemeinsame Form fuer die vier Cockpit-Warteschlangen (dieselben Zahlen wie Cockpit.php). */
    private function operationsQueue(string $slug, string $label, $query): array
    {
        return [
            'label' => $label,
            'count' => $query->count(),
            'href' => route('operations.workspace', $slug),
        ];
    }

    private function operationsOrders(): array
    {
        $open = Order::query()->whereNotIn('status', ['completed', 'invoiced', 'cancelled'])->count();

        return ['label' => 'Offene Leistungen', 'count' => $open, 'href' => route('operations.workspace', 'orders')];
    }

    private function operationsShiftCoverage(): array
    {
        $today = now(config('operations.display_timezone'))->startOfDay();
        $shifts = Shift::notCancelled()->during($today, $today->copy()->addDays(7))
            ->withCount(['assignments as reserved' => fn ($q) => $q->blocking()])
            ->get();
        $days = collect(range(0, 6))->map(fn (int $offset) => $today->copy()->addDays($offset));
        $byDate = $shifts->groupBy(fn (Shift $shift) => $shift->starts_at->toDateString());

        return [
            'labels' => $days->map(fn ($day) => $day->translatedFormat('D d.'))->values()->all(),
            'required' => $days->map(fn ($day) => (int) $byDate->get($day->toDateString(), collect())->sum('required_staff'))->values()->all(),
            'reserved' => $days->map(fn ($day) => (int) $byDate->get($day->toDateString(), collect())->sum('reserved'))->values()->all(),
            'href' => route('operations.workspace', 'shift-management'),
        ];
    }

    private function operationsNextShifts(int $rows): array
    {
        $shifts = Shift::notCancelled()
            ->during(now(config('operations.display_timezone'))->startOfDay(), now()->addDays(14))
            ->with(['order.customer'])
            ->withCount(['assignments as reserved' => fn ($q) => $q->blocking()])
            ->orderBy('starts_at')
            ->limit($rows === 2 ? 6 : 3)
            ->get();

        return ['shifts' => $shifts, 'href' => route('operations.workspace', 'shift-management')];
    }

    private function operationsCustomers(): array
    {
        return [
            'active' => Customer::query()->active()->count(),
            'total' => Customer::query()->count(),
            'href' => route('operations.workspace', 'customers'),
        ];
    }

    private function operationsRules(): array
    {
        $profile = OperationsRuleProfile::where('is_active', true)->first();

        return ['profile' => $profile, 'href' => route('operations.workspace', 'rules')];
    }

    private function employees(User $user): array
    {
        $snapshot = User::query()->where('role', 'staff')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as active')
            ->first();

        return [
            'total' => (int) ($snapshot->total ?? 0),
            'active' => (int) ($snapshot->active ?? 0),
            'href' => route($user->isAdmin() ? 'admin.employees' : 'employees.index'),
        ];
    }

    private function recentActivity(int $rows): array
    {
        return ['entries' => $this->systemDashboardData->recentActivity()->take($rows === 2 ? 6 : 3)];
    }

    private function accountGrowth(): array
    {
        $charts = $this->systemDashboardData->charts();
        $growth = $charts['userGrowth'] ?? ['labels' => [], 'totals' => []];

        return [
            'labels' => $growth['labels'] ?? [],
            'values' => $growth['totals'] ?? [],
            'total' => (int) (collect($growth['totals'] ?? [])->last() ?? 0),
        ];
    }

    private function mailManagement(User $user): array
    {
        return [
            'pending' => Mail::query()->where('status', false)->count(),
            'href' => route('admin.mail-management'),
        ];
    }

    private function calls(User $user, int $rows): array
    {
        $mine = fn () => Room::query()->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))->where('type', 'meeting');

        return [
            'thisWeek' => $mine()->where('ended_at', '>=', now()->subDays(7))->count(),
            'recent' => $rows === 2 ? $mine()->whereNotNull('ended_at')->latest('ended_at')->limit(4)->get() : collect(),
            'href' => route('calls.index'),
        ];
    }

    /**
     * Immer sichtbar, ohne eigenes Rechte-Gate (jede aktive Person darf ihre
     * eigenen Faelle sehen) - deshalb defensiv wie die beiden
     * Geraete-Snapshots: eine Umgebung ohne den Support-Baustein darf das
     * ganze Dashboard nicht mitreissen.
     */
    private function supportCases(User $user, int $rows): array
    {
        $href = route('support.cases');

        try {
            $canManage = Gate::forUser($user)->allows('support.manage');
            $base = fn () => SupportCase::query()->when(! $canManage, fn ($q) => $q->where('user_id', $user->id))->whereNull('closed_at');

            return [
                'scope' => $canManage ? 'team' : 'personal',
                'open' => $base()->count(),
                'cases' => $rows === 2 ? $base()->with('user:id,name')->latest('updated_at')->limit(4)->get() : collect(),
                'href' => $href,
            ];
        } catch (\Throwable) {
            return ['scope' => 'personal', 'open' => 0, 'cases' => collect(), 'href' => $href];
        }
    }

    private function marketing(): array
    {
        return [
            'pending' => MarketingCreative::query()->where('status', 'draft')->count(),
            'href' => route('admin.marketing.creatives.index'),
        ];
    }
}
