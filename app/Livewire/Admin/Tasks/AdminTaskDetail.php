<?php

namespace App\Livewire\Admin\Tasks;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\AdminTask;
use App\Models\Mail;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;

// Falls dein Context wirklich App\Models\ReportBook ist:
use App\Models\ReportBook as ReportBookModel;

class AdminTaskDetail extends Component
{
    public ?int $taskId = null;
    public ?AdminTask $task = null;

    public bool $showDetailModal = false;

    // 'task' = Aufgabendetails, 'context' = Berichtsheft / Antrag
    public string $viewMode = 'task';

    // erhÃ¤lt zusÃ¤tzliche Metadaten beim Ã–ffnen
    public array $payload = [];
    public array $entryApprovals = [];
    public string $rejectionComment = '';

    protected $listeners = [
        'openAdminTaskDetail' => 'open',
    ];

    public function open(int|array $payload): void
    {
        if (is_int($payload)) {
            $taskId = $payload;
            $meta   = [];
        } else {
            $taskId = $payload['taskId'] ?? null;
            $meta   = $payload;
        }

        if (! $taskId) return;

        $this->taskId = $taskId;

        $this->task = AdminTask::with([
            'creator',
            'assignedAdmin',
            'context',
        ])->find($taskId);

        $this->payload = $meta;
        $this->initializeEntryApprovals();
        $this->rejectionComment = '';
        $this->viewMode = 'task';
        $this->showDetailModal = true;
    }

    public function close(): void
    {
        $this->showDetailModal = false;
    }

    public function switchToTask(): void
    {
        $this->viewMode = 'task';
    }

    public function switchToContext(): void
    {
        if ($this->task && $this->task->context) {
            $this->viewMode = 'context';
        }
    }

    public function assignToMe(): void
    {
        if (! $this->taskId) return;

        $task = AdminTask::findOrFail($this->taskId);

        if (! is_null($task->assigned_to)) {
            return;
        }

        $task->assignTo(Auth::id());

        $this->switchToContext();

        $this->task = $task->fresh(['creator', 'assignedAdmin', 'context']);

        $this->dispatch('taskAssigned');

        $this->dispatch('showAlert', [
            'type'  => 'success',
            'title' => 'Ãœbernommen',
            'text'  => 'Aufgabe erfolgreich Ã¼bernommen.',
        ]);
    }

    public function markAsCompleted(): void
    {
        if (! $this->taskId) return;

        $task = AdminTask::findOrFail($this->taskId);

        if ((int) $task->assigned_to !== (int) Auth::id()) {
            return;
        }

        $task->complete();

        $this->task = $task->fresh(['creator', 'assignedAdmin', 'context']);

        $this->dispatch('taskCompleted');

        $this->close();

        $this->dispatch('showAlert', [
            'type'  => 'success',
            'title' => 'Abgeschlossen',
            'text'  => 'Aufgabe erfolgreich abgeschlossen.',
        ]);
    }

    public function releaseTask(): void
    {
        if (! $this->taskId) return;

        $task = AdminTask::findOrFail($this->taskId);

        $isAdmin = (Auth::user()?->role === 'admin');
        $isAssignee = ((int) $task->assigned_to === (int) Auth::id());

        if (! $task->assigned_to) {
            return;
        }

        if ((int) $task->status !== AdminTask::STATUS_IN_PROGRESS) {
            return;
        }

        if (! $isAdmin && ! $isAssignee) {
            return;
        }

        $task->release();

        $this->viewMode = 'task';
        $this->task = $task->fresh(['creator', 'assignedAdmin', 'context']);

        // Existing listener in list uses this event to refresh.
        $this->dispatch('taskAssigned');

        $this->dispatch('showAlert', [
            'type'  => 'success',
            'title' => 'ZurÃ¼ckgegeben',
            'text'  => 'Aufgabe wurde wieder freigegeben.',
        ]);
    }

    /* ============================================================
     *  ReportBook-Kontext: Ausbilder-PrÃ¼fung + Signatur
     * ============================================================ */

