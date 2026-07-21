<?php

namespace App\Livewire\Profile;

use App\Models\UserProfile;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class UpdateContactInformationForm extends Component
{
    public $position;
    public $phone;
    public $mobile;
    public $street;
    public $postal_code;
    public $city;
    public $country;
    public $birth_date;

    public function mount(): void
    {
        $profile = $this->profile();

        $this->position = $profile->position;
        $this->phone = $profile->phone;
        $this->mobile = $profile->mobile;
        $this->street = $profile->street;
        $this->postal_code = $profile->postal_code;
        $this->city = $profile->city;
        $this->country = $profile->country;
        $this->birth_date = $profile->birth_date?->format('Y-m-d');
    }

    public function save(): void
    {
        $this->validate([
            'position' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'street' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date|before_or_equal:today',
        ]);

        $this->profile()->update([
            'position' => $this->position ?: null,
            'phone' => $this->phone ?: null,
            'mobile' => $this->mobile ?: null,
            'street' => $this->street ?: null,
            'postal_code' => $this->postal_code ?: null,
            'city' => $this->city ?: null,
            'country' => $this->country ?: null,
            'birth_date' => $this->birth_date ?: null,
        ]);

        $this->dispatch('saved');
    }

    /**
     * 1:1-Profil des angemeldeten Benutzers (wird bei Bedarf angelegt).
     * Bewusst ueber das Model statt ueber eine User-Relation geloest,
     * damit die Komponente auch ohne User::profile()-Relation funktioniert.
     */
    protected function profile(): UserProfile
    {
        return UserProfile::firstOrCreate(['user_id' => Auth::id()]);
    }

    public function render()
    {
        return view('livewire.profile.update-contact-information-form');
    }
}
