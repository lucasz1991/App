<?php

namespace App\Support\Operations;

use App\Livewire\Operations\CaseWorkspace;
use App\Models\User;
use App\Support\CustomerPortal\CustomerPortalScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only index. All writes remain in their native, revision-guarded workflows. */
final class UnifiedInboxProjection
{
    public static function query(User $actor, ?int $customer = null): Builder
    {
        $sections = CaseWorkspace::availableSections($actor);
        abort_unless($sections, 403);
        $queries = [];
        $portalCustomers = isset($sections['portal']) ? app(CustomerPortalScope::class)->manageableCustomers($actor)->pluck('id')->all() : [];
        $ai = isset($sections['ai-intake']) && AiIntakeSchema::ready();

        if (isset($sections['overview'])) {
            $q = DB::table('operation_inquiries as r')->selectRaw("r.id, 'inquiry' as kind, r.customer_id, r.title, r.status, CASE WHEN r.source_reference LIKE 'reviewed-import:%' THEN 'import' ELSE r.channel END as channel, r.updated_at, CASE WHEN r.order_id IS NOT NULL OR r.duplicate_of_id IS NOT NULL OR r.status IN ('rejected','converted') THEN 'done' WHEN r.status = 'offered' THEN 'waiting' WHEN r.status IN ('verified','accepted') THEN 'ready' ELSE 'review' END as bucket");
            if ($ai) {
                $q->whereNotExists(fn ($linked) => $linked->selectRaw('1')->from('ai_intake_proposals as p')->join('ai_intakes as a', 'a.id', '=', 'p.intake_id')->whereColumn('p.inquiry_id', 'r.id')->whereColumn('p.source_revision', 'a.source_revision')->whereNotIn('a.status', ['completed', 'not_inquiry'])->when($customer, fn ($s) => $s->where('a.customer_id', $customer)));
            }
            if ($portalCustomers) {
                $q->whereNotExists(fn ($linked) => $linked->selectRaw('1')->from('customer_portal_submission_items as p')->whereColumn('p.inquiry_id', 'r.id')->whereIn('p.customer_id', $portalCustomers));
            }
            $queries[] = $q->when($customer, fn ($q) => $q->where('r.customer_id', $customer));
        }
        if ($ai) {
            $queries[] = DB::table('ai_intakes as r')->selectRaw("r.id, 'ai' as kind, r.customer_id, r.title, r.status, CASE WHEN r.source_type = 'email' THEN 'email' ELSE 'manual' END as channel, r.updated_at, CASE WHEN r.status = 'completed' THEN 'done' WHEN r.status = 'waiting_customer' THEN 'waiting' WHEN r.status = 'ready' THEN 'ready' ELSE 'review' END as bucket")
                ->where('r.status', '!=', 'not_inquiry')
                ->where(fn ($q) => $q->where('r.status', '!=', 'completed')->orWhereNotExists(fn ($p) => $p->selectRaw('1')->from('ai_intake_proposals as p')->whereColumn('p.intake_id', 'r.id')->whereNotNull('p.inquiry_id')))
                ->when($customer, fn ($q) => $q->where('r.customer_id', $customer));
        }
        if (isset($sections['imports']) && ! $customer) {
            $queries[] = DB::table('operation_workflows as r')->where('kind', 'import')->where('status', '!=', 'converted')->selectRaw("r.id, 'import' as kind, NULL as customer_id, r.title, r.status, 'import' as channel, r.updated_at, CASE WHEN r.status = 'reviewed' THEN 'ready' ELSE 'review' END as bucket");
        }
        if ($portalCustomers) {
            foreach (['customer_portal_submissions' => 'submission', 'customer_portal_requests' => 'request'] as $table => $kind) {
                $queries[] = DB::table($table.' as r')->whereIn('r.customer_id', $portalCustomers)->when($customer, fn ($q) => $q->where('r.customer_id', $customer))->selectRaw("r.id, '$kind' as kind, r.customer_id, 'Portal-Anliegen' as title, r.status, 'portal' as channel, r.updated_at, CASE WHEN r.status IN ('accepted','rejected','closed','completed','resolved') THEN 'done' WHEN r.status IN ('offered','waiting_customer') THEN 'waiting' ELSE 'review' END as bucket");
            }
        }
        $union = array_shift($queries);
        if (! $union) {
            $union = DB::query()->selectRaw("0 as id, '' as kind, NULL as customer_id, '' as title, '' as status, '' as channel, NULL as updated_at, '' as bucket")->whereRaw('1 = 0');
        }
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()->fromSub($union, 'inbox')->leftJoin('customers as c', 'c.id', '=', 'inbox.customer_id')->select('inbox.*', 'c.company_name as customer_name');
    }
}
