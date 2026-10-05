<?php

namespace App\Services\Operations;

use App\Models\Customer;
use App\Models\CustomerCondition;
use App\Models\CustomerContact;
use App\Models\CustomerLocation;
use App\Models\InquiryFollowUp;
use App\Models\InquiryProcessDetail;
use App\Models\OperationInquiry;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerWorkflowService
{
    public function __construct(private OperationsAuditService $audit) {}

    public static function ready(): bool
    {
        return Schema::hasTable('customer_contacts') && Schema::hasTable('customer_locations') && Schema::hasTable('customer_conditions')
            && Schema::hasTable('inquiry_process_details') && Schema::hasTable('inquiry_follow_ups');
    }

    private function access(User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        abort_unless(self::ready(), 503, 'Kundenprozesse nicht verfügbar.');
    }

    public function contact(Customer $customer, ?int $id, ?int $revision, array $input, User $actor): CustomerContact
    {
        $this->access($actor);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:180'], 'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:80'], 'roles' => ['required', 'array', 'min:1', 'max:6'],
            'roles.*' => ['required', 'distinct', Rule::in(array_keys(CustomerContact::ROLES))], 'is_active' => ['required', 'boolean'],
        ])->validate();

        return OperationsTransaction::run(function () use ($customer, $id, $revision, $data, $actor) {
            Customer::lockForUpdate()->findOrFail($customer->id);
            $record = $id ? CustomerContact::where('customer_id', $customer->id)->lockForUpdate()->findOrFail($id) : new CustomerContact(['customer_id' => $customer->id]);
            if ($record->exists) {
                $this->assertRevision($record->revision, $revision);
                $record->revision++;
            }
            $record->fill($data)->forceFill(['updated_by' => $actor->id])->save();
            $this->audit->record($record, $actor, 'customer.contact.saved', $record->only(['customer_id', 'roles', 'is_active']));

            return $record;
        });
    }

    /** Conditions are dated append-only facts; issued offers retain their own snapshot. */
    public function condition(Customer $customer, array $input, User $actor): CustomerCondition
    {
        $this->access($actor);
        $data = Validator::make($input, [
            'code' => ['required', 'regex:/^[A-Za-z0-9_.-]{1,50}$/'], 'label' => ['required', 'string', 'max:180'],
            'unit' => ['required', 'string', 'max:30'], 'price' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'],
            'valid_from' => ['required', 'date_format:Y-m-d'], 'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return OperationsTransaction::run(function () use ($customer, $data, $actor) {
            Customer::lockForUpdate()->findOrFail($customer->id);
            $overlap = CustomerCondition::where('customer_id', $customer->id)->where('code', $data['code'])
                ->where('valid_from', '<=', ($data['valid_until'] ?? null) ?: '9999-12-31')
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $data['valid_from']))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['valid_from' => 'Für diese Kennung besteht bereits eine Kondition im Zeitraum.']);
            }
            $record = CustomerCondition::create([
                'customer_id' => $customer->id, 'code' => $data['code'], 'label' => $data['label'], 'unit' => $data['unit'],
                'unit_price_cents' => CommercialOfferService::scaled($data['price'], 2), 'valid_from' => $data['valid_from'],
                'valid_until' => ($data['valid_until'] ?? null) ?: null, 'terms' => $data['terms'] ?? null, 'created_by' => $actor->id,
            ]);
            $this->audit->record($record, $actor, 'customer.condition.created', $record->only(['customer_id', 'code', 'valid_from', 'valid_until']));

            return $record;
        });
    }

    public function process(OperationInquiry $inquiry, ?int $revision, array $input, User $actor): InquiryProcessDetail
    {
        $this->access($actor);
        $data = Validator::make($input, [
            'customer_contact_id' => ['nullable', 'integer'], 'assignee_id' => ['nullable', 'integer'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['nullable', 'string'], 'timezone' => ['required', 'timezone'],
        ])->validate();
        $data['assignee_id'] = filled($data['assignee_id'] ?? null) ? (int) $data['assignee_id'] : null;
        $this->assignee($data['assignee_id']);
        $data['customer_contact_id'] = filled($data['customer_contact_id'] ?? null) ? (int) $data['customer_contact_id'] : null;
        $data['due_at'] = filled($data['due_at'] ?? null) ? OperationsDateTime::local($data['due_at'], $data['timezone'], 'due_at') : null;

        return OperationsTransaction::run(function () use ($inquiry, $revision, $data, $actor) {
            $inquiry = OperationInquiry::lockForUpdate()->findOrFail($inquiry->id);
            if ($data['customer_contact_id']) {
                abort_unless(CustomerContact::where('customer_id', $inquiry->customer_id)->where('is_active', true)->whereKey($data['customer_contact_id'])->exists(), 422, 'Kontakt gehört nicht zum Kunden.');
            }
            $record = InquiryProcessDetail::where('operation_inquiry_id', $inquiry->id)->lockForUpdate()->first();
            $this->assertRevision($record?->revision, $revision);
            $record ??= new InquiryProcessDetail(['operation_inquiry_id' => $inquiry->id]);
            $record->fill($data)->forceFill(['revision' => ($record->revision ?? 0) + 1, 'updated_by' => $actor->id])->save();
            $this->audit->record($inquiry, $actor, 'inquiry.process.updated', $record->only(['revision', 'assignee_id', 'customer_contact_id', 'priority', 'due_at']));

            return $record;
        });
    }

    public function location(Customer $customer, ?int $id, ?int $revision, array $input, User $actor): CustomerLocation
    {
        $this->access($actor);
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:180'], 'street' => ['nullable', 'string', 'max:180'],
            'postal_code' => ['nullable', 'string', 'max:20'], 'city' => ['nullable', 'string', 'max:100'], 'country' => ['required', 'regex:/^[A-Z]{2}$/'],
            'access_note' => ['nullable', 'string', 'max:5000'], 'is_active' => ['required', 'boolean']])->validate();

        return OperationsTransaction::run(function () use ($customer, $id, $revision, $data, $actor) {
            Customer::lockForUpdate()->findOrFail($customer->id);
            $record = $id ? CustomerLocation::where('customer_id', $customer->id)->lockForUpdate()->findOrFail($id) : new CustomerLocation(['customer_id' => $customer->id]);
            if ($record->exists) {
                $this->assertRevision($record->revision, $revision);
                $record->revision++;
            }
            $record->fill($data)->forceFill(['updated_by' => $actor->id])->save();
            $this->audit->record($record, $actor, 'customer.location.saved', ['customer_id' => $customer->id, 'is_active' => $record->is_active]);

            return $record;
        });
    }

    public function endCondition(int $id, int $revision, string $date, User $actor): void
    {
        $this->access($actor);
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        OperationsTransaction::run(function () use ($id, $revision, $date, $actor): void {
            $reference = CustomerCondition::findOrFail($id);
            Customer::lockForUpdate()->findOrFail($reference->customer_id);
            $record = CustomerCondition::lockForUpdate()->findOrFail($id);
            $this->assertRevision($record->revision, $revision);
            if ($record->valid_until || $date < $record->valid_from->format('Y-m-d')) {
                throw ValidationException::withMessages(['date' => 'Bitte ein Ende nach dem Beginn für die offene Kondition angeben.']);
            }
            $record->forceFill(['valid_until' => $date, 'revision' => $revision + 1])->save();
            $this->audit->record($record, $actor, 'customer.condition.ended', ['valid_until' => $date]);
        });
    }

    public function followUp(OperationInquiry $inquiry, array $input, User $actor): InquiryFollowUp
    {
        $this->access($actor);
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:180'], 'note' => ['nullable', 'string', 'max:5000'],
            'kind' => ['required', Rule::in(['call', 'email', 'offer', 'check', 'other'])],
            'due_at' => ['required', 'string'], 'timezone' => ['required', 'timezone'], 'assignee_id' => ['nullable', 'integer'],
        ])->validate();
        $data['assignee_id'] = filled($data['assignee_id'] ?? null) ? (int) $data['assignee_id'] : null;
        $this->assignee($data['assignee_id']);
        $data['due_at'] = OperationsDateTime::local($data['due_at'], $data['timezone'], 'due_at');

        return OperationsTransaction::run(function () use ($inquiry, $data, $actor) {
            $inquiry = OperationInquiry::lockForUpdate()->findOrFail($inquiry->id);
            $record = InquiryFollowUp::create($data + ['operation_inquiry_id' => $inquiry->id, 'updated_by' => $actor->id]);
            $this->audit->record($inquiry, $actor, 'inquiry.followup.created', ['follow_up_id' => $record->id, 'kind' => $record->kind, 'due_at' => $record->due_at]);

            return $record;
        });
    }

    public function completeFollowUp(int $id, int $revision, string $note, User $actor): void
    {
        $this->access($actor);
        Validator::make(['note' => $note], ['note' => ['required', 'string', 'min:3', 'max:5000']])->validate();
        OperationsTransaction::run(function () use ($id, $revision, $note, $actor): void {
            $record = InquiryFollowUp::lockForUpdate()->findOrFail($id);
            $this->assertRevision($record->revision, $revision);
            abort_unless($record->status === 'open', 422);
            $record->forceFill(['revision' => $revision + 1, 'status' => 'completed', 'completion_note' => $note, 'completed_at' => now()->utc(), 'updated_by' => $actor->id])->save();
            $this->audit->record($record->inquiry, $actor, 'inquiry.followup.completed', ['follow_up_id' => $record->id, 'revision' => $record->revision, 'completion_note' => $note]);
        });
    }

    private function assignee(?int $id): void
    {
        if ($id) {
            $user = User::find($id);
            abort_unless($user && $user->status && Gate::forUser($user)->allows('operations.inquiries.manage'), 422, 'Zuständigkeit nicht verfügbar.');
        }
    }

    private function assertRevision(?int $actual, ?int $expected): void
    {
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['workflow' => 'Der Stand wurde geändert. Bitte neu laden.']);
        }
    }
}
