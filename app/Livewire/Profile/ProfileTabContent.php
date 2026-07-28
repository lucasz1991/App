<?php

namespace App\Livewire\Profile;

use Illuminate\Validation\Rule;
use Livewire\Component;

class ProfileTabContent extends Component
{
    public string $tab;

    public bool $ready = false;

    public function mount(string $tab): void
    {
        validator(
            ['tab' => $tab],
            ['tab' => ['required', Rule::in(['personal', 'security', 'app', 'sessions'])]],
        )->validate();

        $this->tab = $tab;
    }

    public function load(): void
    {
        if ($this->ready) {
            return;
        }

        $this->ready = true;
    }

    public function render()
    {
        return view('livewire.profile.profile-tab-content');
    }
}
