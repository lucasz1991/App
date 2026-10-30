<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Livewire\Profile\OutlookPreferences;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinWordingPolicy;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookProfileWordingTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->buildMinimalRailTimeSchema();
        Http::preventStrayRequests();
        config(['outlook_addin.entra.tenant_id' => '11111111-1111-4111-8111-111111111111']);
        Schema::create('employee_identity_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('external_id')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('principal');
            $table->string('email')->nullable();
            $table->string('lifecycle_status');
            $table->timestamps();
        });
        Schema::create('activity_log', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true, 'email_verified_at' => now()]);
        $this->other = User::factory()->create(['role' => 'staff', 'status' => true, 'email_verified_at' => now()]);
        $this->actingAs($this->employee);
    }

    public function test_explicit_false_flags_are_recorded_once_but_old_noop_clients_still_write_nothing(): void
    {
        $policy = app(OutlookAddinWordingPolicy::class);
        $current = $policy->readForUser($this->employee);
        $policy->updateForSession($this->employee, $current['greeting'], $current['farewell'], $current['revision']);
        self::assertSame(0, Setting::query()->count());
        $current = $policy->updateForSession($this->employee, $current['greeting'], $current['farewell'], $current['revision'], false, false);
        $stored = Setting::getValueUncached('outlook_addin', $this->key());
        self::assertFalse($stored['showGreeting']);
        self::assertFalse($stored['showFarewell']);
        $before = DB::table('settings')->get()->toJson();
        $policy->updateForSession($this->employee, $current['greeting'], $current['farewell'], $current['revision'], false, false);
        self::assertSame($before, DB::table('settings')->get()->toJson());
    }

    public function test_visibility_is_independent_and_toggle_preserves_unsaved_text_drafts_and_old_client_flags(): void
    {
        $component = Livewire::test(OutlookPreferences::class)->assertSet('showGreeting', false)->assertSet('showFarewell', false);
        $component->set('greeting', 'Noch nicht gespeichert,')->call('toggleWordingVisibility', 'showGreeting')
            ->assertSet('showGreeting', true)->assertSet('showFarewell', false)->assertSet('greeting', 'Noch nicht gespeichert,');
        $policy = app(OutlookAddinWordingPolicy::class);
        $stored = $policy->readForUser($this->employee);
        self::assertSame('Guten Tag,', $stored['greeting']);
        self::assertTrue($stored['showGreeting']);
        $next = $policy->updateForSession($this->employee, 'Hallo,', 'Grüße,', $stored['revision']);
        self::assertTrue($next['showGreeting'], 'An old text-only client must preserve the additive flags.');
        self::assertFalse($next['showFarewell']);
        $component->call('toggleWordingVisibility', 'showFarewell')->assertSet('showFarewell', false)
            ->assertSet('greeting', 'Noch nicht gespeichert,')->assertSee('Inzwischen geändert');
        self::assertSame(0, Setting::query()->where('key', 'like', 'automatic_insertion_user_%')->count());
    }

    private function key(?User $user = null): string
    {
        return 'template_wording_user_'.($user ?? $this->employee)->id;
    }

    public function test_unbound_own_session_has_text_defaults_but_cannot_change_automatic_insertion(): void
    {
        $component = Livewire::test(OutlookPreferences::class)->assertSet('available', false)
            ->assertSet('wordingAvailable', true)->assertSet('greeting', 'Guten Tag,')
            ->assertSet('farewell', 'Mit freundlichen Grüßen,')->assertSee('Texte speichern')
            ->assertSee('Dein Name wird automatisch')->assertDontSeeHtml('role="switch"');
        self::assertSame(0, Setting::query()->count());
        $component->set('greeting', 'Hallo zusammen,')->set('farewell', 'Beste Grüße,');
        self::assertSame(0, Setting::query()->count(), 'Editing deferred fields never stores wording.');
        $component->call('saveWording')->assertHasNoErrors()->assertSee('Vorlagentexte gespeichert');
        $stored = Setting::getValueUncached('outlook_addin', $this->key());
        self::assertSame('Hallo zusammen,', $stored['greeting']);
        self::assertSame('Beste Grüße,', $stored['farewell']);
        self::assertSame(0, EmployeeIdentityAccount::query()->count());
        self::assertSame(0, Setting::query()->where('key', 'like', 'automatic_insertion_user_%')->count());
        $component->call('updatePreference', 'replies', 'signature', true)->assertSet('available', false);
        self::assertSame(0, Setting::query()->where('key', 'like', 'automatic_insertion_user_%')->count());
    }

    public function test_reader_is_uncached_and_independent_of_a_web_session_without_writing_defaults(): void
    {
        $policy = app(OutlookAddinWordingPolicy::class);
        Auth::logout();
        $defaults = $policy->readForUser($this->employee);
        self::assertSame($defaults, $policy->preferencesForUser($this->employee));
        self::assertSame(['schema', 'greeting', 'farewell', 'showGreeting', 'showFarewell', 'revision'], array_keys($defaults));
        self::assertSame(0, Setting::query()->count());
        Setting::setValue('outlook_addin', $this->key(), ['schema' => 1, 'greeting' => 'Hallo,', 'farewell' => 'Viele Grüße,']);
        self::assertSame('Hallo,', $policy->preferencesForUser($this->employee)['greeting']);
        self::assertNotSame($defaults['revision'], $policy->preferencesForUser($this->employee)['revision']);
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public function test_saving_unchanged_defaults_does_not_create_a_setting_or_rotate_the_revision(): void
    {
        $component = Livewire::test(OutlookPreferences::class);
        $revision = $component->get('wordingRevision');
        $component->call('saveWording')->assertHasNoErrors()->assertSet('wordingRevision', $revision)
            ->assertSet('greeting', 'Guten Tag,')->assertSet('farewell', 'Mit freundlichen Grüßen,');
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public function test_explicit_save_preserves_other_settings_and_metadata_audits_only_hashes_and_is_idempotent(): void
    {
        Setting::setValue('outlook_addin', 'automatic_insertion_user_'.$this->employee->id, ['enabled' => false, 'keep' => 'automatic metadata']);
        Setting::setValue('outlook_addin', $this->key($this->other), ['schema' => 1, 'greeting' => 'Andere Anrede,', 'farewell' => 'Anderer Gruß,']);
        Setting::setValue('outlook_addin', $this->key(), ['schema' => 1, 'greeting' => 'Guten Tag,', 'farewell' => 'Mit freundlichen Grüßen,', 'keep' => ['exact' => 'metadata']]);
        $others = Setting::query()->where('key', '!=', $this->key())->orderBy('id')->get()->toArray();
        $component = Livewire::test(OutlookPreferences::class);
        $beforeRevision = $component->get('wordingRevision');
        $component->set('greeting', '  Hallo & willkommen,  ')->set('farewell', '  Freundliche Grüße,  ')
            ->call('saveWording')->assertHasNoErrors()->assertSet('greeting', 'Hallo & willkommen,')->assertSet('farewell', 'Freundliche Grüße,');
        $stored = Setting::getValueUncached('outlook_addin', $this->key());
        self::assertSame(['exact' => 'metadata'], $stored['keep']);
        self::assertSame(1, $stored['schema']);
        self::assertArrayNotHasKey('name', $stored);
        self::assertArrayNotHasKey('displayName', $stored);
        $revision = $component->get('wordingRevision');
        self::assertNotSame($beforeRevision, $revision);
        $component->call('saveWording')->assertSet('wordingRevision', $revision);
        self::assertSame($stored, Setting::getValueUncached('outlook_addin', $this->key()));
        self::assertSame($others, Setting::query()->where('key', '!=', $this->key())->orderBy('id')->get()->toArray());
        $audits = DB::table('activity_log')->get();
        self::assertCount(1, $audits);
        self::assertSame('outlook_addin_template_wording_changed', $audits[0]->description);
        self::assertSame($this->employee->id, $audits[0]->causer_id);
        self::assertSame($this->employee->id, $audits[0]->subject_id);
        self::assertStringNotContainsString('willkommen', $audits[0]->properties);
        self::assertStringNotContainsString('Freundliche', $audits[0]->properties);
    }

    public function test_stale_wording_save_preserves_the_draft_until_explicit_reload_and_never_overwrites_another_change(): void
    {
        $first = Livewire::test(OutlookPreferences::class);
        $second = Livewire::test(OutlookPreferences::class);
        $first->set('greeting', 'Hallo Team,')->call('saveWording')->assertHasNoErrors();
        $second->set('greeting', 'Meine noch nicht gespeicherte Anrede,')->call('saveWording')
            ->assertSet('greeting', 'Meine noch nicht gespeicherte Anrede,')->assertSee('Deine Eingabe bleibt erhalten');
        self::assertSame('Hallo Team,', Setting::getValueUncached('outlook_addin', $this->key())['greeting']);
        self::assertSame(1, DB::table('activity_log')->count());
        $second->call('reloadWording')->assertSet('greeting', 'Hallo Team,')
            ->set('farewell', 'Viele Grüße,')->call('saveWording')->assertHasNoErrors();
        self::assertSame(2, DB::table('activity_log')->count());
    }

    public function test_malformed_wording_does_not_disable_or_rewrite_the_existing_automatic_preferences(): void
    {
        EmployeeIdentityAccount::create(['user_id' => $this->employee->id, 'provider' => AccountProvider::Microsoft365,
            'external_id' => '33333333-3333-4333-8333-333333333333', 'tenant_id' => config('outlook_addin.entra.tenant_id'),
            'principal' => 'own@company.test', 'email' => 'own@company.test', 'lifecycle_status' => 'active']);
        Setting::setValue('outlook_addin', 'automatic_insertion_user_'.$this->employee->id, ['enabled' => false]);
        Setting::setValue('outlook_addin', $this->key(), ['schema' => 1, 'greeting' => '<b>Hallo</b>', 'farewell' => 'Gruß,']);
        $before = Setting::query()->orderBy('id')->get()->toArray();
        Livewire::test(OutlookPreferences::class)->assertSet('available', true)->assertSet('wordingAvailable', false)
            ->assertSet('preferences.newMessages.signature', false)->assertSeeHtml('role="switch"');
        self::assertSame($before, Setting::query()->orderBy('id')->get()->toArray());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public static function invalidTexts(): array
    {
        return [
            ['', 'Gruß,', 'greeting'], ['Hallo,', '', 'farewell'], [str_repeat('a', 121), 'Gruß,', 'greeting'],
            ["Hallo,\r\nZweite Zeile", 'Gruß,', 'greeting'], ['Hallo,', "Gruß,\r\n", 'farewell'],
            ['Hallo,', '<b>Gruß</b>', 'farewell'], ['&lt;img src=x&gt;', 'Gruß,', 'greeting'],
            ['{{OUTLOOK_GRUSS}}', 'Gruß,', 'greeting'], ['Hallo,', "Gruß,\t", 'farewell'],
            ["Hallo,\u{2028}Text", 'Gruß,', 'greeting'], [123, 'Gruß,', 'greeting'],
        ];
    }

    #[DataProvider('invalidTexts')]
    public function test_server_rejects_non_plain_multiline_or_unbounded_wording_without_partial_save(mixed $greeting, mixed $farewell, string $field): void
    {
        $policy = app(OutlookAddinWordingPolicy::class);
        $revision = $policy->readForSession($this->employee)['revision'];
        try {
            $policy->updateForSession($this->employee, $greeting, $farewell, $revision);
            self::fail('Invalid wording was accepted.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey($field, $error->errors());
        }
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public static function revokedSession(): array
    {
        return [['status', false], ['email_verified_at', null], ['role', 'customer']];
    }

    #[DataProvider('revokedSession')]
    public function test_wording_save_rechecks_fresh_own_user_inside_the_transaction(string $column, mixed $value): void
    {
        $component = Livewire::test(OutlookPreferences::class)->assertSet('wordingAvailable', true);
        $once = false;
        Event::listen(TransactionBeginning::class, function () use (&$once, $column, $value): void {
            if (! $once) {
                $once = true;
                DB::table('users')->where('id', $this->employee->id)->update([$column => $value]);
            }
        });
        $component->set('greeting', 'Neue Anrede,')->call('saveWording')->assertSet('wordingAvailable', false);
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public function test_wording_writer_cannot_target_a_different_user(): void
    {
        $policy = app(OutlookAddinWordingPolicy::class);
        $revision = $policy->readForUser($this->other)['revision'];
        $this->expectException(OutlookAddinException::class);
        $policy->updateForSession($this->other, 'Hallo,', 'Gruß,', $revision);
    }

    public function test_wording_revision_is_not_client_mutable(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(OutlookPreferences::class)->set('wordingRevision', str_repeat('0', 64));
    }
}
