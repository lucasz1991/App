<?php

namespace App\Livewire;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class MessageBox extends Component
{
    use WithPagination;

    private const PER_PAGE = 15;

    public string $search = '';

    public string $status = 'all';

    #[Url(as: 'open')]
    public ?int $openMessageId = null;

    /** @var array<int, int> */
    public array $selectedMessages = [];

    protected $listeners = [
        'inbox:refresh' => '$refresh',
    ];

    public function mount(): void
    {
        if (! $this->openMessageId) {
            return;
        }

        if (! $this->inboxQuery()->whereKey($this->openMessageId)->exists()) {
            $this->openMessageId = null;

            return;
        }

        $this->dispatch('message-viewer:open', messageId: $this->openMessageId);
    }

    public function updatingSearch(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->status = $this->normalizedStatus();
    }

    public function updatingPaginators(): void
    {
        $this->clearSelection();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = 'all';
        $this->clearSelection();
        $this->resetPage();
    }

    public function clearSelection(): void
    {
        $this->selectedMessages = [];
    }

    public function togglePageSelection(): void
    {
        $visibleIds = $this->visibleMessageIds();
        $selectedIds = $this->authorizedSelectedIds($visibleIds);

        $this->selectedMessages = $visibleIds !== [] && count($selectedIds) === count($visibleIds)
            ? []
            : $visibleIds;
    }

    public function toggleMessageSelection(int $messageId): void
    {
        $visibleIds = $this->visibleMessageIds();
        $selectedIds = $this->authorizedSelectedIds($visibleIds);

        if (! in_array($messageId, $visibleIds, true)) {
            $this->selectedMessages = $selectedIds;

            return;
        }

        $this->selectedMessages = in_array($messageId, $selectedIds, true)
            ? array_values(array_diff($selectedIds, [$messageId]))
            : [...$selectedIds, $messageId];
    }

    public function openMessageDetail(int $messageId): void
    {
        if (! $this->inboxQuery()->whereKey($messageId)->exists()) {
            return;
        }

        $this->openMessageId = $messageId;
        $this->dispatch('message-viewer:open', messageId: $messageId);
    }

    public function markAsRead(int $messageId): void
    {
        $this->markMessages([$messageId], 2);
    }

    public function markAsUnread(int $messageId): void
    {
        $this->markMessages([$messageId], 1);
    }

    public function markSelectedAsRead(): void
    {
        $this->markMessages($this->authorizedSelectedIds(), 2);
    }

    public function markSelectedAsUnread(): void
    {
        $this->markMessages($this->authorizedSelectedIds(), 1);
    }

    /** Explicitly applies to every unread message in the actor's own inbox. */
    public function markAllAsRead(): void
    {
        $count = $this->inboxQuery()->where('status', 1)->update(['status' => 2]);

        $this->clearSelection();
        $this->reportMutation($count, 'read');
    }

    public function deleteMessage(int $messageId): void
    {
        $this->deleteMessages([$messageId]);
    }

    public function deleteSelectedMessages(): void
    {
        $this->deleteMessages($this->authorizedSelectedIds());
    }

    private function inboxQuery(): Builder
    {
        $user = auth()->user();
        abort_unless($user, 403);

        return $user->receivedMessages()->getQuery();
    }

    private function normalizedStatus(): string
    {
        return in_array($this->status, ['all', 'unread', 'read'], true) ? $this->status : 'all';
    }

    private function filteredQuery(): Builder
    {
        $query = $this->inboxQuery()
            ->with(['sender:id,name,role,profile_photo_path'])
            ->withCount('files')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return match ($this->normalizedStatus()) {
            'unread' => $query->where('status', 1),
            'read' => $query->where('status', 2),
            default => $query,
        };
    }

    private function messagesPage(): LengthAwarePaginator
    {
        $query = $this->filteredQuery();
        $page = max(1, (int) $this->getPage());
        $needle = mb_strtolower(mb_substr(trim($this->search), 0, 500));

        if ($needle === '') {
            $total = (clone $query)->count();
            $page = $this->clampPage($page, $total);

            return $query->paginate(self::PER_PAGE, ['*'], 'page', $page, $total);
        }

        // Encrypted subject/body cannot be SQL-LIKE searched. Scan only the
        // recipient's filtered inbox in bounded batches, retaining page IDs
        // rather than every decrypted message in component memory.
        $total = 0;
        $pageIds = [];
        $lastPageIds = [];
        $offset = ($page - 1) * self::PER_PAGE;

        $query->select(['messages.id', 'messages.from_user', 'messages.subject', 'messages.message']);

        foreach ($query->lazy(100) as $message) {
            $haystack = mb_strtolower(implode(' ', [
                $message->sender?->name,
                $message->subject,
                html_entity_decode(strip_tags((string) $message->message), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ]));

            if (! str_contains($haystack, $needle)) {
                continue;
            }

            if ($total % self::PER_PAGE === 0) {
                $lastPageIds = [];
            }

            $lastPageIds[] = (int) $message->id;

            if ($total >= $offset && count($pageIds) < self::PER_PAGE) {
                $pageIds[] = (int) $message->id;
            }

            $total++;
        }

        $clampedPage = $this->clampPage($page, $total);

        if ($clampedPage !== $page) {
            $pageIds = $lastPageIds;
        }

        $messages = $pageIds === []
            ? new Collection
            : $this->filteredQuery()->whereKey($pageIds)->get();

        return new LengthAwarePaginator(
            $messages,
            $total,
            self::PER_PAGE,
            $clampedPage,
            ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'page']
        );
    }

