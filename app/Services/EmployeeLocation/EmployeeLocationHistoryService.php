<?php

namespace App\Services\EmployeeLocation;

use App\Models\EmployeeLocationPoint;
use App\Models\User;
use App\Services\Operations\PersonnelScopeService;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use App\Support\Operations\TimelineLocationPreview;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Read-only, request-local projection; never cache employee coordinates. */
class EmployeeLocationHistoryService
{
    public const PAGE_NAME = 'locationHistoryPage';

    public const PER_PAGE = 20;

    public const ZONE = 'Europe/Berlin';

    /** @return array{User, User} */
    public function authorizeProfileAccess(int $userId, ?User $actor = null): array
    {
        $actor ??= auth()->user();
        abort_unless($actor instanceof User, 403);
        // Re-fetch role, status and current team on every render/action. An old
        // hydrated profile or a cached relation cannot preserve revoked access.
        $actor = User::with('currentTeam')->find($actor->id);
        abort_unless($actor?->isActive() && $actor->isAdmin(), 403);
        Gate::forUser($actor)->authorize('employees.view');
        $employee = User::findOrFail($userId);
        abort_unless($employee->role === 'staff', 403);

        return [$actor, $employee];
    }

    public function validateDay(string $day): CarbonImmutable
    {
        $today = CarbonImmutable::now(self::ZONE)->startOfDay();
        $firstDay = CarbonImmutable::now('UTC')->subDays(7)->setTimezone(self::ZONE)->startOfDay();
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $day, self::ZONE);
        } catch (\InvalidArgumentException) {
            $date = null;
        }
        if (! $date || $date->toDateString() !== $day || $date->lt($firstDay) || $date->gt($today)) {
            throw ValidationException::withMessages(['selectedDay' => 'Bitte einen Tag aus den letzten sieben Tagen auswählen.']);
        }

        return $date;
    }

    public function read(int $userId, string $day, int $page = 1, ?User $actor = null): array
    {
        [$actor, $employee] = $this->authorizeProfileAccess($userId, $actor);
        $date = $this->validateDay($day);
        $today = CarbonImmutable::now(self::ZONE)->startOfDay();
        $cutoff = CarbonImmutable::now('UTC')->subDays(7)->setTimezone(self::ZONE);
        $firstDay = $cutoff->startOfDay();
        $dayOffsets = range(0, (int) $firstDay->diffInDays($today));
        $view = [
            'state' => 'restricted', 'enabled' => false, 'records' => null, 'latest' => null,
            'selected_day' => $day, 'day_label' => $date->locale('de')->isoFormat('dddd, D. MMMM YYYY'),
            'first_day' => $firstDay->toDateString(), 'last_day' => $today->toDateString(),
            'partial_day' => $date->isSameDay($firstDay) && ! $cutoff->isStartOfDay(), 'cutoff_time' => $cutoff->format('H:i'),
            'previous_available' => $date->gt($firstDay), 'next_available' => $date->lt($today),
            'days' => collect($dayOffsets)->map(fn ($offset) => [
                'value' => $today->subDays($offset)->toDateString(),
                'label' => match ($offset) {
                    0 => 'Heute · '.$today->format('d.m.'),
                    1 => 'Gestern · '.$today->subDay()->format('d.m.'),
                    default => $today->subDays($offset)->locale('de')->isoFormat('dd, D. MMM').($today->subDays($offset)->isSameDay($firstDay) && ! $cutoff->isStartOfDay() ? ' · Teil des Tages' : ''),
                },
            ])->all(),
            'map' => null, 'total' => 0,
        ];
        // Deliberately stronger than the ordinary admin Gate bypass, including
        // self access: the explicitly stored permission AND personnel scope
        // must pass before any point or consent table is queried.
        if (($actor->currentTeam?->rbac_permissions['operations.locations.view'] ?? false) !== true
            || ! app(PersonnelScopeService::class)->allows($actor, $employee, 'operations.locations.view')) {
            return $view;
        }
        if (! EmployeeLocationSettings::ready()) {
            return array_replace($view, ['state' => 'unconfigured']);
        }
        $settings = EmployeeLocationSettings::read();
        $now = CarbonImmutable::now('UTC');
        $query = $this->historyQuery((int) $employee->id, $date, $now);
        $total = (clone $query)->count();
        $page = max(1, min($page, max(1, (int) ceil($total / self::PER_PAGE))));
        $points = (clone $query)->orderByDesc('measured_at')->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();
        $latestPoint = $page === 1 ? $points->first()
            : (clone $query)->orderByDesc('measured_at')->orderByDesc('id')->first();
        $latest = $latestPoint ? $this->projectPoint($latestPoint) : null;
        $records = new LengthAwarePaginator($points->map(fn ($point) => $this->projectPoint($point)), $total, self::PER_PAGE, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => self::PAGE_NAME,
        ]);

        return array_replace($view, [
            'state' => $total === 0 ? 'empty' : 'available', 'enabled' => (bool) $settings['enabled'],
            'records' => $records, 'latest' => $latest, 'total' => $total,
            'map' => $latest ? $this->mapPoint($latest) : null,
        ]);
    }

    private function historyQuery(int $userId, CarbonImmutable $date, CarbonImmutable $now): Builder
    {
        return EmployeeLocationPoint::query()->where('user_id', $userId)
            ->where('measured_at', '>', $now->subDays(7)->format('Y-m-d H:i:s.u'))
            ->where('measured_at', '<=', $now->format('Y-m-d H:i:s.u'))
            ->where('measured_at', '>=', $date->utc()->format('Y-m-d H:i:s.u'))
            ->where('measured_at', '<', $date->addDay()->utc()->format('Y-m-d H:i:s.u'))
            ->whereBetween('accuracy_m', [0, 50])
            ->whereExists(function ($consents) use ($userId, $date): void {
                $consents->selectRaw('1')->from('employee_location_consents as history_consent')
                    ->whereColumn('history_consent.id', 'employee_location_points.consent_id')
                    ->where('history_consent.user_id', $userId)
                    ->whereColumn('history_consent.installation_id', 'employee_location_points.installation_id')
                    ->whereColumn('history_consent.device_assignment_id', 'employee_location_points.device_assignment_id')
                    ->where('history_consent.local_date', $date->toDateString())
                    ->where('history_consent.purpose', 'work_routing')->where('history_consent.decision', 'accepted')
                    ->whereNull('history_consent.withdrawn_at')->whereNotNull('history_consent.accepted_at')
                    ->whereColumn('history_consent.accepted_at', '<=', 'employee_location_points.measured_at')
                    ->whereColumn('history_consent.expires_at', '>', 'employee_location_points.measured_at');
            });
    }

    private function projectPoint(EmployeeLocationPoint $point): array
    {
        try {
            $position = $point->position;
        } catch (DecryptException) {
            $position = null;
        }
        $latitude = $position['latitude'] ?? null;
        $longitude = $position['longitude'] ?? null;
        $valid = is_numeric($latitude) && is_numeric($longitude) && is_finite((float) $latitude) && is_finite((float) $longitude)
            && abs((float) $latitude) <= 90 && abs((float) $longitude) <= 180;
        $measured = CarbonImmutable::instance($point->measured_at)->setTimezone(self::ZONE);

        return [
            'id' => (int) $point->id, 'date' => $measured->toDateString(), 'time' => $measured->format('H:i'),
            'measured_at' => $measured->toIso8601String(), 'display_date' => $measured->format('d.m.Y'),
            'accuracy' => number_format((float) $point->accuracy_m, 0, ',', '.').' m',
            'latitude' => $valid ? (float) $latitude : null, 'longitude' => $valid ? (float) $longitude : null,
            'coordinates' => $valid ? number_format((float) $latitude, 5, ',', '').' / '.number_format((float) $longitude, 5, ',', '') : null,
        ];
    }

    /** Local Germany outline only; no guessed city, provider or browser cache. */
    private function mapPoint(array $point): ?array
    {
        if ($point['latitude'] === null || $point['longitude'] === null) {
            return null;
        }
        $projection = json_decode(file_get_contents(resource_path('data/maps/germany-outline.json')), true, 512, JSON_THROW_ON_ERROR)['projection'];
        $cosine = cos(deg2rad($projection['standard_parallel']));
        $width = ($projection['east'] - $projection['west']) * $cosine;
        $height = $projection['north'] - $projection['south'];
        $scale = min(($projection['width'] - 2 * $projection['padding']) / $width, ($projection['height'] - 2 * $projection['padding']) / $height);
        $x = ($projection['width'] - $width * $scale) / 2 + ($point['longitude'] - $projection['west']) * $cosine * $scale;
        $y = ($projection['height'] - $height * $scale) / 2 + ($projection['north'] - $point['latitude']) * $scale;
        if ($x < 0 || $x > 160 || $y < 0 || $y > 176) {
            return null;
        }

        return ['x' => round($x, 2), 'y' => round($y, 2), 'view_box' => TimelineLocationPreview::viewBox(), 'outline' => TimelineLocationPreview::outlinePath()];
    }
}
