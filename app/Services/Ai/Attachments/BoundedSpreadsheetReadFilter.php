<?php

namespace App\Services\Ai\Attachments;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

final readonly class BoundedSpreadsheetReadFilter implements IReadFilter
{
    public function __construct(
        private int $maxRows = 2000,
        private int $maxColumns = 100,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row <= $this->maxRows
            && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumns;
    }
}
