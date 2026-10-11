<?php

namespace App\Livewire\Admin;

use App\Services\Routing\RoutingUsageService;
use App\Services\Routing\ServerRoutingCache;
use App\Services\Routing\TomTomRoutingService;
use App\Support\Routing\TomTomSettings as Settings;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class TomTomSettings extends Component
{
    public array $config = [];

    public string $apiKey = '';

    public array $connection = [];

    public function mount(): void
    {
        $this->authorizeSettings();
        $this->config = Settings::all();
    }

    private function authorizeSettings(): void
    {
        Gate::authorize('settings.manage');
        abort_unless(auth()->user()?->isActive() && auth()->user()?->isSuperAdmin(), 403);
    }

    public function save(): void
    {
        $this->authorizeSettings();
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
        $this->authorizeSettings();
        Settings::save(Settings::all(), null, true);
        $this->reset('apiKey', 'connection');
        $this->config = Settings::all();
        app(ServerRoutingCache::class)->clear();
    }

    public function clearServerCache(): void
    {
        $this->authorizeSettings();
        $removed = app(ServerRoutingCache::class)->clear();
        $this->dispatch('swal:toast', type: 'success', text: 'Server-Cache für alle Nutzer geleert: '.$removed.' Einträge entfernt.');
    }

    public function test(): void
    {
        $this->authorizeSettings();
        $result = app(TomTomRoutingService::class)->testConnection();
        $this->connection = ['ok' => ($result['status'] ?? null) === 'known', 'reason' => $result['reason'] ?? null];
    }

    public function render()
    {
        $this->authorizeSettings();

        return view('livewire.admin.tom-tom-settings', [
            'usage' => app(RoutingUsageService::class)->current(), 'saved' => Settings::all(),
            'cacheStats' => app(ServerRoutingCache::class)->stats(),
        ]);
    }
}
