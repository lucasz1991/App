<?php

namespace App\Livewire\Operations;

use App\Models\Customer;
use App\Models\CustomerPortalRequest;
use App\Models\CustomerPortalSubmission;
use App\Models\User;
use App\Services\Operations\DispositionMailboxService;
use App\Support\Operations\UnifiedInboxProjection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class UnifiedInbox extends Component
{
    use WithPagination;

    public const FILTERS = ['all' => 'Alle', 'review' => 'Zu prüfen', 'waiting' => 'Rückmeldung offen', 'ready' => 'Bereit', 'done' => 'Erledigt'];

    private const KINDS = ['inquiry', 'ai', 'import', 'submission', 'request'];

    public string $status = 'all';

    public string $channel = 'all';

    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public ?array $detail = null;

    #[Locked]
    public int $detailRevision = 0;

    public function mount(?int $customerId = null): void
    {
        $this->customerId = $customerId;
        if ($customerId) {
            Customer::findOrFail($customerId);
        }
        $this->query();
    }

    private function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User && $actor->status, 403);

        return $actor;
    }

    private function query()
    {
        return UnifiedInboxProjection::query($this->actor(), $this->customerId);
    }

    public function setStatus(string $status): void
    {
        abort_unless(isset(self::FILTERS[$status]), 422);
        $this->status = $status;
        $this->resetPage('inboxPage');
    }

    public function updatedChannel(): void
    {
        $this->resetPage('inboxPage');
    }

    #[On('disposition-mailbox-updated')]
    #[On('ai-intake-submitted')]
    public function refreshMailbox(): void
    {
        $query = $this->query();
        if (($this->detail['kind'] ?? null) === 'ai' && isset($this->detail['id'])
            && ! $query->where('inbox.kind', 'ai')->where('inbox.id', $this->detail['id'])->exists()) {
            $this->detail = null;
            $this->detailRevision++;
        }
        $this->resetPage('inboxPage');
    }

    public function select(string $kind, int $id): void
    {
        $row = $this->query()->where('inbox.kind', $kind)->where('inbox.id', $id)->first();
        abort_unless($row, 404);
        if ($kind === 'ai') {
            $this->detail = null;
            $this->detailRevision++;
            $this->dispatch('open-ai-intake-panel', intakeId: $id, create: false,
                customerId: $this->customerId ?? ($row->customer_id ? (int) $row->customer_id : null));

            return;
        }
        $this->detail = ['kind' => $kind, 'id' => $id, 'customer' => $row->customer_id ? (int) $row->customer_id : null];
        $this->detailRevision++;
    }

    public function openRow(int $id): void
    {
        $kind = self::KINDS[$id % 8] ?? null;
        abort_unless($id > 0 && $kind, 404);
        $this->select($kind, intdiv($id, 8));
    }

    public function create(string $kind = 'inquiry'): void
    {
        $sections = CaseWorkspace::availableSections($this->actor());
        abort_unless(isset($sections[match ($kind) {
            'inquiry' => 'overview', 'ai' => 'ai-intake', 'import' => 'imports', default => ''
        }]), 403);
        if ($kind === 'ai') {
            $this->detail = null;
            $this->detailRevision++;
            $this->dispatch('open-ai-intake-panel', intakeId: null, create: true, customerId: $this->customerId);

            return;
        }
        $this->detail = ['kind' => $kind, 'id' => null, 'customer' => $this->customerId];
        $this->detailRevision++;
    }

    public function openMailbox(): void
    {
        abort_unless($this->customerId === null && app(DispositionMailboxService::class)->available($this->actor()), 403);
        $this->dispatch('open-disposition-mailbox')->to(DispositionMailbox::class);
    }

    public function render()
    {
        abort_unless(isset(self::FILTERS[$this->status]) && in_array($this->channel, ['all', 'email', 'phone', 'manual', 'portal', 'ai', 'import'], true), 422);
        $query = $this->query()->when($this->channel !== 'all', fn ($q) => $this->channel === 'ai' ? $q->where('inbox.kind', 'ai') : $q->where('inbox.channel', $this->channel));
        $counts = (clone $query)->select('inbox.bucket')->selectRaw('COUNT(*) as aggregate')->groupBy('inbox.bucket')->pluck('aggregate', 'bucket');
        $rows = $query->when($this->status !== 'all', fn ($q) => $q->where('inbox.bucket', $this->status))->orderByRaw("CASE inbox.bucket WHEN 'review' THEN 0 WHEN 'ready' THEN 1 WHEN 'waiting' THEN 2 ELSE 3 END")->orderByDesc('inbox.updated_at')->orderBy('inbox.kind')->orderByDesc('inbox.id')->paginate(15, ['*'], 'inboxPage');
        $submissions = CustomerPortalSubmission::query();
        $requests = CustomerPortalRequest::query();
        // Decrypt only authorized records on this page, not the entire source collection.
        $titles = [];
        foreach (['submission' => $submissions, 'request' => $requests] as $kind => $source) {
            $ids = $rows->getCollection()->where('kind', $kind)->pluck('id');
            if ($ids->isNotEmpty()) {
                foreach ($source->whereIn('id', $ids)->get() as $record) {
                    $titles[$kind.':'.$record->id] = $record->payload['positions'][0]['title'] ?? $record->payload['title'] ?? $record->payload['subject'] ?? 'Portal-Anliegen';
                }
            }
        }

        foreach ($rows as $row) {
            $key = $row->kind.':'.$row->id;
            $row->title = $titles[$key] ?? $row->title;
            $row->record_id = (int) $row->id;
            $row->id = $row->record_id * 8 + array_search($row->kind, self::KINDS, true);
        }

        return view('livewire.operations.unified-inbox', ['rows' => $rows, 'counts' => $counts, 'sections' => CaseWorkspace::availableSections($this->actor()),
            'mailboxAvailable' => $this->customerId === null && app(DispositionMailboxService::class)->available($this->actor())]);
    }
}
