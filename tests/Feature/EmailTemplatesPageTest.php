<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\EmailTemplateBuilder;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmailTemplatesPageTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_guest_is_redirected_from_email_templates_page(): void
    {
        $this->get(route('email-templates.index'))
            ->assertRedirect(route('login'));
    }

    public function test_verified_user_sees_standalone_page_sidebar_link_and_one_global_message_viewer(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('email-templates.index'));

        $response->assertOk()
            ->assertSee(__('app.email_templates'))
            ->assertSee(route('email-templates.download', ['template' => 'vorlage-eml']), escape: false)
            ->assertSee('data-menu-active="true"', escape: false);

        $this->assertSame(1, substr_count($response->getContent(), 'data-testid="message-viewer-host"'));
    }

    public function test_profile_no_longer_contains_an_email_templates_tab(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertDontSee('id="tab-templates"', escape: false)
            ->assertDontSee('id="panel-templates"', escape: false);
    }

    public function test_admin_sees_email_templates_as_active_own_sidebar_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('email-templates.index'))
            ->assertOk()
            ->assertSee(route('email-templates.index'), escape: false)
            ->assertSee('data-menu-active="true"', escape: false);
    }

    public function test_personalized_template_can_be_downloaded_and_unknown_key_returns_404(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);

        $response = $this->actingAs($user)
            ->get(route('email-templates.download', ['template' => 'signatur-text']));

        $response->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertSee('Mara Beispiel');

        $this->assertStringContainsString(
            'attachment; filename="RailTime-Signatur-mara-beispiel.txt"',
            (string) $response->headers->get('content-disposition')
        );

        $this->actingAs($user)
            ->get(route('email-templates.download', ['template' => 'unbekannt']))
            ->assertNotFound();
    }

    public function test_downloadable_mail_templates_have_no_top_image_and_keep_the_footer_logo(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $builder = new EmailTemplateBuilder($user);

        $html = $builder->build('vorlage-html')['content'];
        $eml = $builder->build('vorlage-eml')['content'];

        $this->assertStringNotContainsString('hero-railtime', $html);
        $this->assertStringNotContainsString('{{HERO_SRC}}', $html);
        $this->assertSame(1, substr_count($html, '<img '));
        $this->assertStringContainsString('class="rt-logo"', $html);

        $this->assertStringNotContainsString('railtime-hero', $eml);
        $this->assertStringContainsString('Content-ID: <railtime-logo>', $eml);
    }
}
