<?php

namespace App\Support\Dashboard;

use App\Enums\MarketingCreativeType;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeQualification;
use App\Models\Mail;
use App\Models\MarketingCreative;
use App\Models\OperationInquiry;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Room;
use App\Models\Shift;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\DeviceManagement\DeviceFleetSnapshot;
use App\Services\DeviceManagement\PersonalDeviceSnapshot;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\PersonalSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
    public function data(string $key, User $user, string $size, int $rows = 1, ?string $date = null): array
    {
        return match ($key) {
            'my_work' => $this->myWork($user),
            'wagon_list' => $this->wagonList($user),
            'messages' => $this->messages($user, $rows),
            'files' => $this->files($user, $rows),
            'my_devices' => $this->myDevices($user),
            'profile_completion' => $this->profileCompletion($user),
            'operations_inquiries' => $this->operationsInquiries($rows),
            'operations_orders' => $this->operationsOrders($rows),
            'operations_dispatch_map' => app(DispatchMapData::class)->forUser($user, $date),
            'operations_shift_coverage' => $this->operationsShiftCoverage(),
            'operations_next_shifts' => $this->operationsNextShifts($rows),
            'operations_customers' => $this->operationsCustomers($rows),
            'operations_qualifications' => $this->operationsQualifications($rows),
            'operations_absences' => $this->operationsAbsences($rows),
            'operations_times' => $this->operationsTimes($rows),
            'operations_rules' => $this->operationsRules(),
            'fleet_devices' => $this->fleetDevices($user),
            'employees' => $this->employees($user, $rows),
            'recent_activity' => $this->recentActivity($rows, $size),
            'account_growth' => $this->accountGrowth(),
            'mail_management' => $this->mailManagement($rows),
            'calls' => $this->calls($user, $rows),
            'support_cases' => $this->supportCases($user, $rows),
            'marketing' => $this->marketing($rows),
            'system_status' => [],
            default => [],
        };
    }

    private function myWork(User $user): array
    {
        $activeTime = WorkTimeEntry::where('user_id', $user->id)->whereIn('status', ['running', 'paused'])->first();
        $nextAssignment = app(PersonalSchedule::class)
            ->assignments($user, CarbonImmutable::now('UTC'), null)
            ->first();

        return [
            'activeTime' => $activeTime,
            'nextAssignment' => $nextAssignment,
            'href' => route('operations.mine'),
            'calendarHref' => route('operations.mine', ['tab' => 'schedule']),
        ];
    }

    private function wagonList(User $user): array
    {
        // Zaehlung passiert clientseitig aus localStorage (siehe wagon_list.blade.php)
        // - der Kopf kann hier also keinen datenabhaengigen Zustand zeigen.
        return ['tone' => 'neutral', 'href' => route($user->isAdmin() ? 'admin.operations.wagon-list' : 'operations.wagon-list')];
    }

    private function messages(User $user, int $rows): array
    {
        $unread = $user->receivedMessages()->where('status', 1)->count();
        $latest = $rows === 2
            ? $user->receivedMessages()->with('sender:id,name')->latest()->limit(4)->get()
            : collect();

        return ['unread' => $unread, 'latest' => $latest, 'tone' => $unread === 0 ? 'ok' : 'warn']
            + $this->dailySparkline(fn () => $user->receivedMessages())
            + ['href' => route('messages')];
    }

    private function files(User $user, int $rows): array
    {
        $grouped = $user->availableFilesGrouped();
        $teamFiles = collect($grouped['teams'])->flatMap(fn (array $entry) => $entry['files']);
        $all = $grouped['personal']->merge($grouped['company'])->merge($teamFiles)->unique('id');

        $byType = $all->countBy(fn ($file) => self::fileCategory($file->mime_type, $file->name));

        return [
            'total' => $all->count(),
            'byType' => collect(['pdf', 'doc', 'sheet', 'image', 'other'])
                ->mapWithKeys(fn (string $type) => [$type => (int) ($byType[$type] ?? 0)])
                ->filter()
                ->all(),
            'totalBytes' => (int) $all->sum('size'),
            'recent' => $rows === 2 ? $all->sortByDesc('created_at')->take(4)->values() : collect(),
            'tone' => 'brand',
            'href' => route('files'),
        ];
    }

    /** Grobe Dateiart fuer die Typ-Chips im Ablage-Widget (MIME zuerst, Endung als Rueckfall). */
    public static function fileCategory(?string $mime, ?string $name): string
    {
        $mime = strtolower((string) $mime);
        $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        return match (true) {
            $mime === 'application/pdf' || $extension === 'pdf' => 'pdf',
            str_starts_with($mime, 'image/') => 'image',
            str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel') || in_array($extension, ['xls', 'xlsx', 'csv', 'ods'], true) => 'sheet',
            str_contains($mime, 'word') || str_contains($mime, 'document') || str_starts_with($mime, 'text/') || in_array($extension, ['doc', 'docx', 'odt', 'txt', 'rtf'], true) => 'doc',
            default => 'other',
        };
    }

    private function myDevices(User $user): array
    {
        $stats = $this->personalDeviceSnapshot->get($user);

        return [
            'stats' => $stats,
            'tone' => $stats['available'] ? ($stats['blocked'] > 0 ? 'warn' : 'ok') : null,
            'href' => $stats['available'] ? route('devices.mine') : null,
        ];
    }

    private function fleetDevices(User $user): array
    {
        $stats = $this->fleetSnapshot->get();

        return [
            'stats' => $stats,
            'utilization' => $stats['total'] > 0 ? (int) round($stats['assigned'] / $stats['total'] * 100) : 0,
            'tone' => $stats['available'] ? ($stats['attention'] > 0 ? 'warn' : 'ok') : null,
            'href' => $stats['available'] ? route($user->isAdmin() ? 'admin.devices' : 'devices.index') : null,
        ];
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

    /**
     * Gemeinsame Form fuer die vier Cockpit-Warteschlangen (dieselben Zahlen
     * wie Cockpit.php). Bei zwei Zeilen Hoehe zeigt jede Warteschlange
     * zusaetzlich ihre vier juengsten Eintraege - $describe formt je Modell
     * die konkrete Zeile (Titel/Nebeninfo/Zeitpunkt), da die vier Abfragen
     * unterschiedliche Spalten liefern.
     */
    private function operationsQueue(string $slug, string $label, string $icon, Builder $query, int $rows, \Closure $describe): array
    {
        $count = (clone $query)->count();

        return [
            'label' => $label,
            'icon' => $icon,
            'count' => $count,
            'tone' => $count === 0 ? 'ok' : 'warn',
            'items' => $rows === 2 ? (clone $query)->latest()->limit(4)->get()->map($describe) : collect(),
            'href' => \App\Support\Operations\OperationsPages::moduleUrl($slug),
        ];
    }

    /**
     * Anfragen veralten schnell - entscheidend ist deshalb nicht nur WIE
     * viele offen sind, sondern wie lange schon: drei Altersstufen
     * (unter 24 h / 1-3 Tage / ueber 3 Tage) als Ampel.
     */
    private function operationsInquiries(int $rows): array
    {
        $open = fn () => OperationInquiry::query()->whereNull('order_id')->whereNull('duplicate_of_id');
        $dayAgo = now()->subDay();
        $threeDaysAgo = now()->subDays(3);

        $data = $this->operationsQueue(
            'inquiries', 'Offene Anfragen', 'inbox', $open()->with('customer:id,company_name'), $rows,
            fn (OperationInquiry $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'meta' => $i->customer?->company_name,
                'when' => $i->created_at,
                'channel' => $i->channel,
                'age' => $i->created_at === null ? 'fresh' : ($i->created_at->lt($threeDaysAgo) ? 'overdue' : ($i->created_at->lt($dayAgo) ? 'aging' : 'fresh')),
                'href' => \App\Support\Operations\OperationsPages::moduleUrl('inquiries', ['inquiry' => $i->id, 'customer' => $i->customer_id]),
            ],
        );
        $oldest = $open()->min('created_at');

        return $data + [
            'ages' => [
                'fresh' => $open()->where('created_at', '>=', $dayAgo)->count(),
                'aging' => $open()->where('created_at', '<', $dayAgo)->where('created_at', '>=', $threeDaysAgo)->count(),
                'overdue' => $open()->where('created_at', '<', $threeDaysAgo)->count(),
            ],
            'oldest' => $oldest ? CarbonImmutable::parse($oldest) : null,
        ];
    }

    /**
     * Neben der Pruef-Warteschlange zeigt das Widget, welche bereits
     * genehmigten Nachweise in den naechsten 60 Tagen ablaufen - genau die
     * Faelle, um die sich die Verwaltung VOR dem Ablauf kuemmern muss.
     */
    private function operationsQualifications(int $rows): array
    {
        $data = $this->operationsQueue(
            'qualifications', 'Nachweise prüfen', 'award',
            EmployeeQualification::where('status', 'pending')->with(['user:id,name', 'type:id,name']), $rows,
            fn (EmployeeQualification $q) => ['title' => $q->user?->name, 'meta' => $q->type?->name, 'when' => $q->created_at],
        );

        $today = now(config('operations.display_timezone'))->startOfDay();
        $horizon = $today->copy()->addDays(60);
        $approved = fn () => EmployeeQualification::query()->where('status', 'approved');
        $expiringQuery = fn () => $approved()->whereDate('valid_until', '>=', $today->toDateString())->whereDate('valid_until', '<=', $horizon->toDateString());
        $expired = $approved()->whereDate('valid_until', '<', $today->toDateString())->count();

        return array_merge($data, [
            'tone' => $data['count'] === 0 && $expired === 0 ? 'ok' : 'warn',
            'expiringCount' => $expiringQuery()->count(),
            'expiredCount' => $expired,
            'expiring' => $expiringQuery()->with(['user:id,name', 'type:id,name'])->orderBy('valid_until')->limit(3)->get()
                ->map(fn (EmployeeQualification $q) => [
                    'name' => $q->user?->name,
                    'type' => $q->type?->name,
                    'daysLeft' => (int) $today->diffInDays($q->valid_until->startOfDay(), false),
                ]),
        ]);
    }

    /**
     * Abwesenheiten der naechsten 14 Tage als Tagesstreifen (genehmigt +
     * beantragt, je Tag eindeutige Personen) - zeigt Engpaesse, bevor die
     * offenen Antraege entschieden werden.
     */
    private function operationsAbsences(int $rows): array
    {
        $kinds = ['vacation' => 'Urlaub', 'unavailable' => 'Nicht verfügbar', 'other' => 'Abwesenheit'];
        $tz = config('operations.display_timezone');
        $format = fn ($value) => CarbonImmutable::parse($value, 'UTC')->setTimezone($tz)->format('d.m.');

        $data = $this->operationsQueue(
            'absences', 'Abwesenheiten prüfen', 'calendar',
            AbsenceRequest::where('status', 'pending')->with('user:id,name'), $rows,
            fn (AbsenceRequest $a) => [
                'title' => $a->user?->name,
                'meta' => $kinds[$a->kind] ?? 'Abwesenheit',
                'when' => $a->created_at,
                'range' => $format($a->starts_at).'–'.$format($a->ends_at),
            ],
        );

        $start = CarbonImmutable::now($tz)->startOfDay();
        $end = $start->addDays(14);
        $absences = AbsenceRequest::query()->whereIn('status', ['approved', 'pending'])
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())
            ->get(['user_id', 'starts_at', 'ends_at'])
            ->map(fn (AbsenceRequest $a) => [
                'user_id' => $a->user_id,
                'from' => CarbonImmutable::parse($a->starts_at, 'UTC'),
                'until' => CarbonImmutable::parse($a->ends_at, 'UTC'),
            ]);

        $strip = collect(range(0, 13))->map(function (int $offset) use ($start, $absences) {
            $day = $start->addDays($offset);
            $next = $day->addDay();

            return [
                'weekday' => mb_substr($day->translatedFormat('D'), 0, 2),
                'date' => $day->format('d.m.'),
                'count' => $absences->filter(fn (array $a) => $a['from']->lt($next) && $a['until']->gt($day))->unique('user_id')->count(),
                'today' => $offset === 0,
                'weekend' => $day->isWeekend(),
            ];
        })->all();

        return $data + [
            'strip' => $strip,
            // Mindestmassstab 3: eine einzelne Abwesenheit soll nicht wie ein
            // Spitzentag in voller Signalfarbe aussehen.
            'stripMax' => max(3, max(array_column($strip, 'count'))),
            'absentToday' => $strip[0]['count'],
        ];
    }

    /**
     * Statt einer reinen Anzahl Meldungen: wie viele Stunden warten auf
     * Freigabe und bei wem - die Groessenordnung entscheidet, wer zuerst
     * geprueft werden sollte (Lohnlauf, Abrechnung).
     */
    private function operationsTimes(int $rows): array
    {
        $pending = fn () => WorkTimeEntry::query()->where('status', 'submitted');

        $data = $this->operationsQueue(
            'times', 'Zeiten prüfen', 'check-circle', $pending()->with('user:id,name'), $rows,
            fn (WorkTimeEntry $t) => ['title' => $t->user?->name, 'meta' => OperationsDateTime::duration($t->netSeconds()), 'when' => $t->submitted_at ?? $t->created_at],
        );

        $entries = $pending()->with('user:id,name,profile_photo_path')->limit(500)->get();
        $people = $entries->groupBy('user_id')
            ->map(fn ($group) => [
                'user' => $group->first()->user,
                'seconds' => $group->sum(fn (WorkTimeEntry $entry) => $entry->netSeconds()),
                'entries' => $group->count(),
            ])
            ->sortByDesc('seconds')
            ->values();
        $oldest = $entries->map(fn (WorkTimeEntry $entry) => $entry->submitted_at ?? $entry->created_at)->filter()->min();

        return $data + [
            'totalSeconds' => (int) $people->sum('seconds'),
            'people' => $people->take($rows === 2 ? 5 : 3)->values(),
            'peopleMax' => max(1, (int) ($people->max('seconds') ?? 0)),
            'oldest' => $oldest,
        ];
    }

    private function operationsOrders(int $rows): array
    {
        $open = Order::query()->whereNotIn('status', ['completed', 'invoiced', 'cancelled']);
        $byStatus = (clone $open)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $recent = $rows === 2 ? (clone $open)->with('customer:id,company_name')->orderBy('starts_at')->limit(3)->get() : collect();

        return [
            'label' => 'Offene Leistungen',
            'count' => (clone $open)->count(),
            'byStatus' => $byStatus,
            'startingSoon' => (clone $open)->where('starts_at', '>=', now())->where('starts_at', '<=', now()->addDays(7))->count(),
            'urgent' => (clone $open)->whereIn('priority', ['high', 'urgent'])->count(),
            'tone' => 'brand',
            'recent' => $recent,
            'orderHrefs' => $recent->mapWithKeys(fn (Order $order) => [$order->id => \App\Support\Operations\OperationsPages::moduleUrl('orders', ['order' => $order->id, 'customer' => $order->customer_id])]),
            'href' => \App\Support\Operations\OperationsPages::moduleUrl('orders'),
        ];
    }

    private function operationsShiftCoverage(): array
    {
        $today = now(config('operations.display_timezone'))->startOfDay();
        $shifts = Shift::notCancelled()->during($today, $today->copy()->addDays(7))
            ->withCount(['assignments as reserved' => fn ($q) => $q->blocking()])
            ->get();
        $days = collect(range(0, 6))->map(fn (int $offset) => $today->copy()->addDays($offset));
        $byDate = $shifts->groupBy(fn (Shift $shift) => $shift->starts_at->setTimezone(config('operations.display_timezone'))->toDateString());

        $required = $days->map(fn ($day) => (int) $byDate->get($day->toDateString(), collect())->sum('required_staff'))->values()->all();
        $reserved = $days->map(fn ($day) => (int) $byDate->get($day->toDateString(), collect())->sum('reserved'))->values()->all();
        // Ueberbesetzung einer Schicht gleicht keine Luecke einer anderen aus.
        $covered = (int) $shifts->sum(fn (Shift $shift) => min((int) $shift->reserved, (int) $shift->required_staff));
        $needed = (int) $shifts->sum('required_staff');

        return [
            'labels' => $days->map(fn ($day) => $day->translatedFormat('D d.'))->values()->all(),
            'weekdays' => $days->map(fn ($day) => mb_substr($day->translatedFormat('D'), 0, 2))->values()->all(),
            'required' => $required,
            'reserved' => $reserved,
            'coverage' => $needed > 0 ? (int) floor($covered / $needed * 100) : null,
            'openSlots' => $needed - $covered,
            'shiftCount' => $shifts->count(),
            'criticalDays' => collect($required)->filter(fn (int $need, int $i) => $need > 0 && $reserved[$i] < $need)->count(),
            'tone' => $needed === 0 ? 'neutral' : ($covered >= $needed ? 'ok' : 'warn'),
            'href' => \App\Support\Operations\OperationsPages::moduleUrl('shift-management'),
        ];
    }

    private function operationsNextShifts(int $rows): array
    {
        // Laufende und kommende Dienste - nicht alles ab Mitternacht, sonst
        // stuenden bereits beendete Nachtdienste von gestern ganz oben.
        $shifts = Shift::notCancelled()
            ->upcoming()
            ->where('starts_at', '<', now()->addDays(14)->utc())
            ->with(['order.customer'])
            ->withCount(['assignments as reserved' => fn ($q) => $q->blocking()])
            ->orderBy('starts_at')
            ->limit($rows === 2 ? 5 : 2)
            ->get();

        return [
            'shifts' => $shifts,
            'shiftHrefs' => $shifts->mapWithKeys(fn (Shift $shift) => [$shift->id => \App\Support\Operations\OperationsPages::moduleUrl('shift-management', ['shift' => $shift->id])]),
            'understaffed' => $shifts->filter(fn (Shift $shift) => $shift->reserved < $shift->required_staff)->count(),
            'href' => \App\Support\Operations\OperationsPages::moduleUrl('shift-management'),
        ];
    }

    /**
     * Rangliste nach laufendem Geschaeft (offene Leistungen je Kunde) statt
     * der juengsten Anlage - die wichtigsten Kunden stehen oben.
     */
    private function operationsCustomers(int $rows): array
    {
        $active = Customer::query()->active()->count();
        $total = Customer::query()->count();
        $closed = ['completed', 'invoiced', 'cancelled'];

        $top = Customer::query()->active()
            ->select(['id', 'company_name', 'city'])
            ->withCount(['orders as open_orders' => fn ($q) => $q->whereNotIn('status', $closed)])
            ->withCount('orders')
            ->orderByDesc('open_orders')->orderByDesc('orders_count')->orderBy('company_name')
            ->limit($rows === 2 ? 5 : 1)
            ->get();

        return [
            'active' => $active,
            'total' => $total,
            'inactive' => $total - $active,
            'newRecently' => Customer::query()->where('created_at', '>=', now()->subDays(30))->count(),
            'top' => $top,
            'topMax' => max(1, (int) ($top->max('open_orders') ?? 0)),
            'tone' => 'brand',
            'href' => \App\Support\Operations\OperationsPages::moduleUrl('customers'),
        ];
    }

    private function operationsRules(): array
    {
        $profile = OperationsRuleProfile::where('is_active', true)->first();

        return ['profile' => $profile, 'href' => \App\Support\Operations\OperationsPages::moduleUrl('rules')];
    }

    private function employees(User $user, int $rows): array
    {
        $snapshot = User::query()->where('role', 'staff')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as active')
            ->first();

        $total = (int) ($snapshot->total ?? 0);
        $active = (int) ($snapshot->active ?? 0);

        $staff = fn () => User::query()->where('role', 'staff');

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'activePct' => $total > 0 ? (int) round($active / $total * 100) : 0,
            'newRecently' => $staff()->where('created_at', '>=', now()->subDays(30))->count(),
            'faces' => $staff()->where('status', true)->latest()->limit(6)->get(['id', 'name', 'profile_photo_path']),
            'tone' => 'brand',
            'recent' => $rows === 2 ? $staff()->latest()->limit(3)->get(['id', 'name', 'created_at', 'profile_photo_path']) : collect(),
            'href' => route($user->isAdmin() ? 'admin.employees' : 'employees.index'),
        ];
    }

    private function recentActivity(int $rows, string $size): array
    {
        return [
            // 1 Zeile = Avatar-Reihe: so viele, wie nebeneinander passen.
            'entries' => $this->systemDashboardData->recentActivity($rows === 2 ? 6 : ($size === 'lg' ? 9 : 4)),
            'online' => $this->systemDashboardData->activeUsersSince(now()->subMinutes(5)),
            'today' => $this->systemDashboardData->activeUsersSince(now()->startOfDay()),
        ];
    }

    private function accountGrowth(): array
    {
        $charts = $this->systemDashboardData->charts();
        $growth = $charts['userGrowth'] ?? ['labels' => [], 'totals' => []];

        return [
            'labels' => $growth['labels'] ?? [],
            'values' => $growth['totals'] ?? [],
            'total' => (int) (collect($growth['totals'] ?? [])->last() ?? 0),
            'delta' => (int) array_sum($growth['registrations'] ?? []),
        ];
    }

    /**
     * Neben dem Rueckstand die Zustellbilanz der letzten 7 Tage: Anteil der
     * Empfaenger, die tatsaechlich versorgt wurden (recipients[].status, wie
     * ProcessMailJob ihn setzt).
     */
    private function mailManagement(int $rows): array
    {
        $pending = Mail::query()->where('status', false);
        $count = (clone $pending)->count();
        $week = Mail::query()->where('created_at', '>=', now()->subDays(7))->get(['id', 'status', 'recipients']);
        $recipients = $week->flatMap(fn (Mail $mail) => $mail->recipients ?? []);
        $reached = $recipients->filter(fn ($recipient) => (bool) ($recipient['status'] ?? false))->count();

        return [
            'pending' => $count,
            'sentWeek' => $week->where('status', true)->count(),
            'recipientsWeek' => $recipients->count(),
            'reachRate' => $recipients->isNotEmpty() ? (int) floor($reached / $recipients->count() * 100) : null,
            'tone' => $count === 0 ? 'ok' : 'warn',
            'recent' => $rows === 2 ? (clone $pending)->latest()->limit(4)->get() : collect(),
            'href' => route('admin.mail-management'),
        ];
    }

    /**
     * Jeder Anruf der letzten 7 Tage als Punkt auf seinem Tag (statt eines
     * weiteren Balkendiagramms) plus die tatsaechliche Gespraechszeit.
     */
    private function calls(User $user, int $rows): array
    {
        $mine = fn () => Room::query()->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))->where('type', 'meeting');
        $since = now()->subDays(6)->startOfDay();
        $week = $mine()->whereNotNull('ended_at')->where('ended_at', '>=', $since)->get(['id', 'started_at', 'ended_at']);
        $days = collect(range(6, 0))->map(fn (int $daysAgo) => now()->subDays($daysAgo));
        $byDay = $week->countBy(fn (Room $room) => $room->ended_at->toDateString());

        return [
            'thisWeek' => $mine()->where('ended_at', '>=', now()->subDays(7))->count(),
            'talkSeconds' => (int) $week->sum(fn (Room $room) => $room->started_at ? max(0, (int) $room->started_at->diffInSeconds($room->ended_at)) : 0),
            'dots' => $days->map(fn ($day) => [
                'label' => mb_substr($day->translatedFormat('D'), 0, 2),
                'count' => (int) ($byDay[$day->toDateString()] ?? 0),
                'today' => $day->isToday(),
            ])->values()->all(),
            'tone' => 'brand',
            'recent' => $rows === 2 ? $mine()->whereNotNull('ended_at')->latest('ended_at')->limit(3)->get() : collect(),
            'href' => route('calls.index'),
        ];
    }

    /**
     * Tageszaehlung der letzten $days Tage (heute eingeschlossen), fuer die
     * kleinen Sparkline-Diagramme. $query liefert je Aufruf frisch eine neue
     * Query (Relations wie receivedMessages() koennen nicht sicher geklont
     * werden), damit dieselbe Basis hier unabhaengig von deren sonstiger
     * Verwendung weitergefiltert werden kann.
     *
     * @return array{sparkline: array<int, int>, sparklineLabels: array<int, string>}
     */
    private function dailySparkline(\Closure $query, string $column = 'created_at', int $days = 7): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $byDay = $query()->where($column, '>=', $since)
            ->selectRaw("DATE({$column}) as day, COUNT(*) as aggregate")
            ->groupBy('day')->pluck('aggregate', 'day');
        $range = collect(range($days - 1, 0))->map(fn (int $daysAgo) => now()->subDays($daysAgo));

        return [
            'sparkline' => $range->map(fn ($day) => (int) ($byDay[$day->toDateString()] ?? 0))->values()->all(),
            'sparklineLabels' => $range->map(fn ($day) => $day->translatedFormat('d.m.'))->values()->all(),
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
            $open = $base()->count();

            return [
                'scope' => $canManage ? 'team' : 'personal',
                'open' => $open,
                'unassigned' => $canManage ? $base()->whereNull('assigned_to')->count() : null,
                'tone' => $open === 0 ? 'ok' : 'warn',
                'byStatus' => $base()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status'),
                'cases' => $rows === 2 ? $base()->with('user:id,name')->latest('updated_at')->limit(4)->get() : collect(),
                'href' => $href,
            ];
        } catch (\Throwable) {
            return ['scope' => 'personal', 'open' => 0, 'unassigned' => null, 'tone' => 'ok', 'byStatus' => collect(), 'cases' => collect(), 'href' => $href];
        }
    }

    private function marketing(int $rows): array
    {
        $pending = MarketingCreative::query()->where('status', 'draft');
        $byType = (clone $pending)->selectRaw('type, COUNT(*) as aggregate')->groupBy('type')->pluck('aggregate', 'type');
        $count = (clone $pending)->count();

        return [
            'pending' => $count,
            'tone' => $count === 0 ? 'ok' : 'warn',
            'byType' => $byType,
            'approvedRecently' => MarketingCreative::query()->where('status', 'approved')->where('approved_at', '>=', now()->subDays(30))->count(),
            'recent' => $rows === 2 ? (clone $pending)->latest()->limit(4)->get(['id', 'title', 'type', 'created_at'])->map(fn (MarketingCreative $creative) => [
                'title' => $creative->title,
                'typeLabel' => $creative->type === MarketingCreativeType::Job ? 'Stellenanzeige' : 'Info-Motiv',
                'when' => $creative->created_at,
            ]) : collect(),
            'href' => route('admin.marketing.creatives.index'),
        ];
    }
}
