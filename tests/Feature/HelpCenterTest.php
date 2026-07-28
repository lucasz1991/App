<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_help_page_is_authenticated_and_contains_install_guides(): void
    {
        $this->get(route('help'))->assertRedirect(route('login'));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('help'))
            ->assertOk()
            ->assertSee(__('app.help_install_ios_title'))
            ->assertSee(__('app.help_install_android_title'))
            ->assertSee(__('app.push_install_app'))
            ->assertSee('data-help-install-control', escape: false)
            ->assertSee('data-help-install-action', escape: false)
            ->assertSee('railtimePwaInstall(', escape: false)
            ->assertDontSee(__('app.open_app_push'))
            ->assertDontSee('data-testid="push-server-diagnostics"', escape: false)
            ->assertDontSee(__('app.help_push_server'))
            ->assertDontSee(__('app.help_push_queue_worker_hint', [
                'queue' => config('webpush.queue'),
            ]))
            ->assertSee('data-help-center', escape: false);
    }

    public function test_help_page_uses_one_install_action_and_exposes_the_push_test_endpoint(): void
    {
        config([
            'webpush.enabled' => true,
            'webpush.test_enabled' => true,
        ]);
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('help'));

        $response
            ->assertOk()
            ->assertSee(__('app.push_send_test'))
            ->assertSee('x-show.important="canTest"', escape: false)
            ->assertDontSee(__('app.open_app_push'));

        $this->assertSame(
            1,
            substr_count($response->getContent(), 'data-help-install-action'),
        );
    }

    public function test_help_link_is_between_profile_and_last_support_link_in_both_sidebars(): void
    {
        foreach (['admin-sidebar.blade.php', 'user-sidebar.blade.php'] as $sidebarFile) {
            $sidebar = file_get_contents(resource_path('views/layouts/'.$sidebarFile));
            $profile = strrpos($sidebar, "route('profile.show')");
            $help = strrpos($sidebar, "route('help')");
            $support = strrpos($sidebar, "route('support')");

            $this->assertNotFalse($profile);
            $this->assertNotFalse($help);
            $this->assertNotFalse($support);
            $this->assertGreaterThan($profile, $help);
            $this->assertGreaterThan($help, $support);
        }
    }

    public function test_help_page_omits_all_push_server_status_and_configuration_details(): void
    {
        config([
            'webpush.enabled' => false,
            'webpush.vapid.subject' => null,
            'webpush.vapid.public_key' => null,
            'webpush.vapid.private_key' => null,
            'webpush.vapid.pem_file' => null,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('help'))
            ->assertOk()
            ->assertSee(__('app.help_install_ios_title'))
            ->assertSee(__('app.help_install_android_title'))
            ->assertDontSee('data-testid="push-server-diagnostics"', escape: false)
            ->assertDontSee(__('app.help_push_server'))
            ->assertDontSee(__('app.help_active_devices'))
            ->assertDontSee(__('app.help_push_issue_disabled'))
            ->assertDontSee(__('app.help_push_config_cache_hint'))
            ->assertDontSee(__('app.help_push_queue_worker_hint', [
                'queue' => config('webpush.queue'),
            ]));
    }
}
