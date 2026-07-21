<?php

namespace App\Livewire\Admin;

use App\Models\File;
use App\Models\Message;
use App\Models\StaffInvitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class Dashboard extends Component
{
    public int $totalUsers = 0;

    public int $activeUsers = 0;

    public int $totalEmployees = 0;

    public int $totalTeams = 0;

    public function mount(): void
    {
        $this->totalUsers = User::query()->count();
        $this->activeUsers = User::query()->where('status', true)->count();
        $this->totalEmployees = User::query()->whereIn('role', ['admin', 'staff'])->count();
        $this->totalTeams = Team::query()->where('personal_team', false)->count();
    }

    public function render()
    {
        $recentUsers = User::query()
            ->latest()
            ->limit(6)
            ->get(['id', 'name', 'email', 'role', 'status', 'created_at']);

        return view('livewire.admin.dashboard', [
            'recentUsers' => $recentUsers,
            'recentActivity' => $this->recentActivity(),
            'operations' => $this->operationsInfo(),
            'system' => $this->systemInfo(),
        ])->layout('layouts.master', ['area' => 'admin']);
    }

    /**
     * Zuletzt aktive Benutzer (letzter Seitenaufruf laut Activity-Log,
     * Super-Admin wird dort ohnehin nicht erfasst).
     *
     * @return \Illuminate\Support\Collection<int, array{user: User, lastSeen: Carbon}>
     */
    protected function recentActivity(): \Illuminate\Support\Collection
    {
        $lastSeen = Activity::query()
            ->whereNotNull('causer_id')
            ->where('causer_type', User::class)
            ->selectRaw('causer_id, MAX(created_at) as last_seen')
            ->groupBy('causer_id')
            ->orderByDesc('last_seen')
            ->limit(6)
            ->get();

        $users = User::query()
            ->whereIn('id', $lastSeen->pluck('causer_id'))
            ->get(['id', 'name', 'email', 'profile_photo_path'])
            ->keyBy('id');

        return $lastSeen
            ->map(fn ($row) => [
                'user' => $users->get($row->causer_id),
                'lastSeen' => Carbon::parse($row->last_seen),
            ])
            ->filter(fn (array $entry) => $entry['user'] !== null)
            ->values();
    }

    /**
     * Betriebszahlen fuer Admins/Verwaltung.
     *
     * @return array{online: int, openInvitations: int, unreadTotal: int}
     */
    protected function operationsInfo(): array
    {
        $online = Activity::query()
            ->whereNotNull('causer_id')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->distinct()
            ->count('causer_id');

        $openInvitations = StaffInvitation::query()
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        return [
            'online' => $online,
            'openInvitations' => $openInvitations,
            'unreadTotal' => Message::query()->where('status', 1)->count(),
        ];
    }

    /**
     * Systemstatus — nur fuer den Admin-/Verwaltungsbereich gedacht.
     * Alle externen Abfragen defensiv, damit das Dashboard nie an einer
     * einzelnen Kennzahl scheitert.
     *
     * @return array<string, mixed>
     */
    protected function systemInfo(): array
    {
        $databaseLabel = null;

        try {
            $driver = DB::connection()->getDriverName();
            $databaseLabel = $driver;

            if ($driver === 'mysql') {
                $bytes = (int) DB::scalar(
                    'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()'
                );
                $databaseLabel = 'MySQL · ' . static::formatBytes($bytes);
            }
        } catch (\Throwable) {
            $databaseLabel = '—';
        }

        try {
            $queueLabel = __('app.jobs_pending', ['count' => DB::table('jobs')->count()]);
            $failedJobs = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $queueLabel = '—';
            $failedJobs = 0;
        }

        $diskFree = @disk_free_space(storage_path());
        $diskTotal = @disk_total_space(storage_path());

        $lastActivityAt = Activity::query()->latest('created_at')->value('created_at');

        return [
            'appVersion' => config('app.name') . (config('app.version') ? ' v' . config('app.version') : ''),
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database' => $databaseLabel,
            'queue' => $queueLabel,
            'failedJobs' => $failedJobs,
            'storage' => trans_choice('app.files_count', File::query()->count()) . ' · ' . static::formatBytes((int) File::query()->sum('size')),
            'disk' => ($diskFree && $diskTotal)
                ? static::formatBytes((int) $diskFree) . ' / ' . static::formatBytes((int) $diskTotal)
                : '—',
            'lastActivityAt' => $lastActivityAt ? Carbon::parse($lastActivityAt) : null,
        ];
    }

    protected static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }

        return $bytes . ' B';
    }
}
