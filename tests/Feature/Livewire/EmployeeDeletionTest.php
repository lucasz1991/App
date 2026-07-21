<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Admin\Employees;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeDeletionTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_admin_can_delete_an_employee_from_the_employee_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($admin)
            ->test(Employees::class)
            ->assertSee(__('app.delete_user'))
            ->call('deleteUser', $employee->id)
            ->assertDispatched('swal:toast');

        $this->assertModelMissing($employee);
    }

    public function test_delegated_employee_permissions_never_allow_deletion(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $employee = User::factory()->create(['role' => 'staff']);
        $team = Team::forceCreate([
            'user_id' => $staff->id,
            'name' => 'Mitarbeiter',
            'personal_team' => false,
            'rbac_permissions' => [
                'employees.view' => true,
                'employees.create' => true,
                'users.edit' => true,
            ],
        ]);

        $staff->forceFill(['current_team_id' => $team->id])->save();

        Livewire::actingAs($staff)
            ->test(Employees::class)
            ->assertDontSee(__('app.delete_user'))
            ->call('deleteUser', $employee->id)
            ->assertForbidden();

        $this->assertModelExists($employee);
    }

    public function test_admin_cannot_delete_self_or_primary_system_administrator(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Employees::class)
            ->call('deleteUser', $admin->id)
            ->assertDispatched('swal:toast')
            ->call('deleteUser', $primaryAdmin->id)
            ->assertDispatched('swal:toast');

        $this->assertModelExists($admin);
        $this->assertModelExists($primaryAdmin);
    }
}
