<?php

namespace App\Services\Dropbox;

use App\Enums\OrderStatus;
use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Customer;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\EmployeeCompetencyFact;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\WorkTimeEntry;
use App\Services\Operations\OrderSchedulingService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\ShiftSchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DomainAdapter
{
    public function identity(DropboxConnection $connection, string $name, string $kind = 'unresolved'): DropboxIdentity
    {
        return SyncContext::import(fn () => DropboxIdentity::firstOrCreate([
            'connection_id' => $connection->id, 'alias' => WorkbookReader::normalize($name),
        ], ['kind' => $kind, 'details' => ['display_name' => trim($name)]]));
    }

    public function current(DropboxRecord $record): array
    {
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
            $cancelled = $shift->status === ShiftStatus::Cancelled || $assignment?->status === ShiftAssignmentStatus::Cancelled;
            $values = [
                'location' => $shift->location_name ?? '', 'date' => $shift->starts_at->setTimezone($zone)->format('Y-m-d'),
                'starts' => $shift->starts_at->setTimezone($zone)->format('H:i'), 'ends' => $shift->ends_at->setTimezone($zone)->format('H:i'),
                'actual_end' => $actualEnd, 'employee' => $employee,
                'train_reference' => $details['train_reference'] ?? '', 'notes' => $shift->notes ?? '',
                'role' => $shift->role_name ?? '', 'customer' => $shift->order->customer->company_name,
                'ordered_at' => $details['ordered_at'] ?? '', 'cancellation' => $record->metadata['cancellation'] ?? $details['cancellation'] ?? '',
                'information' => $details['information'] ?? '', 'billing_notes' => $details['billing_notes'] ?? '', 'other' => $details['other'] ?? '',
                'draft' => (int) $shift->published_revision !== (int) $shift->revision || ! $shift->published_revision,
                'cancelled' => $cancelled,
            ];
            if ($cancelled && ! preg_match('/storno|storniert/iu', $values['cancellation'])) {
                $values['cancellation'] = trim('Storno '.$values['cancellation']);
            }

            return $values;
        }
        if ($record->domain === 'contacts') {
            $identity = DropboxIdentity::findOrFail($record->model_id);
            $values = $identity->details ?? [];
            unset($values['display_name']);
            if ($identity->user_id && ($profile = UserProfile::where('user_id', $identity->user_id)->first())) {
                foreach (['first_name', 'last_name', 'phone', 'mobile', 'city', 'birth_date', 'birth_place', 'nationality'] as $field) {
                    if (array_key_exists($field, $values)) {
                        $v = $profile->{$field};
                        $values[$field] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v;
                    }
                }
            }

            return $values;
        }

        return EmployeeCompetencyFact::findOrFail($record->model_id)->value;
    }

    public function create(DropboxConnection $connection, array $entry): DropboxRecord
    {
        return DB::transaction(function () use ($connection, $entry) {
            if ($entry['domain'] === 'planning') {
                $record = new DropboxRecord(['connection_id' => $connection->id, 'domain' => 'planning', 'model_type' => 'Shift', 'model_id' => 0, 'metadata' => ['imported_order' => true]]);
                $this->apply($connection, $record, $entry['values']);

                return $record;
            }
            $loc = $entry['locator'];
            $identity = $this->identity($connection, $loc['subject'], $loc['kind'] === 'provider' ? 'provider' : 'unresolved');
            if ($entry['domain'] === 'contacts') {
                $record = DropboxRecord::firstOrCreate(['connection_id' => $connection->id, 'domain' => 'contacts', 'model_type' => 'DropboxIdentity', 'model_id' => $identity->id], ['metadata' => ['kind' => $loc['kind']]]);
            } else {
                $fact = EmployeeCompetencyFact::where('identity_id', $identity->id)->where('kind', $loc['kind'])->where('name', $loc['name'])->where('scope', $loc['scope'])->first();
                if (! $fact) {
                    $fact = SyncContext::import(fn () => EmployeeCompetencyFact::create(['identity_id' => $identity->id, 'kind' => $loc['kind'], 'name' => $loc['name'], 'scope' => $loc['scope'], 'value' => $entry['values']]));
                }
                $record = DropboxRecord::firstOrCreate(['connection_id' => $connection->id, 'domain' => 'competencies', 'model_type' => 'EmployeeCompetencyFact', 'model_id' => $fact->id], ['metadata' => $loc]);
            }
            $this->apply($connection, $record, $entry['values']);

            return $record;
        });
    }

    public function apply(DropboxConnection $connection, DropboxRecord $record, array $values): void
    {
        if (isset($record->metadata['historical_values'])) {
            throw ValidationException::withMessages(['sync' => 'Diese frühere Mitarbeiterzuordnung ist storniert. Änderungen am aktuellen Einsatz in der Hauptübersicht vornehmen.']);
        }
        SyncContext::import(function () use ($connection, $record, $values) {
            if ($record->domain === 'planning') {
                $this->planning($connection, $record, $values);

                return;
            }
            if ($record->domain === 'contacts') {
                $identity = DropboxIdentity::lockForUpdate()->findOrFail($record->model_id);
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
        ])->validate();
        $actor = User::findOrFail(1);
        abort_unless($actor->isSuperAdmin() && $actor->status, 403);
        $zone = $connection->option('timezone');
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $values['date'].' '.$values['starts'], $zone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $values['date'].' '.$values['ends'], $zone);
        if ($end->lte($start)) {
            $end = $end->addDay();
        }
        if ($start->format('Y-m-d H:i') !== $values['date'].' '.$values['starts']) {
            throw ValidationException::withMessages(['sync' => 'Zeit liegt in einer Sommerzeitlücke.']);
        }
        // Scheduling services compare SQL timestamps in UTC; workbook values are local civil times.
        $start = $start->utc();
        $end = $end->utc();
        $identity = ! empty($values['employee']) ? $this->identity($connection, $values['employee']) : null;
        if ($identity && ! in_array($identity->kind, ['employee', 'provider'], true)) {
            throw ValidationException::withMessages(['sync' => 'Mitarbeiter oder Dienstleister zuerst unter Zuordnungen bestätigen.']);
        }
        if ($identity?->kind === 'employee' && ! $identity->user_id) {
            throw ValidationException::withMessages(['sync' => 'Das Mitarbeiterkonto fehlt in der bestätigten Zuordnung.']);
        }
        $customers = Customer::where('company_name', $values['customer'])->get();
        if ($customers->count() > 1) {
            throw ValidationException::withMessages(['sync' => 'Kundenbezeichnung ist mehrdeutig.']);
        }
        $customer = $customers->first() ?? Customer::create(['company_name' => $values['customer'], 'is_active' => true]);
        $shift = $record->model_id ? Shift::lockForUpdate()->findOrFail($record->model_id) : new Shift;
        if ($shift->exists) {
            $current = $this->current($record);
            $changed = array_filter($values, fn ($value, $key) => ($current[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
            if ($changed && ! array_diff(array_keys($changed), ['actual_end'])) {
                $time = $record->assignment_id ? WorkTimeEntry::where('shift_assignment_id', $record->assignment_id)->first() : null;
                $record->metadata = array_merge($record->metadata ?? [], ['actual_end' => $values['actual_end'], 'time_entry_version' => $time ? $this->timeVersion($time) : null, 'actual_end_origin' => 'reported']);
                $record->save();

                return;
            }
        }
        $order = $shift->exists ? Order::lockForUpdate()->findOrFail($shift->order_id) : new Order;
        if ($order->exists && $order->customer_id !== $customer->id && $order->shifts()->where('id', '!=', $shift->id)->exists()) {
            throw ValidationException::withMessages(['sync' => 'Kundenwechsel betrifft weitere Schichten dieses Auftrags. Bitte in der Auftragsverwaltung prüfen.']);
        }
        $order = app(OrderSchedulingService::class)->save($order, [
            'customer_id' => $customer->id, 'title' => $order->title ?: $values['location'],
            'service_type' => $order->service_type ?: ($values['role'] ?: 'Einsatz'), 'status' => $order->status ?? OrderStatus::Requested,
            'priority' => $order->priority ?? 'normal', 'timezone' => $zone, 'required_staff' => $order->required_staff ?: 1,
            'starts_at' => $order->exists ? min($order->starts_at->utc(), $start) : $start,
            'ends_at' => $order->exists ? max($order->ends_at->utc(), $end) : $end,
        ], $actor);
        $details = array_intersect_key($values, array_flip(['train_reference', 'ordered_at', 'cancellation', 'information', 'billing_notes', 'other', 'actual_end']));
        if ($identity?->kind === 'provider') {
            $details['provider_label'] = $values['employee'];
        }
        $oldAssignment = $record->assignment_id ? ShiftAssignment::find($record->assignment_id) : null;
        if ($oldAssignment) {
            $details['cancellation'] = $shift->disposition_details['cancellation'] ?? '';
            $details['actual_end'] = $shift->disposition_details['actual_end'] ?? null;
        }
        $cancelAssignmentOnly = ($values['cancelled'] ?? false) && $oldAssignment && $shift->assignments()->where('id', '!=', $oldAssignment->id)->whereNotIn('status', ['cancelled', 'declined'])->exists();
        if ($cancelAssignmentOnly && $oldAssignment->status !== ShiftAssignmentStatus::Cancelled) {
            app(ShiftAssignmentService::class)->cancel($oldAssignment, $actor, 'Excel-Stornierung dieser Zuordnung');
        }
        if ($oldAssignment && (int) $oldAssignment->user_id !== (int) $identity?->user_id) {
            app(ShiftAssignmentService::class)->cancel($oldAssignment, $actor, 'Excel-Zuordnung geändert');
            $record->assignment_id = null;
        }
        $shift = app(ShiftSchedulingService::class)->save($shift, [
            'order_id' => $order->id, 'title' => $shift->title ?: $values['location'], 'role_name' => $values['role'] ?: 'Einsatz',
            'starts_at' => $start, 'ends_at' => $end, 'timezone' => $zone, 'location_name' => $values['location'],
            'required_staff' => $shift->required_staff ?: 1, 'notes' => $values['notes'] ?? '', 'disposition_details' => $details,
            'status' => ($values['cancelled'] ?? false) && ! $cancelAssignmentOnly ? ShiftStatus::Cancelled : ($shift->status ?? ShiftStatus::Draft),
            'expected_revision' => $shift->revision,
        ], $actor);
        if ($identity?->kind === 'employee' && ! ($values['cancelled'] ?? false)) {
            if (! $oldAssignment || $oldAssignment->user_id !== $identity->user_id || $oldAssignment->status === ShiftAssignmentStatus::Cancelled) {
                $assignment = app(ShiftAssignmentService::class)->assign($shift, User::findOrFail($identity->user_id), $actor, ShiftAssignmentStatus::Requested);
                $record->assignment_id = $assignment->id;
            }
        }
        $record->forceFill(['model_id' => $shift->id, 'metadata' => array_merge($record->metadata ?? [], ['employee_label' => $values['employee'] ?? '', 'user_id' => $identity?->user_id, 'cancellation' => $values['cancellation'] ?? '', 'actual_end' => $values['actual_end'] ?? null])])->save();
    }

    /** All changed application entities resolve to records, including new draft assignments. */
    public function recordsFor(DropboxConnection $connection, string $type, int $id, bool $preview = false): Collection
    {
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
                        $existingUnassigned->metadata = [];
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

        return DropboxRecord::where('connection_id', $connection->id)->where('model_type', $type)->where('model_id', $id)->get();
    }

    private function timeVersion(WorkTimeEntry $time): string
    {
        return WorkbookReader::fingerprint(['id' => $time->id, 'revision' => $time->revision, 'ends' => $time->ends_at?->toIso8601String()]);
    }
}
