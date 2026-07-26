<?php

namespace App\Livewire;

use App\Models\File;
use App\Models\ManagedDocument;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserFiles extends Component
{
    /**
     * Download nur fuer Dateien, die dem Benutzer tatsaechlich bereitstehen
     * (persoenlich, per Team freigegeben oder aus einem Team-Pool).
     */
    public function downloadFile(int $fileId): StreamedResponse
    {
        $file = File::findOrFail($fileId);
        abort_unless(auth()->user()->canAccessFile($file, 'download'), 403);

        return $file->download($file->disk ?: 'private');
    }

    public function render()
    {
        // Kein fester Bereich: Admins/Verwaltung behalten im Download-Center
        // ihre Admin-Sidebar, normale Nutzer die Nutzer-Sidebar.
        return view('livewire.user-files', [
            'grouped' => auth()->user()->availableFilesGrouped(),
            'managedDocuments' => ManagedDocument::query()
                ->visibleTo(auth()->user())
                ->whereHas('currentVersion.file')
                ->with(['currentVersion.file', 'teams'])
                ->orderByDesc('content_updated_at')
                ->get(),
        ])->layout('layouts.master');
    }
}
