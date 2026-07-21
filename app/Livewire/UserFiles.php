<?php

namespace App\Livewire;

use App\Models\File;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserFiles extends Component
{
    /**
     * Download nur fuer Dateien, die dem Benutzer tatsaechlich bereitstehen
     * (persoenlich, per Rolle/Team freigegeben oder aus einem Team-Pool).
     */
    public function downloadFile(int $fileId): StreamedResponse
    {
        abort_unless(in_array($fileId, auth()->user()->availableFileIds(), true), 403);

        $file = File::findOrFail($fileId);

        return $file->download($file->disk ?: 'private');
    }

    public function render()
    {
        // Kein fester Bereich: Admins/Verwaltung behalten im Download-Center
        // ihre Admin-Sidebar, normale Nutzer die Nutzer-Sidebar.
        return view('livewire.user-files', [
            'grouped' => auth()->user()->availableFilesGrouped(),
        ])->layout('layouts.master');
    }
}
