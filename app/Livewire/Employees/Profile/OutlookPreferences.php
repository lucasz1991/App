<?php

declare(strict_types=1);

namespace App\Livewire\Admin\UserProfile;

use App\Livewire\Concerns\ManagesOutlookPreferences;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
use App\Support\OutlookAddin\OutlookEmployeePreferencesAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Outlook-Einstellungen einer verwalteten Person (fremdes Profil, Rechte über OutlookEmployeePreferencesAccess). */
final class OutlookPreferences extends Component
{
    use ManagesOutlookPreferences;

    #[Locked]
    public int $userId;

    #[Locked]
    public string $instancePrefix;

    #[Locked]
    public string $targetName;

    public function mount(int $userId, OutlookAddinAutomaticInsertionPolicy $policy): void
    {
        $this->userId = $userId;
        $target = $this->outlookTargetUser();
        app(OutlookEmployeePreferencesAccess::class)->resolve($this->outlookManagingActor(), $target);
        $this->instancePrefix = 'employee-outlook-'.(int) Auth::id().'-'.$userId.'-'.$this->getId();
        $this->targetName = (string) $target->name;
        $this->loadOutlookPreferenceState($policy);
    }

    protected function outlookTargetUser(): User
    {
        return User::query()->findOrFail($this->userId);
    }

    protected function outlookManagingActor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        app(OutlookEmployeePreferencesAccess::class)->resolve($this->outlookManagingActor(), $this->outlookTargetUser());

        return view('livewire.admin.user-profile.outlook-preferences');
    }
}
