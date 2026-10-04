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
            'totalTeams' => $this->teamCount(),
        ];
    }

    /**
     * Fachliche Teams (Rechtegruppen) ohne die persoenlichen Jetstream-Teams.
     *
     * Eigene Methode, damit Aufrufer, die nur diese eine Zahl brauchen, nicht
     * die drei uebrigen Zaehlabfragen aus counters() mitausloesen.
     */
    public function teamCount(): int
    {
        return Team::query()->where('personal_team', false)->count();
    }

    public function recentUsers(): Collection
    {
        return User::query()
            ->where('role', '!=', 'admin')
            ->latest()
            ->limit(6)
            ->get(['id', 'name', 'email', 'role', 'status', 'created_at', 'profile_photo_path']);
    }

    /** Anzahl verschiedener Personen mit Aktivitaet seit $since (0, wenn das Activity-Log fehlt). */
    public function activeUsersSince(\DateTimeInterface $since): int
    {
        try {
            return Activity::query()
                ->whereNotNull('causer_id')
                ->where('causer_type', User::class)
                ->where('created_at', '>=', $since)
                ->distinct()
                ->count('causer_id');
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return Collection<int, array{user:User, lastSeen:Carbon}> */
    public function recentActivity(int $limit = 6): Collection
    {
        try {
            $lastSeen = Activity::query()
                ->whereNotNull('causer_id')
                ->where('causer_type', User::class)
                ->selectRaw('causer_id, MAX(created_at) as last_seen')
                ->groupBy('causer_id')
                ->orderByDesc('last_seen')
                ->limit($limit)
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
     *     activity: array{labels: array<int, string>, values: array<int, int>}
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
                $databaseLabel = 'MySQL · '.static::formatBytes($bytes);
            }
        } catch (\Throwable) {
            // Das Dashboard bleibt auch bei fehlender DB-Metrik erreichbar.
        }

        try {
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();
            $queueLabel = __('app.jobs_pending', ['count' => $pendingJobs])
                .' · '
                .__('app.jobs_failed', ['count' => $failedJobs]);
        } catch (\Throwable) {
            $queueLabel = '—';
        }

        $diskFree = @disk_free_space(storage_path());
        $diskTotal = @disk_total_space(storage_path());
        try {
            $lastActivityAt = Activity::query()->latest('created_at')->value('created_at');
        } catch (\Throwable) {
            $lastActivityAt = null;
        }

        return [
            'appVersion' => config('app.name').(config('app.version') ? ' v'.config('app.version') : ''),
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'php' => PHP_VERSION,
            'developer' => 'Lucas M. Zacharias',
            'database' => $databaseLabel,
            'queue' => $queueLabel,
            'storage' => trans_choice('app.files_count', File::query()->count()).' · '.static::formatBytes((int) File::query()->sum('size')),
            'disk' => ($diskFree && $diskTotal)
                ? static::formatBytes((int) $diskFree).' / '.static::formatBytes((int) $diskTotal)
                : '—',
            'lastActivityAt' => $lastActivityAt ? Carbon::parse($lastActivityAt) : null,
        ];
    }

    /**
     * Rohwerte fuer die Ampelpunkte im Systemzustand-Widget. Bewusst getrennt
     * von system(): dessen Form (ein zusammengefasstes "queue"-Label statt
     * einzelner Zaehler) ist fuer das Admin-Dashboard festgelegt.
     *
     * @return array{databaseOk: bool, failedJobs: int|null, diskUsedPct: int|null}
     */
    public function health(): array
    {
        try {
            DB::connection()->getPdo();
            $databaseOk = true;
        } catch (\Throwable) {
            $databaseOk = false;
        }

        try {
            $failedJobs = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $failedJobs = null;
        }

        $diskFree = @disk_free_space(storage_path());
        $diskTotal = @disk_total_space(storage_path());

        return [
            'databaseOk' => $databaseOk,
            'failedJobs' => $failedJobs,
            'diskUsedPct' => ($diskFree && $diskTotal) ? (int) round(100 - $diskFree / $diskTotal * 100) : null,
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1, ',', '.').' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.').' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return $bytes.' B';
    }
}
