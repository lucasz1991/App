<?php

namespace App\Livewire\Admin;

use App\Services\SystemHealth\SystemHealthService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SystemHealth extends Component
{
    /** @var array<int, array<string, mixed>> Safe, server-derived results only. */
    #[Locked]
    public array $rows = [];

    public function mount(): void
    {
        $this->authorizeDiagnostics();
        // Mounting a hidden tab must never start a network or worker probe.
        $this->rows = app(SystemHealthService::class)->snapshot();
    }

    public function refreshSnapshot(): array
    {
        $this->authorizeDiagnostics();
        $this->rows = app(SystemHealthService::class)->snapshot();
        $this->skipRender();

        return $this->rows;
    }

    public function checkOne(string $id, bool $force = false): array
    {
        $this->authorizeDiagnostics();
        $this->authorizeCheck($id);
        $row = app(SystemHealthService::class)->check($id, $force);
        $this->replaceRow($row);
        $this->skipRender();

        return $row;
    }

    public function pollCheck(string $id, string $runId): array
    {
        $this->authorizeDiagnostics();
        $this->authorizeCheck($id);
        if (! Str::isUuid($runId)) {
            throw ValidationException::withMessages(['systemHealth' => 'Ungültiger Prüflauf.']);
        }

        $row = app(SystemHealthService::class)->poll($id, $runId);
        $this->replaceRow($row);
        $this->skipRender();

        return $row;
    }

    private function authorizeDiagnostics(): void
    {
        Gate::authorize('settings.manage');
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }

    private function authorizeCheck(string $id): void
    {
        if (! in_array($id, array_column($this->rows, 'id'), true)) {
            throw ValidationException::withMessages(['systemHealth' => 'Diese Prüfung ist nicht verfügbar.']);
        }
    }

    private function replaceRow(array $row): void
    {
        foreach ($this->rows as $index => $existing) {
            if ($existing['id'] === $row['id']) {
                $this->rows[$index] = $row;

                return;
            }
        }
    }

    public function render()
    {
        $this->authorizeDiagnostics();

        return view('livewire.admin.system-health');
    }
}
