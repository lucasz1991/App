<?php

namespace App\Livewire\Admin;

use App\Models\AssistantKnowledgeEntry;
use App\Models\AssistantKnowledgeTopic;
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
        $this->validate([
            'knowledgeIntro' => ['required', 'string', 'max:1200'],
        ]);

        AssistantKnowledgeSettings::setIntro($this->knowledgeIntro);
        $this->knowledgeIntro = AssistantKnowledgeSettings::intro(uncached: true);
        $this->dispatch('knowledge-pool-saved', fields: ['knowledgeIntro']);
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
        $validated = $this->validate([
            'topicName' => ['required', 'string', 'min:2', 'max:120'],
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
        $this->resetTopicForm();
        $this->dispatch('knowledge-pool-saved', fields: ['topic']);
    }

    public function deleteTopic(int $topicId): void
    {
        $this->authorizeSuperAdmin();
        $topic = AssistantKnowledgeTopic::query()->findOrFail($topicId);

        DB::transaction(function () use ($topic): void {
            $topic->entries()->eachById(static fn (AssistantKnowledgeEntry $entry) => $entry->delete());
            $topic->delete();
        });

        if ($this->topicFilter === (string) $topicId) {
            $this->topicFilter = 'all';
        }

        $this->resetPage();
        $this->dispatch('knowledge-pool-saved', fields: ['topic']);
    }

    public function createEntry(?int $topicId = null): void
    {
        $this->authorizeSuperAdmin();
        $this->resetEntryForm();

        $preferredTopic = $topicId
            ?: (ctype_digit($this->topicFilter) ? (int) $this->topicFilter : null);
        $this->entryTopicId = $preferredTopic
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
        $this->entryEditorOpen = true;
        $this->resetValidation();
    }

    public function saveEntry(): void
    {
        $this->authorizeSuperAdmin();
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
            'entryBaseline' => ['boolean'],
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
        $this->resetEntryForm();
        $this->dispatch('knowledge-pool-saved', fields: ['entry']);
    }

    public function deleteEntry(int $entryId): void
    {
        $this->authorizeSuperAdmin();
        AssistantKnowledgeEntry::query()->findOrFail($entryId)->delete();
        $this->resetPage();
        $this->dispatch('knowledge-pool-saved', fields: ['entry']);
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
            ->orderBy('sort_order')
            ->orderByDesc('updated_at')
            ->paginate(10);

        return view('livewire.admin.assistant-knowledge-manager', [
            'topics' => $topics,
            'entries' => $entries,
            'activeEntryCount' => AssistantKnowledgeEntry::query()->active()->count(),
            'baselineEntryCount' => AssistantKnowledgeEntry::query()->active()->where('include_in_baseline', true)->count(),
        ]);
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
}
