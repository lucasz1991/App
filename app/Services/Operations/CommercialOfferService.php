<?php

namespace App\Services\Operations;

use App\Models\CommercialOfferRevision;
use App\Models\CustomerCondition;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommercialOfferService
{
    public function __construct(private OperationsAuditService $audit) {}

    public static function ready(): bool
    {
        return Schema::hasTable('commercial_offer_revisions');
    }

    /** Integer fixed-point conversion, never binary float currency arithmetic. */
    public static function scaled(string|int $number, int $places): int
    {
        $parts = explode('.', (string) $number, 2);

        return ((int) $parts[0] * (10 ** $places)) + (int) str_pad($parts[1] ?? '', $places, '0');
    }

    public function positions(array $input, int $customerId, string $serviceDate): array
    {
        $data = Validator::make(['positions' => $input], [
            'positions' => ['required', 'array', 'min:1', 'max:100'], 'positions.*.title' => ['required', 'string', 'max:180'],
            'positions.*.unit' => ['required', 'string', 'max:30'], 'positions.*.quantity' => ['required', 'decimal:0,3', 'min:0.001', 'max:99999.999'],
            'positions.*.price' => ['required', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'positions.*.kind' => ['required', Rule::in(['standard', 'alternative', 'eventual', 'eventual_included'])],
            'positions.*.condition_id' => ['nullable', 'integer'],
            'positions.*.starts_at' => ['nullable', 'string', 'required_with:positions.*.ends_at'],
            'positions.*.ends_at' => ['nullable', 'string', 'required_with:positions.*.starts_at'],
            'positions.*.timezone' => ['nullable', 'timezone', 'required_with:positions.*.starts_at'],
        ])->validate()['positions'];
        $total = 0;
        $rows = [];
        foreach ($data as $row) {
            $condition = null;
            if (filled($row['condition_id'] ?? null)) {
                $condition = CustomerCondition::where('customer_id', $customerId)->where('valid_from', '<=', $serviceDate)
                    ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $serviceDate))->find($row['condition_id']);
                if (! $condition || $condition->unit !== $row['unit'] || $condition->unit_price_cents !== self::scaled($row['price'], 2)) {
                    throw ValidationException::withMessages(['positions' => 'Die Kundenkondition passt nicht zu Datum, Einheit oder Preis.']);
                }
            }
            $milli = self::scaled($row['quantity'], 3);
            $cents = self::scaled($row['price'], 2);
            $line = intdiv(($milli * $cents) + 500, 1000);
            $included = in_array($row['kind'], ['standard', 'eventual_included'], true);
            if ($included) {
                $total += $line;
            }
            $rows[] = ['title' => $row['title'], 'unit' => $row['unit'], 'quantity_milli' => $milli,
                'unit_price_cents' => $cents, 'total_cents' => $line, 'kind' => $row['kind'], 'included' => $included,
                'condition' => $condition?->only(['id', 'code', 'label', 'unit', 'unit_price_cents', 'valid_from', 'valid_until', 'terms'])];
            if (filled($row['starts_at'] ?? null)) {
                [$start, $end] = OperationsDateTime::interval($row['starts_at'], $row['ends_at'], $row['timezone']);
                $rows[array_key_last($rows)] += ['starts_at' => $start->utc()->toIso8601String(), 'ends_at' => $end->utc()->toIso8601String(), 'timezone' => $row['timezone']];
            }
        }
        if ($total > 9999999900) {
            throw ValidationException::withMessages(['positions' => 'Der Gesamtbetrag überschreitet den zulässigen Angebotsrahmen.']);
        }

        return ['positions' => $rows, 'amount_cents' => $total, 'currency' => 'EUR'];
    }

    public function draft(OperationInquiry|Order $subject, ?int $expectedLatestId, array $input, User $actor): CommercialOfferRevision
    {
        $this->access($subject, $actor);
        $data = Validator::make($input, ['terms' => ['required', 'string', 'max:5000'], 'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'kind' => ['required', Rule::in($subject instanceof Order ? ['amendment'] : ['offer'])], 'reason' => ['nullable', 'string', 'max:5000']])->validate();
        if ($subject instanceof Order && mb_strlen(trim($data['reason'] ?? '')) < 5) {
            throw ValidationException::withMessages(['reason' => 'Den Zusatzumfang bitte begründen.']);
        }

        return OperationsTransaction::run(function () use ($subject, $expectedLatestId, $input, $data, $actor) {
            $subject = $subject::lockForUpdate()->findOrFail($subject->id);
            if ($subject instanceof Order && in_array($subject->status->value, ['cancelled', 'invoiced'], true)) {
                throw ValidationException::withMessages(['workflow' => 'Für stornierte oder abgerechnete Aufträge ist kein neuer Nachtrag möglich.']);
            }
            if ($subject instanceof OperationInquiry && ($subject->order_id || $subject->duplicate_of_id || $subject->status === 'rejected')) {
                throw ValidationException::withMessages(['workflow' => 'Dieser Anfragevorgang ist abgeschlossen.']);
            }
            $type = class_basename($subject);
            $latest = CommercialOfferRevision::where('subject_type', $type)->where('subject_id', $subject->id)->latest('revision')->first();
            if ($latest?->id !== $expectedLatestId) {
                throw ValidationException::withMessages(['workflow' => 'Das Angebot wurde geändert. Bitte neu laden.']);
            }
            $calculated = $this->positions($input['positions'] ?? [], $subject->customer_id ?? 0, $subject->starts_at?->format('Y-m-d') ?? now($subject->timezone)->format('Y-m-d'));
            $record = CommercialOfferRevision::create([
                'subject_type' => $type, 'subject_id' => $subject->id, 'revision' => ($latest?->revision ?? 0) + 1,
                'kind' => $data['kind'], 'snapshot' => $calculated + ['terms' => $data['terms'], 'reason' => $data['reason'] ?? '',
                    'customer_id' => $subject->customer_id, 'source_revision' => $subject->revision, 'source_title' => $subject->title,
                    'source_fingerprint' => $this->fingerprint($subject)],
                'total_cents' => $calculated['amount_cents'], 'valid_until' => ($data['valid_until'] ?? null) ?: null, 'created_by' => $actor->id,
            ]);
            $this->audit->record($record, $actor, 'commercial.draft.created', ['subject_type' => $type, 'subject_id' => $subject->id, 'total_cents' => $record->total_cents]);

            return $record;
        });
    }

    public function issue(int $id, int $version, User $actor): CommercialOfferRevision
    {
        return OperationsTransaction::run(function () use ($id, $version, $actor) {
            $reference = CommercialOfferRevision::findOrFail($id);
            $subject = $this->subject($reference);
            $this->access($subject, $actor);
            $subject = $subject::lockForUpdate()->findOrFail($subject->id);
            $record = CommercialOfferRevision::lockForUpdate()->findOrFail($id);
            $this->current($record, $version, 'draft');
            if (($record->snapshot['source_fingerprint'] ?? null) !== $this->fingerprint($subject)
                || ($subject instanceof Order && in_array($subject->status->value, ['cancelled', 'invoiced'], true))) {
                throw ValidationException::withMessages(['workflow' => 'Die Auftragsgrundlage wurde geändert. Bitte einen neuen Stand anlegen.']);
            }
            if ($record->valid_until?->format('Y-m-d') < now($subject->timezone)->format('Y-m-d') && $record->valid_until) {
                throw ValidationException::withMessages(['valid_until' => 'Das Angebot ist bereits abgelaufen.']);
            }
            if ($subject instanceof OperationInquiry) {
                if ($subject->revision !== ($record->snapshot['source_revision'] ?? null)) {
                    throw ValidationException::withMessages(['workflow' => 'Der Bedarf wurde geändert. Bitte ein neues Angebot anlegen.']);
                }
                $rows = array_map(fn ($row) => ['title' => $row['title'], 'unit' => $row['unit'],
                    'quantity' => number_format($row['quantity_milli'] / 1000, 3, '.', ''), 'price' => number_format($row['unit_price_cents'] / 100, 2, '.', ''),
                    'kind' => $row['kind']], $record->snapshot['positions']);
                app(InquiryWorkflowService::class)->transition($subject, $subject->revision, 'offer', [
                    'amount' => number_format($record->total_cents / 100, 2, '.', ''), 'terms' => $record->snapshot['terms'],
                    'positions' => $rows, 'commercial_offer_id' => $record->id, 'valid_until' => $record->valid_until?->format('Y-m-d'),
                ], $actor);
            }
            $record->forceFill(['status' => 'offered', 'state_version' => $version + 1, 'issued_at' => now()->utc()])->save();
            $this->audit->record($record, $actor, 'commercial.issued');

            return $record;
        });
    }

    public function accept(int $id, int $version, string $note, bool $authorized, User $actor): void
    {
        Validator::make(['note' => $note, 'authorized' => $authorized], ['note' => ['required', 'string', 'min:5', 'max:5000'], 'authorized' => ['accepted']])->validate();
        OperationsTransaction::run(function () use ($id, $version, $note, $actor): void {
            $reference = CommercialOfferRevision::findOrFail($id);
            $subject = $this->subject($reference);
            $this->access($subject, $actor);
            $subject = $subject::lockForUpdate()->findOrFail($subject->id);
            $record = CommercialOfferRevision::lockForUpdate()->findOrFail($id);
            $this->current($record, $version, 'offered');
            if (($record->snapshot['source_fingerprint'] ?? null) !== $this->fingerprint($subject)
                || ($subject instanceof Order && in_array($subject->status->value, ['cancelled', 'invoiced'], true))) {
                throw ValidationException::withMessages(['workflow' => 'Die Auftragsgrundlage wurde geändert. Bitte die Zusage am aktuellen Stand dokumentieren.']);
            }
            if ($record->valid_until && $record->valid_until->format('Y-m-d') < now($subject->timezone)->format('Y-m-d')) {
                throw ValidationException::withMessages(['workflow' => 'Angebotsfrist abgelaufen. Bitte einen neuen Stand ausstellen.']);
            }
            if ($subject instanceof OperationInquiry) {
                abort_unless(($subject->offer['commercial_offer_id'] ?? null) === $record->id, 422);
                app(InquiryWorkflowService::class)->transition($subject, $subject->revision, 'accept', ['note' => $note, 'authorized' => true], $actor);
            }
            $record->forceFill(['status' => 'accepted', 'state_version' => $version + 1, 'accepted_at' => now()->utc(), 'accepted_by' => $actor->id, 'acceptance_note' => $note])->save();
            $this->audit->record($record, $actor, 'commercial.accepted', ['note' => $note]);
        });
    }

    private function current(CommercialOfferRevision $record, int $version, string $status): void
    {
        $latestId = CommercialOfferRevision::where('subject_type', $record->subject_type)->where('subject_id', $record->subject_id)->latest('revision')->value('id');
        if ($record->state_version !== $version || $record->status !== $status || $latestId !== $record->id) {
            throw ValidationException::withMessages(['workflow' => 'Der Angebotsstand wurde geändert. Bitte neu laden.']);
        }
    }

    private function access(OperationInquiry|Order $subject, User $actor): void
    {
        OperationsAccess::authorize($actor, $subject instanceof Order ? 'operations.manage' : 'operations.inquiries.manage');
        abort_unless(self::ready(), 503, 'Angebote nicht verfügbar.');
    }

    private function subject(CommercialOfferRevision $record): OperationInquiry|Order
    {
        return match ($record->subject_type) {
            'OperationInquiry' => OperationInquiry::findOrFail($record->subject_id),
            'Order' => Order::findOrFail($record->subject_id),
            default => abort(404),
        };
    }

    private function fingerprint(OperationInquiry|Order $subject): string
    {
        return hash('sha256', json_encode($subject->only(['customer_id', 'title', 'starts_at', 'ends_at', 'timezone', 'required_staff', 'role_name', 'service_type']), JSON_THROW_ON_ERROR));
    }
}
