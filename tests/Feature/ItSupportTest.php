<?php

namespace Tests\Feature;

use App\Jobs\ProcessMailJob;
use App\Livewire\ItSupport;
use App\Models\Mail as MailModel;
use App\Models\Setting;
use App\Models\SupportCase;
use App\Models\Team;
use App\Models\User;
use App\Support\SupportRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class ItSupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_employee_can_send_a_support_request_to_the_configured_admin_address(): void
    {
        config()->set('mail.super_admin', 'fallback@example.test');
        Setting::setValue('mails', 'admin_email', 'support@example.test');

        $owner = User::factory()->create(['role' => 'admin']);
        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Mitarbeiter',
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $employee = User::factory()->create([
            'name' => 'Mara Muster',
            'email' => 'mara@example.test',
            'role' => 'staff',
            'current_team_id' => $team->id,
        ]);
        $employee->teams()->attach($team->id);

        RateLimiter::clear('it-support:'.$employee->id);

        Livewire::actingAs($employee)
            ->test(ItSupport::class)
            ->set('category', 'technical_issue')
            ->set('subject', 'Download funktioniert nicht')
            ->set('message', 'Beim Öffnen der aktuellen Wagenliste erscheint wiederholt eine Fehlermeldung.')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('sent', true)
            ->assertSet('subject', '')
            ->assertSet('message', '')
            ->assertDispatched('swal:toast');

        $mail = MailModel::query()->sole();
        $content = $mail->content;

        $this->assertSame('mail', $mail->type);
        $this->assertSame('support@example.test', $mail->recipients[0]['email']);
        $case = SupportCase::query()->sole();
        $this->assertSame($employee->id, $case->user_id);
        $this->assertSame('Download funktioniert nicht', $case->subject);
        $this->assertSame('Beim Öffnen der aktuellen Wagenliste erscheint wiederholt eine Fehlermeldung.', $case->messages()->sole()->body);
        $this->assertStringContainsString($case->public_id, $content['link']);
        $this->assertStringNotContainsString($case->subject, json_encode($content));
        $this->assertArrayNotHasKey('reply_to', $content);

        Queue::assertPushed(
            ProcessMailJob::class,
            fn (ProcessMailJob $job): bool => $job->mail->is($mail)
        );
    }

    public function test_support_recipient_uses_environment_then_global_admin_as_fallbacks(): void
    {
        config()->set('mail.super_admin', 'superadmin@example.test');

        $this->assertSame('superadmin@example.test', SupportRecipient::resolve());

        config()->set('mail.super_admin', null);
        $admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'global-admin@example.test',
        ]);

        $this->assertSame($admin->email, SupportRecipient::resolve());
    }

    public function test_support_page_is_authenticated_and_is_the_last_link_in_both_sidebars(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->get(route('support'))->assertRedirect(route('login'));

        $this->actingAs($staff)
            ->get(route('support'))
            ->assertOk()
            ->assertSee(__('app.it_support'));

        $this->actingAs($admin)
            ->get(route('support'))
            ->assertOk()
            ->assertSee(__('app.it_support'));

        foreach (['admin-sidebar.blade.php', 'user-sidebar.blade.php'] as $sidebarFile) {
            $sidebar = file_get_contents(resource_path('views/layouts/'.$sidebarFile));
            $supportPosition = strrpos($sidebar, "route('support')");
            $profilePosition = strrpos($sidebar, "route('profile.show')");

            $this->assertNotFalse($supportPosition);
            $this->assertNotFalse($profilePosition);
            $this->assertGreaterThan($profilePosition, $supportPosition);
        }
    }

    public function test_operational_preview_links_and_routes_are_restricted_to_global_admin_role(): void
    {
        $adminSidebar = file_get_contents(resource_path('views/layouts/admin-sidebar.blade.php'));
        $routes = file_get_contents(base_path('routes/web.php'));
        $dashboard = file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php'));

        $this->assertStringContainsString("auth()->user()?->role === 'admin'", $adminSidebar);
        $this->assertStringContainsString("'verified', 'role:admin'", $routes);
        // Im Dashboard stand dieselbe Rollenpruefung noch einmal im Blade,
        // obwohl die Route bereits role:admin traegt und die Komponente
        // zusaetzlich abort_unless(isAdmin()) ausfuehrt. Die Verlinkung der
        // Betriebsmodule haengt jetzt allein an diesen beiden Schranken.
        $this->assertStringNotContainsString("auth()->user()?->role === 'admin'", $dashboard);
        $this->assertStringContainsString(
            'abort_unless(auth()->user()?->isAdmin(), 403)',
            file_get_contents(app_path('Livewire/Admin/Dashboard.php')),
        );

        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)
            ->get(route('admin.operations.preview', ['module' => 'orders']))
            ->assertRedirect(route('dashboard'));
    }
}
