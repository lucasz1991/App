<?php

namespace App\Services\DeviceManagement;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\EmployeeWorkplaceProvision;
use App\Models\User;
use App\Support\OutlookAddin\VerifiedEntraIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class EmployeeWorkplaceProvisioner
{
    public function approve(User $employee, array $input, User $actor): EmployeeWorkplaceProvision
    {
        $this->authorize($actor);
        $data = validator($input, ['principal' => ['required', 'email:rfc', 'max:191'], 'usage_location' => ['required', 'regex:/^[A-Z]{2}$/'],
            'profile_key' => ['required', 'in:railtime_basic,corporate_standard,byod_managed'], 'sku_id' => ['nullable', 'uuid'], 'confirmed' => ['accepted']])->validate();
        $config = app(MicrosoftProvisioningSettings::class)->configuration();
        abort_unless(Str::isUuid($config['tenant_id']), 422, 'Zuerst den getrennten Microsoft-Schreibzugang vorbereiten.');
        abort_if(WorkplaceProfileCatalog::get($data['profile_key'])['office_required'] && empty($data['sku_id']), 422, 'Das Office-Profil benötigt eine ausgewählte vorhandene Lizenz.');

        return DB::transaction(function () use ($employee, $actor, $data, $config) {
            $employee = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            abort_if($employee->isSuperAdmin() || ! in_array($employee->role, ['staff', 'admin'], true), 403);
            $principal = strtolower(trim($data['principal']));
            abort_unless(strtolower($employee->email) === $principal, 422, 'Anmeldeadresse und freigegebene Mitarbeiteradresse müssen übereinstimmen.');
            $existing = EmployeeWorkplaceProvision::query()->where('user_id', $employee->id)->first();
            if ($existing) {
                abort_unless($existing->tenant_id === $config['tenant_id'] && $existing->principal === $principal && $existing->profile_key === $data['profile_key'] && $existing->sku_id === ($data['sku_id'] ?? null) && $existing->usage_location === $data['usage_location'], 409, 'Bestehender Auftrag weicht ab. Nicht erneut anlegen.');

                return $existing;
            }
            abort_if($employee->isActive(), 422, 'Neue Freigabe nur für inaktive Mitarbeiter. Bestehende aktive Konten werden nicht deaktiviert.');
            $order = EmployeeWorkplaceProvision::query()->create(['user_id' => $employee->id, 'tenant_id' => $config['tenant_id'], 'principal' => $principal,
                'usage_location' => $data['usage_location'], 'profile_key' => $data['profile_key'], 'sku_id' => $data['sku_id'] ?? null,
                'approved_by' => $actor->id, 'approved_at' => now(), 'state' => 'approved']);
            activity('device-management')->causedBy($actor)->performedOn($employee)->event('employee-workplace.approved')->withProperties(['order_id' => $order->id])->log('Mitarbeiter-Arbeitsplatz freigegeben');

            return $order;
        }, 3);
    }

    /** Explicit per-employee execution, never triggered by adding a second device. */
    public function run(EmployeeWorkplaceProvision $order, User $actor): void
    {
        $this->authorize($actor);
        Cache::lock('employee-provision:'.$order->user_id, 180)->block(1, function () use ($order, $actor): void {
            $order = $order->fresh();
            abort_if($order->signed_in_at || $order->state === 'cancelled', 409);
            $config = app(MicrosoftProvisioningSettings::class)->configuration();
            abort_unless($config['enabled'] && $config['tenant_id'] === $order->tenant_id, 409, 'Microsoft-Schreibzugang ist nicht freigegeben.');
            $graph = app(MicrosoftProvisioningGraph::class);
            try {
                $graph->begin($config);
                $bound = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->where('user_id', $order->user_id)->get();
                if ($bound->count() > 1) {
                    throw new \RuntimeException('identity_conflict');
                }
                $identity = $bound->first();
                if ($identity && ($identity->tenant_id !== $order->tenant_id || strtolower($identity->principal) !== $order->principal || ! Str::isUuid($identity->external_id))) {
                    throw new \RuntimeException('identity_conflict');
                }
                $objectId = $identity?->external_id ?? $order->object_id;
                $remote = $graph->user($objectId ?: $order->principal);
                if (! $objectId && $remote) {
                    throw new \RuntimeException('existing_account_requires_binding');
                }
                if (! $remote) {
                    if ($objectId || $order->account_state !== 'pending') {
                        throw new \RuntimeException('account_result_requires_review');
                    }
                    $domainName = explode('@', $order->principal)[1];
                    $domain = collect($graph->domains())->first(fn ($domain) => strtolower($domain['id'] ?? '') === $domainName && ($domain['isVerified'] ?? false) === true && ($domain['authenticationType'] ?? '') === 'Managed');
                    if (! $domain) {
                        throw new \RuntimeException('managed_domain_required');
                    }
                    $password = Str::password(32);
                    $order->update(['state' => 'creating_account', 'account_state' => 'uncertain', 'initial_password' => $password, 'password_expires_at' => now()->addHours(24)]);
                    $this->freshWriteAuthorization($order, $actor, $config);
                    $remote = $graph->createUser(['accountEnabled' => true, 'displayName' => $order->user->name, 'userPrincipalName' => $order->principal,
                        'mailNickname' => explode('@', $order->principal)[0], 'usageLocation' => $order->usage_location,
                        'passwordProfile' => ['forceChangePasswordNextSignIn' => true, 'password' => $password]]);
                    unset($password);
                    if (! Str::isUuid($remote['id'] ?? '')) {
                        throw new \RuntimeException('account_result_requires_review');
                    }
                    $order->update(['object_id' => strtolower($remote['id']), 'account_state' => 'created']);
                    $remote = $graph->user($order->object_id);
                }
                if (! $remote || ! Str::isUuid($remote['id'] ?? '') || ($remote['accountEnabled'] ?? false) !== true || ($remote['userType'] ?? '') !== 'Member' || strtolower($remote['userPrincipalName'] ?? '') !== $order->principal) {
                    throw new \RuntimeException('identity_conflict');
                }
                if ($objectId && strtolower($remote['id']) !== strtolower($objectId)) {
                    throw new \RuntimeException('identity_conflict');
                }
                $order->update(['object_id' => strtolower($remote['id']), 'account_state' => 'ready']);
                $this->bind($order);
                $license = 'not_required';
                if ($order->sku_id) {
                    $assigned = collect($remote['assignedLicenses'] ?? [])->contains(fn ($entry) => strtolower($entry['skuId'] ?? '') === strtolower($order->sku_id));
                    if (! $assigned) {
                        if ($order->license_state === 'uncertain') {
                            throw new \RuntimeException('license_result_requires_review');
                        }
                        if (($remote['usageLocation'] ?? '') !== $order->usage_location) {
                            throw new \RuntimeException('usage_location_requires_review');
                        }
                        $sku = collect($graph->skus())->first(fn ($sku) => strtolower($sku['skuId'] ?? '') === strtolower($order->sku_id));
                        if (! $sku || ($sku['capabilityStatus'] ?? '') !== 'Enabled' || (int) ($sku['prepaidUnits']['enabled'] ?? 0) <= (int) ($sku['consumedUnits'] ?? 0)) {
                            throw new \RuntimeException('license_seats_exhausted');
                        }
                        $order->update(['state' => 'assigning_license', 'license_state' => 'uncertain']);
                        $this->freshWriteAuthorization($order, $actor, $config);
                        $graph->assignLicense($order->object_id, $order->sku_id);
                        $remote = $graph->user($order->object_id);
                        $assigned = collect($remote['assignedLicenses'] ?? [])->contains(fn ($entry) => strtolower($entry['skuId'] ?? '') === strtolower($order->sku_id));
                    }
                    $license = $assigned ? 'assigned' : 'uncertain';
                }
                $exchange = collect($remote['provisionedPlans'] ?? [])->first(fn ($plan) => strtolower($plan['service'] ?? '') === 'exchange');
                // A mail attribute or an assigned SKU is not proof of an operational Exchange mailbox.
                $mailbox = $exchange ? (($exchange['provisioningStatus'] ?? '') === 'Success' ? 'service_provisioned' : 'pending') : ($order->sku_id ? 'unknown' : 'not_required');
                $order->update(['state' => $license === 'uncertain' ? 'review_required' : 'awaiting_sign_in', 'license_state' => $license, 'mailbox_state' => $mailbox, 'error_code' => null]);
            } catch (\Throwable $error) {
                $code = preg_match('/^[a-z_0-9]{3,64}$/', $error->getMessage()) ? $error->getMessage() : 'provisioning_failed';
                $order->update(['state' => 'review_required', 'error_code' => $code]);
                // No response bodies, credentials, Graph request payloads or passwords in logs.
            }
        });
    }

    public function cancel(EmployeeWorkplaceProvision $order, User $actor): void
    {
        $this->authorize($actor);
        Cache::lock('employee-provision:'.$order->user_id, 180)->block(1, function () use ($order, $actor): void {
            DB::transaction(function () use ($order, $actor): void {
                $order = EmployeeWorkplaceProvision::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_if($order->signed_in_at, 409, 'Aktivierte Mitarbeiter über die reguläre Mitarbeiterverwaltung sperren.');
                $order->update(['state' => 'cancelled', 'initial_password' => null, 'password_expires_at' => null]);
                activity('device-management')->causedBy($actor)->performedOn($order)->event('employee-workplace.cancelled')
                    ->withProperties(['order_id' => $order->id])->log('Lokale Mitarbeiterfreigabe zurückgenommen; Microsoft-Konto bleibt unverändert');
            }, 3);
        });
    }

    private function bind(EmployeeWorkplaceProvision $order): void
    {
        DB::transaction(function () use ($order): void {
            User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $existing = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)
                ->where(fn ($q) => $q->where('user_id', $order->user_id)->orWhere('external_id', $order->object_id)->orWhere('principal', $order->principal))->lockForUpdate()->get();
            if ($existing->count() > 1) {
                throw new \RuntimeException('identity_conflict');
            }
            $account = $existing->first();
            if ($account && ((int) $account->user_id !== (int) $order->user_id || $account->tenant_id !== $order->tenant_id || $account->external_id !== $order->object_id || $account->lifecycle_status !== 'active')) {
                throw new \RuntimeException('identity_conflict');
            }
            if (! $account) {
                EmployeeIdentityAccount::query()->create(['user_id' => $order->user_id, 'provider' => AccountProvider::Microsoft365, 'tenant_id' => $order->tenant_id, 'external_id' => $order->object_id, 'principal' => $order->principal, 'email' => $order->principal, 'lifecycle_status' => 'active', 'provisioning_status' => 'ready', 'license_status' => 'unknown']);
            }
        }, 3);
    }

    public function activate(VerifiedEntraIdentity $verified): void
    {
        if (! Schema::hasTable('employee_workplace_provisions')) {
            return;
        }
        DB::transaction(function () use ($verified): void {
            $order = EmployeeWorkplaceProvision::query()->where('tenant_id', strtolower($verified->tenantId))->where('object_id', strtolower($verified->objectId))->where('principal', strtolower($verified->principal))->where('state', 'awaiting_sign_in')->whereNull('signed_in_at')->lockForUpdate()->first();
            if (! $order) {
                return;
            }
            $user = User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $approvalActor = User::query()->find($order->approved_by);
            if (! $approvalActor?->isActive() || ! $approvalActor->email_verified_at || ! Gate::forUser($approvalActor)->allows('employees.create')
                || ! Gate::forUser($approvalActor)->allows('devices.accounts.manage') || $user->isSuperAdmin()
                || strtolower($user->email) !== $order->principal || ! in_array($user->role, ['staff', 'admin'], true)
                || ! EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->where('user_id', $user->id)
                    ->where('tenant_id', $order->tenant_id)->where('external_id', $order->object_id)->where('lifecycle_status', 'active')->exists()) {
                return;
            }
            $user->forceFill(['status' => true, 'email_verified_at' => now()])->save();
            $order->update(['state' => 'active', 'signed_in_at' => now(), 'initial_password' => null]);
            activity('device-management')->performedOn($user)->event('employee-workplace.first-sign-in')->withProperties(['order_id' => $order->id])->log('Freigegebenen Mitarbeiter nach verifizierter Microsoft-Anmeldung aktiviert');
        }, 3);
    }

    private function authorize(User $actor): void
    {
        $actor = $actor->fresh();
        abort_unless($actor->isActive() && $actor->email_verified_at, 403);
        Gate::forUser($actor)->authorize('employees.create');
        Gate::forUser($actor)->authorize('devices.accounts.manage');
    }

    private function freshWriteAuthorization(EmployeeWorkplaceProvision $order, User $actor, array $snapshot): void
    {
        $this->authorize($actor);
        $current = app(MicrosoftProvisioningSettings::class)->configuration();
        abort_unless($current === $snapshot && $current['enabled'] && $order->fresh()->state !== 'cancelled', 409);
    }
}