    /**
     * Entry-Status, den "geprÃ¼ft" bedeutet.
     * (bei dir: 2)
     */
    protected int $reviewedStatus = 2;
    protected int $rejectedStatus = 3;

    /**
     * File-Type fÃ¼r Ausbilder-Signatur (wie Teilnehmer: sign_reportbook_participant)
     */
    protected string $trainerSignatureType = 'sign_reportbook_trainer';

    /**
     * Startet die Freigabe:
     * - prÃ¼ft Task + Rechte
     * - prÃ¼ft, ob Ausbilder-Signatur existiert
     * - wenn nicht: Ã¶ffnet Signature-Form
     * - wenn ja: fÃ¼hrt Approval aus + Task abschlieÃŸen
     */
    public function approveReportBook(): void
    {
        if (! $this->taskId || ! $this->task) return;

        if ($this->task->task_type !== 'reportbook_review') return;

        if ((int) $this->task->assigned_to !== (int) Auth::id()) return;

        $reportBook = $this->task->context;

        if (! $reportBook) return;

        if ($this->hasRejectedEntries()) {
            $this->dispatch('showAlert', [
                'type'  => 'warning',
                'title' => 'Freigabe nicht mÃ¶glich',
                'text'  => 'Es sind abgelehnte EintrÃ¤ge markiert. Nutze in diesem Fall bitte "Ablehnen".',
            ]);
            return;
        }

        // Signatur vorhanden?
        if (! $this->hasTrainerSignature($reportBook)) {
            $this->openTrainerSignatureForm($reportBook);

            // direkt Kontextansicht zeigen
            $this->viewMode = 'context';
            return;
        }

        // Wenn Signatur schon existiert -> direkt freigeben
        $this->applyReportBookReview($reportBook);

        $this->finalizeApprovedReportBook($reportBook);
    }

    public function rejectReportBook(): void
    {
        if (! $this->taskId || ! $this->task) return;
        if ($this->task->task_type !== 'reportbook_review') return;
        if ((int) $this->task->assigned_to !== (int) Auth::id()) return;

        $reportBook = $this->task->context;

        if (! $reportBook) return;

        if (! $this->hasRejectedEntries()) {
            return;
        }


        $this->applyReportBookReview($reportBook);
        $this->sendReportBookRejectionMessage($reportBook);

        $this->task = $this->task->fresh(['creator', 'assignedAdmin', 'context']);
        $this->viewMode = 'context';
        $this->rejectionComment = '';

        $this->markAsCompleted();
    }

    /**
     * Ã–ffnet das generische Signature-Modal (wie im Teilnehmer-Flow) :contentReference[oaicite:3]{index=3}
     */
    protected function openTrainerSignatureForm($reportBook): void
    {
        // Kontext-Name fÃ¼r den Dialog
        $courseTitle = data_get($reportBook, 'course.title') ?? 'Kurs';
        $klasse      = data_get($reportBook, 'course.klassen_id');

        $contextName = $klasse ? "{$courseTitle} â€“ {$klasse}" : $courseTitle;

        $this->dispatch('openSignatureForm', [
            'fileableType' => ReportBookModel::class,
            'fileableId'   => (int) $reportBook->id,
            'fileType'     => 'sign_reportbook_trainer',
            'label'        => 'Berichtsheft prüfen',
            'signForName'  => 'Berichtsheft (Ausbilder)',
            'contextName'  => $contextName,
            'confirmText'  => "Ich bestätige als <strong>Ausbilder</strong>, dass ich das Berichtsheft<br><strong>({$contextName})</strong><br>geprüft habe und die Angaben vollständig sind.",
        ]);
    }

