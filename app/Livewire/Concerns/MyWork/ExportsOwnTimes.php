<?php

namespace App\Livewire\Concerns\MyWork;

use App\Services\Operations\OperationsReportService;
use App\Support\Exports\CsvDownload;
use App\Support\Operations\WorkTimeSchema;

/**
 * CSV-Ausgaben der eigenen Zeiten für den gewählten Zeitraum: v1 (Dienstzeiten) und
 * v2 (Arbeitszeiten mit Kontexten).
 *
 * Erwartet in der Komponente: access() sowie $timeFrom und $timeUntil aus TracksWorkTime.
 */
trait ExportsOwnTimes
{
    abstract private function access(): void;

    public function exportOwnTimes(OperationsReportService $service)
    {
        $this->access();
        $csv = $service->ownTimes(auth()->user(), $this->timeFrom, $this->timeUntil);

        return CsvDownload::stream($csv, 'RailTime-Meine-Zeiten-'.$this->timeFrom.'.csv');
    }

    public function exportOwnWorkTimes(OperationsReportService $service)
    {
        $this->access();
        WorkTimeSchema::requireReady();
        $csv = $service->ownTimesV2(auth()->user(), $this->timeFrom, $this->timeUntil);

        return CsvDownload::stream($csv, 'RailTime-Meine-Arbeitszeiten-v2-'.$this->timeFrom.'.csv');
    }
}
