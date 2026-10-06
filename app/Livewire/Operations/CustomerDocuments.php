<?php

namespace App\Livewire\Operations;

use App\Models\User;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/** Portal document metadata only; downloading and publishing retain their original guards. */
class CustomerDocuments extends Component
{
    use WithoutUrlPagination, WithPagination;

    #[Locked]
    public int $customerId;

    public string $search = '';

    public string $status = 'all';

    public string $kind = 'all';

    public function mount(int $customerId): void
    {
        abort_unless($customerId > 0, 404);
        $this->customerId = $customerId;
        $this->access();
    }

    private function access(): void
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User && $actor->status, 403);
        abort_unless($actor->can('customers.portal.manage') && $actor->can('customers.portal.publish'), 403);
        abort_unless(CustomerPortalWorkflowSchema::ready(), 503);
        $scope = app(CustomerPortalScope::class);
        $scope->authorizeManager($actor, $this->customerId);
        $scope->authorizeManager($actor, $this->customerId, 'customers.portal.publish');
        foreach (['customer_portal_publications' => ['id', 'title', 'file_mime', 'file_size', 'created_at'], 'customer_portal_attachments' => ['id', 'file_name', 'file_mime', 'file_size', 'created_at', 'source_type', 'source_id']] as $table => $columns) {
            abort_unless(Schema::hasColumns($table, $columns), 503);
        }
    }

    private function validateFilters(): void
    {
        abort_unless(mb_strlen($this->search) <= 100
            && in_array($this->status, ['all', 'quarantined', 'published', 'withdrawn', 'reviewed'], true)
            && in_array($this->kind, ['all', 'document', 'invoice', 'attachment'], true), 422);
        abort_unless(($this->paginators['customerDocumentsPage'] ?? 1) > 0, 422);
    }

    public function updated(string $property): void
    {
        $this->access();
        $this->validateFilters();
        if (in_array($property, ['search', 'status', 'kind'], true)) {
            $this->resetPage('customerDocumentsPage');
        }
    }

    public function updatedPaginators(int $page, string $pageName): void
    {
        $this->access();
        abort_unless($page > 0 && $pageName === 'customerDocumentsPage', 422);
    }

    public function resetFilters(): void
    {
        $this->access();
        $this->reset(['search', 'status', 'kind']);
        $this->resetPage('customerDocumentsPage');
    }

    private function query(): Builder
    {
        $publications = DB::table('customer_portal_publications')->where('customer_id', $this->customerId)->whereIn('subject_type', ['document', 'invoice'])
            ->select(['id as record_id', 'title', 'subject_type as kind', 'status', 'revision', 'file_mime', 'file_size', 'created_at'])
            ->selectRaw("'publication' as source, NULL as source_type, NULL as source_id, CASE WHEN file_path IS NULL THEN 0 ELSE 1 END as has_file");
        $attachments = DB::table('customer_portal_attachments')->where('customer_id', $this->customerId)
            // The two projections keep the exact same column order for the SQL union.
            ->select(['id as record_id', 'file_name as title', DB::raw("'attachment' as kind"), 'status', 'revision', 'file_mime', 'file_size', 'created_at', DB::raw("'attachment' as source"), 'source_type', 'source_id', DB::raw('CASE WHEN file_path IS NULL THEN 0 ELSE 1 END as has_file')]);
        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $publications->where('title', 'like', $term);
            $attachments->where('file_name', 'like', $term);
        }

        return DB::query()->fromSub($publications->unionAll($attachments), 'customer_documents')
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->kind !== 'all', fn (Builder $query) => $query->where('kind', $this->kind))
            ->orderByDesc('created_at')->orderByDesc('record_id')->orderBy('source');
    }

    public function render()
    {
        $this->access();
        $this->validateFilters();
        $items = $this->query()->paginate(15, ['*'], 'customerDocumentsPage');
        $items->through(function (object $record): object {
            $route = $record->source === 'publication' ? 'customer-portal.manager.document' : 'customer-portal.manager.attachment';
            $record->id = (int) hexdec(substr(hash('sha256', $record->source.':'.$record->record_id), 0, 12));
            $record->download_url = $record->has_file && Route::has($route) ? route($route, ['id' => $record->record_id]) : null;
            unset($record->has_file);

            return $record;
        });

        return view('livewire.operations.customer-documents', [
            'items' => $items,
            'activeFilterCount' => (trim($this->search) !== '' ? 1 : 0) + ($this->status !== 'all' ? 1 : 0) + ($this->kind !== 'all' ? 1 : 0),
        ]);
    }
}
