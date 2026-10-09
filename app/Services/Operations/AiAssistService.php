<?php

namespace App\Services\Operations;

use App\Enums\OrderStatus;
use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Livewire\Operations\AiIntakeInbox;
use App\Models\AbsenceRequest;
use App\Models\AiIntake;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * AI-Assist der Disposition: beantwortet Fragen und Aktionen aus den echten Planungsdiensten.
 *
 * Bewusst ohne Sprachmodell: Live- und Personaldaten verlassen den Server nicht (Plattformregel des
 * RailTime-Assistenten). Jede Antwort ist eine nachvollziehbare Auswertung; schreibende Schritte
 * (Einteilen, Zurücknehmen) laufen über den ShiftAssignmentService mit Sperren und Revisionsprüfung.
 */
class AiAssistService
{
    public const NOTE = 'AI-Assist-Vorschlag';

    /** Schlüssel => [Symbol, Titel] – Details hängen vom Kontext ab. */
    private const ACTIONS = [
        'period' => ['fa-sparkles', 'Besetzung für den Zeitraum vorschlagen'],
        'open-today' => ['fa-clock', 'Heute offene Dienste erklären'],
        'workload' => ['fa-tachometer-alt', 'Auslastung ausgleichen'],
        'without' => ['fa-clipboard-list', 'Leistungen ohne Schichten'],
        'summary' => ['fa-list-ul', 'Neue Anfragen zusammenfassen'],
        'intake' => ['fa-inbox', 'AI-Eingänge prüfen'],
        'day' => ['fa-heartbeat', 'Tageslage zusammenfassen'],
    ];

    public function __construct(private readonly TimelinePlanningSuggestionService $planning) {}

    /** @return array{page: string, label: string, from: string, until: string} */
    public function context(string $page, string $view = '', string $section = '', ?string $from = null, ?string $until = null): array
    {
        $zone = (string) config('operations.display_timezone', 'Europe/Berlin');
        $today = CarbonImmutable::now($zone);
        $start = $this->date($from) ?? $today->startOfWeek(CarbonImmutable::MONDAY);
        $end = $this->date($until) ?? $start->endOfWeek(CarbonImmutable::SUNDAY);
        if ($end->lt($start) || $start->diffInDays($end) > 94) {
            $start = $today->startOfWeek(CarbonImmutable::MONDAY);
            $end = $start->endOfWeek(CarbonImmutable::SUNDAY);
        }
        $key = match (true) {
            $page === 'cases' && $view === 'shifts' => $section === 'calendar' ? 'calendar' : 'shifts',
            $page === 'cases' && $view === 'orders' => 'orders',
            $page === 'cases' && $view === 'offers' => 'offers',
            $page === 'cases' => 'inbox',
            in_array($page, ['attention', 'planning', 'duty'], true) => $page,
            default => 'other',
        };
        $labels = ['shifts' => 'Schichtplan', 'calendar' => 'Kalender', 'orders' => 'Aufträge', 'offers' => 'Angebote', 'inbox' => 'Eingang',
            'attention' => 'Arbeitsliste', 'planning' => 'Ressourcen & Kapazität', 'duty' => 'Leitstelle', 'other' => 'Disposition'];

        return ['page' => $key, 'label' => $labels[$key], 'from' => $start->toDateString(), 'until' => $end->toDateString()];
    }

    /** @return array{page: list<array>, global: list<array>} */
    public function actions(User $actor, array $context): array
    {
        $byPage = [
            'shifts' => ['period', 'open-today', 'workload'],
            'calendar' => ['period', 'open-today'],
            'planning' => ['period', 'without'],
            'orders' => ['without'],
            'inbox' => ['intake', 'summary'],
            'offers' => ['summary'],
            'attention' => ['day', 'summary'],
            'duty' => ['open-today', 'day'],
        ][$context['page']] ?? [];
        $permitted = $this->permitted($actor);
        $allowed = fn (string $key) => in_array($key, $permitted, true);
        $page = array_values(array_filter($byPage, $allowed));
        $global = array_values(array_filter(['day', 'intake'], fn ($key) => $allowed($key) && ! in_array($key, $page, true)));
        $describe = fn (string $key) => ['key' => $key, 'icon' => self::ACTIONS[$key][0], 'title' => self::ACTIONS[$key][1], 'detail' => $this->detail($key, $context)];

        return ['page' => array_map($describe, $page), 'global' => array_map($describe, $global)];
    }

