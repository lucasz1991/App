<?php

namespace App\Services\Operations;

use App\Models\AbsenceRequest;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\LocalExcelImport;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Dropbox\SyncContext;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Synthetic local fixtures derived from imported templates, never an Excel backfill. */
class LocalDemoDataService
{
    public const MARKER = 'Lokale Testdaten v1';

    public const TIMEZONE = 'Europe/Berlin';

    public function assertLocal(): void
    {
        $connection = DB::connection();
        $loopback = ['localhost', '127.0.0.1', '::1', '[::1]'];
        $localDatabase = in_array($connection->getDriverName(), ['mysql', 'pgsql'], true)
            && in_array($connection->getConfig('host'), $loopback, true);
        $isolatedTest = app()->environment('testing') && $connection->getDriverName() === 'sqlite'
            && $connection->getDatabaseName() === ':memory:';

        if (! app()->environment('local', 'testing')
            || ! in_array(parse_url(config('app.url'), PHP_URL_HOST), $loopback, true)
            || (! $localDatabase && ! $isolatedTest)) {
            throw new RuntimeException('Testdaten sind nur in einer lokalen App mit lokaler Datenbank erlaubt.');
        }

        OperationsAccess::requireReady();
    }

    public function generate(CarbonImmutable $anchor, User $actor, int $before = 1, int $after = 4, int $limit = 16, bool $preview = false, ?callable $progress = null): array
    {
        $this->assertLocal();
        OperationsAccess::authorize($actor, 'operations.manage');
        if ($before < 0 || $before > 4 || $after < 0 || $after > 8 || $before + $after > 12 || $limit < 1 || $limit > 32) {
            throw new RuntimeException('Erlaubt: 0–4 vergangene, 0–8 kommende Wochen, maximal 13 Wochen und 1–32 Vorlagen.');
        }

        $connections = DropboxConnection::whereIn('id', LocalExcelImport::where('status', 'done')
            ->join('dropbox_sources', 'dropbox_sources.id', '=', 'local_excel_imports.source_id')
            ->select('dropbox_sources.connection_id'))->get()->filter(fn ($c) => $c->isLocalImport());
        $sourceIds = DropboxRecord::whereIn('connection_id', $connections->modelKeys())
            ->where('model_type', 'Shift')->where('domain', 'planning')->select('model_id');
        $templates = Shift::with('order.customer')->whereIn('id', $sourceIds)
            ->whereHas('order.customer', fn ($q) => $q->where('is_active', true))
            ->whereNotNull('location_name')->orderByDesc('starts_at')->orderBy('id')->get()
            ->filter(fn ($s) => filled($s->role_name) && filled($s->location_name))
            ->unique(fn ($s) => $this->templateKey($s))->values();
        // Round robin over Excel roles rather than taking only the most frequent WGM rows.
        $groups = $templates->groupBy('role_name')->values();
        $templates = collect();
        for ($round = 0; $templates->count() < $limit; $round++) {
            $added = false;
            foreach ($groups as $group) {
                if (isset($group[$round]) && $templates->count() < $limit) {
                    $templates->push($group[$round]);
                    $added = true;
                }
            }
            if (! $added) {
                break;
            }
        }
        $employees = User::where('role', 'staff')->where('status', true)
            ->where('email', 'like', 'excel-test-%@railtime.invalid')
            ->whereIn('id', DropboxIdentity::whereIn('connection_id', $connections->modelKeys())
                ->where('kind', 'employee')->whereNotNull('user_id')->select('user_id'))
            ->orderBy('id')->get();
        if ($templates->isEmpty() || $employees->isEmpty()) {
            throw new RuntimeException('Abgeschlossene lokale Excel-Importe mit Schichtvorlagen und zugeordneten Excel-Testkonten fehlen.');
        }

        $first = $anchor->setTimezone(self::TIMEZONE)->startOfWeek()->subWeeks($before);
        $until = $anchor->setTimezone(self::TIMEZONE)->startOfWeek()->addWeeks($after + 1);
        $plan = collect();
        for ($week = $first; $week->lt($until); $week = $week->addWeek()) {
            foreach ($templates as $index => $template) {
                $number = 'TEST-'.$week->format('Ymd').'-'.$this->templateKey($template);
                $days = collect(range(0, 6))->filter(fn ($day) => $day < 5 || in_array($index % 4, [0, 3], true));
                $plan->push(compact('week', 'index', 'template', 'number', 'days'));
            }
        }
        // Deleted/edited demo orders are intentionally not recreated or overwritten.
        $existing = Order::withTrashed()->whereIn('order_number', $plan->pluck('number'))->pluck('order_number')->all();
        $pending = $plan->reject(fn ($item) => in_array($item['number'], $existing, true));
        $result = [
            'marker' => self::MARKER, 'anchor' => $anchor->toDateString(),
            'from' => $first->toDateString(), 'until' => $until->subDay()->toDateString(),
            'timezone' => self::TIMEZONE, 'templates' => $templates->count(), 'employees_available' => $employees->count(),
            'orders_planned' => $pending->count(), 'shifts_planned' => $pending->sum(fn ($item) => $item['days']->count()),
            'orders_skipped' => count($existing), 'orders_created' => 0, 'shifts_created' => 0,
            'assignments_created' => 0, 'absences_created' => 0, 'time_entries_created' => 0,
            'unfilled_slots' => 0, 'rule_profile_created' => false, 'order_ids' => [], 'assignment_issues' => [],
        ];
        if ($preview || $pending->isEmpty()) {
            return $result;
        }

        // Suppress the durable external-sync outbox while keeping native audits and validation.
        return SyncContext::import(fn () => OperationsTransaction::run(function () use ($pending, $first, $until, $employees, $actor, $progress, $result) {
            // Serializes CLI generators and prevents two callers creating the same weekly fixtures.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->assertLocal();
            if (! OperationsRuleProfile::where('is_active', true)->exists()) {
                $profile = OperationsRuleProfile::create([
                    'name' => 'Lokale Planungsregeln', 'minimum_rest_minutes' => 660,
                    'maximum_shift_minutes' => 600, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30,
                    'is_active' => true, 'created_by' => $actor->id, 'approved_at' => now()->utc(),
                ]);
                app(OperationsAuditService::class)->record($profile, $actor, 'demo.rules.created');
                $result['rule_profile_created'] = true;
            }
            $result['absences_created'] = $this->absences($first, $until, $employees, $actor);
            $actualNow = CarbonImmutable::now(self::TIMEZONE);
            foreach ($pending as $item) {
                if (Order::withTrashed()->where('order_number', $item['number'])->exists()) {
                    $result['orders_skipped']++;

                    continue;
                }
                $template = $item['template'];
                $order = app(OrderSchedulingService::class)->save(new Order, [
                    'order_number' => $item['number'], 'customer_id' => $template->order->customer_id,
                    'title' => $template->location_name.' · '.$template->role_name,
                    'service_type' => $template->order->service_type ?: $template->role_name,
                    'description' => self::MARKER.' – aus importierter Excel-Vorlage abgeleitet.',
                    'status' => 'confirmed', 'priority' => $item['index'] % 7 === 0 ? 'high' : 'normal',
                    'starts_at' => $item['week'], 'ends_at' => $item['week']->addWeek()->addHours(8),
                    'timezone' => self::TIMEZONE, 'location_name' => $template->location_name,
                    'required_staff' => $item['index'] % 5 === 0 ? 2 : 1,
                    'requirements' => ['local_demo' => true, 'source_shift_id' => $template->id],
                    'notes' => self::MARKER.'; synthetische Dienste, keine Übernahme von Qualifikationsfreigaben.',
                ], $actor);
                app(OrderLifecycleService::class)->transition($order, 'planned', $actor, self::MARKER);
                $result['order_ids'][] = $order->id;
                $result['orders_created']++;
                foreach ($item['days'] as $day) {
                    $date = $item['week']->addDays($day);
                    $this->shift($order, $template, $date, $item['index'], $employees, $actor, $actualNow, $result);
                }
                $unfinished = $order->shifts()->whereNotIn('status', ['completed', 'cancelled'])->exists();
                $status = $order->ends_at->lt($actualNow) && ! $unfinished ? 'completed'
                    : ($order->starts_at->lte($actualNow) ? 'in_progress' : 'planned');
                if ($status !== 'planned') {
                    app(OrderLifecycleService::class)->transition($order->fresh(), 'in_progress', $actor, self::MARKER);
                    if ($status === 'completed') {
                        app(OrderLifecycleService::class)->transition($order->fresh(), 'completed', $actor, self::MARKER);
                    }
                }
                $progress && $progress($result['orders_created'], $pending->count());
            }

            return $result;
        }));
    }

