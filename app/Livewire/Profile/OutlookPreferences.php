<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Livewire\Concerns\ManagesOutlookPreferences;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** Outlook-Einstellungen im eigenen Profil (Selbstbedienung über die Sitzung). */
final class OutlookPreferences extends Component
{
    use ManagesOutlookPreferences;

    public function mount(OutlookAddinAutomaticInsertionPolicy $policy): void
    {
        $this->loadOutlookPreferenceState($policy);
    }

    protected function outlookTargetUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function outlookManagingActor(): ?User
    {
        return null;
    }

    public function render(): View
    {
        return view('livewire.profile.outlook-preferences');
    }
}
