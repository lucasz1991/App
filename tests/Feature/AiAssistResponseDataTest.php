<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmployeeAvailability;
use App\Models\OperationAudit;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\AiAssistService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiAssistResponseDataTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private User $employee;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            if ($migration === '2026_10_04_120000_create_workforce_personnel_foundations.php' && ! Schema::hasColumn('shifts', 'disposition_details')) {
                Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
            }
            (require database_path('migrations/'.$migration))->up();
        }
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        Http::fake();
        User::factory()->create(['name' => 'Systemverwaltung', 'role' => 'admin', 'status' => true]);
        $this->manager = User::factory()->create(['name' => 'Disposition QA', 'role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['name' => 'Ben Beta', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Gepflegte Regeln', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Rail QA', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Kundenleistung', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-10T00:00', 'ends_at' => '2027-05-17T00:00', 'required_staff' => 1, 'created_by' => $this->manager->id]);
        $this->shift = Shift::create(['order_id' => $order->id, 'title' => 'Offener Donnerstag', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'draft', 'created_by' => $this->manager->id]);
        $this->shift->forceFill(['revision' => 7])->save();
        EmployeeAvailability::create(['user_id' => $this->employee->id, 'kind' => 'preferred', 'from' => '2027-05-13', 'until' => '2027-05-13', 'weekdays' => [4], 'whole_day' => true, 'timezone' => 'Europe/Berlin']);
    }

    public function test_staffing_response_displays_real_shift_data_and_preserves_its_write_capability_without_executing_it(): void
    {
        $service = app(AiAssistService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $answer = $service->answer('period', $this->manager, $service->context('cases', 'shifts', 'plan'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $card = $answer['card'];
        $this->assertSame('staffing', $card['presentation']);
        $this->assertSame('Diese Woche', $card['display_title']);
        $this->assertSame('10.05. – 16.05.2027', $card['subtitle']);
        $this->assertSame('Übernahme als „Angefragt“', $card['summary_note']);
        $this->assertCount(1, $card['rows']);
        $row = $card['rows'][0];
        $this->assertSame('Offener Donnerstag', $row['heading']);
        $this->assertSame('Do 13.05. 08:00', $row['detail']);
        $this->assertSame($row['heading'].' · '.$row['detail'], $row['label']);
        $this->assertSame('Ben Beta', $row['value']);
        $this->assertNotEmpty($row['small']);
        $this->assertNotEmpty($row['why']);
        $this->assertSame([[$this->shift->id, $this->employee->id, 7]], $card['payload']);
        $this->assertSame('apply', $card['buttons'][1]['act']);
        $this->assertTrue($card['buttons'][1]['primary']);
        $this->assertStringContainsString('Konflikte und Revision geprüft', $card['note']);
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, OperationAudit::count());
        $this->assertSame(7, $this->shift->fresh()->revision);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(?:insert|update|delete|replace|alter|drop|create)\b/i', $query['query']);
        }
        Http::assertNothingSent();
    }

    public function test_partial_current_week_keeps_the_actual_range_instead_of_calling_it_this_week(): void
    {
        $service = app(AiAssistService::class);
        $card = $service->answer('period', $this->manager, $service->context('cases', 'shifts', 'plan', '2027-05-12', '2027-05-14'))['card'];

        $this->assertSame('staffing', $card['presentation']);
        $this->assertSame('Besetzungsvorschläge', $card['display_title']);
        $this->assertSame('12.05. – 14.05.2027', $card['subtitle']);
        $this->assertSame([[$this->shift->id, $this->employee->id, 7]], $card['payload']);
    }

    public function test_this_week_uses_the_display_timezone_at_the_week_boundary(): void
    {
        // UTC is still Sunday; the operations display is already on Monday.
        $this->travelTo(CarbonImmutable::parse('2027-05-09T22:30:00Z'));
        $service = app(AiAssistService::class);
        $card = $service->answer('period', $this->manager, $service->context('cases', 'shifts', 'plan'))['card'];

        $this->assertSame('Diese Woche', $card['display_title']);
        $this->assertSame('10.05. – 16.05.2027', $card['subtitle']);
    }

    public function test_missing_candidates_do_not_invent_rows_or_offer_an_apply_action(): void
    {
        $this->employee->update(['status' => false]);
        $service = app(AiAssistService::class);
        $card = $service->answer('period', $this->manager, $service->context('cases', 'shifts', 'plan'))['card'];

        $this->assertSame([], $card['rows']);
        $this->assertArrayNotHasKey('payload', $card);
        $this->assertCount(1, $card['buttons']);
        $this->assertArrayNotHasKey('act', $card['buttons'][0]);
        $this->assertArrayHasKey('href', $card['buttons'][0]);
        $this->assertSame(0, ShiftAssignment::count());
    }
}
