<?php

namespace App\Livewire;

use App\Models\File;
use App\Models\ManagedDocument;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserFiles extends Component
{
    /**
     * Freitextsuche ueber alle bereitgestellten Dateien und Dokumente.
     *
     * Steht als ?q=... in der Adresszeile, damit eine Suche verlinkbar und
     * nach dem Neuladen noch da ist.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

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

    public function clearSearch(): void
    {
        $this->search = '';
    }

    /**
     * Suchbegriff auf Dateinamen anwenden.
     *
     * Bewusst clientfern in PHP: availableFilesGrouped() prueft die
     * Sichtbarkeit jeder einzelnen Datei bereits im Modell. Eine eigene
     * Datenbankabfrage wuerde diese Pruefung umgehen muessen.
     */
    protected function filterFiles(Collection $files, string $needle): Collection
    {
        if ($needle === '') {
            return $files;
        }

        return $files
            ->filter(fn (File $file): bool => str_contains(mb_strtolower((string) $file->name), $needle))
            ->values();
    }

    public function render()
    {
        $needle = mb_strtolower(trim($this->search));
        $grouped = auth()->user()->availableFilesGrouped();

        $grouped['personal'] = $this->filterFiles($grouped['personal'], $needle);
        $grouped['company'] = $this->filterFiles($grouped['company'], $needle);
        $grouped['teams'] = collect($grouped['teams'])
            ->map(fn (array $entry): array => [
                'team' => $entry['team'],
                'files' => $this->filterFiles($entry['files'], $needle),
            ])
            // Teams ohne Treffer verschwinden, damit die Trefferliste nicht
            // aus leeren Ueberschriften besteht.
            ->filter(fn (array $entry): bool => $entry['files']->isNotEmpty())
            ->values()
            ->all();

        $managedDocuments = ManagedDocument::query()
            ->visibleTo(auth()->user())
            ->whereHas('currentVersion.file')
            ->with(['currentVersion.file', 'teams'])
            ->orderByDesc('content_updated_at')
            ->get()
            ->filter(fn (ManagedDocument $document): bool => $needle === ''
                || str_contains(mb_strtolower((string) $document->title), $needle)
                || str_contains(mb_strtolower((string) $document->description), $needle))
            ->values();

        // Kein fester Bereich: Admins/Verwaltung behalten im Download-Center
        // ihre Admin-Sidebar, normale Nutzer die Nutzer-Sidebar.
        return view('livewire.user-files', [
            'grouped' => $grouped,
            'managedDocuments' => $managedDocuments,
            'isFiltered' => $needle !== '',
        ])->layout('layouts.master');
    }
}
