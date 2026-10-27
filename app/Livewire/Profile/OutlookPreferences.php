<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
use App\Support\OutlookAddin\OutlookAddinException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

final class OutlookPreferences extends Component
{
    #[Locked]
    public array $preferences = [];

    #[Locked]
    public string $revision = '';

    #[Locked]
    public bool $available = false;

    #[Locked]
    public string $notice = '';

    public function mount(OutlookAddinAutomaticInsertionPolicy $policy): void
    {
        $this->loadPreferences($policy);
    }

    public function reloadPreferences(OutlookAddinAutomaticInsertionPolicy $policy): void
    {
        $this->resetErrorBag();
        $this->notice = '';
        $this->loadPreferences($policy);
    }

    public function updatePreference(mixed $group, mixed $field, mixed $enabled): void
    {
        $this->resetErrorBag();
        $this->notice = '';
        if (! is_string($group) || ! in_array($group, ['newMessages', 'replies'], true)
            || ! is_string($field) || ! in_array($field, ['signature', 'template'], true) || ! is_bool($enabled)) {
            throw ValidationException::withMessages(['preferences' => 'Bitte genau eine gültige Outlook-Einstellung auswählen.']);
        }
        $policy = app(OutlookAddinAutomaticInsertionPolicy::class);
        try {
            $this->applyPreferences($policy->updateForSession($this->user(), $group, $field, $enabled, $this->revision));
            $this->notice = 'Outlook-Einstellung gespeichert.';
        } catch (OutlookAddinException $error) {
            if ($error->errorCode === 'outlook_addin_preferences_conflict') {
                $this->loadPreferences($policy);
                if ($this->available) {
                    $this->notice = 'Die Einstellungen wurden inzwischen geändert. Der aktuelle Stand wurde geladen. Bitte die gewünschte Änderung erneut auswählen.';
                }
            } else {
                $this->unavailable($error->getMessage());
            }
        } catch (Throwable) {
            $this->unavailable('Die Änderung konnte nicht bestätigt werden. Bitte den aktuellen Stand neu laden.');
        }
    }

    private function loadPreferences(OutlookAddinAutomaticInsertionPolicy $policy): void
    {
        $user = $this->user();
        try {
            $this->applyPreferences($policy->preferencesForSession($user));
        } catch (OutlookAddinException $error) {
            $this->unavailable($error->getMessage());
        } catch (Throwable) {
            $this->unavailable('Die Outlook-Einstellungen sind derzeit nicht verfügbar. Bitte später neu laden.');
        }
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function applyPreferences(array $preferences): void
    {
        $this->preferences = $preferences;
        $this->revision = $preferences['revision'];
        $this->available = true;
    }

    private function unavailable(string $message): void
    {
        $this->available = false;
        $this->preferences = [];
        $this->revision = '';
        $this->notice = $message;
    }

    public function render(): View
    {
        return view('livewire.profile.outlook-preferences');
    }
}
