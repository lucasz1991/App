<?php

declare(strict_types=1);

namespace App\Livewire\Admin\UserProfile;

use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinTemplateChoicesPolicy;
use App\Support\OutlookAddin\OutlookAddinWordingPolicy;
use App\Support\OutlookAddin\OutlookEmployeePreferencesAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

final class OutlookPreferences extends Component
{
    #[Locked]
    public int $userId;

    #[Locked]
    public string $instancePrefix;

    #[Locked]
    public string $targetName;

    #[Locked]
    public array $preferences = [];

    #[Locked]
    public string $revision = '';

    #[Locked]
    public bool $available = false;

    #[Locked]
    public string $notice = '';

    #[Locked]
    public array $choiceSelections = [];

    #[Locked]
    public array $templateOptions = [];

    #[Locked]
    public string $choicesRevision = '';

    #[Locked]
    public bool $choicesAvailable = false;

    #[Locked]
    public string $choicesNotice = '';

    public string $greeting = '';

    public string $farewell = '';

    #[Locked]
    public string $wordingRevision = '';

    #[Locked]
    public bool $wordingAvailable = false;

    #[Locked]
    public string $wordingNotice = '';

    public function mount(int $userId, OutlookAddinAutomaticInsertionPolicy $policy): void
    {
        $this->userId = $userId;
        $target = $this->user();
        app(OutlookEmployeePreferencesAccess::class)->resolve($this->actor(), $target);
        $this->instancePrefix = 'employee-outlook-'.(int) Auth::id().'-'.$userId.'-'.$this->getId();
        $this->targetName = (string) $target->name;
        $this->loadPreferences($policy);
        $this->loadTemplateChoices();
        $this->loadWording();
    }

