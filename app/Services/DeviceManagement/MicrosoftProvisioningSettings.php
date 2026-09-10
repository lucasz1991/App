<?php

namespace App\Services\DeviceManagement;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class MicrosoftProvisioningSettings
{
    public function configuration(): array
    {
        $values = (array) Setting::getValueUncached('device_management', 'microsoft_provisioning');
        $values += ['enabled' => false, 'tenant_id' => '', 'client_id' => '', 'secret' => ''];
        try {
            $values['secret'] = $values['secret'] ? Crypt::decryptString($values['secret']) : '';
        } catch (\Throwable) {
            $values['secret'] = '';
            $values['enabled'] = false;
        }

        return $values;
    }

    public function save(array $input, User $actor): void
    {
        $actor = $actor->fresh();
        abort_unless($actor->isActive() && $actor->isSuperAdmin(), 403);
        Gate::forUser($actor)->authorize('settings.manage');
        $data = Validator::make($input, ['tenant_id' => ['required', 'uuid'], 'client_id' => ['required', 'uuid'],
            'secret' => ['nullable', 'string', 'max:4096'], 'enabled' => ['required', 'boolean'], 'tenant_approval_confirmed' => ['accepted']])->validate();
        $readConfig = app(MicrosoftDeviceSettings::class)->configuration();
        abort_unless(strtolower($data['tenant_id']) === strtolower($readConfig['tenant_id'] ?? ''), 422, 'Derselbe RailTime-Mandant ist erforderlich.');
        abort_if(strtolower($data['client_id']) === strtolower($readConfig['client_id'] ?? ''), 422, 'Getrennte Schreibanwendung verwenden.');
        $old = $this->configuration();
        $secret = trim($data['secret'] ?? '');
        if ($secret === '' && $old['tenant_id'] === strtolower($data['tenant_id']) && $old['client_id'] === strtolower($data['client_id'])) {
            $secret = $old['secret'];
        }
        abort_if($data['enabled'] && $secret === '', 422, 'Client-Geheimnis fehlt.');
        Setting::setValue('device_management', 'microsoft_provisioning', ['tenant_id' => strtolower($data['tenant_id']), 'client_id' => strtolower($data['client_id']),
            'secret' => $secret !== '' ? Crypt::encryptString($secret) : '', 'enabled' => $data['enabled'], 'approved_by' => $actor->id, 'approved_at' => now()->toIso8601String()]);
        activity('device-management')->causedBy($actor)->event('microsoft-provisioning.configured')->log('Getrennter Microsoft-Schreibzugang konfiguriert');
    }
}
