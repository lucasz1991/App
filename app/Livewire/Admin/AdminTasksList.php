<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\AdminTask;
use App\Models\ReportBook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class AdminTasksList extends Component
{
    use WithPagination;

    // Filter OHNE strikte Typen, damit Livewire Strings aus Query/Request setzen darf
    public $search         = '';
    public $filterStatus   = AdminTask::STATUS_OPEN; // null = alle, Default setzen wir in mount()
    public $filterPriority = null; // null = alle
    public bool $onlyMine  = false;

    protected $queryString = [
        'search'         => ['except' => ''],
        'filterStatus'   => ['except' => null],
        'filterPriority' => ['except' => null],
        'onlyMine'       => ['except' => false],
        'page'           => ['except' => 1],
    ];

    protected $listeners = [
        'taskCompleted' => '$refresh',
        'taskAssigned'  => '$refresh',
    ];

    public function mount(): void
    {
        // Berechtigung prüfen, damit niemand ohne Rechte die Seite sieht (und damit auch nicht die Query ausführt)
        // Die eigentlichen Aktionen (z.B. Task abschließen) müssen natürlich auch noch mit Policies gesichert werden
        // Aber hiermit verhindern wir schonmal den Zugriff auf die Seite und die Daten für Unberechtigte
        Gate::authorize('jobs.view');
        $this->filterStatus = AdminTask::STATUS_OPEN;
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    public function updatingFilterStatus(): void
    {
        $this->resetPage();
    }

    public function updatingFilterPriority(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedOnlyMine(): void
    {
        $this->resetPage();
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        // Falls Livewire einen String gesetzt hat, hier „weich“ in int wandeln
        $status   = is_numeric($this->filterStatus)   ? (int) $this->filterStatus   : null;
        $priority = is_numeric($this->filterPriority) ? (int) $this->filterPriority : null;
        $search   = trim((string) $this->search);

        $query = AdminTask::with(['creator.person', 'assignedAdmin', 'context'])
            ->where('task_type', 'reportbook_review')
            ->withStatus($status)           // Scope nimmt ?int, PHP castet sauber
            ->withPriority($priority)
            ->orderBy('status')
            ->orderBy('priority')           // Hoch vor niedrig
            ->orderByDesc('created_at');

        if ($search !== '') {
            $like = '%' . $search . '%';

            $query->where(function ($q) use ($like) {
                $q->where('task_type', 'like', $like)
                    ->orWhereRaw(
                        "CASE task_type
                            WHEN 'reportbook_review' THEN 'Berichtsheft pruefen'
                            WHEN 'user_request_review' THEN 'Teilnehmer Antrag'
                            ELSE 'Unbekannte Aufgabe'
                        END LIKE ?",
                        [$like]
                    )
                    ->orWhereHasMorph('context', [ReportBook::class], function ($contextQuery) use ($like) {
                        $contextQuery->where('title', 'like', $like)
                            ->orWhereHas('course', function ($courseQuery) use ($like) {
                                $courseQuery->where('title', 'like', $like)
                                    ->orWhereRaw(
                                        "JSON_UNQUOTE(JSON_EXTRACT(source_snapshot, '$.course.kurzbez')) LIKE ?",
                                        [$like]
                                    )
                                    ->orWhereRaw(
                                        "CONCAT(COALESCE(title, ''), ' - ', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(source_snapshot, '$.course.kurzbez')), '')) LIKE ?",
                                        [$like]
                                    );
                            });
                    })
                    ->orWhereHas('creator', function ($creatorQuery) use ($like) {
                        $creatorQuery->where('name', 'like', $like)
                            ->orWhereHas('person', function ($personQuery) use ($like) {
                                $personQuery->where('vorname', 'like', $like)
                                    ->orWhere('nachname', 'like', $like)
                                    ->orWhereRaw(
                                        "CONCAT(COALESCE(vorname, ''), ' ', COALESCE(nachname, '')) LIKE ?",
                                        [$like]
                                    );
                            });
                    });
            });
        }

        if ($this->onlyMine) {
            $query->forAdmin(Auth::id());
        }

        $tasks     = $query->paginate(20);
        $openCount = AdminTask::open()->where('task_type', 'reportbook_review')->count();

        return view('livewire.admin.admin-tasks-list', [
            'tasks'     => $tasks,
            'openCount' => $openCount,
        ])->layout('layouts.master');
    }
}