    public function reloadPreferences(OutlookAddinAutomaticInsertionPolicy $policy): void
    {
        $this->resetErrorBag();
        $this->notice = '';
        $this->loadPreferences($policy);
        $this->loadTemplateChoices();
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
            $this->applyPreferences($policy->updateForManagedUser($this->actor(), $this->user(), $group, $field, $enabled, $this->revision));
            $this->notice = 'Outlook-Einstellung gespeichert.';
            $this->loadTemplateChoices();
        } catch (AuthorizationException $error) {
            throw $error;
        } catch (OutlookAddinException $error) {
            if ($error->errorCode === 'outlook_addin_preferences_conflict') {
                $this->loadPreferences($policy);
                $this->loadTemplateChoices();
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

    public function updateTemplateChoice(mixed $group, mixed $selection): void
    {
        $this->resetErrorBag('templateChoices');
        $this->choicesNotice = '';
        if (! is_string($group) || ! is_string($selection)) {
            throw ValidationException::withMessages(['templateChoices' => 'Bitte eine gültige Vorlage auswählen.']);
        }
        $policy = app(OutlookAddinTemplateChoicesPolicy::class);
        try {
            $this->applyTemplateChoices($policy->updateForManagedUser($this->actor(), $this->user(), $group, $selection, $this->choicesRevision));
            $this->loadPreferences(app(OutlookAddinAutomaticInsertionPolicy::class));
            $this->choicesNotice = 'Vorlagenauswahl gespeichert.';
        } catch (ValidationException $error) {
            $this->loadTemplateChoices();
            $this->loadPreferences(app(OutlookAddinAutomaticInsertionPolicy::class));
            throw $error;
        } catch (AuthorizationException $error) {
            throw $error;
        } catch (OutlookAddinException $error) {
            if ($error->errorCode === 'outlook_addin_template_choices_conflict') {
                $this->loadTemplateChoices();
                $this->loadPreferences(app(OutlookAddinAutomaticInsertionPolicy::class));
                $this->choicesNotice = 'Die Auswahl wurde inzwischen geändert. Bitte die gewünschte Vorlage erneut auswählen.';
            } else {
                $this->unavailableTemplateChoices($error->getMessage());
            }
        } catch (Throwable) {
            $this->unavailableTemplateChoices('Die Vorlagenauswahl konnte nicht bestätigt werden. Bitte neu laden.');
        }
    }

    private function loadTemplateChoices(): void
    {
        $this->choicesNotice = '';
        $policy = app(OutlookAddinTemplateChoicesPolicy::class);
        try {
            $this->applyTemplateChoices($policy->preferencesForManagedUser($this->actor(), $this->user()));
        } catch (AuthorizationException $error) {
            throw $error;
        } catch (OutlookAddinException $error) {
            $this->unavailableTemplateChoices($error->getMessage());
        } catch (Throwable) {
            $this->unavailableTemplateChoices('Die Vorlagenauswahl ist derzeit nicht verfügbar.');
        }
    }

    private function applyTemplateChoices(array $choices): void
    {
        $this->choiceSelections = ['newMessages' => $choices['newMessages']['selection'], 'replies' => $choices['replies']['selection']];
        $this->templateOptions = $choices['templates'];
        $this->choicesRevision = $choices['revision'];
        $this->choicesAvailable = true;
    }

    private function unavailableTemplateChoices(string $message): void
    {
        $this->choicesAvailable = false;
        $this->choiceSelections = $this->templateOptions = [];
        $this->choicesRevision = '';
        $this->choicesNotice = $message;
    }

    public function reloadWording(): void
    {
        $this->resetErrorBag(['greeting', 'farewell', 'wording']);
        $this->wordingNotice = '';
        $this->loadWording();
    }

    public function saveWording(): void
    {
        $this->resetErrorBag(['greeting', 'farewell', 'wording']);
        $this->wordingNotice = '';
        try {
            $wording = app(OutlookAddinWordingPolicy::class)->updateForManagedUser($this->actor(), $this->user(), $this->greeting, $this->farewell, $this->wordingRevision);
            $this->applyWording($wording);
            $this->wordingNotice = 'Persönliche Vorlagentexte gespeichert.';
        } catch (ValidationException $error) {
            throw $error;
        } catch (AuthorizationException $error) {
            throw $error;
        } catch (OutlookAddinException $error) {
            if ($error->errorCode === 'outlook_addin_wording_conflict') {
                $this->wordingNotice = 'Die Texte wurden inzwischen geändert. Deine Eingabe bleibt erhalten. Lade zuerst die gespeicherten Texte neu.';
            } else {
                $this->unavailableWording($error->getMessage());
            }
        } catch (Throwable) {
            $this->unavailableWording('Das Speichern der Texte konnte nicht bestätigt werden. Bitte die gespeicherten Texte neu laden.');
        }
    }

    private function loadWording(): void
    {
        $user = $this->user();
        try {
            $this->applyWording(app(OutlookAddinWordingPolicy::class)->readForManagedUser($this->actor(), $user));
        } catch (AuthorizationException $error) {
            throw $error;
        } catch (OutlookAddinException $error) {
            $this->unavailableWording($error->getMessage());
        } catch (Throwable) {
            $this->unavailableWording('Die persönlichen Vorlagentexte sind derzeit nicht verfügbar. Bitte später neu laden.');
        }
    }

    private function applyWording(array $wording): void
    {
        $this->greeting = $wording['greeting'];
        $this->farewell = $wording['farewell'];
        $this->wordingRevision = $wording['revision'];
        $this->wordingAvailable = true;
    }

    private function unavailableWording(string $message): void
    {
        $this->greeting = $this->farewell = $this->wordingRevision = '';
        $this->wordingAvailable = false;
        $this->wordingNotice = $message;
    }

    private function loadPreferences(OutlookAddinAutomaticInsertionPolicy $policy): void
    {
        $user = $this->user();
        try {
            $this->applyPreferences($policy->preferencesForManagedUser($this->actor(), $user));
        } catch (AuthorizationException $error) {
            throw $error;
        } catch (OutlookAddinException $error) {
            $this->unavailable($error->getMessage());
        } catch (Throwable) {
            $this->unavailable('Die Outlook-Einstellungen sind derzeit nicht verfügbar. Bitte später neu laden.');
        }
    }

    private function user(): User
    {
        return User::query()->findOrFail($this->userId);
    }

    private function actor(): User
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
        app(OutlookEmployeePreferencesAccess::class)->resolve($this->actor(), $this->user());

        return view('livewire.admin.user-profile.outlook-preferences');
    }
}
