<?php

namespace Tests\Feature;

use App\Models\User;
use Minishlink\WebPush\VAPID;
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
            ->assertSee(route('profile.show', ['tab' => 'app']))
            ->assertSee('data-help-center', escape: false);
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

    public function test_help_page_explains_each_missing_push_server_requirement_without_secrets(): void
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
            ->assertSee('data-testid="push-server-diagnostics"', escape: false)
            ->assertSee(__('app.help_push_issue_disabled'))
            ->assertSee(__('app.help_push_issue_subject_missing'))
            ->assertSee(__('app.help_push_issue_public_key_missing'))
            ->assertSee(__('app.help_push_issue_private_key_missing'))
            ->assertSee(__('app.help_push_queue_worker_hint', [
                'queue' => config('webpush.queue'),
            ]));
    }

    public function test_help_page_hides_diagnostics_for_a_ready_push_server(): void
    {
        $keys = VAPID::createVapidKeys();
        config([
            'webpush.enabled' => true,
            'webpush.vapid.subject' => 'mailto:push@example.test',
            'webpush.vapid.public_key' => $keys['publicKey'],
            'webpush.vapid.private_key' => $keys['privateKey'],
            'webpush.vapid.pem_file' => null,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('help'))
            ->assertOk()
            ->assertSee(__('app.ready'))
            ->assertDontSee('data-testid="push-server-diagnostics"', escape: false);
    }
}
