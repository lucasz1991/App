<?php

namespace App\Services\Operations;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InquiryWorkflowService
{
    public function __construct(private OperationsAuditService $audit) {}

    public function save(?OperationInquiry $inquiry, array $input, User $actor, ?int $revision = null): OperationInquiry
    {
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        $data = Validator::make($input, [
            'channel' => ['required', Rule::in(['email', 'phone', 'portal', 'manual'])],
            'source_reference' => ['nullable', 'string', 'max:190'],
            'title' => ['required', 'string', 'max:180'], 'original' => ['required', 'string', 'max:20000'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'contact_name' => ['nullable', 'string', 'max:180'], 'contact_email' => ['nullable', 'email', 'max:254'],
            'contact_phone' => ['nullable', 'string', 'max:80'], 'timezone' => ['required', 'timezone'],
            'starts_at' => ['nullable', 'string'], 'ends_at' => ['nullable', 'string'],
            'location_name' => ['nullable', 'string', 'max:180'], 'role_name' => ['nullable', 'string', 'max:160'],
            'required_staff' => ['nullable', 'integer', 'min:1', 'max:999'],
        ])->validate();
        foreach (['source_reference', 'customer_id', 'required_staff'] as $optional) {
            if (blank($data[$optional] ?? null)) {
                $data[$optional] = null;
            }
        }
        foreach (['starts_at', 'ends_at'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? OperationsDateTime::local($data[$key], $data['timezone'], $key) : null;
        }
        if ($data['starts_at'] && $data['ends_at'] && $data['ends_at']->lessThanOrEqualTo($data['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => 'Das Ende muss nach dem Beginn liegen.']);
        }

        return DB::transaction(function () use ($inquiry, $data, $actor, $revision): OperationInquiry {
            $record = $inquiry ? OperationInquiry::lockForUpdate()->findOrFail($inquiry->id) : new OperationInquiry;
            if ($record->exists) {
                $this->editable($record, $revision);
                // Original communication is immutable; corrected demand gets a new revision.
                unset($data['original'], $data['channel'], $data['source_reference']);
                $record->revision++;
            } else {
                if (filled($data['source_reference'] ?? null) && OperationInquiry::where('channel', $data['channel'])->where('source_reference', $data['source_reference'])->exists()) {
                    throw ValidationException::withMessages(['source_reference' => 'Dieser Eingang wurde bereits erfasst.']);
                }
                $record->created_by = $actor->id;
            }
            $record->fill($data)->forceFill([
                'status' => 'new', 'verified_revision' => null, 'offer' => null,
                'accepted_revision' => null, 'acceptance_note' => null, 'updated_by' => $actor->id,
            ])->save();
            $this->audit->record($record, $actor, 'inquiry.saved', ['demand' => $record->only(['customer_id', 'starts_at', 'ends_at', 'timezone', 'required_staff', 'role_name', 'location_name'])]);

            return $record;
        });
    }

    public function transition(OperationInquiry $inquiry, int $revision, string $action, array $input, User $actor): OperationInquiry
    {
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');

        return DB::transaction(function () use ($inquiry, $revision, $action, $input, $actor): OperationInquiry {
            $record = OperationInquiry::lockForUpdate()->findOrFail($inquiry->id);
            if ($action === 'convert' && $record->order_id && $record->revision === $revision) {
                return $record;
            }
            $this->editable($record, $revision);
            switch ($action) {
                case 'verify':
                    $this->require(in_array($record->status, ['new', 'verified'], true), 'Bitte Änderungen zunächst speichern.');
                    $this->complete($record);
                    $record->verified_revision = $record->revision;
                    $record->status = 'verified';
                    break;
                case 'offer':
                    $this->require($record->verified_revision === $revision && in_array($record->status, ['verified', 'offered'], true), 'Der Bedarf muss zuerst geprüft werden.');
                    $offer = Validator::make($input, ['amount' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'], 'terms' => ['required', 'string', 'max:5000']])->validate();
                    // Replacing an offer also creates a new demand/offer revision, invalidating old acceptance.
                    if ($record->offer) {
                        $record->revision++;
                        $record->verified_revision = $record->revision;
                    }
                    $record->offer = ['revision' => $record->revision, 'amount_cents' => (int) round((float) $offer['amount'] * 100), 'currency' => 'EUR', 'terms' => $offer['terms']];
                    $record->accepted_revision = null;
                    $record->acceptance_note = null;
                    $record->status = 'offered';
                    break;
                case 'accept':
                    $this->require($record->status === 'offered' && ($record->offer['revision'] ?? null) === $revision, 'Es liegt kein aktuelles Angebot vor.');
                    $acceptance = Validator::make($input, ['note' => ['required', 'string', 'min:5', 'max:5000'], 'authorized' => ['accepted']])->validate();
                    $record->accepted_revision = $revision;
                    $record->acceptance_note = $acceptance['note'];
                    $record->status = 'accepted';
                    break;
                case 'convert':
                    $this->complete($record);
                    $this->require($record->status === 'accepted' && $record->accepted_revision === $revision && ($record->offer['revision'] ?? null) === $revision, 'Eine aktuelle Kundenzusage ist erforderlich.');
                    $order = app(OrderSchedulingService::class)->save(new Order, [
                        'customer_id' => $record->customer_id, 'title' => $record->title,
                        'service_type' => $record->role_name, 'status' => OrderStatus::Confirmed,
                        'starts_at' => $record->starts_at, 'ends_at' => $record->ends_at,
                        'timezone' => $record->timezone, 'location_name' => $record->location_name,
                        'required_staff' => $record->required_staff, 'priority' => 'normal',
                        'notes' => $record->number,
                    ], $actor);
                    $record->order_id = $order->id;
                    $record->status = 'converted';
                    break;
                case 'duplicate':
                    $id = (int) ($input['original_id'] ?? 0);
                    $original = OperationInquiry::find($id);
                    $this->require($original && $id < $record->id && ! $original->duplicate_of_id, 'Bitte einen älteren Originalvorgang auswählen.');
                    $this->require(! $record->offer && ! $record->accepted_revision, 'Ein Vorgang mit Angebot oder Zusage kann nicht als Dublette verknüpft werden.');
                    $record->duplicate_of_id = $id;
                    $record->status = 'duplicate';
                    break;
                default:
                    throw ValidationException::withMessages(['workflow' => 'Ungültiger Arbeitsschritt.']);
            }
            $record->updated_by = $actor->id;
            $record->save();
            $this->audit->record($record, $actor, 'inquiry.'.$action, ['status' => $record->status, 'offer' => $record->offer, 'acceptance_note' => $record->acceptance_note, 'order_id' => $record->order_id, 'duplicate_of_id' => $record->duplicate_of_id]);

            return $record;
        }, 3);
    }

    private function editable(OperationInquiry $record, ?int $revision): void
    {
        $this->require($record->revision === $revision, 'Der Vorgang wurde geändert. Bitte neu laden.');
        $this->require(! $record->order_id && ! $record->duplicate_of_id, 'Dieser Vorgang ist bereits abgeschlossen.');
    }

    private function complete(OperationInquiry $record): void
    {
        $customer = Customer::find($record->customer_id);
        $this->require($customer && $customer->is_active, 'Bitte einen aktiven Kunden zuordnen.');
        $this->require($record->starts_at && $record->ends_at && $record->ends_at->greaterThan($record->starts_at)
            && filled($record->role_name) && filled($record->location_name) && $record->required_staff > 0, 'Zeitraum, Einsatzort, Tätigkeit und Personalbedarf müssen vollständig sein.');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
