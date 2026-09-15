<?php

use App\Models\Customer;
use App\Models\EmployeeQualification;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\InquiryWorkflowService;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\ShiftSchedulingService;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BuildsMinimalRailTimeSchema;

$app = require __DIR__.'/operations-bootstrap.php';
require_once __DIR__.'/../Support/BuildsMinimalRailTimeSchema.php';
if (file_exists($qaDirectory.'/operations.sqlite')) {
    throw new RuntimeException('QA database already exists; choose a new directory.');
}
touch($qaDirectory.'/operations.sqlite');
mkdir($qaDirectory.'/sessions');
mkdir($qaDirectory.'/views');
(new class
{
    use BuildsMinimalRailTimeSchema;

    public function build()
    {
        $this->buildMinimalRailTimeSchema();
    }
})->build();
(require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
(require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
$admin = User::factory()->create(['name' => 'Disposition QA', 'email' => 'disposition@example.test', 'role' => 'admin', 'password' => Hash::make(getenv('RAILTIME_OPERATIONS_QA_PASSWORD'))]);
$employee = User::factory()->create(['name' => 'Alex Sommer', 'email' => 'mitarbeiter@example.test', 'role' => 'staff', 'password' => Hash::make(getenv('RAILTIME_OPERATIONS_QA_PASSWORD'))]);
$other = User::factory()->create(['name' => 'Robin Berger', 'email' => 'robin@example.test', 'role' => 'staff']);
OperationsRuleProfile::create(['name' => 'QA-Schichtprofil', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $admin->id, 'approved_at' => now()]);
$customer = Customer::create(['company_name' => 'Nordbahn Logistik · QA', 'contact_name' => 'Kundenkontakt QA', 'is_active' => true]);
$order = Order::create(['customer_id' => $customer->id, 'title' => 'Nordkorridor', 'service_type' => 'Triebfahrzeugführer', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(8), 'required_staff' => 2, 'created_by' => $admin->id, 'updated_by' => $admin->id]);
for ($i = 0; $i < 4; $i++) {
    $shift = app(ShiftSchedulingService::class)->save(new Shift, ['order_id' => $order->id, 'title' => ['Hamburg → Hannover', 'Rangierdienst Maschen', 'Bremen → Hamburg', 'Übergabe Lehrte'][$i], 'role_name' => 'Triebfahrzeugführer', 'timezone' => 'Europe/Berlin', 'starts_at' => now()->addDays($i)->addHour(), 'ends_at' => now()->addDays($i)->addHours(9), 'required_staff' => 2, 'planned_break_minutes' => 30, 'status' => 'open', 'location_name' => ['Hamburg Hafen', 'Maschen Rbf', 'Bremen Hbf', 'Lehrte'][$i]], $admin);
    $assignment = app(ShiftAssignmentService::class)->assign($shift, $employee, $admin);
    app(PlanPublicationService::class)->publish($shift, $shift->revision, $admin);
    if ($i === 0) {
        app(PlanPublicationService::class)->respond($assignment->id, $shift->revision, true, $employee);
    }
}
$inquiryService = app(InquiryWorkflowService::class);
foreach (['email' => 'Zusatzleistung Nordkorridor', 'phone' => 'Wochenendbesetzung Maschen', 'portal' => 'Personalbedarf Oktober'] as $channel => $title) {
    $inquiryService->save(null, ['channel' => $channel, 'title' => $title, 'original' => 'Fiktiver QA-Eingang: Bitte ein Angebot für zwei Triebfahrzeugführer erstellen.', 'customer_id' => $customer->id, 'contact_name' => 'Kundenkontakt QA', 'timezone' => 'Europe/Berlin', 'starts_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d\TH:i'), 'ends_at' => now()->addDays(3)->setTime(16, 0)->format('Y-m-d\TH:i'), 'location_name' => 'Hamburg', 'role_name' => 'Triebfahrzeugführer', 'required_staff' => 2], $admin);
}
$type = QualificationType::create(['name' => 'Triebfahrzeugführerschein']);
EmployeeQualification::create(['user_id' => $employee->id, 'qualification_type_id' => $type->id, 'valid_from' => now()->subYear()->toDateString(), 'valid_until' => now()->addYear()->toDateString(), 'status' => 'pending']);
app(PersonnelWorkflowService::class)->requestAbsence($other, ['kind' => 'vacation', 'starts_at' => now()->addDays(10)->setTime(0, 0)->format('Y-m-d\TH:i'), 'ends_at' => now()->addDays(11)->setTime(0, 0)->format('Y-m-d\TH:i'), 'timezone' => 'Europe/Berlin']);
echo "Isolated Operations QA seeded.\n";