    /** Alle Aktionen, die die Rechte erlauben – unabhängig von der Seite (Freitext, „/“). @return list<string> */
    public function permitted(User $actor): array
    {
        $planner = $actor->can('operations.manage');
        $inquiries = $actor->can('operations.inquiries.manage');

        return array_values(array_filter(array_keys(self::ACTIONS), fn (string $key) => match ($key) {
            'period', 'open-today', 'workload', 'without' => $planner,
            'summary' => $inquiries,
            'intake' => $inquiries && AiIntakeSchema::ready(),
            'day' => $planner || $inquiries,
        }));
    }

    /** Kurze, ehrliche Unterzeile je Aktion (ohne teure Berechnungen). */
    private function detail(string $key, array $context): string
    {
        return match ($key) {
            'period' => 'Offene Schichten · '.$this->period($context),
            'open-today' => 'Warum ist niemand eingeteilt, wer käme in Frage?',
            'workload' => 'Wer liegt über Soll oder deutlich darunter?',
            'without' => 'Welche Leistungen brauchen noch Schichten?',
            'summary' => 'Anfragen im Status Neu',
            'intake' => 'Eingänge, die eine Freigabe brauchen',
            'day' => 'Offene Dienste, laufende Dienste, neue Eingänge',
        };
    }

    /** Proaktive Hinweise für die Sprechblase am Orb (nur Zählabfragen). @return list<array{text: string, run: string, label: string}> */
    public function hints(User $actor): array
    {
        $hints = [];
        if ($actor->can('operations.manage') && OperationsAccess::ready()) {
            $today = $this->today();
            $open = $this->planning->openShifts($today, $today, $actor)->get(['shifts.id', 'shifts.starts_at', 'shifts.timezone']);
            if ($open->isNotEmpty()) {
                $first = $this->localTime($open->first()->starts_at);
                $hints[] = ['text' => $open->count() === 1 ? "Heute ab {$first} ist ein Dienst noch offen." : "Heute sind noch {$open->count()} Dienste offen, der erste ab {$first}.", 'run' => 'open-today', 'label' => 'Ersatz zeigen'];
            }
        }
        if ($actor->can('operations.inquiries.manage') && AiIntakeSchema::ready()) {
            $review = AiIntake::where('status', 'review')->count();
            if ($review > 0) {
                $hints[] = ['text' => $review === 1 ? 'Ein AI-Eingang wartet auf deine Freigabe.' : "{$review} AI-Eingänge warten auf deine Freigabe.", 'run' => 'intake', 'label' => 'Prüfen'];
            }
        }

        return $hints;
    }

    public function reviewCount(User $actor): int
    {
        return $actor->can('operations.inquiries.manage') && AiIntakeSchema::ready() ? AiIntake::where('status', 'review')->count() : 0;
    }

    /** Freitext auf eine Aktion abbilden; null = Hilfetext. */
    public function route(string $question): ?string
    {
        $text = mb_strtolower($question);

        return match (true) {
            (bool) preg_match('/heute|spät|früh|wer kann|übernehm|ersatz|krank/u', $text) => 'open-today',
            (bool) preg_match('/auslast|soll|stunden|überstund/u', $text) => 'workload',
            (bool) preg_match('/ohne schicht|leistung|auftr/u', $text) => 'without',
            (bool) preg_match('/ai-eing|ki-eing|eingäng|freigabe/u', $text) => 'intake',
            (bool) preg_match('/anfrage|kunde|zusammenfass/u', $text) => 'summary',
            (bool) preg_match('/tageslage|überblick|lage|stand/u', $text) => 'day',
            (bool) preg_match('/offen|besetz|vorschlag|schicht|woche|einteil/u', $text) => 'period',
            default => null,
        };
    }

