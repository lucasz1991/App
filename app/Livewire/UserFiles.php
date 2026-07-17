<?php

namespace App\Livewire;

use Livewire\Component;

class UserFiles extends Component
{
    public function render()
    {
        return view('livewire.user-files')
            ->layout('layouts.master', ['area' => 'user']);
    }
}