    /**
     * Wird ausgelöst, wenn das Signature-Modul fertig ist.
     * Danach: Approval + Task abschließen.
     */
    #[On('signatureCompleted')]
    public function handleTrainerSignatureCompleted(array $payload): void
    {
        $fileableType = data_get($payload, 'fileableType');
        $fileType     = data_get($payload, 'fileType');
        $fileableId   = (int) data_get($payload, 'fileableId');

        if (
            $fileableType !== ReportBookModel::class ||
            $fileType !== $this->trainerSignatureType ||
            ! $fileableId
        ) {
            return;
        }

        if (! $this->taskId || ! $this->task) return;

        // sicherstellen: Task passt + assigned user passt
        if ($this->task->task_type !== 'reportbook_review') return;
        if ((int) $this->task->assigned_to !== (int) Auth::id()) return;

        $reportBook = $this->task->context;

        if (! $reportBook || (int) $reportBook->id !== (int) $fileableId) {
            return;
        }

        if ($this->hasRejectedEntries()) {
            $this->dispatch('showAlert', [
                'type'  => 'warning',
                'title' => 'Freigabe nicht möglich',
                'text'  => 'Es sind abgelehnte Einträge markiert. Nutze in diesem Fall bitte "Ablehnen".',
            ]);
            return;
        }

        // Jetzt freigeben
        $this->applyReportBookReview($reportBook);
        $this->finalizeApprovedReportBook($reportBook);
    }

    #[On('signatureAborted')]
    public function handleTrainerSignatureAborted(array $payload = []): void
    {
        // optional: Info/Toast
        return;
    }

    /**
     * Setzt alle EintrÃ¤ge auf reviewedStatus (=2) und optional den Gesamtstatus.
     */
    protected function applyReportBookReview($reportBook): void
    {
        $entryIds = $reportBook->entries()->pluck('id')->map(fn ($id) => (string) $id);

        $approvedEntryIds = [];
        $rejectedEntryIds = [];

        foreach ($entryIds as $entryId) {
            $isApproved = filter_var(
                $this->entryApprovals[$entryId] ?? true,
                FILTER_VALIDATE_BOOLEAN
            );

            if ($isApproved) {
                $approvedEntryIds[] = (int) $entryId;
                continue;
            }

            $rejectedEntryIds[] = (int) $entryId;
        }

        if (! empty($approvedEntryIds)) {
            $reportBook->entries()
                ->whereIn('id', $approvedEntryIds)
                ->update(['status' => $this->reviewedStatus]);
        }

        if (! empty($rejectedEntryIds)) {
            $reportBook->entries()
                ->whereIn('id', $rejectedEntryIds)
                ->update(['status' => $this->rejectedStatus]);
        }

    }

    protected function finalizeApprovedReportBook($reportBook): void
    {
        $this->sendApprovalMessage($reportBook);

        $this->task = $this->task?->fresh(['creator', 'assignedAdmin', 'context']);
        $this->viewMode = 'context';
        $this->markAsCompleted();
    }


    protected function initializeEntryApprovals(): void
    {
        $this->entryApprovals = [];

        if (! $this->task) {
            return;
        }

        if ($this->task->task_type !== 'reportbook_review' || ! $this->task->context) {
            return;
        }

        $entryIds = $this->task->context->entries()->pluck('id');

        foreach ($entryIds as $entryId) {
            $this->entryApprovals[(string) $entryId] = true;
        }
    }

    protected function hasRejectedEntries(): bool
    {
        foreach ($this->entryApprovals as $isApproved) {
            $approved = filter_var($isApproved, FILTER_VALIDATE_BOOLEAN);

            if (! $approved) {
                return true;
            }
        }

        return false;
    }

