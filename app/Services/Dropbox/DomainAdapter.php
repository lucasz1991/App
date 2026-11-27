<?php

namespace App\Services\Dropbox;

use App\Enums\OrderStatus;
use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Customer;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\EmployeeCompetencyFact;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\WorkTimeEntry;
use App\Services\Operations\OrderSchedulingService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\ShiftSchedulingService;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DomainAdapter
{
    private const ASSIGNMENT_IDENTITY_REVIEW = 'Mitarbeiter oder Dienstleister zuerst unter Zuordnungen bestätigen.';

    public function __construct(private EmployeeIdentityResolver $identityResolver) {}

    public function identity(DropboxConnection $connection, string $name, string $kind = 'unresolved', bool $historical = false, bool $resolve = true): DropboxIdentity
    {
        $identity = SyncContext::import(fn () => DropboxIdentity::firstOrCreate([
            'connection_id' => $connection->id, 'alias' => WorkbookReader::normalize($name),
        ], ['kind' => $kind, 'details' => ['display_name' => trim($name)]]));

        return ! $resolve || $connection->isLocalImport() || $kind === 'provider' ? $identity : $this->identityResolver->resolve($connection, $identity, $historical);
    }

    public function contactIdentity(DropboxConnection $connection, array $locator): DropboxIdentity
    {
        $kind = $locator['kind'];
        $this->validateContactRoleEvidence($locator);
        $identity = $this->identity($connection, $locator['subject'], $kind === 'provider' ? 'provider' : 'unresolved');
        $this->validateContactKind($identity, $kind);

        return $identity;
    }

    public function validateContactRoleEvidence(array $locator): void
    {
        if ($locator['contact_role_conflict'] ?? false) {
            throw ValidationException::withMessages(['sync' => 'Die Dienstleisterangabe überschneidet sich mit einer Mitarbeiteridentität. Rolle ausdrücklich prüfen.']);
        }
    }

    public function validateContactMapping(DropboxRecord $record, string $kind): void
    {
        $identity = DropboxIdentity::where('connection_id', $record->connection_id)->lockForUpdate()->findOrFail($record->model_id);
        $this->validateContactKind($identity, $kind, $record->metadata['kind'] ?? null);
    }

    private function validateContactKind(DropboxIdentity $identity, string $kind, ?string $registeredKind = null): void
    {
        $compatible = match ($kind) {
            'provider' => $identity->kind === 'provider' && ! $identity->user_id,
            'employee' => in_array($identity->kind, ['employee', 'unresolved'], true),
            default => false,
        };
        if (! $compatible || (in_array($registeredKind, ['employee', 'provider'], true) && $registeredKind !== $kind)) {
            throw ValidationException::withMessages(['sync' => 'Der Excel-Kontakt passt nicht zur vorhandenen Mitarbeiter- oder Dienstleisterzuordnung. Bitte die Bezeichnung in Excel oder ihre Zuordnung prüfen.']);
        }
    }

    public function current(DropboxRecord $record): array
    {
        if (($record->metadata['kind'] ?? '') === 'availability_submission') {
            return app(NativeAvailabilityWorkbook::class)->current($record);
        }
        if (isset($record->metadata['historical_values'])) {
            return $record->metadata['historical_values'];
        }
        if ($record->domain === 'planning') {
            $shift = Shift::with('order.customer')->find($record->model_id);
            if (! $shift) {
                throw ValidationException::withMessages(['sync' => 'Datensatz fehlt in der App; Löschung muss geprüft werden.']);
            }
            $zone = $shift->timezone;
            $assignment = $record->assignment_id ? ShiftAssignment::with('user')->find($record->assignment_id) : null;
            $details = $shift->disposition_details ?? [];
            $time = $assignment ? WorkTimeEntry::where('shift_assignment_id', $assignment->id)->first() : null;
            $actualEnd = array_key_exists('actual_end', $record->metadata ?? []) ? $record->metadata['actual_end'] : ($details['actual_end'] ?? null);
            if ($time?->ends_at && ($record->metadata['time_entry_version'] ?? '') !== $this->timeVersion($time)) {
                $actualEnd = $time->ends_at->setTimezone($zone)->format('H:i');
            }
            $employee = $record->metadata['employee_label'] ?? ($details['provider_label'] ?? '');
            if ($assignment) {
                $identity = DropboxIdentity::where('connection_id', $record->connection_id)->where('user_id', $assignment->user_id)->where('kind', 'employee')->first();
                $employee = $identity?->details['display_name'] ?? $assignment->user->name;
                if ((int) ($record->metadata['user_id'] ?? 0) === $assignment->user_id && isset($record->metadata['employee_label'])) {
                    $employee = $record->metadata['employee_label'];
                }
            }
            $cancelled = $shift->status === ShiftStatus::Cancelled
                || in_array($assignment?->status, [ShiftAssignmentStatus::Cancelled, ShiftAssignmentStatus::Declined], true);
            $values = [
                'location' => $shift->location_name ?? '', 'date' => $shift->starts_at->setTimezone($zone)->format('Y-m-d'),
                'starts' => $shift->starts_at->setTimezone($zone)->format('H:i'), 'ends' => $shift->ends_at->setTimezone($zone)->format('H:i'),
                'actual_end' => $actualEnd, 'employee' => $employee,
                'train_reference' => $details['train_reference'] ?? '', 'notes' => $shift->notes ?? '',
                'role' => $shift->role_name ?? '', 'customer' => $shift->order->customer->company_name,
                'ordered_at' => $details['ordered_at'] ?? '', 'cancellation' => $record->metadata['cancellation'] ?? $details['cancellation'] ?? '',
                'information' => $details['information'] ?? '', 'billing_notes' => $details['billing_notes'] ?? '', 'other' => $details['other'] ?? '',
                'draft' => $shift->status !== ShiftStatus::Completed && ((int) $shift->published_revision !== (int) $shift->revision || ! $shift->published_revision),
                'cancelled' => $cancelled,
            ];
            if ($cancelled && ! preg_match('/storno|storniert/iu', $values['cancellation'])) {
                $values['cancellation'] = trim('Storno '.$values['cancellation']);
            }

            return [...$values, ...app(PlanningServiceProjection::class)->current($record, $shift->order)];
        }
        if ($record->domain === 'contacts') {
            $identity = DropboxIdentity::findOrFail($record->model_id);
            $values = $identity->details ?? [];
            unset($values['display_name']);
            if ($identity->kind === 'employee' && $identity->user_id && ($profile = UserProfile::where('user_id', $identity->user_id)->first())) {
                $values['first_name'] ??= $profile->first_name ?? '';
                $values['last_name'] ??= $profile->last_name ?? '';
                foreach (['first_name', 'last_name', 'phone', 'mobile', 'city', 'birth_date', 'birth_place', 'nationality'] as $field) {
                    if (array_key_exists($field, $values)) {
                        $v = $profile->{$field};
                        $values[$field] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : trim((string) $v);
                    }
                }
            }

            return array_map(fn ($value) => $value === null ? '' : $value, $values);
        }

        return EmployeeCompetencyFact::findOrFail($record->model_id)->value;
    }

    public function create(DropboxConnection $connection, array $entry): DropboxRecord
    {
        return OperationsTransaction::run(function () use ($connection, $entry) {
            if (($entry['locator']['kind'] ?? '') === 'availability_submission') {
                return SyncContext::import(fn () => app(NativeAvailabilityWorkbook::class)->create($connection, $entry));
            }
            if ($entry['domain'] === 'planning') {
                $metadata = ['imported_order' => true];
                $evidence = $entry['locator']['employee_evidence'] ?? null;
                if ($evidence && ($entry['values']['employee'] ?? '') === $evidence['label']) {
                    $metadata['employee_evidence'] = $evidence;
                }
                $record = new DropboxRecord(['connection_id' => $connection->id, 'domain' => 'planning', 'model_type' => 'Shift', 'model_id' => 0, 'metadata' => $metadata]);
                $this->apply($connection, $record, $entry['values']);

                return $record;
            }
            $loc = $entry['locator'];
            if ($entry['domain'] === 'contacts') {
                $identity = $this->contactIdentity($connection, $loc);
                $record = DropboxRecord::firstOrCreate(['connection_id' => $connection->id, 'domain' => 'contacts', 'model_type' => 'DropboxIdentity', 'model_id' => $identity->id], ['metadata' => ['kind' => $loc['kind']]]);
                $this->validateContactKind($identity, $loc['kind'], $record->metadata['kind'] ?? null);
            } else {
                $identity = $this->identity($connection, $loc['subject']);
                $fact = EmployeeCompetencyFact::where('identity_id', $identity->id)->where('kind', $loc['kind'])->where('name', $loc['name'])->where('scope', $loc['scope'])->first();
                if (! $fact) {
                    $fact = SyncContext::import(fn () => EmployeeCompetencyFact::create(['identity_id' => $identity->id, 'kind' => $loc['kind'], 'name' => $loc['name'], 'scope' => $loc['scope'], 'value' => $entry['values']]));
                }
                $record = DropboxRecord::firstOrCreate(['connection_id' => $connection->id, 'domain' => 'competencies', 'model_type' => 'EmployeeCompetencyFact', 'model_id' => $fact->id], ['metadata' => $loc]);
            }
            $this->apply($connection, $record, $entry['values']);

            return $record;
        }, 1);
    }

    public function apply(DropboxConnection $connection, DropboxRecord $record, array $values): void
    {
        if (isset($record->metadata['historical_values'])) {
            throw ValidationException::withMessages(['sync' => 'Diese frühere Mitarbeiterzuordnung ist storniert. Änderungen am aktuellen Einsatz in der Hauptübersicht vornehmen.']);
        }
        SyncContext::import(function () use ($connection, $record, $values) {
            if (($record->metadata['kind'] ?? '') === 'availability_submission') {
                app(NativeAvailabilityWorkbook::class)->apply($connection, $record, $values);

                return;
            }
            if ($record->domain === 'planning') {
                $this->planning($connection, $record, $values);

                return;
            }
            if ($record->domain === 'contacts') {
                $identity = DropboxIdentity::where('connection_id', $connection->id)->lockForUpdate()->findOrFail($record->model_id);
                $kind = $record->metadata['kind'] ?? null;
                if (in_array($kind, ['employee', 'provider'], true)) {
                    $this->validateContactKind($identity, $kind);
                }
                $allowed = ['first_name', 'last_name', 'name', 'reported_qualifications', 'contact_email', 'phone', 'mobile', 'city', 'deployment_region', 'birth_date', 'birth_place', 'nationality', 'notes'];
                $values = array_intersect_key($values, array_flip($allowed));
                Validator::make($values, ['contact_email' => 'nullable|email|max:254', 'birth_date' => 'nullable|date_format:Y-m-d', '*' => 'nullable|string|max:4000'])->validate();
                $identity->details = array_merge($identity->details ?? [], $values);
                $identity->revision++;
                $identity->save();
                if ($identity->kind === 'employee' && $identity->user_id) {
                    $fields = array_intersect_key($values, array_flip(['first_name', 'last_name', 'phone', 'mobile', 'city', 'birth_date', 'birth_place', 'nationality']));
                    UserProfile::updateOrCreate(['user_id' => $identity->user_id], $fields);
                }

                return;
            }
            Validator::make($values, ['value' => 'nullable|string|max:4000', 'color' => ['nullable', 'regex:/^[0-9A-F]{8}$/D']])->validate();
            $fact = EmployeeCompetencyFact::lockForUpdate()->findOrFail($record->model_id);
            $fact->value = ['value' => (string) ($values['value'] ?? ''), 'color' => $values['color'] ?? null];
            $fact->revision++;
            $fact->save();
        });
    }

    private function planning(DropboxConnection $connection, DropboxRecord $record, array $values): void
    {
        Validator::make($values, [
            'date' => 'required|date_format:Y-m-d', 'starts' => 'required|date_format:H:i', 'ends' => 'required|date_format:H:i',
            'customer' => 'required|string|max:191', 'location' => 'required|string|max:191', 'role' => 'nullable|string|max:191',
            'employee' => 'nullable|string|max:191', 'notes' => 'nullable|string|max:20000', 'actual_end' => 'nullable|date_format:H:i',
            'service_reference' => 'nullable|string|max:191', 'service_title' => 'nullable|string|max:191',
            'service_type' => 'nullable|string|max:191', 'service_location' => 'nullable|string|max:191',
        ])->validate();
        $actor = User::findOrFail(1);
        abort_unless($actor->isSuperAdmin() && $actor->status, 403);
        $zone = $connection->option('timezone');
        $date = $values['date'];
        // The workbook supplies only the start date. Determine the local end date before interpreting either clock time.
        $endDate = $values['ends'] <= $values['starts'] ? CarbonImmutable::parse($date, 'UTC')->addDay()->toDateString() : $date;
        $start = OperationsDateTime::local($date.' '.$values['starts'], $zone, 'starts');
        $end = OperationsDateTime::local($endDate.' '.$values['ends'], $zone, 'ends');
        $identity = ! empty($values['employee']) ? $this->identity($connection, $values['employee'], historical: ($record->metadata['imported_order'] ?? false) && $end->isPast()) : null;
        $evidence = $record->metadata['employee_evidence'] ?? null;
        if ($evidence && ($values['employee'] ?? '') !== $evidence['label']) {
            $metadata = $record->metadata;
            unset($metadata['employee_evidence']);
            $record->metadata = $metadata;
        }
        $this->validatePlanningEmployeeEvidence($record, $identity);
        $retainDraft = (bool) $connection->option('retain_unassigned_drafts') && ($record->metadata['imported_order'] ?? false)
            && (! $record->model_id || (($record->metadata['assignment_pending'] ?? false) && ! Shift::findOrFail($record->model_id)->published_revision));
        $assignmentPending = null;
        if ($identity && ! in_array($identity->kind, ['employee', 'provider'], true)) {
            if ($retainDraft) {
                $assignmentPending = ['sync' => [self::ASSIGNMENT_IDENTITY_REVIEW]];
            } else {
                throw ValidationException::withMessages(['sync' => self::ASSIGNMENT_IDENTITY_REVIEW]);
            }
        }
        $assignee = $identity?->kind === 'employee' && $identity->user_id ? User::find($identity->user_id) : null;
        if ($identity?->kind === 'employee' && $assignee?->role !== 'staff') {
            throw ValidationException::withMessages(['sync' => 'Die bestätigte Zuordnung verweist auf kein Mitarbeiterkonto.']);
        }
        $customers = Customer::where('company_name', $values['customer'])->get();
        if ($customers->count() > 1) {
            throw ValidationException::withMessages(['sync' => 'Kundenbezeichnung ist mehrdeutig.']);
        }
        $customer = $customers->first() ?? Customer::create(['company_name' => $values['customer'], 'is_active' => true]);
        $shift = $record->model_id ? Shift::lockForUpdate()->findOrFail($record->model_id) : new Shift;
        if ($shift->status === ShiftStatus::Cancelled && ! ($values['cancelled'] ?? false)) {
            throw ValidationException::withMessages(['sync' => 'Eine stornierte Schicht kann durch Entfernen des Excel-Stornos nicht wieder geöffnet werden. Die Stornorücknahme bitte in der App prüfen.']);
        }
        $serviceOnly = false;
        if ($shift->exists) {
            $current = $this->current($record);
            $changed = array_filter($values, fn ($value, $key) => ($current[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
            $serviceOnly = $changed && ! array_diff(array_keys($changed), PlanningServiceProjection::FIELDS);
            if ($changed && ! array_diff(array_keys($changed), ['actual_end'])) {
                $time = $record->assignment_id ? WorkTimeEntry::where('shift_assignment_id', $record->assignment_id)->first() : null;
                $record->metadata = array_merge($record->metadata ?? [], ['actual_end' => $values['actual_end'], 'time_entry_version' => $time ? $this->timeVersion($time) : null, 'actual_end_origin' => 'reported']);
                $record->save();

                return;
            }
        }
        $order = app(PlanningServiceProjection::class)->order($connection, $record, $shift, $customer, $values);
        if ($order->exists && $order->customer_id !== $customer->id && $order->shifts()->where('id', '!=', $shift->id)->exists()) {
            throw ValidationException::withMessages(['sync' => 'Kundenwechsel betrifft weitere Schichten dieses Auftrags. Bitte in der Auftragsverwaltung prüfen.']);
        }
        $order = app(OrderSchedulingService::class)->save($order, [
            'customer_id' => $customer->id, ...app(PlanningServiceProjection::class)->attributes($order, $values, ! $shift->exists),
            'status' => $order->status ?? OrderStatus::Requested,
            'priority' => $order->priority ?? 'normal', 'timezone' => $zone, 'required_staff' => $order->required_staff ?: 1,
            'starts_at' => $order->exists ? min($order->starts_at->utc(), $start) : $start,
            'ends_at' => $order->exists ? max($order->ends_at->utc(), $end) : $end,
        ], $actor);
        $details = array_intersect_key($values, array_flip(['train_reference', 'ordered_at', 'cancellation', 'information', 'billing_notes', 'other', 'actual_end']));
        if ($identity?->kind === 'provider') {
            $details['provider_label'] = $values['employee'];
        }
        $oldAssignment = $record->assignment_id ? ShiftAssignment::lockForUpdate()->find($record->assignment_id) : null;
        if ($oldAssignment && (int) $oldAssignment->shift_id !== (int) $shift->id) {
            throw ValidationException::withMessages(['sync' => 'Die bisherige Mitarbeiterzuordnung gehört zu einer anderen Schicht.']);
        }
        if (! $oldAssignment && $assignee && $shift->exists) {
            $oldAssignment = $shift->assignments()->where('user_id', $assignee->id)->lockForUpdate()->first();
            if ($oldAssignment && $this->assignmentAlreadyMapped($record, $oldAssignment)) {
                throw ValidationException::withMessages(['sync' => 'Die Mitarbeiterzuordnung gehört bereits zu einem anderen Excel-Datensatz.']);
            }
            $record->assignment_id = $oldAssignment?->id;
        }
        $cancelled = (bool) ($values['cancelled'] ?? false);
        $personChanged = $oldAssignment && (int) $oldAssignment->user_id !== (int) $identity?->user_id;
        if ($oldAssignment?->status === ShiftAssignmentStatus::Confirmed && ($personChanged || ($cancelled && $shift->status !== ShiftStatus::Cancelled))) {
            throw ValidationException::withMessages(['sync' => 'Die bestätigte Mitarbeiterzuordnung muss in der Einsatzplanung geändert werden.']);
        }
        $retiredAssignment = in_array($oldAssignment?->status, [ShiftAssignmentStatus::Cancelled, ShiftAssignmentStatus::Declined], true);
        if ($oldAssignment) {
            $details['cancellation'] = $shift->disposition_details['cancellation'] ?? '';
            $details['actual_end'] = $shift->disposition_details['actual_end'] ?? null;
        }
        $cancelAssignmentOnly = $cancelled && $oldAssignment && ($retiredAssignment
            || $shift->assignments()->where('id', '!=', $oldAssignment->id)->whereNotIn('status', ['cancelled', 'declined'])->exists());
        if ($cancelAssignmentOnly && ! $retiredAssignment && $oldAssignment->status !== ShiftAssignmentStatus::Reported) {
            app(ShiftAssignmentService::class)->cancel($oldAssignment, $actor, 'Excel-Stornierung dieser Zuordnung');
        }
        if ($personChanged && $oldAssignment->status !== ShiftAssignmentStatus::Reported) {
            if (! $retiredAssignment) {
                app(ShiftAssignmentService::class)->cancel($oldAssignment, $actor, 'Excel-Zuordnung geändert');
            }
            $record->assignment_id = null;
        }
        $shiftAttributes = [
            'order_id' => $order->id, 'title' => $shift->title ?: $values['location'], 'role_name' => $values['role'] ?: 'Einsatz',
            'starts_at' => $start, 'ends_at' => $end, 'timezone' => $zone, 'location_name' => $values['location'],
            'required_staff' => $shift->required_staff ?: 1, 'notes' => $values['notes'] ?? '', 'disposition_details' => $details,
            'status' => ($values['cancelled'] ?? false) && ! $cancelAssignmentOnly ? ShiftStatus::Cancelled : ($shift->status ?? ShiftStatus::Draft),
        ];
        // A service edit must preserve nullable notes and every existing shift-only detail.
        if ($serviceOnly) {
            $shiftAttributes['notes'] = $shift->notes;
            $shiftAttributes['disposition_details'] = $shift->disposition_details;
        }
        $candidate = clone $shift;
        $candidate->fill($shiftAttributes);
        // Pure service edits must not reschedule a shift that already has time proof.
        if (! $shift->exists || ! $serviceOnly || $candidate->isDirty()) {
            $shift = app(ShiftSchedulingService::class)->save($shift, [...$shiftAttributes, 'expected_revision' => $shift->revision], $actor);
        }
        $historical = ($record->metadata['imported_order'] ?? false)
            && (($shift->status === ShiftStatus::Completed && $end->isPast())
                || app(HistoricalImportCompletion::class)->canComplete($record, $shift, $connection));
        if ($historical || $oldAssignment?->status === ShiftAssignmentStatus::Reported) {
            $record->assignment_id = app(HistoricalEmployeeAssignmentService::class)->sync($connection, $record, $shift, $identity, $actor, $historical, (bool) ($values['cancelled'] ?? false), ! ($values['cancelled'] ?? false))?->id;
            $oldAssignment = $record->assignment_id ? ShiftAssignment::find($record->assignment_id) : null;
        }
        if ($identity?->kind === 'employee' && ! $cancelled && ! $historical) {
            if (! $oldAssignment || $oldAssignment->user_id !== $identity->user_id || ! $oldAssignment->status->blocksAvailability()) {
                try {
                    $assignment = $shift->assignments()->where('user_id', $identity->user_id)->lockForUpdate()->first();
                    if ($assignment && ! $assignment->status->blocksAvailability()) {
                        throw ValidationException::withMessages(['sync' => 'Zu dieser Person liegt bereits eine beendete Mitarbeiterzuordnung vor. Bitte in der Einsatzplanung prüfen.']);
                    }
                    if ($assignment && $this->assignmentAlreadyMapped($record, $assignment)) {
                        throw ValidationException::withMessages(['sync' => 'Die Mitarbeiterzuordnung gehört bereits zu einem anderen Excel-Datensatz.']);
                    }
                    $assignment ??= app(ShiftAssignmentService::class)->assign($shift, $assignee, $actor, ShiftAssignmentStatus::Requested);
                    $record->assignment_id = $assignment->id;
                } catch (ValidationException $e) {
                    if (! $retainDraft) {
                        throw $e;
                    }
                    $assignmentPending = $e->errors();
                }
            }
        }
        $record->forceFill(['model_id' => $shift->id, 'metadata' => array_merge($record->metadata ?? [], ['employee_label' => $values['employee'] ?? '', 'user_id' => $identity?->user_id, 'cancellation' => $values['cancellation'] ?? '', 'actual_end' => $values['actual_end'] ?? null, 'assignment_pending' => $assignmentPending, 'native_service_projection' => 1])])->save();
        if ($assignmentPending) {
            app(ConflictStore::class)->put($connection, null, $record, 'assignment_pending', ['messages' => $assignmentPending, 'values' => $values]);
        } else {
            DropboxConflict::where('record_id', $record->id)->where('reason', 'assignment_pending')->update(['state' => 'resolved']);
        }
    }

    /** Recheck only old role placeholders when source chunk progress would otherwise skip their rows. */
    public function newlyResolvedAssignmentRecords(DropboxConnection $connection, array $recordIds): Collection
    {
        $pending = DropboxConflict::where('connection_id', $connection->id)->where('reason', 'assignment_pending')
            ->whereIn('state', ['open', 'rechecking'])->whereIntegerInRaw('record_id', $recordIds)->get()
            ->filter(fn ($conflict) => in_array(self::ASSIGNMENT_IDENTITY_REVIEW, $conflict->snapshot['messages']['sync'] ?? [], true))
            ->mapWithKeys(fn ($conflict) => [$conflict->record_id => WorkbookReader::normalize($conflict->snapshot['values']['employee'] ?? '')]);
        if ($pending->isEmpty()) {
            return collect();
        }
        $known = DropboxIdentity::where('connection_id', $connection->id)->whereIn('alias', $pending->unique()->values())
            ->where(fn ($query) => $query->where('kind', 'provider')->whereNull('user_id')
                ->orWhere(fn ($employee) => $employee->where('kind', 'employee')->whereNotNull('user_id')))->pluck('alias')->flip();

        return $pending->filter(fn ($alias) => $known->has($alias));
    }

    /** Resolve the independent supplier role without applying a conflicted plan payload. */
    public function completeProviderRoleDuringFieldConflict(DropboxConnection $connection, DropboxRecord $record, array $local, array $rows, array $conflicts): bool
    {
        $allowedConflicts = ['actual_end', 'notes'];
        $metadata = $record->metadata ?? [];
        if ($record->domain !== 'planning' || $record->model_type !== 'Shift' || ! ($metadata['imported_order'] ?? false)
            || ! in_array('assignments', $connection->option('domains'), true) || $record->assignment_id || ($metadata['user_id'] ?? null)
            || ! in_array(self::ASSIGNMENT_IDENTITY_REVIEW, $metadata['assignment_pending']['sync'] ?? [], true)
            || ! $conflicts || array_diff(array_keys($conflicts), $allowedConflicts)) {
            return false;
        }
        $label = $metadata['employee_label'] ?? '';
        if ($label === '' || $label !== ($local['employee'] ?? null)) {
            return false;
        }
        $provider = DropboxIdentity::where('connection_id', $connection->id)->where('alias', WorkbookReader::normalize($label))
            ->where('kind', 'provider')->whereNull('user_id')->lockForUpdate()->first();
        if (! $provider || ! DropboxAppearance::where('locator->kind', 'provider')->where('slot', 'not like', 'missing:%')
            ->whereIn('record_id', DropboxRecord::where('connection_id', $connection->id)->where('domain', 'contacts')->where('model_type', 'DropboxIdentity')
                ->where('model_id', $provider->id)->where('metadata->kind', 'provider')->select('id'))
            ->whereIn('source_id', DropboxSource::where('connection_id', $connection->id)->whereNotIn('state', ['missing', 'excluded'])->select('id'))->exists()) {
            return false;
        }
        $shift = Shift::lockForUpdate()->findOrFail($record->model_id);
        $details = $shift->disposition_details ?? [];
        if ($shift->published_revision || $shift->published_at || $shift->assignments()->exists() || ($metadata['historical_assignment_id'] ?? null)
            || (filled($details['provider_label'] ?? null) && $details['provider_label'] !== $label)) {
            return false;
        }

        $sources = collect($rows)->pluck('excel')->concat(DropboxAppearance::where('record_id', $record->id)->get(['last_excel'])->pluck('last_excel'));
        $ignored = array_fill_keys($allowedConflicts, true);
        foreach ($sources as $source) {
            if (($source['employee'] ?? null) !== $label) {
                return false;
            }
            foreach (array_diff_key($source, $ignored) as $field => $value) {
                if (($value ?? '') !== ($local[$field] ?? '')) {
                    return false;
                }
            }
        }

        SyncContext::import(fn () => $shift->update(['disposition_details' => [...$details, 'provider_label' => $label]]));
        $record->update(['metadata' => [...$metadata, 'assignment_pending' => null]]);
        DropboxConflict::where('connection_id', $connection->id)->where('record_id', $record->id)->where('reason', 'assignment_pending')
            ->whereIn('state', ['open', 'rechecking'])->update(['state' => 'resolved']);

        return true;
    }

    /** Repair native projections even when the Excel values themselves did not change. */
    public function reconcileNative(DropboxConnection $connection, DropboxRecord $record, bool $historicalAssignments = true): void
    {
        if ($record->domain !== 'planning' || $record->model_type !== 'Shift' || isset($record->metadata['historical_values'])) {
            return;
        }
        SyncContext::import(function () use ($connection, $record, $historicalAssignments) {
            $shift = Shift::with('order')->findOrFail($record->model_id);
            app(PlanningServiceProjection::class)->backfill($record, $shift->order);
            if (($record->metadata['native_service_projection'] ?? 0) < 1) {
                $record->metadata = [...($record->metadata ?? []), 'native_service_projection' => 1];
                $record->save();
            }
            if (! $historicalAssignments) {
                $this->bindExistingPlanningAssignment($connection, $record, $shift);

                return;
            }
            $historical = ($record->metadata['imported_order'] ?? false)
                && (app(HistoricalImportCompletion::class)->canComplete($record, $shift, $connection)
                    || ($shift->status === ShiftStatus::Completed && $shift->ends_at->isPast()));
            if (! $historical || ! in_array('assignments', $connection->option('domains'), true)) {
                return;
            }
            $identity = ! empty($record->metadata['employee_label']) ? $this->identity($connection, $record->metadata['employee_label'], historical: true) : null;
            $this->validatePlanningEmployeeEvidence($record, $identity);
            $assignment = app(HistoricalEmployeeAssignmentService::class)->sync($connection, $record, $shift, $identity, User::findOrFail(1), true);
            if ($assignment) {
                $record->assignment_id = $assignment->id;
                $record->metadata = [...($record->metadata ?? []), 'user_id' => $identity?->user_id, 'assignment_pending' => null];
                $record->save();
                DropboxConflict::where('record_id', $record->id)->where('reason', 'assignment_pending')->update(['state' => 'resolved']);
            }
        });
    }

    private function validatePlanningEmployeeEvidence(DropboxRecord $record, ?DropboxIdentity $identity): void
    {
        $evidence = $record->metadata['employee_evidence'] ?? null;
        if ($evidence && ($identity?->kind !== 'employee' || (int) $identity->user_id !== (int) $evidence['user_id'])) {
            throw ValidationException::withMessages(['sync' => 'Die Mitarbeiterzuordnung widerspricht dem ausgewerteten Personalblatt. Bitte die Zuordnung prüfen.']);
        }
    }

    private function bindExistingPlanningAssignment(DropboxConnection $connection, DropboxRecord $record, Shift $shift): void
    {
        if ($record->assignment_id || ! in_array('assignments', $connection->option('domains'), true) || empty($record->metadata['employee_label'])) {
            return;
        }
        $identity = DropboxIdentity::where('connection_id', $connection->id)->where('kind', 'employee')
            ->where('alias', WorkbookReader::normalize($record->metadata['employee_label']))->first();
        if (! $identity?->user_id || ! User::whereKey($identity->user_id)->where('role', 'staff')->exists()) {
            return;
        }
        $this->validatePlanningEmployeeEvidence($record, $identity);
        $assignment = $shift->assignments()->where('user_id', $identity->user_id)->where('status', '!=', ShiftAssignmentStatus::Reported->value)->lockForUpdate()->first();
        if ($assignment && ! $this->assignmentAlreadyMapped($record, $assignment)) {
            $record->forceFill(['assignment_id' => $assignment->id, 'metadata' => [...$record->metadata, 'user_id' => $identity->user_id]])->save();
        }
    }

    private function assignmentAlreadyMapped(DropboxRecord $record, ShiftAssignment $assignment): bool
    {
        return DropboxRecord::where('connection_id', $record->connection_id)->where('model_type', 'Shift')->where('assignment_id', $assignment->id)
            ->when($record->exists, fn ($query) => $query->whereKeyNot($record->id))->exists();
    }

    /** All changed application entities resolve to records, including new draft assignments. */
    public function recordsFor(DropboxConnection $connection, string $type, int $id, bool $preview = false): Collection
    {
        if ($type === 'EmployeeAvailability') {
            return app(NativeAvailabilityWorkbook::class)->recordsFor($connection, $id, $preview);
        }
        $shiftIds = match ($type) {
            'Shift' => [$id],
            'ShiftAssignment' => ShiftAssignment::whereKey($id)->pluck('shift_id')->all(),
            'WorkTimeEntry' => ShiftAssignment::whereIn('id', WorkTimeEntry::whereKey($id)->select('shift_assignment_id'))->pluck('shift_id')->all(),
            'Order' => Shift::where('order_id', $id)->pluck('id')->all(),
            'Customer' => Shift::whereHas('order', fn ($q) => $q->where('customer_id', $id))->pluck('id')->all(),
            default => [],
        };
        foreach (Shift::whereIn('id', $shiftIds)->get() as $shift) {
            $assignments = $shift->assignments()->whereNotIn('status', ['cancelled', 'declined'])->get();
            $existingUnassigned = DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'Shift')->where('model_id', $shift->id)->whereNull('assignment_id')->first();
            if ($assignments->isEmpty()) {
                DropboxRecord::firstOrCreate(['connection_id' => $connection->id, 'model_type' => 'Shift', 'model_id' => $shift->id, 'assignment_id' => null], ['domain' => 'planning', 'metadata' => []]);
            } else {
                foreach ($assignments as $assignment) {
                    $record = DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'Shift')->where('model_id', $shift->id)->where('assignment_id', $assignment->id)->first();
                    if (! $record && $existingUnassigned) {
                        $existingUnassigned->assignment_id = $assignment->id;
                        $existingUnassigned->save();
                        $existingUnassigned = null;
                    } elseif (! $record) {
                        DropboxRecord::create(['connection_id' => $connection->id, 'model_type' => 'Shift', 'model_id' => $shift->id, 'assignment_id' => $assignment->id, 'domain' => 'planning', 'metadata' => []]);
                    }
                }
            }
        }
        if ($shiftIds) {
            return DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'Shift')->whereIn('model_id', $shiftIds)->get();
        }
        if ($type === 'UserProfile') {
            $profile = UserProfile::find($id);
            $userId = $profile?->user_id;
            if ($profile && $profile->user?->role === 'staff') {
                $identities = DropboxIdentity::where('connection_id', $connection->id)->where('user_id', $userId)->get();
                if ($identities->isEmpty()) {
                    $name = trim($profile->first_name.' '.$profile->last_name) ?: $profile->user->name;
                    if (! DropboxIdentity::where('connection_id', $connection->id)->where('alias', WorkbookReader::normalize($name))->exists()) {
                        $identity = SyncContext::import(fn () => DropboxIdentity::create(['connection_id' => $connection->id, 'alias' => WorkbookReader::normalize($name), 'kind' => 'employee', 'user_id' => $userId, 'details' => ['display_name' => $name, 'first_name' => $profile->first_name ?? '', 'last_name' => $profile->last_name ?? '', 'phone' => $profile->phone ?? '', 'mobile' => $profile->mobile ?? '', 'city' => $profile->city ?? '', 'birth_date' => $profile->birth_date?->format('Y-m-d') ?? '', 'birth_place' => $profile->birth_place ?? '', 'nationality' => $profile->nationality ?? '']]));
                        $identities->push($identity);
                    }
                }
                foreach ($identities as $identity) {
                    DropboxRecord::firstOrCreate(['connection_id' => $connection->id, 'model_type' => 'DropboxIdentity', 'model_id' => $identity->id], ['domain' => 'contacts', 'metadata' => ['kind' => 'employee']]);
                }
            }

            return DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'DropboxIdentity')->whereIn('model_id', DropboxIdentity::where('connection_id', $connection->id)->where('user_id', $userId)->pluck('id'))->get();
        }
        if ($type === 'EmployeeQualification') {
            $ids = app(QualificationProjection::class)->refresh($connection, $id, $preview);
            foreach ($ids as $factId) {
                $this->recordsFor($connection, 'EmployeeCompetencyFact', $factId);
            }

            return DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'EmployeeCompetencyFact')->whereIn('model_id', $ids)->get();
        }
        if (in_array($type, ['DropboxIdentity', 'EmployeeCompetencyFact'], true)) {
            $domain = $type === 'DropboxIdentity' ? 'contacts' : 'competencies';
            $model = $type === 'DropboxIdentity' ? DropboxIdentity::find($id) : EmployeeCompetencyFact::find($id);
            if ($model) {
                DropboxRecord::firstOrCreate(['connection_id' => $connection->id, 'model_type' => $type, 'model_id' => $id], ['domain' => $domain, 'metadata' => $type === 'DropboxIdentity' ? ['kind' => $model->kind] : $model->only(['kind', 'name', 'scope'])]);
            }
        }

        return DropboxRecord::where('connection_id', $connection->id)->where('model_type', $type)->where('model_id', $id)->get()
            ->reject(fn ($record) => ($record->metadata['availability_legacy'] ?? false));
    }

    private function timeVersion(WorkTimeEntry $time): string
    {
        return WorkbookReader::fingerprint(['id' => $time->id, 'revision' => $time->revision, 'ends' => $time->ends_at?->toIso8601String()]);
    }
}
