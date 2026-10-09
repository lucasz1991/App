<?php

namespace App\Services\Operations;

use App\Enums\OrderStatus;
use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Livewire\Operations\AiIntakeInbox;
use App\Models\AbsenceRequest;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeMessage;
use App\Models\AiIntakeProposal;
use App\Models\AiIntakeRun;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\AiCustomerCommunicationSchema;
use App\Support\Operations\AiDispositionSettings;
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
        'exceptions' => ['fa-life-ring', 'Unklare Fälle lösen'],
        'outbox' => ['fa-envelope', 'Kundennachrichten prüfen'],
        'automation' => ['fa-user-clock', 'Personalautomatik prüfen'],
        'recovery' => ['fa-redo', 'Verarbeitung nach Prüfung erneut vormerken'],
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
        $global = array_values(array_filter(['exceptions', 'outbox', 'automation', 'recovery', 'day', 'intake'], fn ($key) => $allowed($key) && ! in_array($key, $page, true)));
        $describe = fn (string $key) => ['key' => $key, 'icon' => self::ACTIONS[$key][0], 'title' => self::ACTIONS[$key][1], 'detail' => $this->detail($key, $context)];

        return ['page' => array_map($describe, $page), 'global' => array_map($describe, $global)];
    }

    /** Alle Aktionen, die die Rechte erlauben – unabhängig von der Seite (Freitext, „/“). @return list<string> */
    public function permitted(User $actor): array
    {
        $actor = $this->readActor($actor);
        if (! $actor) {
            return [];
        }
        $planner = $actor->can('operations.manage');
        $inquiries = $actor->can('operations.inquiries.manage');

        return array_values(array_filter(array_keys(self::ACTIONS), fn (string $key) => match ($key) {
            'period', 'open-today', 'workload', 'without' => $planner,
            'summary' => $inquiries,
            'intake' => $inquiries && AiIntakeSchema::ready(),
            'day' => $planner || $inquiries,
            'exceptions' => $planner || ($inquiries && AiIntakeSchema::ready()),
            'outbox' => $inquiries && $this->outboxReady(),
            'recovery' => $inquiries && AiIntakeSchema::ready(),
            'automation' => $planner && class_exists(StaffingAutomationService::class) && app(StaffingAutomationService::class)->ready(),
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
            'exceptions' => 'Fehlende Angaben und sichere nächste Schritte',
            'outbox' => 'Vorbereitete Nachrichten persönlich freigeben oder verwerfen',
            'automation' => 'Personalvorschläge, Anfragen und Prüfbedarf',
            'recovery' => 'Aktuelle Grundlage und Verbindung zuerst prüfen',
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
        $actor = $this->readActor($actor);

        return $actor?->can('operations.inquiries.manage') && AiIntakeSchema::ready() ? AiIntake::where('status', 'review')->count() : 0;
    }

    /** Counts cover all permitted intakes, independently of the bounded inbox and activity lists. */
    public function overview(User $actor): array
    {
        $actor = $this->readActor($actor);
        $available = $actor?->can('operations.inquiries.manage') && AiIntakeSchema::ready();
        $statuses = array_fill_keys(AiIntake::STATUSES, 0);
        if ($available) {
            foreach (AiIntake::query()->select('status')->selectRaw('COUNT(*) AS aggregate')->groupBy('status')->get() as $row) {
                if (array_key_exists($row->status, $statuses)) {
                    $statuses[$row->status] = (int) $row->aggregate;
                }
            }
        }

        return ['available' => (bool) $available, 'total' => array_sum($statuses), 'statuses' => $statuses,
            'counts' => ['review' => $statuses['review'], 'busy' => $statuses['received'] + $statuses['analyzing'],
                'waiting' => $statuses['waiting_customer'], 'error' => $statuses['failed'], 'ready' => $statuses['ready'],
                'completed' => $statuses['completed'], 'paused' => $statuses['paused']],
            'recent' => $available ? $this->activity($actor, 'intake')->take(5)->map(fn (array $item) => array_replace($item, ['at' => $item['at']->toIso8601String()]))->all() : []];
    }

    /** Freitext auf eine Aktion abbilden; null = Hilfetext. */
    public function route(string $question): ?string
    {
        $text = mb_strtolower($question);

        return match (true) {
            (bool) preg_match('/erneut.*(?:auswert|vormerk|verarbeit)|wiederhol.*verarbeit/u', $text) => 'recovery',
            (bool) preg_match('/unklar|ausnahme|problem|prüfbedarf|fehlend|fehler|blockier/u', $text) => 'exceptions',
            (bool) preg_match('/kundennachricht|postausgang|mail.entwurf|rückfrage.*freig|bestätigung.*mail/u', $text) => 'outbox',
            (bool) preg_match('/personalautomatik|besetzungsautomatik|automatisch.*anfrag/u', $text) => 'automation',
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
        $actor = $this->readActor($actor);
        abort_unless($actor, 403);
        abort_unless(in_array($action, $this->permitted($actor), true), 403);

        return match ($action) {
            'period' => $this->periodAnswer($actor, $context),
            'open-today' => $this->openTodayAnswer($actor),
            'workload' => $this->workloadAnswer($context),
            'without' => $this->withoutAnswer($context),
            'summary' => $this->summaryAnswer(),
            'intake' => $this->intakeAnswer(),
            'day' => $this->dayAnswer($actor),
            'exceptions' => $this->exceptionsAnswer($actor, $context),
            'outbox' => $this->outboxAnswer($actor),
            'automation' => $this->automationAnswer($actor, $context),
            'recovery' => $this->recoveryAnswer($actor),
        };
    }

    public function help(): array
    {
        return ['lead' => "Dabei helfe ich in der Disposition:\n• offene Schichten erklären und Besetzungen vorschlagen\n• Auslastung und Leistungen ohne Schichten prüfen\n• neue Anfragen und AI-Eingänge zusammenfassen\n• unklare Fälle erklären und sichere nächste Schritte vorbereiten\n• Kundennachrichten und Personalautomatik persönlich prüfen\nTippe **/**, um alle Aktionen zu sehen."];
    }

    /** Every permission used by a combined local answer stays attached to its private capability. */
    public function requiredAbilities(string $action, User $actor): array
    {
        if (in_array($action, ['exceptions', 'day', 'recovery'], true)) {
            return array_values(array_filter(['operations.manage', 'operations.inquiries.manage'], fn ($ability) => $actor->can($ability)));
        }

        return [in_array($action, ['summary', 'intake', 'outbox'], true) ? 'operations.inquiries.manage' : 'operations.manage'];
    }

    private function outboxReady(): bool
    {
        return AiIntakeSchema::ready() && class_exists(AiCustomerCommunicationSchema::class) && AiCustomerCommunicationSchema::ready() && method_exists(AiIntakeMailService::class, 'draftMessages');
    }

    public function automationOverview(User $actor): array
    {
        $actor = $this->readActor($actor);
        $result = ['staffing' => ['available' => false, 'mode' => 'off', 'counts' => [], 'items' => []], 'messages' => ['available' => false, 'drafts' => 0, 'unknown' => 0]];
        if (! $actor) {
            return $result;
        }
        if ($actor->can('operations.manage') && class_exists(StaffingAutomationService::class)) {
            $result['staffing'] = ['available' => true] + app(StaffingAutomationService::class)->overview($actor, 10);
        }
        if ($actor->can('operations.inquiries.manage') && $this->outboxReady()) {
            $result['messages'] = ['available' => true, 'drafts' => AiIntakeDelivery::where('status', 'draft')->count(), 'unknown' => AiIntakeDelivery::where('status', 'unknown')->count()];
        }

        return $result;
    }

    /** Safe descriptions only: source text, mail bodies and provider payloads are never exported here. */
    public function exceptions(User $actor, array $context): Collection
    {
        $actor = $this->readActor($actor);
        $items = collect();
        if (! $actor) {
            return $items;
        }
        if ($actor->can('operations.inquiries.manage') && AiIntakeSchema::ready()) {
            foreach (AiIntake::with('customer')->whereIn('status', ['review', 'failed'])->latest('updated_at')->limit(8)->get() as $intake) {
                $steps = $this->intakeNextSteps($intake);
                $items->push(['kind' => 'intake', 'id' => $intake->id, 'title' => $intake->title ?: 'Eingang #'.$intake->id,
                    'detail' => $this->missing($intake), 'steps' => $steps, 'href' => $this->intakeUrl($intake), 'intake_revision' => $intake->revision]);
            }
            foreach (AiIntakeDelivery::where('status', 'unknown')->with('intake')->latest('updated_at')->limit(3)->get() as $delivery) {
                if ($delivery->intake) {
                    $items->prepend(['kind' => 'delivery', 'id' => $delivery->id, 'title' => 'Versandstatus unklar · Eingang #'.$delivery->intake_id,
                        'detail' => 'Der Mailserver-Ausgang wurde nicht sicher bestätigt.', 'steps' => ['Postfach und Versandprotokoll persönlich abgleichen.', 'Keine automatische Wiederholung; eine doppelte Kundennachricht vermeiden.'], 'href' => $this->intakeUrl($delivery->intake)]);
                }
            }
        }
        if ($actor->can('operations.manage') && OperationsAccess::ready()) {
            foreach ($this->planning->openShifts($context['from'], $context['until'], $actor)->limit(5)->get() as $shift) {
                $candidates = $this->planning->rankedCandidates($shift, $actor)->take(3);
                if ($candidates->isNotEmpty()) {
                    $steps = ['Konfliktfreie Alternativen: '.$candidates->pluck('user.name')->implode(', ').'.', 'Besetzungsvorschlag neu auswerten und persönlich prüfen.'];
                } else {
                    $staff = User::where('role', 'staff')->where('status', true)->orderBy('id')->limit(25)->get();
                    $issues = $staff->isEmpty() ? [] : app(StaffEligibilityService::class)->assessMany($shift, $staff);
                    $messages = collect($issues)->flatten(1)->pluck('message')->filter()->unique()->take(4)->all();
                    $steps = [...$messages, 'Die Ursachenübersicht prüft höchstens 25 aktive Mitarbeitende; keine Sperre wird aufgehoben.', 'Nachweise, Arbeitszeit, Region und Kapazität im nativen Plan prüfen oder eine Partneranfrage vorbereiten.'];
                }
                $items->push(['kind' => 'shift', 'id' => $shift->id, 'title' => $shift->title.' · '.$this->when($shift),
                    'detail' => $candidates->isEmpty() ? 'Keine konfliktfreie Besetzung gefunden.' : 'Sichere Alternativen verfügbar.', 'steps' => $steps, 'href' => $this->shiftPlanUrl($context)]);
            }
        }

        return $items->take(16)->values();
    }

    private function intakeNextSteps(AiIntake $intake): array
    {
        $steps = [];
        if (! $intake->customer_id) {
            $steps[] = 'Aktiven Kunden und Kontakt anhand der Quelle persönlich zuordnen; Vorschläge erzeugen keine Kundenbindung.';
        }
        if ($intake->missing_fields) {
            $steps[] = 'Fehlende Angaben ergänzen: '.$this->missing($intake).'.';
        }
        $steps[] = match ($intake->error_code) {
            'recipient_not_verified' => 'Absender und aktiven Kontakt exakt prüfen; Empfänger nicht aus Modelltext übernehmen.',
            'customer_reply_timeout' => 'Die Antwortfrist ist abgelaufen. Vorliegende Antworten und offene Angaben persönlich prüfen.',
            'reply_position_review', 'native_workflow_active' => 'Leistungen ausdrücklich dem passenden offenen Vorgang zuordnen; laufende Angebote im nativen Vorgang bearbeiten.',
            'proposal_application_review' => 'Bestätigten Auftrag und freigegebene Grundlage vergleichen; Entwurfserstellung nur nach korrigierten Voraussetzungen erneut freigeben.',
            'worker_run_outcome_unknown', 'processing_failed', 'automation_unavailable' => 'Verbindung, verantwortliche Disposition und Hintergrundverarbeitung prüfen; danach ausdrücklich erneut auswerten.',
            'automation_disabled' => 'Eingang bleibt gespeichert. Automatik muss durch die Systemverwaltung eingerichtet und aktiviert werden.',
            'incomplete_extraction_review', 'attachments_review', 'validation_review' => 'Die vollständige Originalquelle prüfen und Leistungen persönlich ergänzen; kein abgeschnittener Inhalt wird übernommen.',
            'automatic_message_review', 'manual_intent', 'no_positions' => 'Nachrichtentyp prüfen. Änderungen, Stornos und Autoantworten dürfen keine automatische neue Beauftragung auslösen.',
            'customer_message_approval_required' => 'Vorbereitete Kundennachricht im Postausgang persönlich prüfen und freigeben.',
            'customer_clarification_disabled', 'customer_clarification_rejected' => 'Fehlende Angaben persönlich klären; die deaktivierte oder verworfene Rückfrage wird nicht automatisch gesendet.',
            default => str_starts_with((string) $intake->error_code, 'mailbox_') ? 'Quellenarchiv und Postfach prüfen; unvollständige Originale müssen manuell nachgereicht werden.' : 'Quelle, Leistung und aktuelle native Grundlage persönlich prüfen.',
        };

        return array_values(array_unique($steps));
    }

    private function exceptionsAnswer(User $actor, array $context): array
    {
        $items = $this->exceptions($actor, $context);
        $buttons = [['label' => 'Aktivitäten anzeigen', 'act' => 'tab:activity']];
        if ($actor->can('operations.manage')) {
            $buttons[] = ['label' => 'Sichere Besetzung neu auswerten', 'act' => 'run:period'];
        }
        if ($this->outboxReady() && $actor->can('operations.inquiries.manage')) {
            $buttons[] = ['label' => 'Kundennachrichten prüfen', 'act' => 'run:outbox'];
        }
        if ($actor->can('operations.inquiries.manage') && AiIntakeSchema::ready()) {
            $buttons[] = ['label' => 'Verarbeitung nach Prüfung vorbereiten', 'act' => 'run:recovery'];
        }

        return ['lead' => $items->isEmpty() ? 'Für den gewählten Zeitraum habe ich keine offenen Prüffälle gefunden.' : 'Diese **'.$items->count().' Prüffälle** haben konkrete nächste Schritte. Die Übersicht ist begrenzt; die native Arbeitsliste bleibt vollständig.',
            'card' => ['icon' => 'fa-life-ring', 'title' => 'Unklare Fälle und sichere Alternativen',
                'rows' => $items->map(fn ($item) => ['label' => $item['title'], 'value' => $item['detail'], 'why' => $item['steps'], 'href' => $item['href']])->all(),
                'note' => 'Qualifikationen, Arbeitszeit, regionale Sperren und Kapazität bleiben verbindlich geprüft. Änderungen bestätigst du persönlich.', 'buttons' => $buttons]];
    }

    private function outboxAnswer(User $actor): array
    {
        $drafts = app(AiIntakeMailService::class)->draftMessages($actor);
        $draft = $drafts->first();
        if (! $draft) {
            return ['lead' => 'Keine Kundennachricht wartet auf eine persönliche Freigabe.'];
        }
        $buttons = [['label' => 'Entwurf verwerfen', 'act' => 'reject'], ['label' => 'Eingang prüfen', 'href' => OperationsPages::url('cases', ['view' => 'inbox', 'section' => 'ai-intake', 'intake' => $draft['intake_id']])], ['label' => 'Nächsten Entwurf prüfen', 'act' => 'run:outbox']];
        if (! empty($draft['can_approve'])) {
            array_unshift($buttons, ['label' => 'Nachricht freigeben', 'act' => 'approve', 'primary' => true]);
        }

        return ['lead' => '**'.AiIntakeDelivery::where('status', 'draft')->count().' vorbereitete Kundennachrichten** warten auf Prüfung. Hier ist der nächste Entwurf:',
            'card' => ['icon' => 'fa-envelope', 'title' => $draft['title'], 'rows' => [['label' => 'Empfänger', 'value' => $draft['recipient_label'] ?? $draft['customer_name'] ?? 'Verifizierter Kundenkontakt']],
                'body' => $draft['body'], 'note' => empty($draft['can_approve']) ? 'Diese Grundlage ist nicht versandbereit. Automatik, verantwortliche Disposition, aktive Kontaktzuordnung, Einstellungen und gegebenenfalls Auftragsgrundlage im nativen Eingang prüfen. Der Entwurf bleibt erhalten oder kann ausdrücklich verworfen werden.' : 'Die Freigabe gilt nur für diese konkrete Nachricht und aktuelle Grundlage. Sie ersetzt keine Angebots- oder Auftragsfreigabe.',
                'command' => ['kind' => 'customer_message', 'id' => $draft['id'], 'settings_revision' => $draft['settings_revision'], 'intake_revision' => $draft['intake_revision']],
                'buttons' => $buttons]];
    }

    private function automationAnswer(User $actor, array $context): array
    {
        $overview = app(StaffingAutomationService::class)->overview($actor, 10);
        $settings = AiDispositionSettings::all();
        $offerUrl = OperationsPages::moduleUrl('workforce-planning', ['tab' => 'offers']);
        $card = ['icon' => 'fa-user-clock', 'title' => 'Personal-Anfragen', 'rows' => collect($overview['items'] ?? [])->map(fn ($item) => ['label' => 'Schicht #'.$item['shift_id'], 'value' => $this->staffingState($item['state']), 'small' => StaffingAutomationService::reasonLabel($item['reason_code'] ?? null), 'why' => ['Anfragen reservieren keinen Arbeitsplatz und bestätigen keine Einteilung.'], 'href' => $item['offer_id'] ? $offerUrl : $this->shiftPlanUrl($context)])->all(),
            'note' => 'Sichere Alternativen neu auswerten. Fehlende Nachweise und Sperren zuerst im nativen Vorgang korrigieren.', 'buttons' => [['label' => 'Sichere Alternativen anzeigen', 'act' => 'run:period'], ['label' => 'Personalangebote und Antworten prüfen', 'href' => $offerUrl], ['label' => 'Schichtplan öffnen', 'href' => $this->shiftPlanUrl($context)]]];
        if (($overview['mode'] ?? 'off') !== 'off' && ! empty($overview['supervisor_ready'])) {
            $review = collect($overview['items'])->first(fn ($item) => $item['state'] === 'review' && $item['wave_count'] < (int) $settings['staffing_request_max_waves'] && ! in_array($item['reason_code'], ['interest_received', 'existing_offer', 'publication_changed', 'start_imminent', 'shift_unavailable', 'assisted_proposal'], true));
            if ($review) {
                $card['command'] = ['kind' => 'staffing_retry', 'id' => $review['id'], 'revision' => $review['revision'], 'settings_revision' => $settings['revision']];
                $card['buttons'][] = ['label' => 'Schicht #'.$review['shift_id'].(($overview['mode'] ?? '') === 'assisted' ? ' nach Prüfung neu auswerten' : ' nach Prüfung erneut anfragen'), 'act' => 'retry', 'primary' => true];
            } elseif (($overview['mode'] ?? '') === 'assisted') {
                $target = collect(app(StaffingAutomationService::class)->preparableTargets($actor, 5))->first();
                if ($target) {
                    $card['command'] = ['kind' => 'staffing_prepare', 'id' => $target['shift_id'], 'revision' => $target['plan_revision'], 'settings_revision' => $target['settings_revision']];
                    $card['buttons'][] = ['label' => 'Anfragevorschlag für '.$target['title'].' vorbereiten', 'act' => 'prepare', 'primary' => true];
                }
            }
        }
        $modeLabel = match ($overview['status'] ?? '') {
            'supervisor_not_authorized' => 'verantwortliche Disposition nicht berechtigt',
            'schema_missing' => 'Datenbankerweiterung fehlt',
            default => ['off' => 'ausgeschaltet', 'assisted' => 'assistierter Modus konfiguriert', 'automatic' => 'automatische Anfragen konfiguriert'][$overview['mode']] ?? 'Stand prüfen',
        };

        return ['lead' => 'Personalautomatik: **'.$modeLabel.'**. Anfragen und Prüfbedarf bleiben im nativen Personalprozess nachvollziehbar.', 'card' => $card];
    }

    private function staffingState(string $state): string
    {
        return ['waiting' => 'Wartet auf Auswertung', 'soliciting' => 'Personalanfrage offen', 'review' => 'Persönliche Prüfung nötig', 'awaiting_confirmation' => 'Interesse gemeldet · Freigabe offen', 'completed' => 'Anfrageprozess abgeschlossen', 'stopped' => 'Anfrageprozess beendet'][$state] ?? 'Stand prüfen';
    }

    private function recoveryAnswer(User $actor): array
    {
        if (! AiDispositionSettings::enabled()) {
            return ['lead' => 'Die AI-Annahme ist ausgeschaltet. Gespeicherte Eingänge bleiben erhalten; die Systemverwaltung muss die Voraussetzungen prüfen.'];
        }
        $proposal = $actor->can('operations.manage') ? AiIntakeProposal::with('intake')->where('status', 'failed')->where('error_code', 'proposal_application_review')->whereHas('intake', fn ($q) => $q->where('status', 'review'))->latest('id')->first() : null;
        if ($proposal && $proposal->source_revision === $proposal->intake->source_revision) {
            return ['lead' => 'Für **Eingang #'.$proposal->intake_id.' · Leistung '.($proposal->position_index + 1).'** kann die Entwurfserstellung nach deiner Prüfung erneut vorgemerkt werden.',
                'card' => ['icon' => 'fa-redo', 'title' => 'Entwurfserstellung erneut prüfen', 'rows' => [],
                    'note' => 'Die native Prüfung verlangt dieselbe freigegebene Grundlage, einen unveränderten persönlich übernommenen Auftrag und freie Planung. Bestehende bestätigte Aufträge werden nicht verändert.',
                    'command' => ['kind' => 'proposal_retry', 'id' => $proposal->id, 'revision' => $proposal->revision],
                    'buttons' => [['label' => 'Nach Prüfung erneut vormerken', 'act' => 'retry', 'primary' => true], ['label' => 'Grundlage öffnen', 'href' => $this->intakeUrl($proposal->intake)]]]];
        }
        $intake = AiIntake::where('status', 'review')->whereIn('error_code', ['processing_failed', 'automation_unavailable'])->whereDoesntHave('deliveries', fn ($q) => $q->where('status', 'unknown'))->latest('updated_at')->first();
        if (! $intake) {
            return ['lead' => 'Kein sicher erneut vormerkbarer Eingang gefunden. Unklare Versandstände, Quellenlücken und kommerzielle Änderungen benötigen persönliche Bearbeitung im nativen Vorgang.'];
        }

        return ['lead' => '**'.$intake->title.'** kann nach Prüfung der Verbindung und Quelle erneut ausgewertet werden.',
            'card' => ['icon' => 'fa-redo', 'title' => 'Eingang #'.$intake->id.' erneut auswerten', 'rows' => [], 'note' => 'Bitte Voraussetzungen und vollständige Quelle zuerst prüfen. Die Analyse wird ausdrücklich neu im Hintergrund angefordert.',
                'command' => ['kind' => 'intake_analysis', 'id' => $intake->id, 'revision' => $intake->revision, 'settings_revision' => AiDispositionSettings::all()['revision']],
                'buttons' => [['label' => 'Nach Prüfung erneut auswerten', 'act' => 'reanalyze', 'primary' => true], ['label' => 'Quelle öffnen', 'href' => $this->intakeUrl($intake)]]]];
    }

    /** Commands originate only in an authorized server-side card; native services recheck all mutable context. */
    public function executeCommand(array $command, string $act, User $actor): array
    {
        $actor = $this->readActor($actor);
        abort_unless($actor, 403);
        if (($command['kind'] ?? '') === 'customer_message') {
            OperationsAccess::authorize($actor, 'operations.inquiries.manage');
            abort_unless($this->outboxReady() && in_array($act, ['approve', 'reject'], true), 422);
            $delivery = AiIntakeDelivery::findOrFail((int) $command['id']);
            $service = app(AiIntakeMailService::class);
            if ($act === 'approve') {
                $service->approveDraft($delivery, $actor, (int) $command['settings_revision'], (int) $command['intake_revision']);
            } else {
                $service->rejectDraft($delivery, $actor, (int) $command['settings_revision'], (int) $command['intake_revision']);
            }

            return ['state' => $act === 'approve' ? 'done' : 'dismissed', 'note' => $act === 'approve' ? 'Nachricht persönlich freigegeben und für den Versand vorgemerkt. Der tatsächliche Versandstand erscheint in Aktivitäten.' : 'Nachrichtenentwurf persönlich verworfen.'];
        }
        if (($command['kind'] ?? '') === 'proposal_retry' && $act === 'retry') {
            OperationsAccess::authorize($actor, 'operations.manage');
            app(AiIntakeService::class)->retryApprovedProposal(AiIntakeProposal::findOrFail((int) $command['id']), $actor, (int) $command['revision']);

            return ['state' => 'done', 'note' => 'Erneute Entwurfserstellung persönlich freigegeben. Der bestätigte Auftrag bleibt erhalten; Fortschritt unter Aktivitäten.'];
        }
        if (($command['kind'] ?? '') === 'intake_analysis' && $act === 'reanalyze') {
            OperationsAccess::authorize($actor, 'operations.inquiries.manage');
            abort_unless((int) AiDispositionSettings::all()['revision'] === (int) $command['settings_revision'] && app(AiDispositionClient::class)->isConfigured(), 409, 'AI-Verbindung oder verantwortliche Disposition ist nicht bereit.');
            $intake = AiIntake::findOrFail((int) $command['id']);
            abort_unless($intake->status === 'review' && in_array($intake->error_code, ['processing_failed', 'automation_unavailable'], true), 409, 'Eingang benötigt inzwischen eine andere Prüfung.');
            abort_if($intake->deliveries()->where('status', 'unknown')->exists(), 409, 'Unklaren Versandstatus zuerst persönlich abgleichen. Keine automatische Wiederholung.');
            app(AiIntakeService::class)->reanalyze($intake, $actor, (int) $command['revision']);

            return ['state' => 'done', 'note' => 'Erneute Analyse ausdrücklich vorgemerkt. Der tatsächliche Fortschritt erscheint unter Aktivitäten.'];
        }
        if (in_array($command['kind'] ?? '', ['staffing_retry', 'staffing_prepare'], true)) {
            OperationsAccess::authorize($actor, 'operations.manage');
            abort_unless((int) AiDispositionSettings::all()['revision'] === (int) $command['settings_revision'] && class_exists(StaffingAutomationService::class) && app(StaffingAutomationService::class)->ready(), 409, 'Die Personalanfrage-Grundlage wurde geändert. Bitte erneut prüfen.');
            $service = app(StaffingAutomationService::class);
            if ($command['kind'] === 'staffing_retry' && $act === 'retry') {
                $run = $service->retry((int) $command['id'], (int) $command['revision'], $actor);
            } elseif ($command['kind'] === 'staffing_prepare' && $act === 'prepare') {
                abort_unless((AiDispositionSettings::all()['staffing_request_mode'] ?? 'off') === 'assisted', 409, 'Der persönlich vorbereitete Modus wurde geändert. Bitte neu prüfen.');
                $run = $service->prepare((int) $command['id'], (int) $command['revision'], $actor);
            } else {
                abort(422);
            }

            return ['state' => 'done', 'note' => 'Personalanfrage persönlich vorbereitet: '.StaffingAutomationService::reasonLabel($run->reason_code).'. Keine Einteilung oder Platzreservierung.'];
        }
        abort(422, 'Unbekannte Assistentenaktion.');
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
        $actor = $this->readActor($actor);
        if (! $actor?->can('operations.inquiries.manage') || ! AiIntakeSchema::ready()) {
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
     * @return Collection<int, array{id: string, at: CarbonImmutable, kind: string, phase: string, state: string, icon: string, title: string, detail: string, status: string, tone: string, href: ?string, intake_id: ?int, reference: string}>
     */
    public function activity(User $actor, string $filter = 'all'): Collection
    {
        abort_unless(in_array($filter, ['all', 'intake', 'planning', 'communication', 'automation'], true), 422);
        $actor = $this->readActor($actor);
        if (! $actor) {
            return collect();
        }
        $items = collect();
        if (in_array($filter, ['all', 'intake', 'communication'], true) && $actor->can('operations.inquiries.manage') && AiIntakeSchema::ready()) {
            $items = $this->intakeActivity();
            if ($filter === 'communication') {
                $items = $items->filter(fn ($item) => in_array($item['phase'], ['clarification', 'customer_receipt', 'order_confirmation'], true));
            }
        }
        if (in_array($filter, ['all', 'planning'], true) && $actor->can('operations.manage')) {
            $audits = OperationAudit::query()->with('actor:id,name')->whereIn('action', ['ai_assist.requested', 'ai_assist.withdrawn'])
                ->where('subject_type', class_basename(Shift::class))->latest('id')->limit(40)->get();
            $shifts = Shift::whereIn('id', $audits->pluck('subject_id'))->get(['id', 'title', 'starts_at', 'timezone'])->keyBy('id');
            $people = User::whereIn('id', $audits->pluck('data.user_id')->filter())->pluck('name', 'id');
            foreach ($audits as $audit) {
                $shift = $shifts->get($audit->subject_id);
                $requested = $audit->action === 'ai_assist.requested';
                $items->push(['id' => 'audit:'.$audit->id, 'at' => CarbonImmutable::parse($audit->getRawOriginal('created_at'), 'UTC'), 'kind' => 'planning', 'phase' => 'planning', 'state' => $requested ? 'requested' : 'withdrawn', 'intake_id' => null, 'reference' => 'Schicht #'.$audit->subject_id, 'icon' => $requested ? 'fa-user-plus' : 'fa-undo',
                    'title' => ($people[$audit->data['user_id'] ?? 0] ?? 'Mitarbeiter').' · '.($shift?->title ?? 'Schicht'),
                    'detail' => ($requested ? 'Als Angefragt eingeteilt' : 'Einteilung zurückgenommen').' · '.($audit->actor?->name ?? 'Disposition').($shift ? ' · '.$this->when($shift) : ''),
                    'status' => $requested ? 'Übernommen' : 'Zurückgenommen', 'tone' => $requested ? 'ok' : 'neutral', 'href' => null]);
            }
        }
        if (in_array($filter, ['all', 'planning', 'automation'], true) && $actor->can('operations.manage') && class_exists(StaffingAutomationService::class) && app(StaffingAutomationService::class)->ready()) {
            foreach (app(StaffingAutomationService::class)->recentEvents($actor, 20) as $event) {
                $state = $event['state'] ?? 'review';
                $items->push(['id' => 'staffing:'.($event['id'] ?? $event['run_uuid']), 'at' => CarbonImmutable::parse($event['created_at'], 'UTC'),
                    'kind' => 'planning', 'phase' => 'staffing_request', 'state' => $state, 'intake_id' => null,
                    'reference' => 'Schicht #'.$event['shift_id'], 'icon' => 'fa-user-clock', 'title' => $event['title'] ?? 'Personalanfrage · Schicht #'.$event['shift_id'],
                    'detail' => StaffingAutomationService::reasonLabel($event['reason_code'] ?? null).' · Anfrage reserviert keinen Einsatzplatz',
                    'status' => $this->staffingState($state), 'tone' => $state === 'review' ? 'warn' : 'info',
                    'href' => $this->shiftPlanUrl($this->context('cases', 'shifts'))]);
            }
        }

        return $items->sort(fn (array $left, array $right) => $right['at']->getTimestamp() <=> $left['at']->getTimestamp() ?: strcmp($right['id'], $left['id']))->take(30)->values();
    }

    /** Each source is bounded independently: a new run on an older intake must remain visible. */
    private function intakeActivity(): Collection
    {
        $items = collect();
        $relation = 'intake:id,public_id,title,source_type,customer_id';
        $push = function (AiIntake $intake, string $id, $at, string $phase, string $state, string $title, string $detail, string $status, string $tone, string $icon) use ($items): void {
            $label = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $intake->title ?? '') ?? '', 0, 180);
            $items->push(['id' => $id, 'at' => CarbonImmutable::parse($at), 'kind' => 'intake', 'phase' => $phase, 'state' => $state,
                'intake_id' => (int) $intake->id, 'reference' => 'Eingang #'.$intake->id, 'icon' => $icon, 'title' => $title,
                'detail' => ($label !== '' ? $label : 'Eingang #'.$intake->id).' · '.$detail, 'status' => $status, 'tone' => $tone, 'href' => $this->intakeUrl($intake)]);
        };

        // Older records without message history still have a genuine receipt timestamp.
        foreach (AiIntake::query()->select(['id', 'public_id', 'title', 'source_type', 'customer_id', 'created_at'])->doesntHave('messages')->latest('created_at')->latest('id')->limit(30)->get() as $intake) {
            $push($intake, 'intake:'.$intake->id.':received', $intake->created_at, 'receipt', 'received', 'Eingang erfasst', 'Zur Bearbeitung erfasst', 'Erfasst', 'info', 'fa-inbox');
        }
        foreach (AiIntake::query()->select(['id', 'public_id', 'title', 'source_type', 'customer_id', 'paused_at'])->whereNotNull('paused_at')->latest('paused_at')->latest('id')->limit(30)->get() as $intake) {
            $push($intake, 'intake:'.$intake->id.':paused', $intake->paused_at, 'analysis', 'paused', 'Verarbeitung pausiert', 'Automatische Verarbeitung wurde persönlich pausiert', 'Pausiert', 'neutral', 'fa-pause');
        }
        foreach (AiIntakeMessage::query()->select(['id', 'intake_id', 'direction', 'created_at'])->with($relation)->where('direction', 'inbound')->latest('created_at')->latest('id')->limit(30)->get() as $message) {
            if ($message->intake) {
                $push($message->intake, 'message:'.$message->id.':received', $message->created_at, 'receipt', 'received', 'Nachricht eingegangen', $message->intake->source_type === 'email' ? 'E-Mail im Eingang gespeichert' : 'Text oder Aufnahme im Eingang gespeichert', 'Erfasst', 'info', 'fa-inbox');
            }
        }
        foreach (AiIntakeRun::query()->select(['id', 'intake_id', 'kind', 'status', 'started_at', 'finished_at'])->with($relation)->whereIn('kind', ['analysis', 'apply'])->orderByRaw('COALESCE(finished_at, started_at) DESC')->latest('id')->limit(30)->get() as $run) {
            if (! $run->intake) {
                continue;
            }
            $apply = $run->kind === 'apply';
            $phase = $apply ? 'proposal' : 'analysis';
            if ($run->started_at) {
                $push($run->intake, 'run:'.$run->id.':started', $run->started_at, $phase, 'running', $apply ? 'Entwurfserstellung gestartet' : 'Analyse gestartet', $apply ? 'Freigegebene Grundlage wird verarbeitet' : 'Gespeicherte Quelle wird ausgewertet', 'Gestartet', 'info', 'fa-play');
            }
            if ($run->finished_at && in_array($run->status, ['succeeded', 'failed', 'stale'], true)) {
                [$title, $detail, $status, $tone, $icon] = match ($run->status) {
                    'succeeded' => [$apply ? 'Entwurfserstellung abgeschlossen' : 'Analyse abgeschlossen', $apply ? 'Entwürfe wurden vorbereitet; Besetzung und Veröffentlichung bleiben gesondert freigegeben' : 'Auswertung gespeichert; fachliche Prüfung bleibt erforderlich', 'Abgeschlossen', 'ok', 'fa-check'],
                    'failed' => [$apply ? 'Entwurfserstellung prüfen' : 'Analyse prüfen', 'Verarbeitung wurde beendet; Ergebnis im Eingang prüfen', 'Prüfung nötig', 'warn', 'fa-exclamation-triangle'],
                    'stale' => ['Verarbeitung verworfen', 'Die Grundlage war nicht mehr aktuell; kein Ergebnis übernommen', 'Verworfen', 'neutral', 'fa-history'],
                };
                $push($run->intake, 'run:'.$run->id.':finished', $run->finished_at, $phase, $run->status, $title, $detail, $status, $tone, $icon);
            }
        }
        $deliveryColumns = ['id', 'intake_id', 'status', 'question_round', 'created_at', 'updated_at', 'attempted_at', 'sent_at'];
        if ($this->outboxReady()) {
            $deliveryColumns = [...$deliveryColumns, 'message_type', 'approved_at', 'metadata'];
        }
        foreach (AiIntakeDelivery::query()->select($deliveryColumns)->with($relation)->latest('updated_at')->latest('id')->limit(30)->get() as $delivery) {
            if (! $delivery->intake) {
                continue;
            }
            $type = $delivery->message_type ?? 'clarification';
            $phase = match ($type) {
                'receipt' => 'customer_receipt', 'order_confirmation' => 'order_confirmation', default => 'clarification'
            };
            $label = match ($type) {
                'receipt' => 'Eingangsbestätigung', 'order_confirmation' => 'Auftragsbestätigung', default => 'Rückfrage'
            };
            $round = $type === 'clarification' && $delivery->question_round > 0 ? 'Rückfragerunde '.$delivery->question_round : $label;
            $draft = $delivery->metadata['created_as_draft'] ?? $delivery->status === 'draft';
            $push($delivery->intake, 'delivery:'.$delivery->id.':queued', $delivery->created_at, $phase, $draft ? 'draft' : 'pending', $label.' vorbereitet', $round.($draft ? ' wartet auf persönliche Freigabe' : ' für Versand vorgemerkt'), $draft ? 'Freigabe nötig' : 'Vorgemerkt', $draft ? 'warn' : 'info', 'fa-envelope');
            if ($delivery->approved_at) {
                $push($delivery->intake, 'delivery:'.$delivery->id.':approved', $delivery->approved_at, $phase, 'approved', $label.' persönlich freigegeben', 'Konkrete Nachricht wurde für den Versand freigegeben', 'Freigegeben', 'ok', 'fa-check');
            }
            if ($delivery->attempted_at) {
                $push($delivery->intake, 'delivery:'.$delivery->id.':attempted', $delivery->attempted_at, $phase, 'sending', $label.': Versand gestartet', $round.' · Versandversuch gespeichert', 'Gestartet', 'info', 'fa-paper-plane');
            }
            if ($delivery->status === 'sent' && $delivery->sent_at) {
                $push($delivery->intake, 'delivery:'.$delivery->id.':sent', $delivery->sent_at, $phase, 'sent', $label.' versendet', $round.' · an den Mailserver übergeben'.($type === 'clarification' ? '; Antwort ausstehend' : ''), 'Versendet', 'ok', 'fa-paper-plane');
            } elseif (in_array($delivery->status, ['unknown', 'canceled'], true)) {
                $unknown = $delivery->status === 'unknown';
                $push($delivery->intake, 'delivery:'.$delivery->id.':'.$delivery->status, $delivery->updated_at, $phase, $delivery->status, $label.($unknown ? ': Versandstatus unklar' : ' abgebrochen'), $unknown ? 'Keine automatische Wiederholung; Verlauf im Eingang prüfen' : 'Vorgemerkter Versand wurde abgebrochen', $unknown ? 'Prüfung nötig' : 'Abgebrochen', $unknown ? 'warn' : 'neutral', 'fa-envelope');
            }
        }
        foreach (AiIntakeProposal::query()->select(['id', 'intake_id', 'position_index', 'status', 'created_at', 'updated_at', 'approved_at', 'applied_at'])->with($relation)->latest('updated_at')->latest('id')->limit(30)->get() as $proposal) {
            if (! $proposal->intake) {
                continue;
            }
            $position = 'Leistung '.((int) $proposal->position_index + 1);
            $push($proposal->intake, 'proposal:'.$proposal->id.':prepared', $proposal->created_at, 'proposal', 'proposed', 'Leistung vorbereitet', $position.' · Vorschlag zur persönlichen Prüfung gespeichert', 'Vorbereitet', 'info', 'fa-clipboard-list');
            if ($proposal->approved_at) {
                $push($proposal->intake, 'proposal:'.$proposal->id.':approved', $proposal->approved_at, 'proposal', 'approved', 'Leistung persönlich freigegeben', $position.' · Freigabe der Grundlage gespeichert', 'Freigegeben', 'ok', 'fa-check');
            }
            if ($proposal->applied_at && $proposal->status === 'applied') {
                $push($proposal->intake, 'proposal:'.$proposal->id.':applied', $proposal->applied_at, 'proposal', 'applied', 'Planungsentwürfe erstellt', $position.' · Bedarf und Schichtentwürfe übernommen', 'Übernommen', 'ok', 'fa-calendar');
            } elseif (in_array($proposal->status, ['failed', 'stale'], true)) {
                $failed = $proposal->status === 'failed';
                $push($proposal->intake, 'proposal:'.$proposal->id.':'.$proposal->status, $proposal->updated_at, 'proposal', $proposal->status, $failed ? 'Leistungsentwurf prüfen' : 'Leistungsvorschlag nicht mehr aktuell', $position.' · '.($failed ? 'Übernahme benötigt eine persönliche Prüfung' : 'Vorschlag wird nicht automatisch übernommen'), $failed ? 'Prüfung nötig' : 'Veraltet', $failed ? 'warn' : 'neutral', 'fa-clipboard-list');
            }
        }
        $retries = OperationAudit::query()->select(['id', 'subject_id', 'created_at'])->where('action', 'ai.proposal.retry_approved')
            ->where('subject_type', class_basename(AiIntakeProposal::class))->latest('created_at')->latest('id')->limit(30)->get();
        $retryProposals = AiIntakeProposal::query()->select(['id', 'intake_id', 'position_index'])->with($relation)->whereIn('id', $retries->pluck('subject_id'))->get()->keyBy('id');
        foreach ($retries as $audit) {
            $proposal = $retryProposals->get($audit->subject_id);
            if ($proposal?->intake) {
                $push($proposal->intake, 'audit:'.$audit->id, CarbonImmutable::parse($audit->getRawOriginal('created_at'), 'UTC'), 'proposal', 'retry_approved', 'Entwurfserstellung erneut freigegeben', 'Leistung '.((int) $proposal->position_index + 1).' · erneuter Versuch wurde persönlich freigegeben', 'Freigegeben', 'info', 'fa-redo');
            }
        }

        return $items;
    }

    private function readActor(User $actor): ?User
    {
        return User::query()->where('status', true)->find($actor->id);
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
        if (! $intake->customer_id) {
            $missing->prepend('Kundenzuordnung');
        }
        if ($missing->isNotEmpty()) {
            return 'offen: '.$missing->implode(', ');
        }

        return match ($intake->status) {
            'received' => 'Auswertung ausstehend',
            'analyzing' => 'Wird ausgewertet',
            default => 'Keine offenen Angaben erfasst',
        };
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