    private function templateKey(Shift $shift): string
    {
        return substr(hash('sha256', $shift->order->customer_id.'|'.$shift->location_name.'|'.$shift->role_name), 0, 12);
    }

    private function absences(CarbonImmutable $first, CarbonImmutable $until, Collection $employees, User $actor): int
    {
        $count = 0;
        for ($week = $first, $i = 0; $week->lt($until); $week = $week->addWeek(), $i++) {
            foreach (['vacation', 'unavailable'] as $kindIndex => $kind) {
                $user = $employees[($i * 7 + $kindIndex * 3) % $employees->count()];
                $start = $week->addDays($kindIndex === 0 ? 1 : 4);
                $end = $start->addDays($kindIndex === 0 ? 3 : 1);
                if (AbsenceRequest::where('user_id', $user->id)->whereIn('status', ['pending', 'approved'])
                    ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())->exists()
                    || ShiftAssignment::blocking()->where('user_id', $user->id)->whereHas('shift', fn ($q) => $q->notCancelled()->during($start, $end))->exists()) {
                    continue;
                }
                $request = app(PersonnelWorkflowService::class)->requestAbsence($user, [
                    'kind' => $kind, 'starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $end->format('Y-m-d\TH:i'),
                    'timezone' => self::TIMEZONE, 'note' => self::MARKER.' · '.$week->toDateString(),
                ]);
                if ($kind === 'vacation') {
                    app(PersonnelWorkflowService::class)->absence($request, $request->revision, 'approve', self::MARKER, $actor);
                }
                $count++;
            }
        }