    public function title(string $action): string
    {
        return self::ACTIONS[$action][1] ?? 'AI-Assist';
    }

    /**
     * Antwort auf eine Aktion. Rückgabe: lead (Text mit **Hervorhebung**), optional card.
     *
     * @return array{lead: string, card?: array}
     */
    public function answer(string $action, User $actor, array $context): array
    {
        abort_unless(in_array($action, $this->permitted($actor), true), 403);

        return match ($action) {
            'period' => $this->periodAnswer($actor, $context),
            'open-today' => $this->openTodayAnswer($actor),
            'workload' => $this->workloadAnswer($context),
            'without' => $this->withoutAnswer($context),
            'summary' => $this->summaryAnswer(),
            'intake' => $this->intakeAnswer(),
            'day' => $this->dayAnswer($actor),
        };
    }

    public function help(): array
    {
        return ['lead' => "Dabei helfe ich in der Disposition:\n• offene Schichten erklären und Besetzungen vorschlagen\n• Auslastung und Leistungen ohne Schichten prüfen\n• neue Anfragen und AI-Eingänge zusammenfassen\nTippe **/**, um alle Aktionen zu sehen."];
    }

    private function periodAnswer(User $actor, array $context): array
    {
        $preview = $this->planning->preview($context['from'], $context['until'], $actor);
        $proposals = $preview['proposals'];
        $open = (int) $preview['open_total'];
        if ($open === 0) {
            return ['lead' => 'Im Zeitraum '.$this->period($context).' ist **keine Schicht mehr offen**.'];
        }
        $covered = $proposals->pluck('shift.id')->unique()->count();
        $rest = $open - $covered;
        $lead = "Für **{$open} offene ".($open === 1 ? 'Schicht' : 'Schichten').'** im Zeitraum '.$this->period($context).' habe ich **'.$proposals->count().' '.($proposals->count() === 1 ? 'Besetzung' : 'Besetzungen').'** gefunden.';
        if ($preview['limited']) {
            $lead .= ' Die Vorschau ist auf die nächsten Schichten begrenzt.';
        } elseif ($rest > 0) {
            $lead .= " Für {$rest} ".($rest === 1 ? 'Schicht ist' : 'Schichten ist').' niemand ohne Ausnahme frei.';
        }
        if ($proposals->isEmpty()) {
            return ['lead' => $lead, 'card' => ['icon' => 'fa-sparkles', 'title' => 'Besetzungsvorschlag', 'rows' => [], 'note' => 'Partneranfrage oder Planungswerkzeuge unter Ressourcen & Kapazität prüfen.',
                'buttons' => [['label' => 'Schichtplan öffnen', 'href' => $this->shiftPlanUrl($context), 'primary' => true]]]];
        }

        return ['lead' => $lead, 'card' => [
            'icon' => 'fa-sparkles', 'title' => 'Besetzungsvorschlag',
            'rows' => $proposals->map(fn (array $proposal) => [
                'label' => $proposal['shift']->title.' · '.$this->when($proposal['shift']),
                'value' => $proposal['user']->name, 'small' => $proposal['fit_label'], 'why' => array_values($proposal['reasons']),
            ])->values()->all(),
            'note' => 'Übernommene Vorschläge werden als **Angefragt** eingeteilt. Jede Einteilung wird vorher erneut gegen Konflikte und Revision geprüft und lässt sich hier zurücknehmen.',
            'buttons' => [
                ['label' => 'Im Schichtplan prüfen', 'href' => $this->shiftPlanUrl($context)],
                ['label' => $proposals->count() === 1 ? 'Vorschlag übernehmen' : 'Alle '.$proposals->count().' übernehmen', 'act' => 'apply', 'primary' => true],
            ],
            'payload' => $proposals->map(fn (array $proposal) => [$proposal['shift']->id, $proposal['user']->id, (int) $proposal['revision']])->values()->all(),
        ]];
    }