    protected function sendReportBookRejectionMessage($reportBook): void
    {
        $recipientId = (int) data_get($reportBook, 'user_id');

        if (! $recipientId) {
            return;
        }

        $courseTitle = data_get($reportBook, 'course.title') ?? 'dein Berichtsheft';
        $courseId = (int) data_get($reportBook, 'course_id');
        $baseUrl = rtrim((string) (Setting::getValue('api', 'base_api_url') ?: config('app.url')), '/');
        $rejectedEntryIds = $this->getRejectedEntryIds();

        $rejectedEntries = $reportBook->entries()
            ->whereIn('id', $rejectedEntryIds)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get(['entry_date', 'created_at', 'course_day_id']);

        $entryLines = [];
        foreach ($rejectedEntries as $entry) {
            $dateValue = $entry->entry_date ?? $entry->created_at;
            $dateText = $dateValue ? $dateValue->format('d.m.Y') : '-';
            $dayId = (int) ($entry->course_day_id ?? 0);

            if ($courseId > 0 && $dayId > 0) {
                $entryLines[] = "- {$dateText}: <a href='{$baseUrl}/user/reportbook?course={$courseId}&day={$dayId}' target='_blank'>Berichtsheft ansehen</a><br>";
                continue;
            }

            if ($courseId > 0) {
                $entryLines[] = "- {$dateText}: <a href='{$baseUrl}/user/reportbook?course={$courseId}' target='_blank'>Berichtsheft ansehen</a>";
                continue;
            }

            $entryLines[] = "- {$dateText}";
        }

        $entriesText = empty($entryLines)
            ? "- Keine konkreten Einträge gefunden."
            : implode("\n", $entryLines);

        $subject = 'Berichtsheft abgelehnt';
        $body = "Dein Berichtsheft für den Baustein ({$courseTitle}) wurde abgelehnt und muss neu eingereicht werden.<br><br>"
            . "Abgelehnte Einträge:<br>"
            . "{$entriesText}<br><br>"
            . "Begründung:<br>"
            . trim($this->rejectionComment);

        Mail::create([
            'type' => 'both',
            'status' => false,
            'content' => [
                'subject' => $subject,
                'header'  => 'Berichtsheft abgelehnt',
                'body'    => $body,
                'link'    => '',
            ],
            'recipients' => [[
                'user_id' => $recipientId,
                'email'   => (string) data_get($reportBook, 'user.email', ''),
                'status'  => false,
            ]],
        ]);
    }

    public function sendApprovalMessage($reportBook): void
    {
        $recipientId = (int) data_get($reportBook, 'user_id');

        if (! $recipientId) {
            return;
        }

        $courseTitle = data_get($reportBook, 'course.title') ?? 'dein Berichtsheft';
        $baseUrl = rtrim((string) (Setting::getValue('api', 'base_api_url') ?: config('app.url')), '/');
        $courseId = (int) data_get($reportBook, 'course_id');

        $subject = 'Berichtsheft freigegeben';
        $body = "Dein Berichtsheft für den Baustein ({$courseTitle}) wurde freigegeben. Gute Arbeit!";
        $link = "{$baseUrl}/user/reportbook?course={$courseId}"; 

        Mail::create([
            'type' => 'both',
            'status' => false,
            'content' => [
                'subject' => $subject,
                'header'  => 'Berichtsheft freigegeben',
                'body'    => $body,
                'link'    => $link,
            ],
            'recipients' => [[
                'user_id' => $recipientId,
                'email'   => (string) data_get($reportBook, 'user.email', ''),
                'status'  => false,
            ]],
        ]);
    }

    protected function getRejectedEntryIds(): array
    {
        $ids = [];

        foreach ($this->entryApprovals as $entryId => $isApproved) {
            $approved = filter_var($isApproved, FILTER_VALIDATE_BOOLEAN);

            if (! $approved) {
                $ids[] = (int) $entryId;
            }
        }

        return $ids;
    }

    /**
     * PrÃ¼ft, ob Ausbilder-Signatur vorhanden ist
     */
    protected function hasTrainerSignature($reportBook): bool
    {
        if (method_exists($reportBook, 'trainerSignatureFile')) {
            return (bool) $reportBook->trainerSignatureFile();
        }

        if (method_exists($reportBook, 'files')) {
            return $reportBook->files()
                ->where('type', $this->trainerSignatureType)
                ->exists();
        }

        return false;
    }

    public function render()
    {
        return view('livewire.admin.tasks.admin-task-detail');
    }
}
