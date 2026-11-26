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

        // Der Supportlink steht nicht mehr in den beiden Sidebar-Listen selbst,
        // sondern im gemeinsamen Footer, den die Huelle fuer beide Bereiche
        // nach der Liste einbindet - er bleibt damit der Abschluss der Sidebar.
        $shell = file_get_contents(resource_path('views/layouts/sidebar.blade.php'));
        $footerPosition = strpos($shell, "@include('layouts.sidebar-footer')");
        $this->assertNotFalse($footerPosition);

        foreach (['admin-sidebar', 'user-sidebar'] as $sidebarView) {
            $sidebar = file_get_contents(resource_path("views/layouts/{$sidebarView}.blade.php"));
            $includePosition = strpos($shell, "@include('layouts.{$sidebarView}')");

            $this->assertStringNotContainsString("route('support')", $sidebar, $sidebarView);
            $this->assertNotFalse($includePosition, $sidebarView);
            $this->assertGreaterThan($includePosition, $footerPosition, $sidebarView);
        }

        $footer = file_get_contents(resource_path('views/layouts/sidebar-footer.blade.php'));
        $this->assertSame(1, substr_count($footer, "route('support')"));
        $this->assertNotFalse(strpos($footer, "route('profile.show')"));
        $this->assertGreaterThan(strpos($footer, "route('help')"), strpos($footer, "route('support')"));
    }

    public function test_operational_preview_links_and_routes_are_restricted_to_global_admin_role(): void
    {
        $adminSidebar = file_get_contents(resource_path('views/layouts/admin-sidebar.blade.php'));
        $routes = file_get_contents(base_path('routes/web.php'));
        $dashboard = file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php'));

        // Die Sidebar-Eintraege entstehen in ApplicationNavigation: die
        // Vorschau-Links werden nur fuer die globale Adminrolle erzeugt.
        $navigation = file_get_contents(app_path('Support/Operations/ApplicationNavigation.php'));
        $this->assertStringContainsString("@include('layouts.application-navigation')", $adminSidebar);
        $this->assertStringContainsString('$admin = $user->isAdmin();', $navigation);
        $this->assertMatchesRegularExpression(
            '/if \(! \$ready && \$admin\) \{[^}]*\'admin\.operations\.preview\'/s',
            $navigation,
        );
        $this->assertSame(1, substr_count($navigation, "'admin.operations.preview'"));
        $this->assertStringContainsString("'verified', 'role:admin'", $routes);
        // Im Dashboard stand dieselbe Rollenpruefung noch einmal im Blade,
        // obwohl die Route bereits role:admin traegt und die Komponente
        // zusaetzlich abort_unless(isAdmin()) ausfuehrt. Die Verlinkung der
        // Betriebsmodule haengt jetzt allein an diesen beiden Schranken.
        $this->assertStringNotContainsString("auth()->user()?->role === 'admin'", $dashboard);
        // Seit Phase 5 steckt die Pruefung im gemeinsamen Concern RequiresAdmin,
        // den das Dashboard in render() aufruft.
        $dashboardComponent = file_get_contents(app_path('Livewire/Admin/Dashboard.php'));
        $this->assertStringContainsString('use RequiresAdmin;', $dashboardComponent);
        $this->assertStringContainsString('$this->adminActor();', $dashboardComponent);
        $this->assertStringContainsString(
            'abort_unless($actor instanceof User && $actor->isActive() && $actor->isAdmin(), 403);',
            file_get_contents(app_path('Livewire/Concerns/RequiresAdmin.php')),
        );

        $staff = User::factory()->create(['role' => 'staff']);

        $staffRoutes = collect(\App\Support\Operations\ApplicationNavigation::sections($staff))
            ->flatten(1)
            ->pluck('route');
        $this->assertNotContains('admin.operations.preview', $staffRoutes->all());

        $this->actingAs($staff)
            ->get(route('admin.operations.preview', ['module' => 'orders']))
            ->assertRedirect(route('dashboard'));
    }
}
