<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Orders;
use App\Livewire\Admin\Settings;
use App\Livewire\Operations\PlanningPageWorkspace;
use App\Livewire\Operations\WorkforcePlanning;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\PersonnelTask;
use App\Models\User;
use App\Services\Operations\UnifiedOperationsInboxService;
use App\Support\Operations\OperationsPages;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OperationsPageIntegrationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        Schema::create('file_folders', function ($table) {
            $table->id();
            $table->foreignId('file_pool_id')->constrained('file_pools');
            $table->foreignId('parent_id')->nullable()->constrained('file_folders');
            $table->string('name');
            $table->json('permissions')->nullable();
            $table->timestamps();
        });
        foreach (['2026_07_18_000001_create_activity_log_table.php', '2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_101000_create_personnel_enhancements.php', '2026_10_06_102000_create_operations_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::table('shifts', function ($table) {
            $table->json('disposition_details')->nullable();
        });
        Mail::fake();
        Bus::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true, 'name' => 'Synthetic Person']);
        $this->actingAs($this->admin);
    }

    public function test_all_page_routes_render_real_authorized_children_without_literal_directives(): void
    {
        $customer = Customer::create(['company_name' => 'Synthetic Navigation Customer', 'is_active' => true]);
        OperationInquiry::create(['title' => 'Synthetic Navigation Inquiry', 'channel' => 'manual', 'original' => 'Synthetic only', 'status' => 'new', 'timezone' => 'Europe/Berlin', 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id]);
        foreach (['cases', 'customers', 'people', 'personnel-processes', 'leave', 'time-review', 'payroll', 'attention', 'planning', 'duty', 'documents', 'shifts'] as $page) {
            $response = $this->get(OperationsPages::url($page));
            $response->assertOk()->assertDontSee('@livewire(', false)->assertDontSee('@endif', false);
        }
        $this->get(OperationsPages::url('cases'))->assertSee('Synthetic Navigation Inquiry');
        $this->get(OperationsPages::url('customers', ['customer' => $customer->id]))->assertSee('Synthetic Navigation Customer');
    }

    public function test_each_planning_view_mounts_only_its_current_group(): void
    {
        Livewire::test(PlanningPageWorkspace::class, ['page' => 'planning', 'initialView' => 'staff'])
            ->assertSet('section', 'pools')->assertSee('Pool anlegen')
            ->assertDontSee('Ausfall & Ablösung')->assertDontSee('@livewire(', false)
            ->call('selectSection', 'offers')->assertSee('Dienstangebot anlegen')
            ->call('selectView', 'tools')->assertSet('section', 'variants')
            ->call('selectSection', 'teams')->assertSee('Team anlegen');
    }

    public function test_case_list_does_not_select_an_unrequested_order(): void
    {
        $customer = Customer::create(['company_name' => 'Synthetic Customer', 'is_active' => true]);
        Order::create(['customer_id' => $customer->id, 'title' => 'Synthetic Unselected Order', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'required_staff' => 1, 'created_by' => $this->admin->id]);
        Livewire::test(Orders::class, ['consolidated' => true])->assertSet('selectedOrderId', null)->assertSet('detailOpen', false);
    }

    public function test_worklist_targets_preserve_record_type_person_and_revision(): void
    {
        $task = PersonnelTask::create(['user_id' => $this->employee->id, 'assigned_to' => $this->admin->id, 'type' => 'task', 'title' => 'Synthetic task', 'status' => 'open', 'revision' => 3, 'created_by' => $this->admin->id]);
        $service = app(UnifiedOperationsInboxService::class);
        parse_str(parse_url($service->destination('task-'.$task->id, $this->admin), PHP_URL_QUERY), $query);
        $this->assertSame('task', (string) $query['record_type']);
        $this->assertSame((string) $task->id, $query['record']);
        $this->assertSame((string) $this->employee->id, $query['user']);
        $this->assertSame('3', $query['revision']);
    }

    public function test_unknown_or_wrong_workforce_record_type_cannot_open_another_model(): void
    {
        Livewire::test(WorkforcePlanning::class, ['tab' => 'cases', 'context' => ['record' => 1, 'record_type' => 'availability-period']])->assertNotFound();
    }

    public function test_standard_selects_render_the_supported_change_contract_and_single_page_heading(): void
    {
        $planning = $this->get(OperationsPages::url('planning', ['view' => 'staff']));
        $planning->assertOk()->assertSee('@change="$wire.selectSection($event.target.value)"', false);
        $people = $this->get(OperationsPages::url('people'));
        $people->assertOk()->assertSee('@change="$wire.setSection($event.target.value)"', false);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($people->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $this->assertSame(1, (new \DOMXPath($document))->query('//main//h1')->length);
    }

    public function test_email_settings_open_without_automatically_starting_the_editor_or_sending_mail(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => true]));
        Livewire::withQueryParams(['tab' => 'email', 'section' => 'templates'])
            ->test(Settings::class)
            ->assertSet('initialTab', 'email')->assertSet('initialSection', 'templates')
            ->assertSee('Mailvorlagen & Editor')->assertDontSee('data-mailbuilder-root', false);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('customers', 0);
    }
}
