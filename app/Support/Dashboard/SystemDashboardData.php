<?php

namespace App\Support\Dashboard;

use App\Models\File;
use App\Models\Message;
use App\Models\StaffInvitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class SystemDashboardData
{
    /** @return array{totalUsers:int, activeUsers:int, totalEmployees:int, totalTeams:int} */
    public function counters(): array
    {
        return [
            'totalUsers' => User::query()->count(),
            'activeUsers' => User::query()->where('status', true)->count(),
            'totalEmployees' => User::query()->where('role', 'staff')->count(),
            'totalTeams' => Team::query()->where('personal_team', false)->count(),
        ];
    }

    public function recentUsers(): Collection
    {
        return User::query()
            ->latest()
            ->limit(6)
            ->get(['id', 'name', 'email', 'role', 'status', 'created_at']);
    }

    /** @return Collection<int, array{user:User, lastSeen:Carbon}> */
    public function recentActivity(): Collection
    {
        try {
            $lastSeen = Activity::query()
                ->whereNotNull('causer_id')
                ->where('causer_type', User::class)
                ->selectRaw('causer_id, MAX(created_at) as last_seen')
                ->groupBy('causer_id')
                ->orderByDesc('last_seen')
                ->limit(6)
                ->get();
        } catch (\Throwable) {
            return collect();
        }

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

    /** @return array{online:int, openInvitations:int, unreadTotal:int} */
    public function operations(): array
    {
        try {
            $online = Activity::query()
                ->whereNotNull('causer_id')
                ->where('created_at', '>=', now()->subMinutes(5))
                ->distinct()
                ->count('causer_id');
        } catch (\Throwable) {
            $online = 0;
        }

        try {
            $openInvitations = StaffInvitation::query()
                ->whereNull('accepted_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count();
        } catch (\Throwable) {
            $openInvitations = 0;
        }

        try {
            $unreadTotal = Message::query()->where('status', 1)->count();
        } catch (\Throwable) {
            $unreadTotal = 0;
        }

        return [
            'online' => $online,
            'openInvitations' => $openInvitations,
            'unreadTotal' => $unreadTotal,
        ];
    }

    /**
     * Reale Diagrammdaten fuer den Admin-Einstieg.
     *
     * @return array{
     *     userGrowth: array{labels: array<int, string>, totals: array<int, int>, registrations: array<int, int>},
     *     activity: array{labels: array<int, string>, values: array<int, int>},
     *     status: array{labels: array<int, string>, values: array<int, int>}
     * }
     */
    public function charts(): array
    {
        $days = collect(range(13, 0))->map(fn (int $daysAgo) => now()->subDays($daysAgo)->startOfDay());
        $start = $days->first();

        $registrationsByDay = User::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day')
            ->map(fn ($value) => (int) $value);

        try {
            $activityByDay = Activity::query()
                ->whereNotNull('causer_id')
                ->where('created_at', '>=', $start)
                ->selectRaw('DATE(created_at) as day, COUNT(DISTINCT causer_id) as aggregate')
                ->groupBy('day')
                ->pluck('aggregate', 'day')
                ->map(fn ($value) => (int) $value);
        } catch (\Throwable) {
            $activityByDay = collect();
        }

        $runningTotal = User::query()->where('created_at', '<', $start)->count();
        $growthTotals = [];
        $registrations = [];
        $activity = [];

        foreach ($days as $day) {
            $key = $day->toDateString();
            $dailyRegistrations = (int) ($registrationsByDay[$key] ?? 0);
            $runningTotal += $dailyRegistrations;
            $registrations[] = $dailyRegistrations;
            $growthTotals[] = $runningTotal;
            $activity[] = (int) ($activityByDay[$key] ?? 0);
        }

        $activeUsers = User::query()->where('status', true)->count();
        $inactiveUsers = User::query()->where('status', false)->count();

        return [
            'userGrowth' => [
                'labels' => $days->map(fn (Carbon $day) => $day->translatedFormat('d. M'))->all(),
                'totals' => $growthTotals,
                'registrations' => $registrations,
            ],
            'activity' => [
                'labels' => $days->map(fn (Carbon $day) => $day->translatedFormat('d. M'))->all(),
                'values' => $activity,
            ],
            'status' => [
                'labels' => [__('app.active_users'), __('app.inactive_users')],
                'values' => [$activeUsers, $inactiveUsers],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function system(): array
    {
        $databaseLabel = '—';

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
            // Das Dashboard bleibt auch bei fehlender DB-Metrik erreichbar.
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
        try {
            $lastActivityAt = Activity::query()->latest('created_at')->value('created_at');
        } catch (\Throwable) {
            $lastActivityAt = null;
        }

        return [
            'appVersion' => config('app.name') . (config('app.version') ? ' v' . config('app.version') : ''),
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'php' => PHP_VERSION,
            'developer' => 'Lucas M. Zacharias',
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

    public static function formatBytes(int $bytes): string
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
