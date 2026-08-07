<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\CompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyDataOfficialProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_use_the_confirmed_public_company_profile_without_inventing_a_tax_number(): void
    {
        $defaults = CompanyData::defaults();

        $this->assertSame('04171 546803', $defaults['phone']);
        $this->assertSame('04171 546803', $defaults['emergency_phone']);
        $this->assertSame('info@rail-time.de', $defaults['email']);
        $this->assertSame('https://www.rail-time.de', $defaults['website']);
        $this->assertSame('Amtsgericht Tostedt', $defaults['register_court']);
        $this->assertSame('204604', $defaults['commercial_register_number']);
        $this->assertSame('DE169651368', $defaults['vat_id']);
        $this->assertSame('', $defaults['tax_number']);
    }

    public function test_profile_upgrade_replaces_only_obsolete_or_empty_values(): void
    {
        Setting::query()->where('type', 'company')->where('key', 'profile')->delete();
        Setting::setValue('company', 'profile', [
            ...CompanyData::defaults(),
            'phone' => '+49 4171 999999',
            'emergency_phone' => '0160 1881848',
            'email' => 'kontakt@rail-time.de',
            'website' => (string) config('app.url'),
            'managing_directors' => '',
            'tax_number' => 'CUSTOM-TAX',
        ]);

        $migration = include database_path('migrations/2026_08_07_000010_upgrade_official_company_profile.php');
        $migration->up();

        $profile = CompanyData::all(uncached: true);
        $this->assertSame('+49 4171 999999', $profile['phone']);
        $this->assertSame('04171 546803', $profile['emergency_phone']);
        $this->assertSame('info@rail-time.de', $profile['email']);
        $this->assertSame('https://www.rail-time.de', $profile['website']);
        $this->assertSame('Durim Morina und Mazan Moslehe', $profile['managing_directors']);
        $this->assertSame('CUSTOM-TAX', $profile['tax_number']);
    }
}