    private function openTodayAnswer(User $actor): array
    {
        $today = $this->today();
        $open = $this->planning->openShifts($today, $today, $actor)->limit(20)->get();
        if ($open->isEmpty()) {
            return ['lead' => 'Heute ist **jede Schicht besetzt** – es gibt keinen offenen Dienst mehr.'];
        }
        $shift = $open->first();
        $candidates = $this->planning->rankedCandidates($shift, $actor)->take(3)->values();
        $count = $open->count();
        $lead = ($count === 1 ? 'Heute ist **ein Dienst** offen.' : "Heute sind **{$count} Dienste** offen.").' Für **'.$shift->title.'** ('.$this->when($shift).') '.($candidates->isEmpty() ? 'ist niemand ohne Ausnahme frei.' : 'kommen in Frage:');
        $free = max(0, $shift->required_staff - (int) $shift->reserved_count);
        $first = $candidates->first();
        $buttons = [['label' => 'Schichtplan öffnen', 'href' => $this->shiftPlanUrl($this->context('cases', 'shifts', 'plan'))]];
        if ($first) {
            $buttons[] = ['label' => strtok($first['user']->name, ' ').' anfragen', 'act' => 'apply', 'primary' => true];
        }

        return ['lead' => $lead, 'card' => [
            'icon' => 'fa-user-plus', 'title' => $shift->title.' · '.$free.' frei',
            'rows' => $candidates->map(fn (array $candidate) => ['label' => $candidate['user']->name, 'value' => ['preferred' => 'Wunschdienst', 'available' => 'Verfügbar gemeldet'][$candidate['wish']] ?? 'Konfliktfrei',
                'small' => collect($candidate['reasons'])->first(fn ($reason) => str_starts_with($reason, 'Wochenplanung')) ?? '', 'why' => array_values($candidate['reasons'])])->all(),
            'note' => $candidates->isEmpty() ? 'Partneranfrage oder Übernahme unter Ressourcen & Kapazität prüfen.' : ($count > 1 ? 'Weitere offene Dienste heute: '.$open->skip(1)->take(3)->map(fn (Shift $item) => $item->title.' ('.$this->localTime($item->starts_at).')')->implode(', ').'.' : ''),
            'buttons' => $buttons,
            'payload' => $first ? [[$shift->id, $first['user']->id, (int) $shift->revision]] : [],
        ]];
    }

