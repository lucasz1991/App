<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesSuperAdminSettings;
use App\Services\Routing\RoutingUsageService;
use App\Services\Routing\ServerRoutingCache;
use App\Services\Routing\TomTomRoutingService;
use App\Support\Routing\TomTomSettings as Settings;
use Livewire\Component;

class TomTomSettings extends Component
{
    use AuthorizesSuperAdminSettings;

    public array $config = [];

    public string $apiKey = '';

    public array $connection = [];

    public function mount(): void
    {
        $this->authorizeSuperAdminSettings();
        $this->config = Settings::all();
    }

    public function save(): void
    {
        $this->authorizeSuperAdminSettings();
        $before = Settings::all();
        $keyChanged = trim($this->apiKey) !== '';
        try {
            Settings::save($this->config, $this->apiKey);
        } finally {
            // Also clear an invalid new secret from the response snapshot.
            $this->reset('apiKey');
        }
        $this->reset('connection');
        $this->config = Settings::all();
        $cacheKeys = array_filter(array_keys(Settings::DEFAULTS), fn (string $key) => str_starts_with($key, 'server_') || $key === 'enabled');
        if ($keyChanged || array_intersect_key($before, array_flip($cacheKeys)) !== array_intersect_key($this->config, array_flip($cacheKeys))) {
            app(ServerRoutingCache::class)->clear();
        }
        $this->dispatch('swal:toast', type: 'success', text: 'Routenplanung gespeichert.');
    }

    public function deleteKey(): void
    {
        $this->authorizeSuperAdminSettings();
        Settings::save(Settings::all(), null, true);
        $this->reset('apiKey', 'connection');
        $this->config = Settings::all();
        app(ServerRoutingCache::class)->clear();
    }

    public function clearServerCache(): void
    {
        $this->authorizeSuperAdminSettings();
        $removed = app(ServerRoutingCache::class)->clear();
        $this->dispatch('swal:toast', type: 'success', text: 'Server-Cache für alle Nutzer geleert: '.$removed.' Einträge entfernt.');
    }

    public function test(): void
    {
        $this->authorizeSuperAdminSettings();
        $result = app(TomTomRoutingService::class)->testConnection();
        $this->connection = ['ok' => ($result['status'] ?? null) === 'known', 'reason' => $result['reason'] ?? null];
    }

    public function render()
    {
        $this->authorizeSuperAdminSettings();

        return view('livewire.admin.tom-tom-settings', [
            'usage' => app(RoutingUsageService::class)->current(), 'saved' => Settings::all(),
            'cacheStats' => app(ServerRoutingCache::class)->stats(),
        ]);
    }
}