    private function clampPage(int $page, int $total): int
    {
        $clamped = min($page, max(1, (int) ceil($total / self::PER_PAGE)));

        if ($clamped !== $page) {
            $this->setPage($clamped);
        }

        return $clamped;
    }

    /** @return array<int, int> */
    private function visibleMessageIds(): array
    {
        return $this->messagesPage()->getCollection()->modelKeys();
    }

    /** @return array<int, int> */
    private function authorizedSelectedIds(?array $visibleIds = null): array
    {
        $candidates = [];

        foreach (array_slice($this->selectedMessages, 0, self::PER_PAGE) as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                continue;
            }

            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id !== false) {
                $candidates[] = $id;
            }
        }

        $candidates = array_values(array_unique($candidates));

        if ($candidates === []) {
            return [];
        }

        $visibleIds ??= $this->visibleMessageIds();
        $candidates = array_values(array_intersect($candidates, $visibleIds));

        if ($candidates === []) {
            return [];
        }

        return $this->inboxQuery()->whereKey($candidates)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function markMessages(array $ids, int $status): void
    {
        $count = 0;

        foreach ($this->inboxQuery()->whereKey(array_slice($ids, 0, self::PER_PAGE))->get() as $message) {
            if ((int) $message->status === $status) {
                continue;
            }

            $message->update(['status' => $status]);
            $count++;
        }

        $this->clearSelection();
        $this->reportMutation($count, $status === 2 ? 'read' : 'unread');
    }

    private function deleteMessages(array $ids): void
    {
        $deletedIds = DB::transaction(function () use ($ids): array {
            $messages = $this->inboxQuery()
                ->whereKey(array_slice($ids, 0, self::PER_PAGE))
                ->with('files')
                ->lockForUpdate()
                ->get();

            foreach ($messages as $message) {
                foreach ($message->files as $file) {
                    $file->delete();
                }

                $message->delete();
            }

            return $messages->modelKeys();
        });

        $this->clearSelection();

        if ($deletedIds !== []) {
            if (in_array($this->openMessageId, $deletedIds, true)) {
                $this->openMessageId = null;
            }

            // A viewer opened from the shared topbar may not have set this
            // component's URL property. Close it to avoid stale deleted data.
            $this->dispatch('message-viewer:close');
        }

        $this->reportMutation(count($deletedIds), 'deleted');
    }

    private function reportMutation(int $count, string $action): void
    {
        if ($count > 0) {
            $text = match ($action) {
                'read' => trans_choice('{1} Eine Nachricht als gelesen markiert.|[2,*] :count Nachrichten als gelesen markiert.', $count),
                'unread' => trans_choice('{1} Eine Nachricht als ungelesen markiert.|[2,*] :count Nachrichten als ungelesen markiert.', $count),
                default => trans_choice('{1} Eine Nachricht gelöscht.|[2,*] :count Nachrichten gelöscht.', $count),
            };

            $this->dispatch('swal:toast', type: 'success', text: $text);
        }

        $this->dispatch('inbox:refresh');
    }

    public function render()
    {
        $this->status = $this->normalizedStatus();
        $messages = $this->messagesPage();
        $visibleIds = $messages->getCollection()->modelKeys();
        $this->selectedMessages = $this->authorizedSelectedIds($visibleIds);
        $selectedCount = count($this->selectedMessages);
        $pageSelected = $visibleIds !== [] && $selectedCount === count($visibleIds);
        $totalCount = $this->inboxQuery()->count();
        $unreadCount = $this->inboxQuery()->where('status', 1)->count();
        $area = auth()->user()->usesAdminLayout() ? 'admin' : 'user';

        return view('livewire.message-box', compact('messages', 'totalCount', 'unreadCount', 'selectedCount', 'pageSelected'))
            ->layout('layouts.master', ['area' => $area]);
    }
}
