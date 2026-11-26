<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesSuperAdminSettings;
use App\Livewire\Concerns\ResetsPageOnFilterChange;
use App\Models\AssistantKnowledgeEntry;
use App\Models\AssistantKnowledgeTopic;
use App\Services\Ai\AssistantKnowledgePool;
use App\Support\Ai\AssistantKnowledgeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class AssistantKnowledgeManager extends Component
{
    use AuthorizesSuperAdminSettings;
    use ResetsPageOnFilterChange;
    use WithPagination;

    public string $knowledgeIntro = '';

    public string $assistantDefaultPrompt = '';

    public string $assistantRules = '';

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
        $this->authorizeSuperAdminSettings();
        $this->assistantDefaultPrompt = AssistantKnowledgeSettings::defaultPrompt(uncached: true);
        $this->assistantRules = AssistantKnowledgeSettings::rules(uncached: true);
        $this->knowledgeIntro = AssistantKnowledgeSettings::intro(uncached: true);
    }

    public function hydrate(): void
    {
        $this->authorizeSuperAdminSettings();
    }

    protected function pageResetProperties(): array
    {
        return ['search', 'topicFilter'];
    }

    public function savePromptConfiguration(): void
    {
        $this->authorizeSuperAdminSettings();
        $this->assistantDefaultPrompt = trim($this->assistantDefaultPrompt);
        $this->assistantRules = trim($this->assistantRules);
        $this->validate([
            'assistantDefaultPrompt' => [
                'required',
                'string',
                'max:'.AssistantKnowledgeSettings::DEFAULT_PROMPT_MAX_CHARACTERS,
            ],
            'assistantRules' => [
                'required',
                'string',
                'max:'.AssistantKnowledgeSettings::RULES_MAX_CHARACTERS,
            ],
        ]);

        AssistantKnowledgeSettings::setPromptConfiguration(
            $this->assistantDefaultPrompt,
            $this->assistantRules,
        );
        $this->assistantDefaultPrompt = AssistantKnowledgeSettings::defaultPrompt(uncached: true);
        $this->assistantRules = AssistantKnowledgeSettings::rules(uncached: true);
        $this->dispatch(
            'knowledge-pool-saved',
            fields: ['assistantDefaultPrompt', 'assistantRules'],
            message: __('assistant_knowledge.saved_prompt_rules'),
        );
    }

    public function saveIntro(): void
    {
        $this->authorizeSuperAdminSettings();
        $this->knowledgeIntro = trim($this->knowledgeIntro);
        $this->validate([
            'knowledgeIntro' => ['required', 'string', 'max:1200'],
        ]);

        AssistantKnowledgeSettings::setIntro($this->knowledgeIntro);
        $this->knowledgeIntro = AssistantKnowledgeSettings::intro(uncached: true);
        $this->dispatch(
            'knowledge-pool-saved',
            fields: ['knowledgeIntro'],
            message: __('assistant_knowledge.saved_intro'),
        );
    }

    public function createTopic(): void
    {
        $this->authorizeSuperAdminSettings();
        $this->resetTopicForm();
        $this->topicEditorOpen = true;
    }

    public function editTopic(int $topicId): void
    {
        $this->authorizeSuperAdminSettings();
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
        $this->authorizeSuperAdminSettings();
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
                ? __('assistant_knowledge.topic_updated')
                : __('assistant_knowledge.topic_created'),
        );
    }

    public function deleteTopic(int $topicId): void
    {
        $this->authorizeSuperAdminSettings();
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
            message: __('assistant_knowledge.topic_deleted'),
        );
    }

    public function createEntry(?int $topicId = null): void
    {
        $this->authorizeSuperAdminSettings();
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
        $this->authorizeSuperAdminSettings();
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
        $this->authorizeSuperAdminSettings();
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
                        $fail(__('assistant_knowledge.baseline_limit_reached', ['limit' => AssistantKnowledgePool::BASELINE_ENTRY_LIMIT]));
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
                ? __('assistant_knowledge.entry_updated')
                : __('assistant_knowledge.entry_created'),
        );
    }

    public function deleteEntry(int $entryId): void
    {
        $this->authorizeSuperAdminSettings();
        AssistantKnowledgeEntry::query()->findOrFail($entryId)->delete();
        $this->resetPage();
        $this->dispatch(
            'knowledge-pool-saved',
            fields: ['entry'],
            message: __('assistant_knowledge.entry_deleted'),
        );
    }

    public function render()
    {
        $this->authorizeSuperAdminSettings();

        $topics = AssistantKnowledgeTopic::query()
            ->withCount('entries')
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
            'totalEntryCount' => AssistantKnowledgeEntry::query()->count(),
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
            'assistantDefaultPrompt.required' => __('assistant_knowledge.validation_default_prompt_required'),
            'assistantDefaultPrompt.max' => __('assistant_knowledge.validation_default_prompt_max', ['max' => AssistantKnowledgeSettings::DEFAULT_PROMPT_MAX_CHARACTERS]),
            'assistantRules.required' => __('assistant_knowledge.validation_rules_required'),
            'assistantRules.max' => __('assistant_knowledge.validation_rules_max', ['max' => AssistantKnowledgeSettings::RULES_MAX_CHARACTERS]),
            'knowledgeIntro.required' => __('assistant_knowledge.validation_intro_required'),
            'knowledgeIntro.max' => __('assistant_knowledge.validation_intro_max'),
            'topicName.required' => __('assistant_knowledge.validation_topic_name_required'),
            'topicName.min' => __('assistant_knowledge.validation_topic_name_min'),
            'topicName.max' => __('assistant_knowledge.validation_topic_name_max'),
            'topicName.unique' => __('assistant_knowledge.validation_topic_name_unique'),
            'topicDescription.max' => __('assistant_knowledge.validation_topic_description_max'),
            'entryTopicId.required' => __('assistant_knowledge.validation_entry_topic_required'),
            'entryTopicId.integer' => __('assistant_knowledge.validation_entry_topic_integer'),
            'entryTopicId.exists' => __('assistant_knowledge.validation_entry_topic_exists'),
            'entryTitle.required' => __('assistant_knowledge.validation_entry_title_required'),
            'entryTitle.min' => __('assistant_knowledge.validation_entry_title_min'),
            'entryTitle.max' => __('assistant_knowledge.validation_entry_title_max'),
            'entrySummary.required' => __('assistant_knowledge.validation_entry_summary_required'),
            'entrySummary.max' => __('assistant_knowledge.validation_entry_summary_max'),
            'entryContent.required' => __('assistant_knowledge.validation_entry_content_required'),
            'entryContent.min' => __('assistant_knowledge.validation_entry_content_min'),
            'entryContent.max' => __('assistant_knowledge.validation_entry_content_max'),
            'entryKeywords.max' => __('assistant_knowledge.validation_entry_keywords_max'),
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'assistantDefaultPrompt' => __('assistant_knowledge.attribute_default_prompt'),
            'assistantRules' => __('assistant_knowledge.attribute_rules'),
            'knowledgeIntro' => __('assistant_knowledge.attribute_intro'),
            'topicName' => __('assistant_knowledge.attribute_topic_name'),
            'topicDescription' => __('assistant_knowledge.attribute_topic_description'),
            'topicActive' => __('assistant_knowledge.attribute_topic_active'),
            'entryTopicId' => __('assistant_knowledge.attribute_entry_topic'),
            'entryTitle' => __('assistant_knowledge.attribute_entry_title'),
            'entrySummary' => __('assistant_knowledge.attribute_entry_summary'),
            'entryContent' => __('assistant_knowledge.attribute_entry_content'),
            'entryKeywords' => __('assistant_knowledge.attribute_entry_keywords'),
            'entryActive' => __('assistant_knowledge.attribute_entry_active'),
            'entryBaseline' => __('assistant_knowledge.attribute_entry_baseline'),
        ];
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
}
