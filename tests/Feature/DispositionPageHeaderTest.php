<?php

namespace Tests\Feature;

use App\Livewire\Operations\CaseWorkspace;
use App\Livewire\Operations\CommercialOfferIndex;
use App\Livewire\Operations\UnifiedInbox;
use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\User;
use App\Services\Operations\InquiryWorkflowService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DispositionPageHeaderTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        $this->travelTo(now()->setDate(2027, 5, 12)->startOfDay());
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_07_18_000001_create_activity_log_table.php', '2026_07_22_000002_create_employee_document_requirements_table.php', '2026_10_04_121000_create_customer_workflow_extensions.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic header customer', 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    public function test_compact_header_has_accessible_title_and_stable_teleport_hosts_without_optional_actions(): void
    {
        $xpath = $this->xpath(Blade::render('<x-ui.page-header title="Eingang" variant="compact" :show-back="false" />'));

        $this->assertSame(1, $xpath->query('//header[@data-page-header-variant="compact"]')->length);
        $title = $xpath->query('//header//h1')->item(0);
        $this->assertInstanceOf(DOMElement::class, $title);
        $this->assertSame('Eingang', trim($title->textContent));
        $this->assertContains('sr-only', explode(' ', $title->getAttribute('class')));
        foreach (['data-page-header-search', 'data-page-header-actions', 'data-page-header-action-list'] as $host) {
            $this->assertSame(1, $xpath->query('//header//*[@'.$host.']')->length, $host.' must exist before nested Livewire controls teleport into it.');
        }
    }

    public function test_header_slots_keep_controls_and_actions_separate_and_default_header_stays_visible(): void
    {
        foreach (['default', 'compact'] as $variant) {
            $xpath = $this->xpath(Blade::render(
                '<x-ui.page-header title="Angebote" :variant="$variant" :show-back="false"><x-slot:controls><button type="button" data-filter>Filtern</button></x-slot:controls><x-slot:actions><button type="button" data-create>Erstellen</button></x-slot:actions></x-ui.page-header>',
                compact('variant'),
            ));
            $this->assertSame(1, $xpath->query('//*[@data-page-header-search]/button[@data-filter]')->length);
            $this->assertSame(1, $xpath->query('//*[@data-page-header-action-list]/button[@data-create]')->length);
            $title = $xpath->query('//header//h1')->item(0);
            $this->assertInstanceOf(DOMElement::class, $title);
            $this->assertSame($variant === 'compact', in_array('sr-only', explode(' ', $title->getAttribute('class')), true));
        }
    }

    public function test_inbox_header_controls_keep_their_livewire_owner_and_selection_after_filtering(): void
    {
        $inquiry = $this->inquiry('Header inquiry');
        $component = Livewire::test(UnifiedInbox::class)->assertSee($inquiry->title);
        $xpath = $this->xpath($component->html());
        $statusHost = '//template[@*[name()="x-teleport" and .="[data-page-header-search]"]]';
        $actionsHost = '//template[@*[name()="x-teleport" and .="[data-page-header-action-list]"]]';
        $this->assertSame(5, $xpath->query($statusHost.'//button[@data-status-value]')->length);
        $this->assertSame(1, $xpath->query($actionsHost.'//*[@data-rt-custom-select and @data-autosave-model="channel"]')->length);
        $this->assertSame(1, $xpath->query($actionsHost.'//button[@aria-label="Eingang erfassen"]')->length);
        $this->assertSame(0, $xpath->query('//section[contains(@class,"rt-disposition-panel")]//nav')->length);

        $component->call('setStatus', 'waiting')->assertDontSee($inquiry->title);
        $xpath = $this->xpath($component->html());
        $this->assertSame(1, $xpath->query($statusHost.'//button[@data-status-value="waiting" and @aria-pressed="true"]')->length);
        $this->assertSame(0, $xpath->query($statusHost.'//button[@data-status-value="all" and @aria-pressed="true"]')->length);
        $component->call('setStatus', 'review')->assertSee($inquiry->title);
        $this->assertSame('new', $inquiry->fresh()->status);
        $this->assertNull($inquiry->fresh()->order_id);
    }

    public function test_offers_use_page_controls_only_when_requested_and_keep_search_status_and_customer_scope(): void
    {
        $draft = $this->offer($this->inquiry('Header draft'), 'draft');
        $offered = $this->offer($this->inquiry('Header issued'), 'offered');
        $otherCustomer = Customer::create(['company_name' => 'Other header customer', 'is_active' => true]);
        $this->offer($this->inquiry('Foreign issued', $otherCustomer), 'offered');

        $embedded = Livewire::test(CommercialOfferIndex::class, ['customerId' => $this->customer->id])->assertSet('headerInPage', false);
        $xpath = $this->xpath($embedded->html());
        $this->assertSame(1, $xpath->query('//*[@data-tables-toolbar]')->length);
        $this->assertSame(0, $xpath->query('//template[@*[name()="x-teleport" and .="[data-topbar-page-search]"]]')->length);

        $component = Livewire::test(CommercialOfferIndex::class, ['customerId' => $this->customer->id, 'headerInPage' => true])
            ->assertViewHas('offers', fn ($offers) => $offers->pluck('id')->sort()->values()->all() === collect([$draft->id, $offered->id])->sort()->values()->all());
        $xpath = $this->xpath($component->html());
        $this->assertSame(0, $xpath->query('//*[@data-tables-toolbar]')->length);
        $this->assertSame(1, $xpath->query('//template[@*[name()="x-teleport" and .="[data-topbar-page-search]"]]//input[@*[name()="wire:model.live.debounce.300ms" and .="search"]]')->length);
        $this->assertSame(1, $xpath->query('//template[@*[name()="x-teleport" and .="[data-page-header-search]"]]//*[@data-rt-custom-select and @data-autosave-model="status"]')->length);

        $component->set('status', 'offered')->assertViewHas('offers', fn ($offers) => $offers->pluck('id')->all() === [$offered->id]);
        $component->set('search', 'does not match')->assertViewHas('offers', fn ($offers) => $offers->isEmpty());
        $component->set('search', 'Header issued')->assertViewHas('offers', fn ($offers) => $offers->pluck('id')->all() === [$offered->id]);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('offered', $offered->fresh()->status);
    }

    public function test_case_offers_mount_the_index_in_the_shared_header(): void
    {
        $component = Livewire::test(CaseWorkspace::class, ['initialView' => 'offers'])->assertSeeLivewire(CommercialOfferIndex::class);
        $xpath = $this->xpath($component->html());
        $snapshots = $xpath->query('//*[@*[name()="wire:snapshot"]]');
        $offerHeaderModes = [];
        foreach ($snapshots as $element) {
            $snapshot = json_decode($element->getAttribute('wire:snapshot'), true, flags: JSON_THROW_ON_ERROR);
            if (($snapshot['memo']['name'] ?? null) === 'operations.commercial-offer-index') {
                $offerHeaderModes[] = $snapshot['data']['headerInPage'];
            }
        }
        $this->assertSame([true], $offerHeaderModes);
    }

    public function test_offer_header_placement_cannot_be_changed_by_client_hydration(): void
    {
        $component = Livewire::test(CommercialOfferIndex::class, ['headerInPage' => true]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('headerInPage', false);
    }

    private function inquiry(string $title, ?Customer $customer = null): OperationInquiry
    {
        return app(InquiryWorkflowService::class)->save(null, [
            'channel' => 'manual', 'title' => $title, 'original' => 'Synthetic header test',
            'customer_id' => ($customer ?? $this->customer)->id, 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-14T22:00', 'ends_at' => '2027-05-15T06:00',
            'role_name' => 'Tf', 'location_name' => 'Hamburg', 'required_staff' => 2,
        ], $this->admin);
    }

    private function offer(OperationInquiry $inquiry, string $status): CommercialOfferRevision
    {
        return CommercialOfferRevision::create([
            'subject_type' => 'OperationInquiry', 'subject_id' => $inquiry->id, 'revision' => 1,
            'state_version' => 1, 'status' => $status, 'kind' => 'offer',
            'snapshot' => ['terms' => 'Synthetic terms', 'positions' => [], 'source_revision' => $inquiry->revision],
            'total_cents' => 10000, 'created_by' => $this->admin->id,
        ]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
