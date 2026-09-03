<?php

namespace App\Livewire\Admin\Marketing;

use App\Enums\MarketingCreativeType;
use App\Models\MarketingCreative;
use App\Models\User;
use App\Services\Marketing\MarketingMotiveService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

class CreativesIndex extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    public bool $createMotiveOpen = false;

    public string $motiveTitle = '';

    public string $motiveType = 'job';

    /** @var array<int, mixed> */
    public array $motiveUploads = [];

    #[Locked]
    public bool $createDraftReady = false;

    public function mount(): void
    {
        $this->admin();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function openCreateMotive(): void
    {
        $this->admin();
        $this->resetCreateDraft();

        $this->createDraftReady = true;
        $this->createMotiveOpen = true;
    }

    public function cancelCreateMotive(): void
    {
        $this->admin();
        $this->resetCreateDraft();
    }

    /**
     * Sicherheitsnetz fuer Schliessen ueber Kopf-X, Escape oder Backdrop.
     * Alle Schliesswege brechen auch einen laufenden temporaeren Upload ab.
     */
    public function updatedCreateMotiveOpen(bool $open): void
    {
        $this->admin();

        if (! $open && $this->createDraftReady) {
            $this->resetCreateDraft();
        }
    }

    public function createMotive(MarketingMotiveService $motives): void
    {
        $actor = $this->admin();
        abort_unless($this->createDraftReady && $this->createMotiveOpen, 403);

        $this->motiveTitle = trim($this->motiveTitle);

        $validated = $this->validate([
            'motiveTitle' => ['required', 'string', 'max:160'],
            'motiveType' => ['required', Rule::enum(MarketingCreativeType::class)],
            'motiveUploads' => ['required', 'array', 'min:1', 'max:20'],
            'motiveUploads.*' => ['file', 'max:51200'],
        ], [
            'motiveTitle.required' => 'Bitte gib dem Motiv einen Namen.',
            'motiveTitle.max' => 'Der Name darf maximal 160 Zeichen lang sein.',
            'motiveType.required' => 'Bitte wähle einen Motivtyp aus.',
            'motiveType.enum' => 'Der gewählte Motivtyp ist nicht verfügbar.',
            'motiveUploads.required' => 'Bitte füge mindestens eine Datei hinzu.',
            'motiveUploads.min' => 'Bitte füge mindestens eine Datei hinzu.',
            'motiveUploads.max' => 'Pro Motiv können höchstens 20 Dateien hochgeladen werden.',
            'motiveUploads.*.file' => 'Eine ausgewählte Datei ist ungültig.',
            'motiveUploads.*.max' => 'Jede Datei darf höchstens 50 MB groß sein.',
        ]);

        try {
            $creative = $motives->create(
                $validated['motiveTitle'],
                MarketingCreativeType::from($validated['motiveType']),
                $actor,
                $validated['motiveUploads'],
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError(
                'motiveUploads',
                'Das Motiv konnte nicht vollständig gespeichert werden. Bitte prüfe die Dateien und versuche es erneut.',
            );

            return;
        }

        $this->resetCreateDraft(notifyClient: false);
        $this->dispatch('filepool:saved', model: 'motiveUploads');

        $this->redirectRoute(
            'admin.marketing.creatives.files',
            ['creative' => $creative],
            navigate: true,
        );
    }

    public function deleteMotive(string $creativeId, MarketingMotiveService $motives): void
    {
        $actor = $this->admin();
        $creative = $this->creative($creativeId);
        $title = $creative->title;

        $motives->delete($creative, $actor);
        $this->resetPage();

        $this->dispatch(
            'swal:toast',
            type: 'success',
            text: sprintf('„%s“ wurde entfernt.', $title),
        );
    }

    public function render()
    {
        $this->admin();

        $search = trim($this->search);
        $selectedType = MarketingCreativeType::tryFrom($this->type);

        $creatives = MarketingCreative::query()
            ->with([
                'filePool' => fn ($poolQuery) => $poolQuery
                    ->withCount('files')
                    ->with('latestFile'),
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where('title', 'like', '%'.$search.'%');
            })
            ->when($selectedType, function (Builder $query, MarketingCreativeType $type): void {
                $query->where('type', $type->value);
            })
            ->latest('updated_at')
            ->paginate(12);

        return view('livewire.admin.marketing.creatives-index', [
            'creatives' => $creatives,
        ])->layout('layouts.master', ['area' => 'admin']);
    }

    private function creative(string $creativeId): MarketingCreative
    {
        $this->admin();

        return MarketingCreative::query()
            ->where('public_id', $creativeId)
            ->firstOrFail();
    }

    private function resetCreateDraft(bool $notifyClient = true): void
    {
        $hadActiveDraft = $this->createDraftReady
            || $this->createMotiveOpen
            || $this->motiveUploads !== [];

        // Zuerst die Sperre entfernen, damit der entangle-Hook idempotent bleibt.
        $this->createDraftReady = false;
        $this->createMotiveOpen = false;
        $this->motiveTitle = '';
        $this->motiveType = 'job';
        $this->motiveUploads = [];
        $this->resetValidation();

        if ($notifyClient && $hadActiveDraft) {
            $this->dispatch('filepool:cancelled', model: 'motiveUploads');
        }
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user?->isAdmin(), 403);

        return $user;
    }
}
