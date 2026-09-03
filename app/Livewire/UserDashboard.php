<?php

namespace App\Livewire;

use App\Models\File;
use App\Models\User;
use App\Services\DeviceManagement\DeviceFleetSnapshot;
use App\Services\DeviceManagement\PersonalDeviceSnapshot;
use App\Support\Dashboard\SystemDashboardData;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserDashboard extends Component
{
    public function mount(): void
    {
        if (auth()->user()->usesAdminLayout()) {
            $this->redirectRoute('admin.dashboard', navigate: true);
        }
    }

    /**
     * Download only files that are actually available to the signed-in user.
     */
    public function downloadFile(int $fileId): StreamedResponse
    {
        $file = File::findOrFail($fileId);
        abort_unless(auth()->user()->canAccessFile($file, 'download'), 403);

        return $file->download($file->disk ?: 'private');
    }

    public function render(
        SystemDashboardData $dashboardData,
        DeviceFleetSnapshot $fleetSnapshot,
        PersonalDeviceSnapshot $personalDeviceSnapshot,
    ) {
        $user = auth()->user();
        $audience = $user->dashboardAudience();
        $dashboardTeam = $user->dashboardTeam();
        $deviceWidget = $this->deviceWidget($user, $fleetSnapshot, $personalDeviceSnapshot);

        if ($user->canViewManagementDashboard()) {
            $canViewSystemData = $user->canViewSystemDashboard();

            return view('livewire.management-dashboard', array_merge(
                $dashboardData->counters(),
                [
                    'dashboardAudience' => $audience,
                    'dashboardTeamName' => $dashboardTeam?->name ?? __('app.administration'),
                    'recentActivity' => $dashboardData->recentActivity(),
                    'operations' => $dashboardData->operations(),
                    'charts' => $dashboardData->charts(),
                    'deviceWidget' => $deviceWidget,
                    'canViewSystemData' => $canViewSystemData,
                    'system' => $canViewSystemData ? $dashboardData->system() : null,
                ]
            ))->layout('layouts.master', ['area' => 'user']);
        }

        // Real user-facing data: available files and personal messages.
        $grouped = $user->availableFilesGrouped();
        $teamFiles = collect($grouped['teams'])
            ->flatMap(fn (array $entry) => $entry['files'])
            ->unique('id')
            ->values();
        $availableFiles = $grouped['personal']
            ->merge($grouped['company'])
            ->merge($teamFiles)
            ->unique('id')
            ->values();
        $recentFiles = $availableFiles
            ->sortByDesc('created_at')
            ->take(6)
            ->values();

        $unreadMessages = $user->receivedMessages()->where('status', 1)->count();

        // Persoenliche Verlaufsdaten bleiben Teil des bisherigen Layouts.
        // Sie stammen ausschliesslich aus dem eigenen Nachrichteneingang.
        $today = now()->startOfDay();
        $activityDays = collect(range(13, 0))
            ->map(fn (int $daysAgo) => $today->copy()->subDays($daysAgo));
        $receivedMessagesByDay = $user->receivedMessages()
            ->whereBetween('created_at', [
                $activityDays->first()->copy()->startOfDay(),
                $today->copy()->endOfDay(),
            ])
            ->selectRaw('DATE(created_at) as activity_date, COUNT(*) as message_count')
            ->groupByRaw('DATE(created_at)')
            ->pluck('message_count', 'activity_date');
        $messageActivity = [
            'labels' => $activityDays
                ->map(fn ($day) => $day->translatedFormat('d. M'))
                ->all(),
            'values' => $activityDays
                ->map(fn ($day) => (int) ($receivedMessagesByDay[$day->toDateString()] ?? 0))
                ->all(),
        ];
        $messageActivity['total'] = array_sum($messageActivity['values']);

        $fileSources = [
            'labels' => [
                __('app.provided_for_you'),
                __('app.company_files'),
                __('app.team'),
            ],
            'values' => [
                $grouped['personal']->count(),
                $grouped['company']->count(),
                $teamFiles->count(),
            ],
        ];

        $latestMessages = $user->receivedMessages()
            ->with('sender:id,name,profile_photo_path')
            ->latest()
            ->limit(3)
            ->get();

        $profile = $user->profile;
        $profileChecks = [
            'phone' => filled($profile?->phone),
            'mobile' => filled($profile?->mobile),
            'position' => filled($profile?->position),
            'profile_photo' => filled($user->profile_photo_path),
        ];
        $profileCompletion = (int) round(
            100 * count(array_filter($profileChecks)) / count($profileChecks)
        );

        return view('livewire.user-dashboard', [
            'recentFiles' => $recentFiles,
            'unreadMessages' => $unreadMessages,
            'filesTotal' => $availableFiles->count(),
            'messageActivity' => $messageActivity,
            'fileSources' => $fileSources,
            'latestMessages' => $latestMessages,
            'profileChecks' => $profileChecks,
            'profileCompletion' => $profileCompletion,
            'dashboardAudience' => $audience,
            'dashboardTeamName' => $dashboardTeam?->name
                ?? ($audience === 'guest' ? __('app.team_guests') : __('app.team_employees')),
            'deviceWidget' => $deviceWidget,
            'showSchedule' => $audience === 'employee',
            'wagonListRoute' => route('operations.wagon-list'),
        ])->layout('layouts.master', ['area' => 'user']);
    }

    /**
     * Flottenzahlen bleiben an das delegierbare devices.view-Gate gebunden.
     * Ohne dieses Recht erhaelt jede Rolle ausschliesslich ihre eigenen
     * aktiven Zuweisungen und den persoenlichen Zielpfad.
     *
     * @return array{scope:'fleet'|'personal',stats:array<string, bool|int>,href:?string}
     */
    private function deviceWidget(
        User $user,
        DeviceFleetSnapshot $fleetSnapshot,
        PersonalDeviceSnapshot $personalDeviceSnapshot,
    ): array {
        $canViewFleet = $user->canViewManagementDashboard() && $user->can('devices.view');
        $stats = $canViewFleet
            ? $fleetSnapshot->get()
            : $personalDeviceSnapshot->get($user);

        return [
            'scope' => $canViewFleet ? 'fleet' : 'personal',
            'stats' => $stats,
            'href' => $stats['available']
                ? route($canViewFleet ? 'devices.index' : 'devices.mine')
                : null,
        ];
    }
}
