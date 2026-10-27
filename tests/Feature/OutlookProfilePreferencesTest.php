<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Livewire\Profile\OutlookPreferences;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
use App\Support\OutlookAddin\OutlookAddinException;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookProfilePreferencesTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private User $employee;

    private User $other;

    private EmployeeIdentityAccount $identity;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->buildMinimalRailTimeSchema();
        Http::preventStrayRequests();
        config(['outlook_addin.entra.tenant_id' => self::TENANT]);
        Schema::create('employee_identity_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('external_id')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('principal');
            $table->string('email')->nullable();
            $table->string('lifecycle_status');
            $table->string('provisioning_status')->default('ready');
            $table->string('license_status')->default('unknown');
            $table->text('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'principal']);
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
        $this->identity = $this->identity($this->employee, 'employee@company.test', '33333333-3333-4333-8333-333333333333');
        $this->identity($this->other, 'other@company.test', '44444444-4444-4444-8444-444444444444');
        $this->actingAs($this->employee);
    }

    private function identity(User $user, string $principal, string $object): EmployeeIdentityAccount
    {
        return EmployeeIdentityAccount::create([
            'user_id' => $user->id, 'provider' => AccountProvider::Microsoft365, 'external_id' => $object,
            'tenant_id' => self::TENANT, 'principal' => $principal, 'email' => $principal, 'lifecycle_status' => 'active',
        ]);
    }

    private function key(?User $user = null): string
    {
        return 'automatic_insertion_user_'.($user ?? $this->employee)->id;
    }

    public function test_eligible_profile_reads_four_actual_defaults_without_creating_settings_or_audit(): void
    {
        $component = Livewire::test(OutlookPreferences::class)->assertSet('available', true)
            ->assertSee('auf allen Geräten')->assertSee('iPhone')->assertSee('Weiterleitungen');
        foreach (['newMessages', 'replies'] as $group) {
            foreach (['signature', 'template'] as $field) {
                $component->assertSet('preferences.'.$group.'.'.$field, true);
            }
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="utf-8" ?>'.$component->html());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $inputs = (new DOMXPath($dom))->query('//input[@type="checkbox" and @role="switch"]');
        self::assertSame(2, $inputs->length, 'Only signature booleans remain switches; templates use the shared context selectors.');
        foreach ($inputs as $input) {
            self::assertTrue($input->hasAttribute('checked'));
            self::assertSame('profile-outlook-shared-hint', $input->getAttribute('aria-describedby'));
        }
        self::assertTrue($component->get('choicesAvailable'));
        self::assertSame(['newMessages' => 'default', 'replies' => 'default'], $component->get('choiceSelections'));
        $selectors = (new DOMXPath($dom))->query('//*[@data-rt-select-options]');
        self::assertSame(2, $selectors->length);
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public function test_each_toggle_preserves_other_fields_metadata_accounts_and_existing_employee_settings(): void
    {
        Setting::setValue('outlook_addin', $this->key(), ['enabled' => false, 'keep' => 'existing metadata']);
        Setting::setValue('outlook_addin', $this->key($this->other), ['enabled' => false]);
        Setting::setValue('unrelated', 'keep', ['theme' => 'existing']);
        $otherSettings = Setting::query()->where('key', '!=', $this->key())->orderBy('id')->get()->toArray();
        $users = DB::table('users')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $identities = DB::table('employee_identity_accounts')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $component = Livewire::test(OutlookPreferences::class);
        $expected = ['newMessages' => ['signature' => false, 'template' => false], 'replies' => ['signature' => false, 'template' => false]];
        foreach (array_keys($expected) as $group) {
            foreach (array_keys($expected[$group]) as $field) {
                $expected[$group][$field] = true;
                $component->call('updatePreference', $group, $field, true)->assertHasNoErrors()->assertSee('gespeichert');
                $stored = Setting::getValueUncached('outlook_addin', $this->key());
                self::assertSame($expected['newMessages'], $stored['newMessages']);
                self::assertSame($expected['replies'], $stored['replies']);
                self::assertSame('existing metadata', $stored['keep']);
            }
        }
        self::assertTrue($stored['enabled']);
        $revision = $component->get('revision');
        $component->call('updatePreference', 'replies', 'template', true)->assertSet('revision', $revision);
        self::assertSame($users, DB::table('users')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());
        self::assertSame($identities, DB::table('employee_identity_accounts')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());
        self::assertSame($otherSettings, Setting::query()->where('key', '!=', $this->key())->orderBy('id')->get()->toArray());
        $audits = DB::table('activity_log')->get();
        self::assertCount(4, $audits);
        foreach ($audits as $audit) {
            self::assertSame($this->employee->id, $audit->causer_id);
            self::assertSame($this->employee->id, $audit->subject_id);
            self::assertSame('outlook_addin_automatic_preference_changed', $audit->description);
            self::assertStringNotContainsString('company.test', $audit->properties);
        }
    }

    public function test_stale_profile_reload_keeps_the_other_client_change_without_automatically_retrying_a_write(): void
    {
        Setting::setValue('outlook_addin', $this->key(), ['enabled' => false]);
        $first = Livewire::test(OutlookPreferences::class);
        $second = Livewire::test(OutlookPreferences::class);
        $first->call('updatePreference', 'newMessages', 'signature', true);
        $second->call('updatePreference', 'replies', 'template', true)->assertSee('inzwischen geändert')
            ->assertSet('preferences.newMessages.signature', true)->assertSet('preferences.replies.template', false);
        self::assertSame(1, DB::table('activity_log')->count());
        $second->call('updatePreference', 'replies', 'template', true)->assertHasNoErrors()
            ->assertSet('preferences.newMessages.signature', true)->assertSet('preferences.replies.template', true);
        self::assertSame(2, DB::table('activity_log')->count());
    }

    public static function deniedStates(): array
    {
        return array_map(static fn (string $state): array => [$state], [
            'inactive', 'unverified', 'role', 'revoked', 'reassigned', 'tenant', 'object', 'missing', 'duplicate-own', 'duplicate-object',
        ]);
    }

    #[DataProvider('deniedStates')]
    public function test_session_reader_and_writer_recheck_actual_employee_and_binding_without_inventing_preferences(string $state): void
    {
        $component = Livewire::test(OutlookPreferences::class)->assertSet('available', true);
        match ($state) {
            'inactive' => User::whereKey($this->employee->id)->update(['status' => false]),
            'unverified' => User::whereKey($this->employee->id)->update(['email_verified_at' => null]),
            'role' => User::whereKey($this->employee->id)->update(['role' => 'customer']),
            'revoked' => $this->identity->update(['lifecycle_status' => 'disabled']),
            'reassigned' => $this->identity->update(['user_id' => $this->other->id]),
            'tenant' => $this->identity->update(['tenant_id' => '99999999-9999-4999-8999-999999999999']),
            'object' => $this->identity->update(['external_id' => 'not-an-object-id']),
            'missing' => $this->identity->delete(),
            'duplicate-own' => $this->identity($this->employee, 'second@company.test', '55555555-5555-4555-8555-555555555555'),
            'duplicate-object' => $this->identity($this->other, 'duplicate@company.test', (string) $this->identity->external_id),
        };
        $component->call('updatePreference', 'newMessages', 'signature', false)
            ->assertSet('available', false)->assertSet('preferences', [])->assertSet('revision', '');
        Livewire::test(OutlookPreferences::class)->assertSet('available', false)
            ->assertSet('preferences', [])->assertSee('Microsoft-Zuordnung')->assertDontSeeHtml('role="switch"');
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public function test_binding_reassignment_at_transaction_start_is_rechecked_before_any_preference_write(): void
    {
        $component = Livewire::test(OutlookPreferences::class);
        $once = false;
        Event::listen(TransactionBeginning::class, function () use (&$once): void {
            if (! $once) {
                $once = true;
                DB::table('employee_identity_accounts')->where('id', $this->identity->id)->update(['user_id' => $this->other->id]);
            }
        });
        $component->call('updatePreference', 'replies', 'signature', false)->assertSet('available', false);
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public static function invalidChanges(): array
    {
        return [['all', 'signature', true], ['newMessages', 'role', true], ['replies', 'template', 'false'], ['replies', 'signature', 1]];
    }

    #[DataProvider('invalidChanges')]
    public function test_action_rejects_unknown_fields_and_weak_boolean_values(string $group, string $field, mixed $enabled): void
    {
        Livewire::test(OutlookPreferences::class)->call('updatePreference', $group, $field, $enabled)->assertHasErrors('preferences');
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public function test_malformed_stored_preferences_are_unavailable_without_read_migration_or_write(): void
    {
        Setting::setValue('outlook_addin', $this->key(), ['enabled' => 'false']);
        $before = Setting::query()->get()->toArray();
        Livewire::test(OutlookPreferences::class)->assertSet('available', false)->assertSet('preferences', [])
            ->assertDontSeeHtml('role="switch"');
        self::assertSame($before, Setting::query()->get()->toArray());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public function test_session_service_cannot_target_another_employee_even_with_a_valid_user_model(): void
    {
        $this->expectException(OutlookAddinException::class);
        app(OutlookAddinAutomaticInsertionPolicy::class)->preferencesForSession($this->other);
    }

    public function test_component_does_not_expose_a_mutable_employee_target(): void
    {
        $this->expectException(PublicPropertyNotFoundException::class);
        Livewire::test(OutlookPreferences::class)->set('userId', $this->other->id);
    }

    public function test_client_cannot_replace_the_server_revision(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(OutlookPreferences::class)->set('revision', str_repeat('0', 64));
    }
}
