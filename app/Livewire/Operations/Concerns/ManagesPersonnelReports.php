<?php

namespace App\Livewire\Operations\Concerns;

use App\Models\PersonnelSavedReport;
use App\Services\Operations\PersonnelEnhancementService;
use App\Support\Exports\CsvDownload;

/**
 * PersonnelEnhancements – Fachbereich Berichte: gespeicherte Personalberichte
 * ausführen, als CSV laden und aktivieren/deaktivieren.
 */
trait ManagesPersonnelReports
{
    public function runReport(int $id, int $revision): void
    {
        $this->access();
        abort_if($this->profileUserId !== null, 403);
        abort_if($this->personal, 403);
        $this->display = app(PersonnelEnhancementService::class)->runReport(PersonnelSavedReport::findOrFail($id), $revision, auth()->user());
        $this->formKind = 'report_result';
        $this->formOpen = true;
    }

    public function downloadReport(int $id)
    {
        $this->access();
        abort_if($this->profileUserId !== null, 403);
        abort_if($this->personal, 403);
        $results = app(PersonnelEnhancementService::class)->authorizedReportResult(PersonnelSavedReport::findOrFail($id), auth()->user());
        abort_unless($results !== [], 404);
        $result = end($results);

        $rows = [];
        foreach ($result['rows'] as $row) {
            $name = preg_match('/^[=+\-@\t\r]/', $row['name']) ? "'".$row['name'] : $row['name'];
            $rows[] = [$result['from'], $result['until'], $row['user_id'], $name, $row['target_minutes'], $row['actual_minutes'], $row['balance_minutes'], $row['missing_days'], $row['pending_absences']];
        }
        $csv = CsvDownload::fromRows(['Zeitraum_von', 'Zeitraum_bis', 'Mitarbeiter_ID', 'Name', 'Soll_Minuten', 'Ist_Minuten', 'Saldo_Minuten', 'Fehlende_Grundlagen', 'Offene_Abwesenheiten'], $rows, ';', '"', '');

        return CsvDownload::stream($csv, 'Personalbericht.csv');
    }

    public function reportState(int $id, int $revision, bool $active): void
    {
        $this->access();
        abort_if($this->profileUserId !== null, 403);
        abort_if($this->personal, 403);
        app(PersonnelEnhancementService::class)->reportState(PersonnelSavedReport::findOrFail($id), $revision, $active, auth()->user());
    }
}
