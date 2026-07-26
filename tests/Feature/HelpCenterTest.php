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
}
