<?php

namespace App\Livewire\Profile;

use App\Models\UserProfile;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class UpdateContactInformationForm extends Component
{
    public $first_name;
    public $last_name;
    public $phone;
    public $mobile;
    public $street;
    public $postal_code;
    public $city;
    public $country;
    public $birth_date;
    public $birth_place;
    public $birth_name;
    public $nationality;
    public $education;

    public function mount(): void
    {
        $profile = $this->profile();

        $this->first_name = $profile->first_name;
        $this->last_name = $profile->last_name;
        $this->phone = $profile->phone;
        $this->mobile = $profile->mobile;
        $this->street = $profile->street;
        $this->postal_code = $profile->postal_code;
        $this->city = $profile->city;
        $this->country = $profile->country;
        $this->birth_date = $profile->birth_date?->format('Y-m-d');
        $this->birth_place = $profile->birth_place;
        $this->birth_name = $profile->birth_name;
        $this->nationality = $profile->nationality;
        $this->education = $profile->education;
    }

    public function save(): void
    {
        $this->validate([
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'street' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date|before_or_equal:today',
            'birth_place' => 'nullable|string|max:120',
            'birth_name' => 'nullable|string|max:120',
            'nationality' => 'nullable|string|max:120',
            'education' => 'nullable|string|max:255',
        ]);

        $this->profile()->update([
            'first_name' => $this->first_name ?: null,
            'last_name' => $this->last_name ?: null,
            'phone' => $this->phone ?: null,
            'mobile' => $this->mobile ?: null,
            'street' => $this->street ?: null,
            'postal_code' => $this->postal_code ?: null,
            'city' => $this->city ?: null,
            'country' => $this->country ?: null,
            'birth_date' => $this->birth_date ?: null,
            'birth_place' => $this->birth_place ?: null,
            'birth_name' => $this->birth_name ?: null,
            'nationality' => $this->nationality ?: null,
            'education' => $this->education ?: null,
        ]);

        $displayName = trim(implode(' ', array_filter([$this->first_name, $this->last_name])));
        if ($displayName !== '') {
            Auth::user()->forceFill(['name' => $displayName])->save();
        }

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
