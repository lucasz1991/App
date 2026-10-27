<?php

namespace Tests\Feature;

use App\Livewire\Profile\UpdateContactInformationForm;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OwnProfilePresentationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
        Http::preventStrayRequests();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T10:00:00Z'));
        app()->setLocale('de');
        config(['webpush.enabled' => false, 'webpush.test_enabled' => false]);

        $this->user = User::factory()->create([
            'name' => 'Own Profile Fixture',
            'email' => 'own-profile@example.invalid',
            'role' => 'staff',
            'status' => true,
        ]);
    }

    public function test_all_thirteen_contact_fields_save_only_to_the_authenticated_profile(): void
    {
        $ownProfile = UserProfile::create([
            'user_id' => $this->user->id,
            'first_name' => 'Original',
            'position' => 'Wagenmeister',
            'personnel_nr' => 'INTERNAL-001',
        ]);
        $otherUser = User::factory()->create();
        $otherProfile = UserProfile::create([
            'user_id' => $otherUser->id,
            'first_name' => 'Other employee',
            'position' => 'Dispatcher',
        ]);
        $otherOriginal = $otherProfile->fresh()->getAttributes();

        $component = Livewire::actingAs($this->user)
            ->test(UpdateContactInformationForm::class)
            ->assertSet('first_name', 'Original');
        foreach ($this->contactValues() as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('contact-saved', fields: array_keys($this->contactValues()));

        $this->assertContactValues($ownProfile->fresh(), $this->contactValues());
        $this->assertSame('Wagenmeister', $ownProfile->fresh()->position);
        $this->assertSame('INTERNAL-001', $ownProfile->fresh()->personnel_nr);
        $this->assertSame($otherOriginal, $otherProfile->fresh()->getAttributes());
        $this->assertSame('Own Profile Fixture', $this->user->fresh()->name);
        $this->assertSame('own-profile@example.invalid', $this->user->fresh()->email);
        $this->assertDatabaseCount('user_profiles', 2);
        Http::assertNothingSent();
    }

    public function test_mount_restores_existing_values_and_empty_optional_fields_remain_clearable(): void
    {
        $profile = UserProfile::create(['user_id' => $this->user->id] + $this->contactValues());
        $component = Livewire::actingAs($this->user)->test(UpdateContactInformationForm::class);

        foreach ($this->contactValues() as $field => $value) {
            $component->assertSet($field, $value)->set($field, '');
        }
        $component->call('save')->assertHasNoErrors()->assertDispatched('contact-saved');

        $this->assertContactValues($profile->fresh(), array_fill_keys(array_keys($this->contactValues()), null));
        $this->assertDatabaseCount('user_profiles', 1);
    }

    #[DataProvider('protectedProperties')]
    public function test_contact_form_cannot_be_retargeted_or_edit_the_administrative_position(string $property, mixed $value): void
    {
        $profile = UserProfile::create(['user_id' => $this->user->id, 'position' => 'Wagenmeister']);
        $component = Livewire::actingAs($this->user)->test(UpdateContactInformationForm::class);

        try {
            $component->set($property, $value);
            $this->fail('A non-public contact property was accepted: '.$property);
        } catch (PublicPropertyNotFoundException $exception) {
            $this->assertStringContainsString($property, $exception->getMessage());
        }

        $this->assertSame('Wagenmeister', $profile->fresh()->position);
        $this->assertSame($this->user->id, $profile->fresh()->user_id);
        $this->assertDatabaseCount('user_profiles', 1);
    }

    public static function protectedProperties(): array
    {
        return [
            'administrative position' => ['position', 'Changed through contact form'],
            'another employee id' => ['userId', 99999],
        ];
    }

    public function test_invalid_birth_date_does_not_partially_save_and_reveals_its_collapsed_group(): void
    {
        $profile = UserProfile::create([
            'user_id' => $this->user->id,
            'first_name' => 'Before validation',
            'birth_date' => '1991-04-20',
        ]);
        $component = Livewire::actingAs($this->user)
            ->test(UpdateContactInformationForm::class)
            ->set('first_name', 'Must not persist')
            ->set('birth_date', '2026-10-16')
            ->call('save')
            ->assertHasErrors(['birth_date' => 'before_or_equal'])
            ->assertNotDispatched('contact-saved');

        $this->assertSame('Before validation', $profile->fresh()->first_name);
        $this->assertSame('1991-04-20', $profile->fresh()->birth_date->format('Y-m-d'));
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//*[@id="birth-date-error" and @role="alert"]'));
        $this->assertCount(1, $xpath->query('//*[@x-init="openAccordionSection = \'details\'"]'));
    }

    public function test_three_contact_groups_share_one_form_and_keep_every_native_input_and_autosave_binding(): void
    {
        UserProfile::create(['user_id' => $this->user->id, 'position' => 'Wagenmeister']);
        $component = Livewire::actingAs($this->user)->test(UpdateContactInformationForm::class)
            ->assertSee('Wagenmeister')->assertDontSee(__('app.position_managed_by_administration'));
        $xpath = $this->xpath($component->html());
        $forms = $xpath->query('//form');
        $this->assertCount(1, $forms);
        $this->assertSame('save', $forms->item(0)->getAttribute('wire:submit'));
        $this->assertTrue($forms->item(0)->hasAttribute('data-autosave-scope'));
        $this->assertSame('personal', $forms->item(0)->getAttribute('data-profile-accordion'));
        $this->assertCount(13, $xpath->query('//form//input'));

        $expectedGroups = [
            'contact' => ['first_name', 'last_name', 'phone', 'mobile'],
            'address' => ['street', 'postal_code', 'city', 'country'],
            'details' => ['birth_date', 'birth_place', 'birth_name', 'nationality', 'education'],
        ];
        $groups = $xpath->query('//form/section[@data-rt-accordion-section]');
        $this->assertCount(3, $groups);
        foreach ($groups as $group) {
            $key = $group->getAttribute('data-rt-accordion-section');
            $this->assertArrayHasKey($key, $expectedGroups);
            $trigger = $xpath->query('.//button[@aria-controls]', $group)->item(0);
            $panel = $xpath->query('.//*[@id="'.$trigger->getAttribute('aria-controls').'"]', $group);
            $this->assertCount(1, $panel);
            $this->assertSame($trigger->getAttribute('id'), $panel->item(0)->getAttribute('aria-labelledby'));

            foreach ($expectedGroups[$key] as $field) {
                $inputs = $xpath->query('.//input[@id="'.$field.'"]', $group);
                $this->assertCount(1, $inputs);
                $this->assertSame($field, $inputs->item(0)->getAttribute('wire:model'));
                $this->assertSame($field, $inputs->item(0)->getAttribute('data-autosave-model'));
                $this->assertCount(1, $xpath->query('.//label[@for="'.$field.'"]', $group));
            }
        }
        $this->assertCount(0, $xpath->query('//input[@id="position" or @name="position"]'));
        $this->assertStringContainsString('contact-saved', $component->html());
    }

    #[DataProvider('profileTabs')]
    public function test_profile_retains_native_tabs_lazy_loading_and_query_alias_with_its_own_outlook_tab(?string $queryTab, string $readyTab): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('profile.show', $queryTab === null ? [] : ['tab' => $queryTab]))
            ->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//*[@data-rt-shell-topbar]'));
        $this->assertCount(1, $xpath->query('//*[@data-profile-identity]'));
        $this->assertCount(0, $xpath->query('//*[@data-page-header]'));
        $roots = $xpath->query('//*[@data-rt-tabs-root]');
        $this->assertCount(1, $roots);
        $config = $roots->item(0)->getAttribute('x-data');
        $this->assertStringContainsString('railtimeTabs(', $config);
        $this->assertStringContainsString(".as('own-profile.tabs')", $config);
        $this->assertStringContainsString('forceDefault: '.($queryTab === null ? 'false' : 'true'), $config);
        $this->assertStringContainsString('sessions', $config);
        if ($queryTab !== 'unknown-tab') {
            $this->assertStringContainsString("initial: '".$readyTab."'", $config);
        }

        $panels = $xpath->query('//*[@data-rt-tabs-root]//*[@role="tabpanel"]');
        $this->assertSame(['personal', 'security', 'app', 'outlook'], array_map(
            static fn ($panel): string => $panel->getAttribute('data-tab-panel-id'),
            iterator_to_array($panels),
        ));
        foreach ($panels as $panel) {
            $tab = $panel->getAttribute('data-tab-panel-id');
            $this->assertSame('tab-'.$tab, $panel->getAttribute('aria-labelledby'));
            $this->assertStringContainsString($tab, $panel->getAttribute(':inert'));
            $loaders = $xpath->query('.//*[@data-profile-tab-loader="'.$tab.'"]', $panel);
            $this->assertCount(1, $loaders);
            $this->assertSame($tab === $readyTab ? 'true' : 'false', $loaders->item(0)->getAttribute('data-ready'));
        }
        $picker = $xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " rt-tabs-picker-trigger ")]');
        $this->assertCount(0, $picker);
        $this->assertCount(1, $xpath->query('//*[@role="tablist" and contains(@class,"rt-tabs-shell")]'));
        $this->assertCount(0, $xpath->query('//*[contains(@class,"rt-tabs-underline")]'));
        Http::assertNothingSent();
    }

    public static function profileTabs(): array
    {
        return [
            'default personal' => [null, 'personal'],
            'explicit personal' => ['personal', 'personal'],
            'security' => ['security', 'security'],
            'settings' => ['app', 'app'],
            'outlook' => ['outlook', 'outlook'],
            'legacy sessions link' => ['sessions', 'security'],
            'invalid query falls back' => ['unknown-tab', 'personal'],
        ];
    }

    public function test_profile_route_requires_authentication(): void
    {
        $this->get(route('profile.show'))->assertRedirect(route('login'));
        $this->assertDatabaseCount('user_profiles', 0);
    }

    private function contactValues(): array
    {
        return [
            'first_name' => 'Mara',
            'last_name' => 'Testperson',
            'phone' => '+49 30 123456',
            'mobile' => '+49 170 123456',
            'street' => 'Testweg 12',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country' => 'Deutschland',
            'birth_date' => '1991-04-20',
            'birth_place' => 'Hamburg',
            'birth_name' => 'Beispiel',
            'nationality' => 'deutsch',
            'education' => 'Abitur',
        ];
    }

    private function assertContactValues(UserProfile $profile, array $expected): void
    {
        foreach ($expected as $field => $value) {
            $actual = $field === 'birth_date'
                ? $profile->birth_date?->format('Y-m-d')
                : $profile->getAttribute($field);
            $this->assertSame($value, $actual, 'Unexpected persisted contact field: '.$field);
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
