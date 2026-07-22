<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

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
}
