<?php

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Livewire\Component;
use Livewire\WithFileUploads;

class ProfileIdentityCard extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $email = '';

    public $photo;

    public bool $verificationLinkSent = false;

    public function mount(): void
    {
        $this->syncIdentity();
    }

    public function saveIdentity(UpdatesUserProfileInformation $updater): void
    {
        $user = Auth::user();

        if ($this->name === $user->name && $this->email === $user->email) {
            $this->dispatch('identity-saved', fields: ['name', 'email']);
            $this->skipRender();

            return;
        }

        $this->resetErrorBag();
        $updater->update($user, [
            'name' => $this->name,
            'email' => $this->email,
        ]);

        $this->syncIdentity();
        $this->dispatch('identity-saved', fields: ['name', 'email']);
        $this->dispatch('refresh-navigation-menu');
        $this->skipRender();
    }

    public function updatedPhoto(): void
    {
        $this->validate([
            'photo' => ['required', 'image', 'max:10240'],
        ]);

        // Das Foto ist ein eigener Instant-Pfad. Noch nicht gespeicherte
        // Inline-Aenderungen an Name/E-Mail duerfen dadurch nicht vorzeitig
        // mitpersistiert werden.
        Auth::user()->updateProfilePhoto($this->photo);

        $this->reset('photo');
        Auth::user()->refresh();
        $this->dispatch('identity-saved', fields: ['photo']);
        $this->dispatch('refresh-navigation-menu');
    }

    public function deleteProfilePhoto(): void
    {
        Auth::user()->deleteProfilePhoto();
        Auth::user()->refresh();

        $this->dispatch('identity-saved', fields: ['photo']);
        $this->dispatch('refresh-navigation-menu');
    }

    public function sendEmailVerification(): void
    {
        $user = Auth::user()->fresh();

        // Die Verifikation darf nie an die alte persistierte Adresse gehen,
        // waehrend im Inline-Editor bereits eine andere Adresse steht.
        if ($this->email !== $user->email) {
            return;
        }

        $user->sendEmailVerificationNotification();
        $this->verificationLinkSent = true;
    }

    public function getUserProperty()
    {
        return Auth::user();
    }

    public function render()
    {
        return view('livewire.profile.profile-identity-card');
    }

    private function syncIdentity(): void
    {
        $user = Auth::user()->fresh();

        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
    }
}
