<?php

namespace Tests\Feature;

use App\Livewire\UserDashboard;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class RoleDashboardTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
    }

    public function test_employee_dashboard_is_work_oriented_and_links_to_the_wagon_list(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $team = $this->team($owner, 'Mitarbeiter');
        $employee = User::factory()->create(['role' => 'staff', 'current_team_id' => $team->id]);

        Livewire::actingAs($employee)
            ->test(UserDashboard::class)
            ->assertSee(__('app.next_order'))
            ->assertSee('RT-2407')
            ->assertSee('DGS 69342')
            ->assertSee(__('app.work_checklist'))
            ->assertSee(route('operations.wagon-list'))
            ->assertDontSee('data-system-dashboard', escape: false);
    }

    public function test_guest_dashboard_only_contains_information_and_no_work_assignment(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $team = $this->team($owner, 'Gäste');
        $guest = User::factory()->create(['role' => 'staff', 'current_team_id' => $team->id]);

        Livewire::actingAs($guest)
            ->test(UserDashboard::class)
            ->assertSee(__('app.available_files'))
            ->assertSee(__('app.news_and_information'))
            ->assertDontSee('RT-2407')
            ->assertDontSee('DGS 69342')
            ->assertDontSee(__('app.open_wagon_list'))
            ->assertDontSee('data-system-dashboard', escape: false);
    }

    private function team(User $owner, string $name): Team
    {
        return Team::forceCreate([
            'user_id' => $owner->id,
            'name' => $name,
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
    }
}
