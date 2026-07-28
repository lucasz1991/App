<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Admin\UserProfile as AdminUserProfile;
use App\Livewire\People\PersonPreviewModal;
use App\Models\Chat;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\Rbac\RbacCatalog;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PersonPreviewModalTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_employee_can_open_a_minimal_preview_of_an_active_colleague(): void
    {
        $viewer = User::factory()->create(['role' => 'staff']);
        $colleague = User::factory()->create([
            'role' => 'staff',
            'name' => 'Mara Beispiel',
            'email' => 'mara@example.test',
            'status' => true,
        ]);
        UserProfile::create([
            'user_id' => $colleague->id,
            'position' => 'Lokführerin',
            'phone' => '0176 12345678',
            'iban' => 'DE001234567890',
        ]);

        Livewire::actingAs($viewer)
            ->test(PersonPreviewModal::class)
            ->call('open', $colleague->id)
            ->assertSet('isOpen', true)
            ->assertSet('personId', $colleague->id)
            ->assertSee('Mara Beispiel')
            ->assertSee('Lokführerin')
            ->assertSee('mailto:mara@example.test', false)
            ->assertDontSee('0176 12345678')
            ->assertDontSee('DE001234567890');
    }

    public function test_employee_can_start_or_restore_a_direct_chat_from_the_preview(): void
    {
        $viewer = User::factory()->create(['role' => 'staff']);
        $colleague = User::factory()->create(['role' => 'staff', 'status' => true]);

        $component = Livewire::actingAs($viewer)
            ->test(PersonPreviewModal::class)
            ->call('open', $colleague->id);

        $component->call('startChat');
        $chat = Chat::query()->where('type', 'direct')->sole();

        $component->assertRedirect(route('chat', ['chat' => $chat->id]));
        $this->assertEqualsCanonicalizing(
            [$viewer->id, $colleague->id],
            $chat->participants()->pluck('users.id')->all(),
        );
    }

    public function test_regular_employee_cannot_preview_an_inactive_account_but_management_can(): void
    {
        $employee = User::factory()->create(['role' => 'staff']);
        $inactive = User::factory()->create(['role' => 'staff', 'status' => false]);

        Livewire::actingAs($employee)
            ->test(PersonPreviewModal::class)
            ->call('open', $inactive->id)
            ->assertForbidden();

        $manager = $this->userInTeam('Verwaltung');

        Livewire::actingAs($manager)
            ->test(PersonPreviewModal::class)
            ->call('open', $inactive->id)
            ->assertSet('isOpen', true)
            ->assertSet('personId', $inactive->id);
    }

    public function test_full_profile_stays_for_administration_even_with_a_delegated_view_flag(): void
    {
        $target = User::factory()->create(['role' => 'staff']);
        $employee = $this->userInTeam('Mitarbeiter', [
            'employees.view' => true,
        ]);

        Livewire::actingAs($employee)
            ->test(AdminUserProfile::class, ['userId' => $target->id])
            ->assertForbidden();
    }

    public function test_chat_header_and_employee_list_use_the_anchored_preview(): void
    {
        $chat = file_get_contents(resource_path('views/livewire/chat-box.blade.php'));
        $chatList = file_get_contents(resource_path('views/livewire/chat/partials/chat-list.blade.php'));
        $chatHeader = file_get_contents(resource_path('views/livewire/chat/partials/conversation-header.blade.php'));
        $newChat = file_get_contents(resource_path('views/livewire/chat/partials/new-chat-modal.blade.php'));
        $employees = file_get_contents(resource_path('views/livewire/admin/employees.blade.php'));
        $employeeRow = file_get_contents(resource_path('views/components/tables/rows/employees/employee-row.blade.php'));
        $employeeActions = file_get_contents(resource_path('views/components/tables/rows/employees/employee-actions.blade.php'));

        $this->assertStringContainsString('livewire:people.person-preview-modal', $chat);
        $this->assertStringNotContainsString('livewire:people.person-preview-modal', $employees);
        $this->assertStringContainsString('person-anchor-preview', $employeeRow);
        $this->assertStringNotContainsString('person-preview:open', $employeeRow);
        $this->assertStringContainsString('canViewManagementDashboard()', $employees);
        $this->assertStringContainsString('person-preview:open', $chatList);
        $this->assertStringContainsString('<x-user.person-anchor-preview :user="$headerPerson">', $chatHeader);
        $this->assertStringNotContainsString('person-preview:open', $chatHeader);
        $this->assertStringContainsString('data-no-chat-swipe', $chatHeader);
        $this->assertStringContainsString('person-preview:open', $newChat);
        $this->assertStringNotContainsString('person-preview:open', $employeeActions);
        $this->assertStringContainsString('canViewManagementDashboard()', $employeeActions);
    }

    /**
     * @param  array<string, bool>  $permissionOverrides
     */
    private function userInTeam(string $teamName, array $permissionOverrides = []): User
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => $teamName,
            'personal_team' => false,
            'rbac_permissions' => array_replace(
                RbacCatalog::defaultTeamPermissions(),
                $permissionOverrides,
            ),
        ]);
        $user = User::factory()->create([
            'role' => 'staff',
            'current_team_id' => $team->id,
        ]);
        $user->teams()->attach($team);

        return $user;
    }
}
