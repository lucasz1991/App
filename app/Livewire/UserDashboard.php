<?php

namespace App\Livewire;

use App\Models\File;
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

    public function render(SystemDashboardData $dashboardData)
    {
        $user = auth()->user();
        $audience = $user->dashboardAudience();
        $dashboardTeam = $user->dashboardTeam();

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

        // The compact charts are entirely server-rendered from existing data.
        // Orders and shifts intentionally stay marked as not connected until
        // their productive modules provide a real data source.
        $activityDays = collect(range(13, 0))
            ->map(fn (int $daysAgo) => now()->subDays($daysAgo)->startOfDay());
        $receivedMessagesByDay = $user->receivedMessages()
            ->where('created_at', '>=', $activityDays->first()->copy()->startOfDay())
            ->get(['id', 'created_at'])
            ->countBy(fn ($message) => $message->created_at->toDateString());
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
            'showSchedule' => $audience === 'employee',
            'wagonListRoute' => route('operations.wagon-list'),
        ])->layout('layouts.master', ['area' => 'user']);
    }
}