        return $count;
    }

    private function shift(Order $order, Shift $template, CarbonImmutable $date, int $index, Collection $employees, User $actor, CarbonImmutable $actualNow, array &$result): void
    {
        $hour = [6, 8, 14, 22][$index % 4];
        $start = $date->setTime($hour, 0);
        $minutes = max(120, min(480, (int) $template->starts_at->diffInMinutes($template->ends_at)));
        // Eight-hour overnight duties exercise date boundaries, including October's DST change.
        if ($hour === 22) {
            $minutes = 480;
        }
        $end = $start->addMinutes($minutes);
        $break = $minutes > 360 ? 30 : 0;
        $scenario = ($index + $date->dayOfYear) % 12;
        $past = $end->lte($actualNow);
        $required = $index % 5 === 0 || $scenario === 2 ? 2 : 1;
        $draft = $scenario === 10;
        $cancelled = $scenario === 11;
        $label = $hour === 22 ? 'Nachtdienst' : ($hour >= 14 ? 'Spätdienst' : 'Frühdienst');
        $shift = app(ShiftSchedulingService::class)->save(new Shift, [
            'order_id' => $order->id, 'title' => $template->location_name.' · '.$label,
            'role_name' => $template->role_name, 'starts_at' => $start, 'ends_at' => $end,
            'timezone' => self::TIMEZONE, 'location_name' => $template->location_name,
            'required_staff' => $required, 'planned_break_minutes' => $break,
            'status' => $cancelled ? 'cancelled' : ($draft ? 'draft' : 'open'), 'notes' => self::MARKER,
            'disposition_details' => ['train_reference' => 'TEST-'.$date->format('md').'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'information' => self::MARKER, 'local_demo' => ['version' => 1, 'source_shift_id' => $template->id, 'scenario' => $scenario]],
        ], $actor);
        $result['shifts_created']++;
        if ($cancelled || $draft || $scenario === 0) {
            $result['unfilled_slots'] += $cancelled ? 0 : $required;

            return;
        }

        $target = $scenario === 2 ? 1 : $required;
        $assigned = collect();
        $offset = ($index * 3 + $date->dayOfYear) % $employees->count();
        // Native eligibility enforces imported restrictions, absences, overlaps, breaks and rest.
        foreach (range(0, $employees->count() - 1) as $step) {
            $user = $employees[($offset + $step) % $employees->count()];
            $issues = app(StaffEligibilityService::class)->assessMany($shift, collect([$user]))[$user->id];
            if ($issues !== []) {
                foreach ($issues as $issue) {
                    $result['assignment_issues'][$issue['code']] = ($result['assignment_issues'][$issue['code']] ?? 0) + 1;
                }

                continue;
            }
            $assignment = app(ShiftAssignmentService::class)->assign($shift, $user, $actor, 'requested', self::MARKER);
            $assigned->push($assignment);
            $result['assignments_created']++;
            if ($assigned->count() >= $target) {
                break;
            }
        }
        $decline = ! $past && $scenario === 3;
        $requested = ! $past && $scenario === 1;
        $status = $assigned->isEmpty() || $assigned->count() < $required || $decline ? 'open' : ($requested ? 'requested' : 'confirmed');
        if ($status === 'confirmed' && $start->lte($actualNow) && $end->gt($actualNow)) {
            $status = 'in_progress';
        }
        $shift = app(ShiftSchedulingService::class)->save($shift->fresh(), ['status' => $status], $actor);
        // Historic fixtures replay publication before the duty. The process clock is restored in finally.
        $publish = function () use ($shift, $assigned, $requested, $decline) {
            app(PlanPublicationService::class)->publish($shift, $shift->revision, User::findOrFail($shift->created_by));
            foreach ($assigned as $assignment) {
                if (! $requested) {
                    app(PlanPublicationService::class)->respond($assignment->id, $shift->revision, ! $decline, $assignment->user);
                }
            }
        };
        CarbonImmutable::withTestNow($start->lt($actualNow) ? $start->subDay() : $actualNow, $publish);
        $result['unfilled_slots'] += $decline ? $required : max(0, $required - $assigned->count());

        if ($status === 'in_progress') {
            $assignment = $assigned->first()->fresh();
            $entry = CarbonImmutable::withTestNow($start, fn () => app(WorkTimeService::class)->start($assignment->id, $shift->revision, (string) Str::uuid(), $assignment->user));
            if ($index % 2 === 0) {
                app(WorkTimeService::class)->clock($entry->id, $entry->revision, 'pause', (string) Str::uuid(), $assignment->user);
            }
            $result['time_entries_created']++;
        }

        if ($past && $assigned->isNotEmpty()) {
            // Sample completed, submitted and approved times through the normal employee workflow.
            if ($scenario === 4) {
                $assignment = $assigned->first()->fresh();
                $entry = app(WorkTimeService::class)->manual($assignment->id, $shift->revision, [
                    'starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $end->format('Y-m-d\TH:i'),
                    'pause_minutes' => $break, 'note' => self::MARKER,
                ], (string) Str::uuid(), $assignment->user);
                if ($date->day % 3 !== 0) {
                    app(WorkTimeService::class)->clock($entry->id, $entry->revision, 'submit', (string) Str::uuid(), $assignment->user);
                    if ($date->day % 3 === 2) {
                        $entry->refresh();
                        app(WorkTimeService::class)->review($entry->id, $entry->revision, true, self::MARKER, $actor);
                    }
                }
                $result['time_entries_created']++;
            }
            // Terminal synthetic history keeps the published interval/revision unchanged.
            // This is fixture initialization, not an edit to an existing employee's released plan.
            $shift->forceFill(['status' => 'completed'])->save();
            app(OperationsAuditService::class)->record($shift, $actor, 'demo.history.completed');
        }
    }
}
