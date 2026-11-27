<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithFileExplorer;
use App\Models\File;
use App\Models\ManagedDocument;
use App\Support\FileExplorer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserFiles extends Component
{
    use InteractsWithFileExplorer;

    #[Locked]
    public bool $embedded = false;

    #[Locked]
    public array $scopeLinks = [];

    #[Locked]
    #[Url(as: 'scope', except: 'all')]
    public string $scope = 'all';

    /**
     * Freitextsuche ueber alle bereitgestellten Dateien und Dokumente.
     *
     * Steht als ?q=... in der Adresszeile, damit eine Suche verlinkbar und
     * nach dem Neuladen noch da ist.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(bool $embedded = false, array $scopeLinks = []): void
    {
        $this->embedded = $embedded;
        $this->scopeLinks = $scopeLinks;

        if ($embedded) {
            $this->scope = 'personal';
        }
    }

    public function setExplorerScope(string $scope): void
    {
        if ($this->embedded || $scope === $this->scope || ! in_array($scope, ['all', 'required', 'personal', 'shared'], true)) {
            return;
        }

        $this->scope = $scope;
        $this->clearExplorerFilters();
    }

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

    public function clearExplorerFilters(): void
    {
        $this->clearSearch();
        $this->fileType = '';
    }

    public function render()
    {
        $this->normalizeExplorerOptions();
        $user = auth()->user();
        $scope = match (true) {
            $this->embedded => 'personal',
            in_array($this->scope, ['required', 'personal', 'shared'], true) => $this->scope,
            default => 'all',
        };
        $available = $scope === 'required' ? [] : $user->availableFilesGrouped();

        // Every displayed group and type is drawn from the existing authorized collections.
        $personal = in_array($scope, ['all', 'personal'], true) ? $available['personal'] : collect();
        $company = in_array($scope, ['all', 'shared'], true) ? $available['company'] : collect();
        $teams = collect(in_array($scope, ['all', 'shared'], true) ? $available['teams'] : []);
        $documents = in_array($scope, ['all', 'required'], true)
            ? ManagedDocument::query()
                ->visibleTo($user)
                ->whereHas('currentVersion.file')
                ->with('currentVersion.file')
                ->get()
                ->filter(fn (ManagedDocument $document): bool => $document->currentVersion?->file !== null)
            : collect();

        $fileTypes = FileExplorer::fileTypes($personal
            ->concat($company)
            ->concat($teams->flatMap(fn (array $entry) => $entry['files']))
            ->concat($documents->map(fn (ManagedDocument $document): File => $document->currentVersion->file)), current: $this->fileType);

        $filterAndSort = fn (Collection $files): Collection => FileExplorer::sortFiles(
            FileExplorer::filterFiles($files, $this->search, $this->fileType),
            $this->sortBy,
            $this->sortDirection,
        );
        $personal = $filterAndSort($personal);
        $company = $filterAndSort($company);
        $teams = $teams
            ->map(fn (array $entry): array => ['team' => $entry['team'], 'files' => $filterAndSort($entry['files'])])
            ->filter(fn (array $entry): bool => $entry['files']->isNotEmpty())
            ->values();
        $managedDocuments = FileExplorer::sortDocuments(
            FileExplorer::filterDocuments($documents, $this->search, $this->fileType),
            $this->sortBy,
            $this->sortDirection,
        );

        return view('livewire.user-files', [
            'currentScope' => $scope,
            'fileTypes' => $fileTypes,
            'personal' => $personal,
            'company' => $company,
            'teams' => $teams,
            'managedDocuments' => $managedDocuments,
            'total' => $personal->count() + $company->count() + $teams->sum(fn (array $entry): int => $entry['files']->count()) + $managedDocuments->count(),
            'isFiltered' => trim($this->search) !== '' || $this->fileType !== '',
        ])->layout('layouts.master');
    }
}
