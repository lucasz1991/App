<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\ShelfRental;

class Checkins extends Component
{
    use WithPagination;

    public function render()
    {
        // Lade bevorstehende Check-Ins mit Pagination
        $checkIns = ShelfRental::where('rental_start', '>=', now())
            ->orderBy('rental_start', 'asc')
            ->paginate(10); // 10 Einträge pro Seite

        return view('livewire.admin.checkins', [
            'checkIns' => $checkIns,
        ])->layout('layouts.master');
    }
}


