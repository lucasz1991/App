<?php

namespace Tests\Feature;

use App\Livewire\Admin\Settings\CompanySection;
use App\Models\Setting;
use App\Models\User;
use App\Support\CompanyData;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CompanyWhatsappSettingsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
        Bus::fake();
    }

    public function test_phone_notations_produce_the_same_safe_whatsapp_destination(): void
    {
        foreach ([
            '+49 160 1881848', '0049 160 1881848', '+49 (0) 160 1881848',
            '0049 (0) 160 1881848', '0160 1881848', '491601881848',
            '+49 (160) 188-18-48', '0160/1881848',
        ] as $phone) {
            $this->assertTrue(CompanyData::validWhatsappPhone($phone), $phone);
            $this->assertSame('https://wa.me/491601881848', CompanyData::whatsappHref(['whatsapp_phone' => $phone]));
        }

        $this->assertSame('https://wa.me/442079460123', CompanyData::whatsappHref(['whatsapp_phone' => '+44 20 7946 0123']));
        $this->assertSame('https://wa.me/123', CompanyData::whatsappHref(['whatsapp_phone' => '+123']));
        $this->assertSame('https://wa.me/123456789012345', CompanyData::whatsappHref(['whatsapp_phone' => '+123456789012345']));
    }

    public function test_invalid_values_cannot_become_whatsapp_urls(): void
    {
        foreach ([
            '', ' ', '+12', '12', '0', '+0123456789', '000491601881848',
            '+1234567890123456', 'https://wa.me/491601881848', 'tel:+491601881848',
            'WhatsApp +49 160 1881848', '+49+1601881848', '+49 (160 1881848',
            '+49 160)1881848', '+49 160@1881848', "+49\n1601881848", '٤٩١٦٠١٨٨١٨٤٨',
        ] as $phone) {
            $this->assertFalse(CompanyData::validWhatsappPhone($phone), $phone);
            $this->assertSame('', CompanyData::whatsappHref(['whatsapp_phone' => $phone]));
        }

        $this->assertSame('', CompanyData::whatsappHref(['whatsapp_phone' => ['491601881848']]));
    }

    public function test_save_updates_the_number_and_invalidates_the_company_cache(): void
    {
        $this->assertSame('+49 160 1881848', CompanyData::all()['whatsapp_phone']);

        CompanyData::save([...CompanyData::defaults(), 'whatsapp_phone' => '  +49 170 1234567  ']);

        $this->assertSame('+49 170 1234567', CompanyData::all()['whatsapp_phone']);
        $this->assertSame('+49 170 1234567', Setting::getValueUncached('company', 'profile')['whatsapp_phone']);
        $this->assertSame('https://wa.me/491701234567', CompanyData::whatsappHref());
        $tokens = CompanyData::templateValues();
        $this->assertSame('+49 170 1234567', $tokens['FIRMEN_WHATSAPP']);
        $this->assertSame('https://wa.me/491701234567', $tokens['FIRMEN_WHATSAPP_HREF']);
        $this->assertSame('04171 546803', $tokens['NOTFALLNUMMER']);
    }

    public function test_an_older_save_caller_preserves_the_uncached_stored_number(): void
    {
        $values = CompanyData::defaults();
        unset($values['whatsapp_phone']);
        Setting::setValue('company', 'profile', [...$values, 'whatsapp_phone' => '+49 170 1234567']);
        Cache::put('settings.company.profile', [...$values, 'whatsapp_phone' => '+49 160 1881848']);

        CompanyData::save([...$values, 'city' => 'Hamburg']);

        $stored = Setting::getValueUncached('company', 'profile');
        $this->assertSame('+49 170 1234567', $stored['whatsapp_phone']);
        $this->assertSame('Hamburg', $stored['city']);
    }

    public function test_invalid_explicit_save_values_leave_the_profile_unchanged(): void
    {
        $profile = [...CompanyData::defaults(), 'whatsapp_phone' => '+49 170 1234567'];
        Setting::setValue('company', 'profile', $profile);

        foreach (['', null, ['491601881848'], 'https://wa.me/491601881848'] as $phone) {
            try {
                CompanyData::save([...$profile, 'whatsapp_phone' => $phone]);
                $this->fail('Invalid WhatsApp input was saved.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('company.whatsapp_phone', $exception->errors());
            }
            $this->assertSame($profile, Setting::getValueUncached('company', 'profile'));
        }

        Bus::assertNothingDispatched();
    }

    public function test_form_requires_a_phone_number_and_rejects_links(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)->test(CompanySection::class)
            ->assertSee(__('app.company_whatsapp_phone'))
            ->assertSee(__('app.company_whatsapp_hint'))
            ->assertSet('company.whatsapp_phone', '+49 160 1881848')
            ->assertSee('aria-describedby="company_whatsapp_phone_hint"', escape: false)
            ->set('company.whatsapp_phone', '')
            ->call('saveCompany')
            ->assertHasErrors(['company.whatsapp_phone' => 'required'])
            ->set('company.whatsapp_phone', 'https://wa.me/491601881848')
            ->call('saveCompany')
            ->assertHasErrors(['company.whatsapp_phone'])
            ->assertSee(__('app.company_whatsapp_invalid'))
            ->set('company.whatsapp_phone', '0170 1234567')
            ->call('saveCompany')
            ->assertHasNoErrors()
            ->assertDontSee(__('app.company_whatsapp_invalid'))
            ->assertDispatched('company-settings-saved');

        $this->assertSame('https://wa.me/491701234567', CompanyData::whatsappHref());
    }

    public function test_form_keeps_the_existing_settings_permission(): void
    {
        User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)->test(CompanySection::class)->assertForbidden();

        $this->assertNull(Setting::getValueUncached('company', 'profile'));
    }

    public function test_backfill_adds_only_the_missing_key_and_clears_its_cache(): void
    {
        $profile = ['name' => 'Existing Company', 'phone' => 'CUSTOM', 'custom_key' => 'preserve'];
        Setting::setValue('company', 'profile', $profile);
        Cache::put('settings.company.profile', $profile);

        $migration = require database_path('migrations/2026_11_28_120000_add_company_whatsapp_phone.php');
        $migration->up();

        $this->assertFalse(Cache::has('settings.company.profile'));
        $this->assertSame([...$profile, 'whatsapp_phone' => '+49 160 1881848'], Setting::getValueUncached('company', 'profile'));
        $row = DB::table('settings')->where('type', 'company')->where('key', 'profile')->first();
        $this->travel(1)->hour();
        $migration->up();
        $migration->down();
        $this->assertEquals($row, DB::table('settings')->where('id', $row->id)->first());
        Bus::assertNothingDispatched();
    }

    public function test_backfill_preserves_an_existing_custom_or_empty_number(): void
    {
        $migration = require database_path('migrations/2026_11_28_120000_add_company_whatsapp_phone.php');

        foreach (['+49 170 1234567', '', null] as $phone) {
            $profile = ['custom_key' => 'preserve', 'whatsapp_phone' => $phone];
            Setting::setValue('company', 'profile', $profile);
            $migration->up();
            $this->assertSame($profile, Setting::getValueUncached('company', 'profile'));
        }
    }

    public function test_backfill_creates_the_setting_for_a_new_installation(): void
    {
        $migration = require database_path('migrations/2026_11_28_120000_add_company_whatsapp_phone.php');
        $migration->up();

        $this->assertSame(['whatsapp_phone' => '+49 160 1881848'], Setting::getValueUncached('company', 'profile'));
        $this->assertSame('https://wa.me/491601881848', CompanyData::whatsappHref());
        $this->assertSame(1, Setting::query()->where('type', 'company')->where('key', 'profile')->count());
    }

    public function test_backfill_leaves_a_malformed_profile_untouched(): void
    {
        $id = DB::table('settings')->insertGetId([
            'type' => 'company', 'key' => 'profile', 'value' => '{malformed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_11_28_120000_add_company_whatsapp_phone.php');

        try {
            $migration->up();
            $this->fail('A malformed profile must not be replaced by defaults.');
        } catch (\JsonException) {
            $this->assertSame('{malformed', DB::table('settings')->where('id', $id)->value('value'));
        }
    }
}
