<?php

namespace App\Livewire\Admin\Charts;

use Livewire\Component;
use Carbon\Carbon;
use Spatie\Activitylog\Models\Activity;

class ActiveUsers extends Component
{
    public $data = [];       // Y-Werte für aktive Nutzer
    public $timestamps = []; // X-Werte (Zeitstempel)
    public string $chartId = 'activeUsersChart';
    public $height;

    public function mount($height)
    {
        $this->height = $height;
        $this->chartId = 'activeUsersChart-' . uniqid(); // Eindeutige ID für den Chart
        $this->initializeChartData();
    }

    public function initializeChartData()
    {
        // Initialisiere die letzten 10 Stunden in Stundenintervallen
        $baseTimestamp = Carbon::now()->subHours(9)->startOfHour(); // Beginn vor 10 Stunden
        for ($i = 0; $i < 10; $i++) {
            $timestamp = $baseTimestamp->copy()->addHours($i); // Stunden-Schritte
            $this->timestamps[] = $timestamp->timestamp * 1000; // In Millisekunden für das Chart
            $this->data[] = $this->getActiveUsersCount($timestamp);
        }
    }

    private function getActiveUsersCount(Carbon $timestamp)
    {
        // Zähle Aktivitäten innerhalb der Stunde des Timestamps
        $start = $timestamp->copy();
        $end = $timestamp->copy()->endOfHour();

        return Activity::whereBetween('created_at', [$start, $end])
            ->distinct('causer_id') // Zählt nur eindeutige Nutzer
            ->count('causer_id');
    }

    public function render()
    {
        return view('livewire.admin.charts.active-users', [
            'data' => $this->data,
            'timestamps' => $this->timestamps,
            'chartId' => $this->chartId,
            'height' => $this->height,
        ]);
    }
}
