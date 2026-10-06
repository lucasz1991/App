<?php

namespace App\Livewire\Operations;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerCondition;
use App\Models\CustomerContact;
use App\Models\CustomerInteraction;
use App\Models\CustomerLocation;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerHistory extends Component
{
    use WithPagination;

    #[Locked]
    public int $customerId;

    public string $source = 'all';

    private const ACTIONS = [
        'inquiry.saved' => 'Anfrage gespeichert', 'inquiry.verify' => 'Bedarf bestätigt', 'inquiry.offer' => 'Angebot dokumentiert',
        'inquiry.accept' => 'Zusage dokumentiert', 'inquiry.convert' => 'Auftrag angelegt', 'inquiry.duplicate' => 'Dublette zugeordnet',
        'inquiry.reject' => 'Anfrage abgelehnt', 'inquiry.process.updated' => 'Anfragezuständigkeit aktualisiert',
        'inquiry.followup.created' => 'Wiedervorlage angelegt', 'inquiry.followup.completed' => 'Wiedervorlage erledigt',
        'customer.contact.saved' => 'Ansprechpartner gespeichert', 'customer.location.saved' => 'Einsatzort gespeichert',
        'customer.condition.created' => 'Kondition angelegt', 'customer.condition.ended' => 'Kondition beendet',
        'customer.interaction.recorded' => 'Kontakt protokolliert', 'commercial.draft.created' => 'Angebotsentwurf angelegt',
        'commercial.issued' => 'Angebot ausgegeben', 'commercial.accepted' => 'Angebot angenommen',
        'customer.created' => 'Kundenstammdaten angelegt', 'customer.updated' => 'Kundenstammdaten geändert',
    ];

    private const PORTAL_ACTIONS = [
        'setting_enabled' => 'Portal aktiviert', 'setting_disabled' => 'Portal deaktiviert',
        'membership_pending' => 'Portalzugang vorbereitet', 'membership_active' => 'Portalzugang freigegeben',
        'membership_suspended' => 'Portalzugang gesperrt', 'membership_revoked' => 'Portalzugang widerrufen',
        'invitation_created' => 'Portaleinladung erstellt', 'invitation_accepted' => 'Portaleinladung angenommen',
        'password_reset' => 'Portalpasswort zurückgesetzt', 'mfa_setup_started' => 'Zwei-Faktor-Einrichtung begonnen',
        'mfa_enabled' => 'Zwei-Faktor-Schutz aktiviert', 'mfa_disabled' => 'Zwei-Faktor-Schutz deaktiviert',
        'mfa_recovery_used' => 'Portal-Wiederherstellung bestätigt', 'mfa_challenge_verified' => 'Portal-Anmeldung bestätigt',
        'mfa_recovery_regenerated' => 'Wiederherstellung erneuert',
    ];

    public function mount(int $customerId): void
    {
        abort_unless($customerId > 0, 404);
        $this->customerId = $customerId;
        $this->access();
    }

    private function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User && $actor->status, 403);

        return $actor;
    }

    private function portalAllowed(User $actor): bool
    {
        if (! CustomerPortalSchema::ready() || ! $actor->can('customers.portal.manage')) {
            return false;
        }
        try {
            app(CustomerPortalScope::class)->authorizeManager($actor, $this->customerId);

            return true;
        } catch (AuthorizationException $exception) {
            return false;
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }

            return false;
        }
    }

    private function access(): User
    {
        $actor = $this->actor();
        abort_unless($actor->can('operations.manage') || $actor->can('operations.inquiries.manage') || $this->portalAllowed($actor), 403);
        Customer::findOrFail($this->customerId);

        return $actor;
    }

    private function sources(User $actor): array
    {
        return array_filter([
            'all' => 'Alle Aktivitäten',
            'operations' => Schema::hasTable('operation_audits') && ($actor->can('operations.manage') || $actor->can('operations.inquiries.manage')) ? 'Vorgänge & Kundenpflege' : null,
            'orders' => $actor->can('operations.manage') && Schema::hasTable('order_status_histories') ? 'Auftragsstatus' : null,
            'portal' => $this->portalAllowed($actor) ? 'Portalzugänge' : null,
            'metadata' => $actor->can('operations.manage') ? 'Stammdaten-Zeitpunkte' : null,
        ]);
    }

    public function updatedSource(): void
    {
        abort_unless(array_key_exists($this->source, $this->sources($this->access())), 403);
        $this->resetPage('customerHistoryPage');
    }

    private function columns(Builder $query, string $source, string $time, string $action, string $type, string $subject, string $actor): Builder
    {
        return $query->select(['id', DB::raw("'".$source."' as source"), DB::raw($time.' as occurred_at'), DB::raw($action.' as action'), DB::raw($type.' as subject_type'), DB::raw($subject.' as subject_id'), DB::raw($actor.' as actor_id')]);
    }

    private function operationalEvents(User $actor): ?Builder
    {
        if (! Schema::hasTable('operation_audits') || (! $actor->can('operations.manage') && ! $actor->can('operations.inquiries.manage'))) {
            return null;
        }
        $query = DB::table('operation_audits')->whereIn('action', array_keys(self::ACTIONS));
        $query->where(function (Builder $scope) use ($actor): void {
            $scope->whereRaw('1 = 0');
            if ($actor->can('operations.manage')) {
                $scope->orWhere(fn (Builder $q) => $q->where('subject_type', 'Customer')->where('subject_id', $this->customerId)->whereIn('action', ['customer.created', 'customer.updated']));
            }
            if ($actor->can('operations.inquiries.manage') && Schema::hasTable('operation_inquiries')) {
                $scope->orWhere(fn (Builder $q) => $q->where('subject_type', 'OperationInquiry')->whereIn('action', array_values(array_filter(array_keys(self::ACTIONS), fn ($action) => str_starts_with($action, 'inquiry.'))))->whereIn('subject_id', OperationInquiry::where('customer_id', $this->customerId)->select('id')));
                foreach (['CustomerContact' => [CustomerContact::class, 'customer_contacts', ['customer.contact.saved']], 'CustomerLocation' => [CustomerLocation::class, 'customer_locations', ['customer.location.saved']], 'CustomerCondition' => [CustomerCondition::class, 'customer_conditions', ['customer.condition.created', 'customer.condition.ended']]] as $type => [$model, $table, $actions]) {
                    if (Schema::hasTable($table)) {
                        $scope->orWhere(fn (Builder $q) => $q->where('subject_type', $type)->whereIn('action', $actions)->whereIn('subject_id', $model::where('customer_id', $this->customerId)->select('id')));
                    }
                }
            }
            if (CustomerInteraction::ready()) {
                $scope->orWhere(fn (Builder $q) => $q->where('subject_type', 'CustomerInteraction')->where('action', 'customer.interaction.recorded')->whereIn('subject_id', CustomerInteraction::where('customer_id', $this->customerId)->select('id')));
            }
            if (Schema::hasTable('commercial_offer_revisions')) {
                $scope->orWhere(function (Builder $offers) use ($actor): void {
                    $offers->where('subject_type', 'CommercialOfferRevision')->whereIn('action', ['commercial.draft.created', 'commercial.issued', 'commercial.accepted'])->whereIn('subject_id', DB::table('commercial_offer_revisions')->select('id')->where(function (Builder $subjects) use ($actor): void {
                        $subjects->whereRaw('1 = 0');
                        if ($actor->can('operations.manage')) {
                            $subjects->orWhere(fn (Builder $q) => $q->where('subject_type', 'Order')->whereIn('subject_id', Order::where('customer_id', $this->customerId)->select('id')));
                        }
                        if ($actor->can('operations.inquiries.manage') && Schema::hasTable('operation_inquiries')) {
                            $subjects->orWhere(fn (Builder $q) => $q->where('subject_type', 'OperationInquiry')->whereIn('subject_id', OperationInquiry::where('customer_id', $this->customerId)->select('id')));
                        }
                    }));
                });
            }
        });

        return $this->columns($query, 'operations', 'created_at', 'action', 'subject_type', 'subject_id', 'actor_id');
    }

    /** UTC audits and application-local legacy timestamps must be ordered by the same instant. */
    private function chronologicalExpression(Builder $union): string
    {
        $column = 'customer_events.occurred_at';
        $range = DB::query()->fromSub(clone $union, 'customer_event_range')
            ->selectRaw('MIN(occurred_at) as earliest, MAX(occurred_at) as latest')->first();
        if (! $range?->earliest || ! $range->latest) {
            return $column;
        }
        $timezone = new DateTimeZone(config('app.timezone', 'UTC'));
        // The envelope accounts for local offsets without fetching individual history rows.
        $from = CarbonImmutable::parse($range->earliest, 'UTC')->subDays(2);
        $until = CarbonImmutable::parse($range->latest, 'UTC')->addDays(2);
        $transitions = $timezone->getTransitions($from->timestamp, $until->timestamp) ?: [];
        $baseOffset = (int) ($transitions[0]['offset'] ?? $timezone->getOffset($from));
        $driver = DB::connection()->getDriverName();
        abort_unless(in_array($driver, ['sqlite', 'mysql'], true), 503, 'Verlaufszeitraum nicht verfügbar.');
        $shift = static function (int $offset) use ($column, $driver): string {
            if ($offset === 0) {
                return $column;
            }

            return $driver === 'sqlite'
                ? "datetime(".$column.", '".(-$offset >= 0 ? '+' : '').(-$offset)." seconds')"
                : 'DATE_SUB('.$column.', INTERVAL '.$offset.' SECOND)';
        };
        $clauses = [];
        foreach (array_reverse(array_slice($transitions, 1)) as $transition) {
            $offset = (int) $transition['offset'];
            $boundary = CarbonImmutable::createFromTimestampUTC((int) $transition['ts'] + $offset)->format('Y-m-d H:i:s');
            // Literals come only from timezone offsets and formatted transition instants.
            $clauses[] = "WHEN ".$column." >= '".$boundary."' THEN ".$shift($offset);
        }
        $local = $clauses ? 'CASE '.implode(' ', $clauses).' ELSE '.$shift($baseOffset).' END' : $shift($baseOffset);

        return "CASE WHEN customer_events.source = 'operations' THEN ".$column.' ELSE '.$local.' END';
    }

    public function render()
    {
        $actor = $this->access();
        $sources = $this->sources($actor);
        abort_unless(array_key_exists($this->source, $sources), 403);
        $queries = [];
        if (in_array($this->source, ['all', 'operations'], true) && ($query = $this->operationalEvents($actor))) {
            $queries[] = $query;
        }
        if (in_array($this->source, ['all', 'orders'], true) && isset($sources['orders'])) {
            $queries[] = $this->columns(DB::table('order_status_histories')->whereIn('order_id', Order::where('customer_id', $this->customerId)->select('id')), 'orders', 'created_at', 'to_status', "'Order'", 'order_id', 'changed_by');
        }
        if (in_array($this->source, ['all', 'portal'], true) && isset($sources['portal'])) {
            $queries[] = $this->columns(DB::table('customer_portal_audits')->where('customer_id', $this->customerId)->whereIn('action', array_keys(self::PORTAL_ACTIONS)), 'portal', 'created_at', 'action', "'Customer'", 'customer_id', 'actor_id');
        }
        if (in_array($this->source, ['all', 'metadata'], true) && isset($sources['metadata'])) {
            $queries[] = $this->columns(DB::table('customers')->where('id', $this->customerId)->whereNotNull('created_at'), 'metadata', 'created_at', "'customer_created'", "'Customer'", 'id', 'NULL');
            $queries[] = $this->columns(DB::table('customers')->where('id', $this->customerId)->whereColumn('updated_at', '>', 'created_at'), 'metadata', 'updated_at', "'customer_updated'", "'Customer'", 'id', 'NULL');
        }
        $union = array_shift($queries);
        if (! $union) {
            $union = $this->columns(DB::table('customers')->whereRaw('1 = 0'), 'metadata', 'created_at', "'customer_created'", "'Customer'", 'id', 'NULL');
        }
        foreach ($queries as $query) {
            $union->unionAll($query);
        }
        $chronological = $this->chronologicalExpression($union);
        $events = DB::query()->fromSub($union, 'customer_events')->select('customer_events.*')->selectRaw($chronological.' as chronological_at')
            ->orderByDesc('chronological_at')->orderBy('source')->orderByDesc('id')->paginate(20, ['*'], 'customerHistoryPage');
        $actors = User::whereIn('id', $events->getCollection()->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $inquiryIds = $events->getCollection()->where('subject_type', 'OperationInquiry')->pluck('subject_id')->unique();
        $currentInquiries = $actor->can('operations.inquiries.manage') && Schema::hasTable('operation_inquiries')
            ? OperationInquiry::where('customer_id', $this->customerId)->whereIn('id', $inquiryIds)->get(['id', 'channel', 'status', 'revision'])->keyBy('id')
            : collect();
        $portalSubmissions = collect();
        $canPortalInbox = $this->portalAllowed($actor) && CustomerPortalIntakeSchema::ready() && CustomerPortalWorkflowSchema::ready();
        if ($canPortalInbox && $inquiryIds->isNotEmpty()) {
            $portalSubmissions = DB::table('customer_portal_submission_items as item')->join('customer_portal_submissions as submission', 'submission.id', '=', 'item.submission_id')
                ->where('item.customer_id', $this->customerId)->where('submission.customer_id', $this->customerId)->whereIn('item.inquiry_id', $inquiryIds)
                ->get(['item.inquiry_id', 'submission.id', 'submission.revision'])->keyBy('inquiry_id');
        }
        $events->setCollection($events->getCollection()->map(function ($event) use ($actors, $actor, $currentInquiries, $portalSubmissions) {
            $event->label = match ($event->source) {
                'orders' => 'Auftragsstatus: '.(OrderStatus::tryFrom($event->action)?->label() ?? 'Aktualisiert'),
                'portal' => self::PORTAL_ACTIONS[$event->action],
                'metadata' => $event->action === 'customer_created' ? 'Kunde angelegt' : 'Stammdaten zuletzt gespeichert',
                default => self::ACTIONS[$event->action],
            };
            $event->source_label = ['operations' => str_starts_with($event->action, 'inquiry.followup.') ? 'Wiedervorlage · Aufgabe' : 'Vorgang', 'orders' => 'Auftragsstatus', 'portal' => 'Portalzugang', 'metadata' => 'Datensatz-Zeitpunkt'][$event->source];
            $event->actor_label = $event->actor_id ? ($actors[$event->actor_id] ?? 'Verwaltung') : ($event->source === 'metadata' ? '—' : 'Kundenportal / System');
            $event->occurred_at = CarbonImmutable::parse($event->occurred_at, $event->source === 'operations' ? 'UTC' : config('app.timezone'))->utc();
            $inquiry = $event->subject_type === 'OperationInquiry' ? $currentInquiries->get($event->subject_id) : null;
            $submission = $inquiry ? $portalSubmissions->get($inquiry->id) : null;
            $target = match (true) {
                $inquiry && $submission => ['view' => 'inbox', 'section' => 'portal', 'source' => 'submission', 'record' => $submission->id, 'revision' => $submission->revision],
                $inquiry && $inquiry->channel !== 'portal' => ['view' => 'inbox', 'section' => 'overview', 'inquiry' => $inquiry->id, 'revision' => $inquiry->revision],
                $event->subject_type === 'Order' && $actor->can('operations.manage') => ['view' => 'orders', 'order' => $event->subject_id],
                default => null,
            };
            $event->href = $target && Route::has('operations.page') ? route('operations.page', ['page' => 'cases', 'customer' => $this->customerId, 'detail' => 'history'] + $target) : null;
            $event->id = (int) hexdec(substr(hash('sha256', $event->source.':'.$event->id.':'.$event->action), 0, 12));

            return $event;
        }));

        return view('livewire.operations.customer-history', compact('events', 'sources'));
    }
}
