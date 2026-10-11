<?php

namespace App\Services\Operations;

use App\Models\Customer;
use App\Models\CustomerPortalAudit;
use App\Models\CustomerPortalIdentity;
use App\Models\User;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\Operations\OperationsAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

final class CustomerLogoService
{
    public static function ready(): bool
    {
        return Schema::hasColumn('customers', 'logo_path');
    }

    public static function rules(): array
    {
        return ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048'];
    }

    public function customer(User|CustomerPortalIdentity $actor, int $customerId, bool $write = false, bool $lock = false): Customer
    {
        if ($actor instanceof CustomerPortalIdentity) {
            $membership = app(CustomerPortalScope::class)->membership($actor, $customerId, $write ? 'changes.create' : null, $lock);
            abort_if($write && $membership->role !== 'coordinator', 403);

            return $membership->customer;
        }

        $actor = $actor->fresh();
        abort_unless($actor?->status, 403);
        if ($write) {
            OperationsAccess::authorize($actor, 'operations.manage');
        } elseif (! $actor->can('operations.manage') && ! $actor->can('operations.inquiries.manage') && ! $actor->can('operations.shifts.manage')) {
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId);
        }

        return Customer::whereKey($customerId)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    public function save(User|CustomerPortalIdentity $actor, int $customerId, UploadedFile $upload, ?string $expectedPath): Customer
    {
        $customer = $this->customer($actor, $customerId, true);
        abort_unless(self::ready(), 503, 'Firmenlogos sind noch nicht eingerichtet.');
        Validator::make(['logoFile' => $upload], ['logoFile' => self::rules()], [], ['logoFile' => 'Firmenlogo'])->validate();
        try {
            // Re-encode instead of serving the original file or its metadata/polyglot content.
            $image = (new ImageManager(new Driver))->read($upload->getRealPath());
            $image->orient()->removeAnimation()->removeProfile()->scaleDown(512, 512);
            $encoded = (string) $image->toPng();
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['logoFile' => 'Das Firmenlogo konnte nicht als Bild gelesen werden.']);
        }
        $path = 'customer-logos/'.$customerId.'/'.Str::uuid().'.png';
        try {
            $customer = DB::transaction(function () use ($actor, $customerId, $expectedPath, $path, $encoded): Customer {
                $customer = $this->customer($actor, $customerId, true, true);
                abort_unless($customer->logo_path === $expectedPath, 409, 'Das Firmenlogo wurde zwischenzeitlich geändert. Bitte neu laden.');
                abort_unless(Storage::disk('private')->put($path, $encoded), 503, 'Das Firmenlogo konnte nicht gespeichert werden.');
                $customer->forceFill(['logo_path' => $path])->save();
                $this->audit($customer, $actor, 'customer.logo.updated');

                return $customer;
            });
        } catch (Throwable $exception) {
            // Only our generated, customer-scoped file can be cleaned up after failure.
            Storage::disk('private')->delete($path);
            throw $exception;
        }
        if ($customer->hasValidLogoPath($expectedPath) && $expectedPath !== null) {
            Storage::disk('private')->delete($expectedPath);
        }

        return $customer;
    }

    public function remove(User|CustomerPortalIdentity $actor, int $customerId, ?string $expectedPath): Customer
    {
        $this->customer($actor, $customerId, true);
        abort_unless(self::ready(), 503, 'Firmenlogos sind noch nicht eingerichtet.');
        $customer = DB::transaction(function () use ($actor, $customerId, $expectedPath): Customer {
            $customer = $this->customer($actor, $customerId, true, true);
            abort_unless($customer->logo_path === $expectedPath, 409, 'Das Firmenlogo wurde zwischenzeitlich geändert. Bitte neu laden.');
            if ($customer->logo_path !== null) {
                $customer->forceFill(['logo_path' => null])->save();
                $this->audit($customer, $actor, 'customer.logo.removed');
            }

            return $customer;
        });
        if ($expectedPath !== null && $customer->hasValidLogoPath($expectedPath)) {
            Storage::disk('private')->delete($expectedPath);
        }

        return $customer;
    }

    private function audit(Customer $customer, User|CustomerPortalIdentity $actor, string $action): void
    {
        if ($actor instanceof CustomerPortalIdentity) {
            CustomerPortalAudit::create(['customer_id' => $customer->id, 'identity_id' => $actor->id, 'action' => $action, 'details' => ['changed_fields' => ['logo_path']]]);
        } elseif (Schema::hasTable('operation_audits')) {
            app(OperationsAuditService::class)->record($customer, $actor, $action, ['changed_fields' => ['logo_path']]);
        }
    }
}
