<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Support\WagonListWorkbookExporter;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;
use ZipArchive;

class WagonListPrototypeTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function test_employee_can_open_prototype_guest_cannot_and_admin_uses_administrator_url(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $employeeTeam = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Mitarbeiter',
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $employee = User::factory()->create(['role' => 'staff', 'current_team_id' => $employeeTeam->id]);
        $employee->teams()->attach($employeeTeam);
        $guestTeam = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Gäste',
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $guest = User::factory()->create(['role' => 'staff', 'current_team_id' => $guestTeam->id]);
        $guest->teams()->attach($guestTeam);

        $this->actingAs($employee)
            ->get(route('operations.wagon-list'))
            ->assertOk()
            ->assertSee('rt-wagon-list-prototype:v1:'.$employee->id, escape: false)
            ->assertSee('data-wagon-demo-notice', escape: false);

        $this->actingAs($guest)->get(route('operations.wagon-list'))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.operations.wagon-list'))->assertOk();
        $this->actingAs($owner)
            ->get(route('operations.wagon-list'))
            ->assertRedirect(route('admin.operations.wagon-list'));
    }

    public function test_prototype_has_forty_wagon_limit_calculations_and_no_database_persistence_layer(): void
    {
        $script = file_get_contents(resource_path('js/wagon-list-prototype.js'));
        $view = file_get_contents(resource_path('views/livewire/operations/wagon-list-prototype.blade.php'));

        $this->assertStringContainsString('const MAX_WAGONS = 40', $script);
        $this->assertStringContainsString('expectedCheckDigit', $script);
        $this->assertStringContainsString('deductionP19', $script);
        $this->assertStringContainsString('localStorage.setItem', $script);
        $this->assertStringContainsString('localStorage.removeItem', $script);
        $this->assertStringContainsString("__('app.wagon_list')", $view);
        $this->assertStringContainsString("__('app.brake_sheet')", $view);
        $this->assertStringContainsString('wagon-sheet-grid', $view);
        $this->assertStringContainsString('data-mobile-wagon-editor', $view);
        $this->assertStringContainsString('focusNextCell', $script);
        $this->assertStringContainsString('mobileWagon', $script);
        $this->assertFileDoesNotExist(app_path('Models/WagonList.php'));
        $this->assertFileDoesNotExist(app_path('Models/Wagon.php'));
    }

    public function test_employee_can_export_the_browser_draft_into_the_original_excel_layout(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $employeeTeam = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Mitarbeiter',
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $employee = User::factory()->create(['role' => 'staff', 'current_team_id' => $employeeTeam->id]);
        $employee->teams()->attach($employeeTeam);

        $payload = [
            'meta' => [
                'trainNumber' => '4711',
                'date' => '2026-07-24',
                'origin' => 'Berlin',
                'destination' => 'Hamburg',
                'reference' => 'RT-2026-0815',
            ],
            'wagons' => [[
                'number12' => '80',
                'number34' => '80',
                'number58' => '1234',
                'number911' => '567',
                'checkDigit' => '8',
                'category' => 'Habbiins',
                'axlesEmpty' => 4,
                'axlesLoaded' => 0,
                'length' => 21.5,
                'wagonWeight' => 24.3,
                'loadWeight' => 31.7,
                'brakeG' => 38,
                'brakeP' => 44,
                'shippingStation' => 'Berlin',
                'destinationStation' => 'Hamburg',
                'brakeType' => 'K',
                'discBrake' => true,
                'parkingBrake' => 20,
                'maxSpeed' => 120,
                'remark' => 'Testwagen',
            ]],
            'brakeSheet' => [
                'tractionWeight' => 80,
                'tractionBrakeWeight' => 72,
                'tractionAxles' => 4,
                'minimumBrakePercentage' => 80,
                'brakedAxles' => 4,
                'lowerVehicleSpeed' => 100,
                'nbuepBrake' => 'yes',
                'dangerousGoods' => 'no',
                'issuerName' => 'Max Mustermann',
            ],
        ];

        $this->actingAs($employee)
            ->postJson(route('operations.wagon-list.export'), $payload)
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('RailTime_Wagenliste_4711_2026-07-24.xlsx');

        $path = app(WagonListWorkbookExporter::class)->export($payload);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        try {
            $wagonSheet = $this->xlsxCells($zip->getFromName('xl/worksheets/sheet1.xml'));
            $brakeSheet = $this->xlsxCells($zip->getFromName('xl/worksheets/sheet2.xml'));

            $this->assertSame('4711', $wagonSheet['C2']);
            $this->assertSame('46227', $wagonSheet['K2']);
            $this->assertSame('Berlin', $wagonSheet['C3']);
            $this->assertSame('Hamburg', $wagonSheet['K3']);
            $this->assertSame('80', $wagonSheet['B7']);
            $this->assertSame('Habbiins', $wagonSheet['G7']);
            $this->assertSame('56', $wagonSheet['M7']);
            $this->assertSame('D', $wagonSheet['S7']);
            $this->assertSame('ja', $brakeSheet['E29']);
            $this->assertSame('nein', $brakeSheet['E36']);
            $this->assertStringContainsString('Max Mustermann', $brakeSheet['A38']);
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    private function xlsxCells(string $xml): array
    {
        $document = new DOMDocument();
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cells = [];

        foreach ($xpath->query('//x:c') as $cell) {
            $reference = $cell->getAttribute('r');
            $inline = $xpath->query('./x:is/x:t', $cell)->item(0);
            $value = $xpath->query('./x:v', $cell)->item(0);
            $cells[$reference] = $inline?->textContent ?? $value?->textContent ?? '';
        }

        return $cells;
    }
}