    private function workloadAnswer(array $context): array
    {
        $zone = (string) config('operations.display_timezone', 'Europe/Berlin');
        $from = CarbonImmutable::parse($context['from'], $zone)->startOfWeek(CarbonImmutable::MONDAY);
        $until = $from->addWeek();
        $users = User::where('role', 'staff')->where('status', true)->orderBy('name')->get(['id', 'name']);
        $assignments = ShiftAssignment::blocking()->whereIn('user_id', $users->pluck('id'))
            ->whereHas('shift', fn ($query) => $query->notCancelled()->during($from, $until))->with('shift')->get()->groupBy('user_id');
        $absences = AbsenceRequest::whereIn('user_id', $users->pluck('id'))->whereIn('status', ['pending', 'approved'])
            ->where('starts_at', '<', $until->utc())->where('ends_at', '>', $from->utc())->get()->groupBy('user_id');
        $days = collect(range(0, 6))->map(fn (int $offset) => $from->addDays($offset));
        $loads = app(TimelineWorkloadService::class)->forRows($users, $assignments, $absences, $days);
        $known = $users->filter(fn (User $user) => $loads[$user->id]['percent'] !== null);
        $over = $known->filter(fn (User $user) => $loads[$user->id]['state'] === 'over')->sortByDesc(fn (User $user) => $loads[$user->id]['ratio']);
        $low = $known->filter(fn (User $user) => $loads[$user->id]['ratio'] < .5)->sortBy(fn (User $user) => $loads[$user->id]['ratio']);
        $week = 'KW '.$from->isoWeek;
        if ($known->isEmpty()) {
            return ['lead' => "Für {$week} ist bei niemandem ein Wochensoll gepflegt – ohne Soll bewerte ich keine Auslastung."];
        }
        $hours = fn (float $minutes) => number_format($minutes / 60, 1, ',', '.').' h';

        return ['lead' => ($over->isNotEmpty() ? '**'.$over->count().' über Soll**' : 'Niemand liegt über Soll').', **'.$low->count().' deutlich darunter** (unter 50 %). Wer wenig verplant ist, sollte offene Dienste zuerst bekommen.'
            .($known->count() < $users->count() ? ' '.($users->count() - $known->count()).' ohne gepflegtes Soll sind nicht bewertet.' : ''),
            'card' => ['icon' => 'fa-tachometer-alt', 'title' => 'Auslastung '.$week,
                'rows' => $over->concat($low)->take(6)->map(fn (User $user) => ['label' => $user->name, 'value' => $loads[$user->id]['percent'].' %',
                    'small' => $hours($loads[$user->id]['planned']).' von '.$hours((float) $loads[$user->id]['target'])])->values()->all(),
                'buttons' => [['label' => 'Zeitleiste öffnen', 'href' => $this->shiftPlanUrl($context), 'primary' => true]]]];
    }

