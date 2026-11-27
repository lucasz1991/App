<?php

namespace App\Livewire\Concerns;

use App\Support\FileExplorer;
use Livewire\Attributes\Url;

trait InteractsWithFileExplorer
{
    #[Url(as: 'layout', except: 'grid')]
    public string $layout = 'grid';

    public string $fileType = '';

    public string $sortBy = 'name';

    public string $sortDirection = 'asc';

    public function setExplorerLayout(string $layout): void
    {
        $this->layout = in_array($layout, FileExplorer::LAYOUTS, true) ? $layout : 'grid';
    }

    public function sortExplorer(string $column): void
    {
        if (! in_array($column, FileExplorer::SORT_COLUMNS, true)) {
            return;
        }

        $this->sortDirection = $this->sortBy === $column && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortBy = $column;
    }

    protected function normalizeExplorerOptions(): void
    {
        $this->layout = in_array($this->layout, FileExplorer::LAYOUTS, true) ? $this->layout : 'grid';
        $this->fileType = FileExplorer::normalizeType($this->fileType);
        $this->sortBy = in_array($this->sortBy, FileExplorer::SORT_COLUMNS, true) ? $this->sortBy : 'name';
        $this->sortDirection = $this->sortDirection === 'desc' ? 'desc' : 'asc';
    }
}
