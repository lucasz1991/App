<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsNavigation;

final class NativeOperationsDashboard
{
    public function forUser(User $user): array
    {
        $groups = $user->availableFilesGrouped();
        $files = $groups['personal']->merge($groups['company'])->merge(collect($groups['teams'])->flatMap(fn ($item) => $item['files']))->unique('id');

        return [
            'hasOperations' => count(OperationsNavigation::forUser($user)) > 0,
            'hasPersonalWork' => OperationsAccess::isEmployee($user),
            'recentFiles' => $files->sortByDesc('created_at')->take(5),
            'filesTotal' => $files->count(),
            'unreadMessages' => $user->receivedMessages()->where('status', 1)->count(),
        ];
    }
}
