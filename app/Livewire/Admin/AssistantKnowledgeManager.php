<?php

namespace App\Livewire\Admin;

use App\Models\AssistantKnowledgeEntry;
use App\Models\AssistantKnowledgeTopic;
use App\Services\Ai\AssistantKnowledgePool;
use App\Support\Ai\AssistantKnowledgeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class AssistantKnowledgeManager extends Component
{
    use WithPagination;

    public string $knowledgeIntro = '';

    public string $search = '';

    public string $topicFilter = 'all';

    public bool $topicEditorOpen = false;

    public ?int $editingTopicId = null;

    public string $topicName = '';

    public string $topicDescription = '';

    public bool $topicActive = true;

    public bool $entryEditorOpen = false;

    public ?int $editingEntryId = null;

    public ?int $entryTopicId = null;

    public string $entryTitle = '';

    public string $entrySummary = '';

    public string $entryContent = '';

    public string $entryKeywords = '';

    public bool $entryActive = true;

    public bool $entryBaseline = false;

    public bool $entryOriginallyBaseline = false;

    public function mount(): void
    {
        $this->authorizeSuperAdmin();
        $this->knowledgeIntro = AssistantKnowledgeSettings::intro(uncached: true);
    }

    public function hydrate(): void
    {
        $this->authorizeSuperAdmin();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTopicFilter(): void
    {
        $this->resetPage();
    }

    public function saveIntro(): void
    {
        $this->authorizeSuperAdmin();
        $this->knowledgeIntro = trim($this->knowledgeIntro);
        $this->validate([
            'knowledgeIntro' => ['required', 'string', 'max:1200'],
        ]);

        AssistantKnowledgeSettings::setIntro($this->knowledgeIntro);
        $this->knowledgeIntro = AssistantKnowledgeSettings::intro(uncached: true);
        $this->dispatch(
            'knowledge-pool-saved',
            fields: ['knowledgeIntro'],
            message: $this->localized('Basistext gespeichert.', 'Baseline text saved.'),
        );
    }

    public function createTopic(): void
    {
        $this->authorizeSuperAdmin();
        $this->resetTopicForm();
        $this->topicEditorOpen = true;
    }

    public function editTopic(int $topicId): void
    {
        $this->authorizeSuperAdmin();
        $topic = AssistantKnowledgeTopic::query()->findOrFail($topicId);

        $this->editingTopicId = (int) $topic->getKey();
        $this->topicName = $topic->name;
        $this->topicDescription = (string) $topic->description;
        $this->topicActive = $topic->is_active;
        $this->topicEditorOpen = true;
        $this->resetValidation();
    }

    public function saveTopic(): void
    {
        $this->authorizeSuperAdmin();
        $this->topicName = trim($this->topicName);
        $this->topicDescription = trim($this->topicDescription);
        $wasEditing = $this->editingTopicId !== null;
        $validated = $this->validate([
            'topicName' => [
                'required',
                'string',
                'min:2',
                'max:120',
                Rule::unique('assistant_knowledge_topics', 'name')
                    ->ignore($this->editingTopicId)
                    ->whereNull('deleted_at'),
            ],
            'topicDescription' => ['nullable', 'string', 'max:500'],
            'topicActive' => ['boolean'],
        ]);

        $topic = $this->editingTopicId
            ? AssistantKnowledgeTopic::query()->findOrFail($this->editingTopicId)
            : new AssistantKnowledgeTopic([
                'sort_order' => ((int) AssistantKnowledgeTopic::query()->max('sort_order')) + 10,
            ]);

        $topic->fill([
            'name' => trim($validated['topicName']),
            'description' => trim((string) $validated['topicDescription']) ?: null,
            'is_active' => (bool) $validated['topicActive'],
        ])->save();

        $this->topicEditorOpen = false;
        $this->search = '';
        $this->topicFilter = (string) $topic->getKey();
        $this->resetPage();
        $this->resetTopicForm();
        $this->dispatch(
            'knowledge-pool-saved',
            fields: ['topic'],
            message: $wasEditing
                ? $this->localized('Thema aktualisiert.', 'Topic updated.')
                : $this->localized('Thema angelegt.', 'Topic created.'),
        );
    }

    public function deleteTopic(int $topicId): void
    {
        $this->authorizeSuperAdmin();
        $topic = AssistantKnowledgeTopic::query()->findOrFail($topicId);

        DB::transaction(function () use ($topic): void {
            $topic->entries()->eachById(static fn (AssistantKnowledgeEntry $entry) => $entry->delete());
            $topic->delete();
        });
        app(AssistantKnowledgePool::class)->forgetCache();

        if ($this->topicFilter === (string) $topicId) {
            $this->topicFilter = 'all';
        }

        $this->resetPage();
        $this->dispatch(
            'knowledge-pool-saved',
            fields: ['topic'],
            message: $this->localized('Thema und enthaltene Informationen entfernt.', 'Topic and contained information removed.'),
        );
    }

    public function createEntry(?int $topicId = null): void
    {
        $this->authorizeSuperAdmin();
        $this->resetEntryForm();

        $preferredTopic = $topicId
            ?: (ctype_digit($this->topicFilter) ? (int) $this->topicFilter : null);
        $this->entryTopicId = $preferredTopic
            ?: AssistantKnowledgeTopic::query()->active()->orderBy('sort_order')->value('id')
            ?: AssistantKnowledgeTopic::query()->orderBy('sort_order')->value('id');
        $this->entryEditorOpen = true;
    }

    public function editEntry(int $entryId): void
    {
        $this->authorizeSuperAdmin();
        $entry = AssistantKnowledgeEntry::query()->findOrFail($entryId);

        $this->editingEntryId = (int) $entry->getKey();
        $this->entryTopicId = (int) $entry->topic_id;
        $this->entryTitle = $entry->title;
        $this->entrySummary = (string) $entry->summary;
        $this->entryContent = $entry->content;
        $this->entryKeywords = implode(', ', (array) $entry->keywords);
        $this->entryActive = $entry->is_active;
        $this->entryBaseline = $entry->include_in_baseline;
        $this->entryOriginallyBaseline = $entry->include_in_baseline;
        $this->entryEditorOpen = true;
        $this->resetValidation();
    }

    public function saveEntry(): void
    {
        $this->authorizeSuperAdmin();
        $this->entryTitle = trim($this->entryTitle);
        $this->entrySummary = trim($this->entrySummary);
        $this->entryContent = trim($this->entryContent);
        $this->entryKeywords = trim($this->entryKeywords);
        $wasEditing = $this->editingEntryId !== null;
        $validated = $this->validate([
            'entryTopicId' => [
                'required',
                'integer',
                Rule::exists('assistant_knowledge_topics', 'id')->whereNull('deleted_at'),
            ],
            'entryTitle' => ['required', 'string', 'min:2', 'max:180'],
            'entrySummary' => [Rule::requiredIf($this->entryBaseline), 'nullable', 'string', 'max:1000'],
            'entryContent' => ['required', 'string', 'min:3', 'max:50000'],
            'entryKeywords' => ['nullable', 'string', 'max:1000'],
            'entryActive' => ['boolean'],
            'entryBaseline' => [
                'boolean',
                function (string $attribute, mixed $value, $fail): void {
                    if (! (bool) $value) {
                        return;
                    }

                    $assigned = AssistantKnowledgeEntry::query()
                        ->where('include_in_baseline', true)
                        ->when(
                            $this->editingEntryId !== null,
                            fn ($query) => $query->whereKeyNot($this->editingEntryId),
                        )
                        ->count();

                    if ($assigned >= AssistantKnowledgePool::BASELINE_ENTRY_LIMIT) {
                        $fail($this->localized(
                            'Es können höchstens '.AssistantKnowledgePool::BASELINE_ENTRY_LIMIT.' Kurzinfos als Basiswissen markiert werden.',
                            'At most '.AssistantKnowledgePool::BASELINE_ENTRY_LIMIT.' summaries can be marked as baseline knowledge.',
                        ));
                    }
                },
            ],
        ]);

        $entry = $this->editingEntryId
            ? AssistantKnowledgeEntry::query()->findOrFail($this->editingEntryId)
            : new AssistantKnowledgeEntry([
                'sort_order' => ((int) AssistantKnowledgeEntry::query()
                    ->where('topic_id', $validated['entryTopicId'])
                    ->max('sort_order')) + 10,
            ]);

        $entry->fill([
            'topic_id' => (int) $validated['entryTopicId'],
            'title' => trim($validated['entryTitle']),
            'summary' => trim((string) $validated['entrySummary']) ?: null,
            'content' => trim($validated['entryContent']),
            'keywords' => $this->normalizedKeywords($validated['entryKeywords'] ?? ''),
            'is_active' => (bool) $validated['entryActive'],
            'include_in_baseline' => (bool) $validated['entryBaseline'],
        ])->save();

        $this->entryEditorOpen = false;
        $this->search = '';
        $this->topicFilter = (string) $entry->topic_id;
        $this->resetPage();
        $this->resetEntryForm();
        $this->dispatch(
            'knowledge-pool-saved',
            fields: ['entry'],
            message: $wasEditing
                ? $this->localized('Information aktualisiert.', 'Information updated.')
                : $this->localized('Information angelegt.', 'Information created.'),
        );
    }

    public function deleteEntry(int $entryId): void
    {
        $this->authorizeSuperAdmin();
        AssistantKnowledgeEntry::query()->findOrFail($entryId)->delete();
        $this->resetPage();
        $this->dispatch(
            'knowledge-pool-saved',
            fields: ['entry'],
            message: $this->localized('Information entfernt.', 'Information removed.'),
        );
    }

    public function render()
    {
        $this->authorizeSuperAdmin();

        $topics = AssistantKnowledgeTopic::query()
            ->withCount([
                'entries',
                'entries as active_entries_count' => static fn ($query) => $query->where('is_active', true),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $search = trim($this->search);
        $entries = AssistantKnowledgeEntry::query()
            ->with('topic:id,name,is_active')
            ->when(ctype_digit($this->topicFilter), fn ($query) => $query->where('topic_id', (int) $this->topicFilter))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $like = '%'.$search.'%';
                    $nested
                        ->where('title', 'like', $like)
                        ->orWhere('summary', 'like', $like)
                        ->orWhere('content', 'like', $like)
                        ->orWhere('keywords', 'like', $like);
                });
            })
            ->orderByDesc('updated_at')
            ->orderBy('sort_order')
            ->paginate(10);

        return view('livewire.admin.assistant-knowledge-manager', [
            'topics' => $topics,
            'entries' => $entries,
            'activeEntryCount' => AssistantKnowledgeEntry::query()->active()->count(),
            'baselineEntryCount' => AssistantKnowledgeEntry::query()->active()->where('include_in_baseline', true)->count(),
            'baselineAssignedCount' => AssistantKnowledgeEntry::query()->where('include_in_baseline', true)->count(),
            'baselineLimit' => AssistantKnowledgePool::BASELINE_ENTRY_LIMIT,
        ]);
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'knowledgeIntro.required' => $this->localized('Bitte einen kurzen Basistext eintragen.', 'Please enter a short baseline text.'),
            'knowledgeIntro.max' => $this->localized('Der Basistext darf höchstens 1.200 Zeichen enthalten.', 'The baseline text may contain at most 1,200 characters.'),
            'topicName.required' => $this->localized('Bitte einen Namen für das Thema eintragen.', 'Please enter a topic name.'),
            'topicName.min' => $this->localized('Der Themenname muss mindestens 2 Zeichen enthalten.', 'The topic name must contain at least 2 characters.'),
            'topicName.max' => $this->localized('Der Themenname darf höchstens 120 Zeichen enthalten.', 'The topic name may contain at most 120 characters.'),
            'topicName.unique' => $this->localized('Ein Thema mit diesem Namen ist bereits vorhanden.', 'A topic with this name already exists.'),
            'topicDescription.max' => $this->localized('Die Themenbeschreibung darf höchstens 500 Zeichen enthalten.', 'The topic description may contain at most 500 characters.'),
            'entryTopicId.required' => $this->localized('Bitte ein Thema auswählen.', 'Please select a topic.'),
            'entryTopicId.integer' => $this->localized('Das gewählte Thema ist ungültig.', 'The selected topic is invalid.'),
            'entryTopicId.exists' => $this->localized('Das gewählte Thema ist nicht mehr verfügbar.', 'The selected topic is no longer available.'),
            'entryTitle.required' => $this->localized('Bitte einen Titel für die Information eintragen.', 'Please enter a title for the information.'),
            'entryTitle.min' => $this->localized('Der Titel muss mindestens 2 Zeichen enthalten.', 'The title must contain at least 2 characters.'),
            'entryTitle.max' => $this->localized('Der Titel darf höchstens 180 Zeichen enthalten.', 'The title may contain at most 180 characters.'),
            'entrySummary.required' => $this->localized('Für Basiswissen ist eine Kurzinfo erforderlich.', 'A summary is required for baseline knowledge.'),
            'entrySummary.max' => $this->localized('Die Kurzinfo darf höchstens 1.000 Zeichen enthalten.', 'The summary may contain at most 1,000 characters.'),
            'entryContent.required' => $this->localized('Bitte die vollständige Information eintragen.', 'Please enter the full information.'),
            'entryContent.min' => $this->localized('Die vollständige Information muss mindestens 3 Zeichen enthalten.', 'The full information must contain at least 3 characters.'),
            'entryContent.max' => $this->localized('Die vollständige Information darf höchstens 50.000 Zeichen enthalten.', 'The full information may contain at most 50,000 characters.'),
            'entryKeywords.max' => $this->localized('Die Suchbegriffe dürfen zusammen höchstens 1.000 Zeichen enthalten.', 'Search keywords may contain at most 1,000 characters in total.'),
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'knowledgeIntro' => $this->localized('Basistext', 'baseline text'),
            'topicName' => $this->localized('Themenname', 'topic name'),
            'topicDescription' => $this->localized('Themenbeschreibung', 'topic description'),
            'topicActive' => $this->localized('Themenfreigabe', 'topic availability'),
            'entryTopicId' => $this->localized('Thema', 'topic'),
            'entryTitle' => $this->localized('Titel', 'title'),
            'entrySummary' => $this->localized('Kurzinfo', 'summary'),
            'entryContent' => $this->localized('vollständige Information', 'full information'),
            'entryKeywords' => $this->localized('Suchbegriffe', 'search keywords'),
            'entryActive' => $this->localized('Wissensfreigabe', 'knowledge availability'),
            'entryBaseline' => $this->localized('Basiswissen', 'baseline knowledge'),
        ];
    }

    private function authorizeSuperAdmin(): void
    {
        Gate::authorize('settings.manage');
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }

    private function resetTopicForm(): void
    {
        $this->editingTopicId = null;
        $this->topicName = '';
        $this->topicDescription = '';
        $this->topicActive = true;
        $this->resetValidation();
    }

    private function resetEntryForm(): void
    {
        $this->editingEntryId = null;
        $this->entryTopicId = null;
        $this->entryTitle = '';
        $this->entrySummary = '';
        $this->entryContent = '';
        $this->entryKeywords = '';
        $this->entryActive = true;
        $this->entryBaseline = false;
        $this->entryOriginallyBaseline = false;
        $this->resetValidation();
    }

    /** @return array<int, string> */
    private function normalizedKeywords(string $keywords): array
    {
        return collect(preg_split('/[,;\n]+/u', $keywords) ?: [])
            ->map(static fn (string $keyword): string => mb_substr(trim($keyword), 0, 80))
            ->filter()
            ->unique(static fn (string $keyword): string => mb_strtolower($keyword))
            ->take(16)
            ->values()
            ->all();
    }

    private function localized(string $german, string $english): string
    {
        return app()->getLocale() === 'de' ? $german : $english;
    }
}
