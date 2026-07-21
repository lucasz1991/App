<?php

namespace Tests\Feature;

use App\Livewire\Admin\Settings;
use App\Mail\StaffInvitationMail;
use App\Models\Setting;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\CustomResetPasswordNotification;
use App\Support\CompanyData;
use App\Support\EmailTemplateBuilder;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CompanySettingsAndMailTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_admin_can_manage_central_company_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->assertSee(__('app.company_data'))
            ->assertSee('fa-save', escape: false)
            ->assertDontSee('fa-floppy-disk', escape: false)
            ->set('company.name', 'RailTime Test GmbH')
            ->set('company.street', 'Testweg 12')
            ->set('company.postal_code', '12345')
            ->set('company.city', 'Teststadt')
            ->set('company.country', 'Deutschland')
            ->set('company.email', 'zentrale@example.test')
            ->set('company.website', 'https://example.test')
            ->set('company.vat_id', 'DE123456789')
            ->call('saveCompany')
            ->assertHasNoErrors()
            ->assertDispatched('swal:toast');

        $stored = Setting::getValueUncached('company', 'profile');

        $this->assertSame('RailTime Test GmbH', $stored['name']);
        $this->assertSame('DE123456789', $stored['vat_id']);
    }

    public function test_company_data_is_used_in_downloadable_templates_and_system_mail_footer(): void
    {
        CompanyData::save(array_merge(CompanyData::defaults(), [
            'name' => 'RailTime Test GmbH',
            'street' => 'Testweg 12',
            'postal_code' => '12345',
            'city' => 'Teststadt',
            'email' => 'zentrale@example.test',
            'vat_id' => 'DE123456789',
            'tax_number' => '12/345/67890',
        ]));

        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $signature = (new EmailTemplateBuilder($user))->build('signatur-text')['content'];
        $htmlSignature = (new EmailTemplateBuilder($user))->build('signatur-hell')['content'];

        $this->assertStringContainsString('RailTime Test GmbH', $signature);
        $this->assertStringContainsString('Testweg 12', $signature);
        $this->assertStringContainsString('USt-IdNr.: DE123456789', $signature);
        $this->assertStringContainsString('Steuernummer: 12/345/67890', $signature);
        $this->assertStringContainsString('RailTime Test GmbH', $htmlSignature);
        $this->assertStringContainsString('DE123456789', $htmlSignature);
        $this->assertStringNotContainsString('{{FIRMENNAME}}', $htmlSignature);

        $mailHtml = (new CustomResetPasswordNotification($user, 'token'))
            ->toMail($user)
            ->render()
            ->toHtml();

        $this->assertStringContainsString('RailTime Test GmbH', $mailHtml);
        $this->assertStringContainsString('Testweg 12', $mailHtml);
        $this->assertStringContainsString('Sie haben angefordert', $mailHtml);
    }

    public function test_staff_invitation_uses_formal_address_and_company_footer(): void
    {
        CompanyData::save(array_merge(CompanyData::defaults(), [
            'name' => 'RailTime Test GmbH',
            'street' => 'Testweg 12',
            'postal_code' => '12345',
            'city' => 'Teststadt',
        ]));

        $inviter = User::factory()->create(['name' => 'Alex Verwaltung']);
        $invitation = new StaffInvitation([
            'email' => 'mitarbeiter@example.test',
            'role' => 'staff',
            'token' => str_repeat('a', 64),
            'expires_at' => now()->addDays(7),
        ]);
        $invitation->setRelation('inviter', $inviter);

        $html = (new StaffInvitationMail($invitation))->render();

        $this->assertStringContainsString('Sie wurden zu', $html);
        $this->assertStringContainsString('hat Sie eingeladen', $html);
        $this->assertStringContainsString('Klicken Sie', $html);
        $this->assertStringContainsString('RailTime Test GmbH', $html);
        $this->assertStringNotContainsString('Du wurdest', $html);
    }
}