    private function withoutAnswer(array $context): array
    {
        $zone = (string) config('operations.display_timezone', 'Europe/Berlin');
        $from = CarbonImmutable::parse($context['from'], $zone)->startOfDay()->utc();
        $until = CarbonImmutable::parse($context['until'], $zone)->endOfDay()->utc();
        $orders = Order::query()->with('customer')
            ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Invoiced->value, OrderStatus::Cancelled->value])
            ->whereDoesntHave('shifts', fn (Builder $query) => $query->where('status', '!=', ShiftStatus::Cancelled->value))
            ->where('starts_at', '<', $until)->where('ends_at', '>', $from)->orderBy('starts_at')->limit(8)->get();
        if ($orders->isEmpty()) {
            return ['lead' => 'Im Zeitraum '.$this->period($context).' hat **jede Leistung Schichten**.'];
        }

        return ['lead' => '**'.$orders->count().($orders->count() === 8 ? '+' : '').' '.($orders->count() === 1 ? 'Leistung hat' : 'Leistungen haben').'** im Zeitraum '.$this->period($context).' noch keine Schichten:',
            'card' => ['icon' => 'fa-clipboard-list', 'title' => 'Ohne Schichten',
                'rows' => $orders->map(fn (Order $order) => ['label' => $order->title, 'value' => $order->customer?->company_name ?? 'Kunde offen',
                    'small' => 'ab '.$order->starts_at->setTimezone($zone)->format('d.m. H:i').' · '.$order->required_staff.' Personen'])->all(),
                'buttons' => [['label' => 'Aufträge öffnen', 'href' => OperationsPages::url('cases', ['view' => 'orders']), 'primary' => true]]]];
    }

    private function summaryAnswer(): array
    {
        $inquiries = OperationInquiry::query()->with('customer')->where('status', 'new')->latest('id')->limit(6)->get();
        $total = OperationInquiry::where('status', 'new')->count();
        if ($total === 0) {
            return ['lead' => 'Es gibt **keine neuen Anfragen**.'];
        }
        $zone = (string) config('operations.display_timezone', 'Europe/Berlin');

        return ['lead' => '**'.$total.' '.($total === 1 ? 'neue Anfrage' : 'neue Anfragen').'** warten auf eine Bearbeitung'.($total > 6 ? ' – die neuesten sechs:' : ':'),
            'card' => ['icon' => 'fa-inbox', 'title' => 'Neue Anfragen',
                'rows' => $inquiries->map(fn (OperationInquiry $inquiry) => ['label' => $inquiry->title ?: 'Anfrage #'.$inquiry->id, 'value' => $inquiry->customer?->company_name ?? 'Kunde noch zuordnen',
                    'small' => $inquiry->starts_at ? 'ab '.CarbonImmutable::parse($inquiry->starts_at)->setTimezone($zone)->format('d.m. H:i') : 'Termin noch offen'])->all(),
                'buttons' => [['label' => 'Eingang öffnen', 'href' => OperationsPages::url('cases', ['view' => 'inbox']), 'primary' => true]]]];
    }

    private function intakeAnswer(): array
    {
        $review = AiIntake::query()->with('customer')->where('status', 'review')->latest('id')->limit(6)->get();
        if ($review->isEmpty()) {
            return ['lead' => 'Kein AI-Eingang wartet auf eine Freigabe.', 'card' => ['icon' => 'fa-inbox', 'title' => 'AI-Eingänge', 'rows' => [], 'buttons' => [['label' => 'Alle Eingänge', 'act' => 'tab:intake', 'primary' => true]]]];
        }

        return ['lead' => '**'.$review->count().' '.($review->count() === 1 ? 'Eingang wartet' : 'Eingänge warten').'** auf deine Freigabe:',
            'card' => ['icon' => 'fa-inbox', 'title' => 'AI-Eingänge',
                'rows' => $review->map(fn (AiIntake $intake) => ['label' => $intake->title ?: 'Eingang #'.$intake->id, 'value' => $intake->customer?->company_name ?? 'Kunde offen',
                    'small' => $this->missing($intake)])->all(),
                'buttons' => [['label' => 'Alle Eingänge', 'act' => 'tab:intake'], ['label' => 'Ersten Eingang öffnen', 'href' => $this->intakeUrl($review->first()), 'primary' => true]]]];
    }

    private function dayAnswer(User $actor): array
    {
        $zone = (string) config('operations.display_timezone', 'Europe/Berlin');
        $now = CarbonImmutable::now($zone);
        $parts = [];
        $rows = [];
        if ($actor->can('operations.manage') && OperationsAccess::ready()) {
            $today = $this->today();
            $open = $this->planning->openShifts($today, $today, $actor)->limit(3)->get();
            $openCount = $this->planning->openShifts($today, $today, $actor)->count();
            $running = Shift::where('status', ShiftStatus::InProgress->value)->count();
            $parts[] = "**{$running} im Dienst**";
            $parts[] = "**{$openCount} ".($openCount === 1 ? 'Dienst' : 'Dienste').' heute offen**';
            foreach ($open as $shift) {
                $rows[] = ['label' => $shift->title, 'value' => 'heute '.$this->localTime($shift->starts_at).' besetzen', 'small' => ''];
            }
        }
        if ($actor->can('operations.inquiries.manage')) {
            $new = OperationInquiry::where('status', 'new')->count();
            $parts[] = $new.' '.($new === 1 ? 'neue Anfrage' : 'neue Anfragen');
            if (AiIntakeSchema::ready()) {
                $review = AiIntake::where('status', 'review')->count();
                $parts[] = $review.' AI-'.($review === 1 ? 'Eingang' : 'Eingänge').' zur Prüfung';
            }
        }

        return ['lead' => 'Stand '.$now->format('H:i').': '.implode(', ', $parts).'.', 'card' => $rows === [] ? null : [
            'icon' => 'fa-heartbeat', 'title' => 'Als Nächstes', 'rows' => $rows,
            'buttons' => [['label' => 'Arbeitsliste öffnen', 'href' => OperationsPages::url('attention'), 'primary' => true]],
        ]];
    }

    /**
     * Vorschläge übernehmen: je Paar frisch prüfen und als „Angefragt“ einteilen.
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $pairs
     * @return array{done: list<array{assignment: int, shift: int, user: int}>, failed: list<string>}
     */
    public function apply(array $pairs, User $actor): array
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        OperationsAccess::requireReady();
        $done = [];
        $failed = [];
        foreach (array_slice($pairs, 0, 25) as [$shiftId, $userId, $revision]) {
            $shift = Shift::query()->find($shiftId);
            $user = User::query()->where('role', 'staff')->where('status', true)->find($userId);
            $label = ($shift?->title ?? 'Schicht #'.$shiftId).' · '.($user?->name ?? 'Person #'.$userId);
            try {
                abort_unless($shift && $user, 404);
                $assignment = OperationsTransaction::run(function () use ($shift, $user, $actor, $revision) {
                    PlanningLocks::acquire([$shift->id], [$user->id]);
                    // Noch offen und nicht begonnen? Eignung, Kapazität und Revision prüft assign() unter derselben Sperre.
                    $day = $shift->starts_at->setTimezone((string) config('operations.display_timezone', 'Europe/Berlin'))->toDateString();
                    if (! $this->planning->openShifts($day, $day, $actor)->whereKey($shift->id)->exists()) {
                        throw ValidationException::withMessages(['workflow' => 'Schicht ist nicht mehr offen.']);
                    }
                    $assignment = app(ShiftAssignmentService::class)->assign($shift, $user, $actor, ShiftAssignmentStatus::Requested, self::NOTE, $revision);
                    app(OperationsAuditService::class)->record($shift, $actor, 'ai_assist.requested', ['user_id' => $user->id, 'assignment_id' => $assignment->id]);

                    return $assignment;
                });
                $done[] = ['assignment' => $assignment->id, 'shift' => $shift->id, 'user' => $user->id];
            } catch (ValidationException $exception) {
                $failed[] = $label.': '.(collect($exception->errors())->flatten()->first() ?: 'nicht übernommen');
            } catch (\DomainException|HttpException $exception) {
                $failed[] = $label.': '.($exception->getMessage() ?: 'nicht übernommen');
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /** Eigene, noch angefragte Einteilungen zurücknehmen. @param list<int> $assignmentIds */
    public function undo(array $assignmentIds, User $actor): int
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        $count = 0;
        $assignments = ShiftAssignment::query()->whereIn('id', array_slice($assignmentIds, 0, 25))
            ->where('status', ShiftAssignmentStatus::Requested->value)->where('assigned_by', $actor->id)->with('shift')->get();
        foreach ($assignments as $assignment) {
            app(ShiftAssignmentService::class)->cancel($assignment, $actor, 'AI-Assist: zurückgenommen');
            app(OperationsAuditService::class)->record($assignment->shift, $actor, 'ai_assist.withdrawn', ['user_id' => $assignment->user_id, 'assignment_id' => $assignment->id]);
            $count++;
        }

        return $count;
    }

    /** @return Collection<int, AiIntake> */
    public function intakes(User $actor, string $search = '', string $status = 'all'): Collection
    {
        if (! $actor->can('operations.inquiries.manage') || ! AiIntakeSchema::ready()) {
            return collect();
        }
        abort_unless($status === 'all' || in_array($status, AiIntake::STATUSES, true), 422);

        return AiIntake::query()->with('customer')
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when(trim($search) !== '', fn (Builder $query) => $query->where('title', 'like', '%'.mb_substr(trim($search), 0, 100).'%'))
            ->latest('updated_at')->latest('id')->limit(30)->get();
    }

    /**
     * Aktivitäten aus AI-Eingängen und den eigenen AI-Assist-Einteilungen.
     *
     * @return Collection<int, array{at: CarbonImmutable, kind: string, icon: string, title: string, detail: string, status: string, tone: string, href: ?string}>
     */
    public function activity(User $actor, string $filter = 'all'): Collection
    {
        abort_unless(in_array($filter, ['all', 'intake', 'planning'], true), 422);
        $items = collect();
        if ($filter !== 'planning' && $actor->can('operations.inquiries.manage') && AiIntakeSchema::ready()) {
            foreach (AiIntake::query()->with('customer')->latest('updated_at')->limit(15)->get() as $intake) {
                $items->push(['at' => CarbonImmutable::parse($intake->updated_at), 'kind' => 'intake', 'icon' => $intake->status === 'completed' ? 'fa-check' : 'fa-inbox',
                    'title' => $intake->title ?: 'Eingang #'.$intake->id, 'detail' => ($intake->customer?->company_name ?? 'Kunde offen').($this->missing($intake) !== 'vollständig' ? ' · '.$this->missing($intake) : ''),
                    'status' => AiIntakeInbox::LABELS[$intake->status] ?? $intake->status, 'tone' => $this->tone($intake->status), 'href' => $this->intakeUrl($intake)]);
            }
        }
        if ($filter !== 'intake' && $actor->can('operations.manage')) {
            $audits = OperationAudit::query()->with('actor:id,name')->whereIn('action', ['ai_assist.requested', 'ai_assist.withdrawn'])
                ->where('subject_type', class_basename(Shift::class))->latest('id')->limit(40)->get();
            $shifts = Shift::whereIn('id', $audits->pluck('subject_id'))->get(['id', 'title', 'starts_at', 'timezone'])->keyBy('id');
            $people = User::whereIn('id', $audits->pluck('data.user_id')->filter())->pluck('name', 'id');
            foreach ($audits as $audit) {
                $shift = $shifts->get($audit->subject_id);
                $requested = $audit->action === 'ai_assist.requested';
                $items->push(['at' => $audit->created_at, 'kind' => 'planning', 'icon' => $requested ? 'fa-user-plus' : 'fa-undo',
                    'title' => ($people[$audit->data['user_id'] ?? 0] ?? 'Mitarbeiter').' · '.($shift?->title ?? 'Schicht'),
                    'detail' => ($requested ? 'Als Angefragt eingeteilt' : 'Einteilung zurückgenommen').' · '.($audit->actor?->name ?? 'Disposition').($shift ? ' · '.$this->when($shift) : ''),
                    'status' => $requested ? 'Übernommen' : 'Zurückgenommen', 'tone' => $requested ? 'ok' : 'neutral', 'href' => null]);
            }
        }

        return $items->sortByDesc('at')->take(30)->values();
    }

    public function intakeUrl(AiIntake $intake): string
    {
        return OperationsPages::url('cases', array_filter(['view' => 'inbox', 'section' => 'ai-intake', 'source' => 'ai-intake', 'record' => $intake->id, 'customer' => $intake->customer_id]));
    }

    public function tone(string $status): string
    {
        return match ($status) {
            'review', 'failed' => 'warn',
            'completed', 'ready' => 'ok',
            'analyzing', 'received' => 'info',
            default => 'neutral',
        };
    }

    private function missing(AiIntake $intake): string
    {
        $missing = collect($intake->missing_fields ?? [])->map(fn ($field) => AiIntakeInbox::FIELD_LABELS[$field] ?? null)->filter()->take(3);

        return $missing->isEmpty() ? 'vollständig' : 'offen: '.$missing->implode(', ');
    }

    private function shiftPlanUrl(array $context): string
    {
        return OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'plan']);
    }

    private function period(array $context): string
    {
        $from = CarbonImmutable::parse($context['from']);
        $until = CarbonImmutable::parse($context['until']);

        return $from->format('d.m.').' – '.$until->format('d.m.Y');
    }

    private function when(Shift $shift): string
    {
        $zone = (string) config('operations.display_timezone', 'Europe/Berlin');
        $start = $shift->starts_at->setTimezone($zone);

        return ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][$start->isoWeekday() - 1].' '.$start->format('d.m. H:i');
    }

    private function localTime($instant): string
    {
        return CarbonImmutable::parse($instant)->setTimezone((string) config('operations.display_timezone', 'Europe/Berlin'))->format('H:i');
    }

    private function today(): string
    {
        return CarbonImmutable::now((string) config('operations.display_timezone', 'Europe/Berlin'))->toDateString();
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('operations.display_timezone', 'Europe/Berlin')) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
