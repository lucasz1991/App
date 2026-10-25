<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Http\Controllers\Api\OutlookAddinBootstrapController;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use Firebase\JWT\JWT;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookAddinAutomaticInsertionPolicyTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const MAILBOX = 'target@company.test';

    private ?string $privateKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        Http::preventStrayRequests();
        Storage::fake('private');
        config([
            'outlook_addin.enabled' => true, 'outlook_addin.deployed' => true,
            'outlook_addin.base_url' => 'https://app.rail-time.de',
            'outlook_addin.entra.tenant_id' => self::TENANT,
            'outlook_addin.entra.client_id' => '22222222-2222-4222-8222-222222222222',
            'outlook_addin.entra.audience' => '22222222-2222-4222-8222-222222222222',
            'outlook_addin.entra.scope' => 'Signature.Read',
            'outlook_addin.entra.scope_uri' => 'api://22222222-2222-4222-8222-222222222222/Signature.Read',
            'outlook_addin.snapshots.auto_refresh' => false,
            'outlook_addin.snapshots.disk' => 'private',
            'app.url' => 'https://app.rail-time.de',
        ]);
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
        User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        User::factory()->create(['id' => 5, 'role' => 'staff', 'status' => true, 'email' => self::MAILBOX]);
        User::factory()->create(['id' => 6, 'role' => 'staff', 'status' => true]);
        $this->identity(5, self::MAILBOX, '33333333-3333-4333-8333-333333333333');
        $this->identity(6, 'other@company.test', '44444444-4444-4444-8444-444444444444');
    }

    public function test_query_and_disable_dry_run_do_not_write_preferences_or_audit_or_employee_data(): void
    {
        $before = $this->employeeRows();
        $this->artisan('outlook-addin:automatic-insertion', ['mailbox' => self::MAILBOX])->assertSuccessful();
        $this->artisan('outlook-addin:automatic-insertion', ['mailbox' => self::MAILBOX, '--disable' => true])->assertSuccessful();
        self::assertSame($before, $this->employeeRows());
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
        self::assertTrue(app(OutlookAddinAutomaticInsertionPolicy::class)->enabledForUser(User::findOrFail(5)));
    }

    public function test_apply_changes_only_selected_preference_and_audits_actor_with_idempotent_reenable(): void
    {
        Setting::setValue('unrelated', 'untouched', ['keep' => true]);
        Setting::setValue('outlook_addin', 'automatic_insertion_user_6', ['enabled' => true, 'keep' => 'other']);
        $before = $this->employeeRows();
        $other = Setting::query()->orderBy('id')->get()->toArray();
        $this->change(false);
        $policy = app(OutlookAddinAutomaticInsertionPolicy::class);
        self::assertFalse($policy->enabledForUser(User::findOrFail(5)));
        self::assertTrue($policy->enabledForUser(User::findOrFail(6)));
        self::assertSame($before, $this->employeeRows());
        self::assertSame($other, Setting::query()->where('key', '!=', 'automatic_insertion_user_5')->orderBy('id')->get()->toArray());
        $stored = Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_5');
        self::assertFalse($stored['enabled']);
        self::assertSame(['signature' => false, 'template' => false], $stored['newMessages']);
        self::assertSame($stored['newMessages'], $stored['replies']);
        $audit = DB::table('activity_log')->sole();
        self::assertSame(1, $audit->causer_id);
        self::assertSame(5, $audit->subject_id);
        self::assertSame('outlook_addin_automatic_insertion_changed', $audit->description);
        self::assertStringNotContainsString(self::MAILBOX, $audit->properties);
        self::assertSame(['employee_id' => 5, 'identity_id' => 1, 'enabled_before' => true, 'enabled_after' => false], json_decode($audit->properties, true));
        $this->change(false);
        self::assertSame(1, DB::table('activity_log')->count());
        $this->change(true);
        self::assertTrue($policy->enabledForUser(User::findOrFail(5)));
        self::assertSame(2, DB::table('activity_log')->count());
    }

    public function test_apply_requires_an_explicit_active_verified_administrator(): void
    {
        foreach ([null, 'unknown', '6'] as $actor) {
            $arguments = ['mailbox' => self::MAILBOX, '--disable' => true, '--apply' => true];
            if ($actor !== null) {
                $arguments['--actor'] = $actor;
            }
            $this->artisan('outlook-addin:automatic-insertion', $arguments)->assertFailed();
        }
        foreach ([['status' => false], ['status' => true, 'email_verified_at' => null]] as $state) {
            User::findOrFail(1)->forceFill($state)->save();
            $this->artisan('outlook-addin:automatic-insertion', ['mailbox' => self::MAILBOX, '--disable' => true, '--apply' => true, '--actor' => 1])->assertFailed();
        }
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public static function invalidLinks(): array
    {
        return [
            'missing object' => [['external_id' => null]],
            'wrong tenant' => [['tenant_id' => '55555555-5555-4555-8555-555555555555']],
            'inactive identity' => [['lifecycle_status' => 'inactive']],
            'unlinked' => [['user_id' => null]],
            'other provider' => [['provider' => AccountProvider::GoogleWorkspace]],
        ];
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_or_unlinked_mailbox_never_falls_back_to_user_email(array $changes): void
    {
        EmployeeIdentityAccount::findOrFail(1)->forceFill($changes)->save();
        $before = $this->employeeRows();
        $this->artisan('outlook-addin:automatic-insertion', ['mailbox' => self::MAILBOX, '--disable' => true, '--apply' => true, '--actor' => 1])->assertFailed();
        self::assertSame($before, $this->employeeRows());
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public function test_alias_or_ambiguous_case_insensitive_principal_is_not_a_unique_exact_target(): void
    {
        EmployeeIdentityAccount::findOrFail(1)->update(['email' => 'alias@company.test']);
        $this->artisan('outlook-addin:automatic-insertion', ['mailbox' => 'alias@company.test', '--disable' => true, '--apply' => true, '--actor' => 1])->assertFailed();
        $this->identity(6, strtoupper(self::MAILBOX), '55555555-5555-4555-8555-555555555555');
        $this->artisan('outlook-addin:automatic-insertion', ['mailbox' => self::MAILBOX, '--disable' => true, '--apply' => true, '--actor' => 1])->assertFailed();
        self::assertSame(0, Setting::query()->count());
    }

    public function test_identity_reassignment_between_preview_and_transaction_prevents_the_setting_write(): void
    {
        $pending = true;
        Event::listen(TransactionBeginning::class, static function () use (&$pending): void {
            if ($pending) {
                $pending = false;
                EmployeeIdentityAccount::findOrFail(1)->update(['user_id' => 6]);
            }
        });
        $this->artisan('outlook-addin:automatic-insertion', ['mailbox' => self::MAILBOX, '--disable' => true, '--apply' => true, '--actor' => 1])->assertFailed();
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public function test_authenticated_bootstrap_preference_is_fresh_changes_etag_and_does_not_change_manual_or_cached_payload(): void
    {
        $this->withoutMiddleware();
        foreach (['2026_08_09_000100_create_mail_documents_table.php', '2026_08_27_000100_add_design_slots_to_mail_documents.php'] as $migration) {
            (include database_path('migrations/'.$migration))->up();
        }
        $case = new EmailTemplatesPageTest('test_outlook_addin_validates_a_real_entra_rs256_access_token');
        (new ReflectionMethod($case, 'createCanonicalMailDocuments'))->invoke($case);
        $headers = $this->headers(EmployeeIdentityAccount::findOrFail(1));
        $first = $this->getJson('/api/outlook-addin/bootstrap', $headers)->assertOk()->assertJsonPath('automaticInsertionEnabled', true);
        $old = $first->json();
        $etag = $first->headers->get('ETag');
        $snapshotPath = app(OutlookAddinUserSnapshotStore::class)->pathForUser(5);
        $snapshotBytes = Storage::disk('private')->get($snapshotPath);
        $this->change(false);
        $second = $this->getJson('/api/outlook-addin/bootstrap', $headers + ['If-None-Match' => $etag])->assertOk()->assertJsonPath('automaticInsertionEnabled', false);
        $expected = $old;
        $expected['automaticInsertionEnabled'] = false;
        $expected['automaticInsertionPreferences'] = app(OutlookAddinAutomaticInsertionPolicy::class)->preferencesForUser(User::findOrFail(5));
        self::assertSame($expected, $second->json(), 'Manual signature/template contents and binding remain available unchanged.');
        $this->getJson('/api/outlook-addin/bootstrap', $headers + ['X-RailTime-Outlook-Context' => 'taskpane'])
            ->assertOk()->assertExactJson($expected);
        self::assertNotSame($etag, $second->headers->get('ETag'));
        self::assertSame($snapshotBytes, Storage::disk('private')->get($snapshotPath));
        self::assertArrayNotHasKey('automaticInsertionEnabled', app(OutlookAddinUserSnapshotStore::class)->currentForUser(User::findOrFail(5)));
        $this->getJson('/api/outlook-addin/bootstrap', $headers + ['If-None-Match' => $second->headers->get('ETag')])->assertNoContent(304);
        $this->getJson('/api/outlook-addin/bootstrap', $this->headers(EmployeeIdentityAccount::findOrFail(2)))->assertOk()->assertJsonPath('automaticInsertionEnabled', true);
        $this->getJson('/api/outlook-addin/bootstrap', array_replace($headers, ['X-RailTime-Outlook-Mailbox' => 'other@company.test']))
            ->assertForbidden()->assertJsonMissingPath('automaticInsertionEnabled');
    }

    public function test_disabled_automatic_event_returns_no_insertable_artifacts_and_never_reads_a_snapshot(): void
    {
        $this->withoutMiddleware();
        $this->change(false);
        $headers = $this->headers(EmployeeIdentityAccount::findOrFail(1)) + ['X-RailTime-Outlook-Context' => 'event'];
        $before = $this->employeeRows();
        $storage = Storage::getFacadeRoot();
        Storage::shouldReceive('disk')->never();
        try {
            $response = $this->getJson('/api/outlook-addin/bootstrap', $headers)->assertOk()->assertExactJson([
                'schema' => 1,
                'marker' => 'RT-SIGNATURE-MANAGED-V1',
                'automaticInsertionEnabled' => false,
                'automaticInsertionPreferences' => app(OutlookAddinAutomaticInsertionPolicy::class)->preferencesForUser(User::findOrFail(5)),
                'binding' => ['schema' => 1, 'mailboxAddress' => self::MAILBOX, 'senderAddress' => self::MAILBOX, 'allowedSenderAddresses' => [self::MAILBOX]],
            ])->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            self::assertStringContainsString('X-RailTime-Outlook-Context', $response->headers->get('Vary'));
            $etag = $response->headers->get('ETag');
            self::assertNotEmpty($etag);
            $this->getJson('/api/outlook-addin/bootstrap', $headers + ['If-None-Match' => $etag])->assertNoContent(304);
            $this->getJson('/api/outlook-addin/bootstrap', array_replace($headers, ['X-RailTime-Outlook-Mailbox' => 'other@company.test']))
                ->assertForbidden()->assertJsonMissingPath('automaticInsertionEnabled');
            $this->getJson('/api/outlook-addin/bootstrap', array_replace($headers, ['X-RailTime-Compose-Contract' => 'invalid']))
                ->assertStatus(409)->assertJsonMissingPath('automaticInsertionEnabled');
            self::assertSame($before, $this->employeeRows());
            self::assertFalse(Schema::hasTable('mail_documents'), 'The disabled event needs neither published source tables nor a snapshot.');
        } finally {
            Storage::swap($storage);
        }
    }

    public function test_malformed_preference_is_not_silently_treated_as_permission_to_insert(): void
    {
        Setting::setValue('outlook_addin', 'automatic_insertion_user_5', ['enabled' => 'false']);
        $this->expectException(RuntimeException::class);
        app(OutlookAddinAutomaticInsertionPolicy::class)->enabledForUser(User::findOrFail(5));
    }

    public function test_legacy_opt_out_is_four_false_without_a_read_migration_and_defaults_are_four_true(): void
    {
        $policy = app(OutlookAddinAutomaticInsertionPolicy::class);
        Setting::setValue('outlook_addin', 'automatic_insertion_user_5', ['enabled' => false]);
        $before = Setting::query()->get()->toArray();
        $disabled = $policy->preferencesForUser(User::findOrFail(5));
        $enabled = $policy->preferencesForUser(User::findOrFail(6));
        self::assertSame(2, $disabled['schema']);
        self::assertSame(['signature' => false, 'template' => false], $disabled['newMessages']);
        self::assertSame($disabled['newMessages'], $disabled['replies']);
        self::assertSame(['signature' => true, 'template' => true], $enabled['newMessages']);
        self::assertSame($enabled['newMessages'], $enabled['replies']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $disabled['revision']);
        self::assertNotSame($disabled['revision'], $enabled['revision']);
        self::assertSame($before, Setting::query()->get()->toArray());
    }

    public function test_verified_self_can_change_exactly_one_toggle_and_stale_cas_keeps_the_current_other_fields(): void
    {
        $this->withoutMiddleware();
        $policy = app(OutlookAddinAutomaticInsertionPolicy::class);
        Setting::setValue('unrelated', 'keep', ['value' => 'untouched']);
        Setting::setValue('outlook_addin', 'automatic_insertion_user_5', ['enabled' => false, 'keep' => 'own metadata']);
        $users = $this->employeeRows();
        $other = Setting::query()->where('key', '!=', 'automatic_insertion_user_5')->get()->toArray();
        $current = $policy->preferencesForUser(User::findOrFail(5));
        $headers = $this->headers(EmployeeIdentityAccount::findOrFail(1)) + ['X-RailTime-Outlook-Context' => 'taskpane'];
        $data = ['group' => 'newMessages', 'field' => 'signature', 'enabled' => true, 'revision' => $current['revision']];
        $first = $this->patchJson('/api/outlook-addin/preferences', $data, $headers)->assertOk();
        $next = $first->json('automaticInsertionPreferences');
        self::assertTrue($next['newMessages']['signature']);
        self::assertFalse($next['newMessages']['template']);
        self::assertSame(['signature' => false, 'template' => false], $next['replies']);
        self::assertNotSame($current['revision'], $next['revision']);
        self::assertSame($users, $this->employeeRows());
        self::assertSame($other, Setting::query()->where('key', '!=', 'automatic_insertion_user_5')->get()->toArray());
        self::assertSame('own metadata', Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_5')['keep']);
        $this->patchJson('/api/outlook-addin/preferences', array_replace($data, ['field' => 'template']), $headers)
            ->assertStatus(409)->assertJsonPath('error', 'outlook_addin_preferences_conflict')->assertJsonPath('automaticInsertionPreferences', $next);
        self::assertSame($next, $policy->preferencesForUser(User::findOrFail(5)));
        $this->patchJson('/api/outlook-addin/preferences', array_replace($data, ['enabled' => false, 'revision' => $next['revision']]), $headers)->assertOk();
        $again = $policy->preferencesForUser(User::findOrFail(5));
        self::assertSame($current['newMessages'], $again['newMessages']);
        self::assertNotSame($current['revision'], $again['revision'], 'Returning to an old value must not restore an old CAS token.');
        $audits = DB::table('activity_log')->where('description', 'outlook_addin_automatic_preference_changed')->get();
        self::assertCount(2, $audits);
        foreach ($audits as $audit) {
            self::assertSame(5, $audit->causer_id);
            self::assertSame(5, $audit->subject_id);
            self::assertStringNotContainsString(self::MAILBOX, $audit->properties);
        }
        self::assertTrue($policy->enabledForUser(User::findOrFail(6)));
    }

    public function test_self_patch_rejects_unknown_fields_weak_booleans_foreign_binding_and_wrong_context_without_writes(): void
    {
        $this->withoutMiddleware();
        $headers = $this->headers(EmployeeIdentityAccount::findOrFail(1)) + ['X-RailTime-Outlook-Context' => 'taskpane'];
        $revision = app(OutlookAddinAutomaticInsertionPolicy::class)->preferencesForUser(User::findOrFail(5))['revision'];
        $data = ['group' => 'replies', 'field' => 'template', 'enabled' => false, 'revision' => $revision];
        foreach ([['userId' => 6], ['enabled' => 'false'], ['enabled' => 0], ['group' => 'all'], ['field' => 'role'], ['revision' => 'invalid']] as $change) {
            $this->patchJson('/api/outlook-addin/preferences', array_replace($data, $change), $headers)->assertStatus(422);
        }
        $this->patchJson('/api/outlook-addin/preferences', $data, array_replace($headers, ['X-RailTime-Outlook-Context' => 'event']))->assertForbidden();
        $this->patchJson('/api/outlook-addin/preferences', $data, array_replace($headers, ['X-RailTime-Outlook-Sender' => 'other@company.test']))->assertForbidden();
        $this->patchJson('/api/outlook-addin/preferences', $data, array_replace($headers, ['X-RailTime-Compose-Contract' => 'invalid']))->assertStatus(409);
        $this->patchJson('/api/outlook-addin/preferences', $data)->assertUnauthorized();
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public static function selfBindingRaces(): array
    {
        return [
            'identity ownership' => ['identity', ['user_id' => 6]],
            'identity tenant' => ['identity', ['tenant_id' => '55555555-5555-4555-8555-555555555555']],
            'identity alias' => ['identity', ['email' => 'new-alias@company.test']],
            'inactive employee' => ['user', ['status' => false]],
            'unverified employee' => ['user', ['email_verified_at' => null]],
        ];
    }

    #[DataProvider('selfBindingRaces')]
    public function test_self_patch_rechecks_identity_and_employee_inside_the_locked_transaction(string $target, array $changes): void
    {
        $this->withoutMiddleware();
        $headers = $this->headers(EmployeeIdentityAccount::findOrFail(1)) + ['X-RailTime-Outlook-Context' => 'taskpane'];
        $revision = app(OutlookAddinAutomaticInsertionPolicy::class)->preferencesForUser(User::findOrFail(5))['revision'];
        $pending = true;
        Event::listen(TransactionBeginning::class, static function () use (&$pending, $target, $changes): void {
            if ($pending) {
                $pending = false;
                if ($target === 'identity') {
                    EmployeeIdentityAccount::findOrFail(1)->update($changes);
                } else {
                    User::findOrFail(5)->forceFill($changes)->save();
                }
            }
        });
        $this->patchJson('/api/outlook-addin/preferences', ['group' => 'replies', 'field' => 'signature', 'enabled' => false, 'revision' => $revision], $headers)->assertForbidden();
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public function test_v2_event_matrix_returns_only_selected_artifacts_and_legacy_clients_fail_closed_on_every_partial_state(): void
    {
        $this->withoutMiddleware();
        $this->publishedLibrary();
        $headers = $this->headers(EmployeeIdentityAccount::findOrFail(1)) + ['X-RailTime-Outlook-Context' => 'event'];
        $snapshots = app(OutlookAddinUserSnapshotStore::class);
        $snapshots->currentForUser(User::findOrFail(5));
        $path = $snapshots->pathForUser(5);
        $cached = Storage::disk('private')->get($path);
        foreach (range(0, 15) as $mask) {
            $new = ['signature' => (bool) ($mask & 1), 'template' => (bool) ($mask & 2)];
            $replies = ['signature' => (bool) ($mask & 4), 'template' => (bool) ($mask & 8)];
            Setting::setValue('outlook_addin', 'automatic_insertion_user_5', ['schema' => 2, 'newMessages' => $new, 'replies' => $replies]);
            foreach (['newMail', 'reply', 'forward'] as $composeType) {
                $selected = $composeType === 'reply' ? $replies : $new;
                $response = $this->getJson('/api/outlook-addin/bootstrap', $headers + ['X-RailTime-Automatic-Policy' => '2', 'X-RailTime-Compose-Type' => $composeType])->assertOk();
                $body = $response->json();
                self::assertSame(['schema' => 2, 'composeType' => $composeType] + $selected, $body['automaticInsertion']);
                self::assertSame($selected['signature'] || $selected['template'], $body['automaticInsertionEnabled']);
                if (! $selected['signature'] && ! $selected['template']) {
                    foreach (['signature', 'templates', 'template', 'version'] as $field) {
                        self::assertArrayNotHasKey($field, $body);
                    }
                } elseif ($selected['signature'] && ! $selected['template']) {
                    self::assertArrayHasKey('signature', $body);
                    self::assertSame([], $body['templates']);
                    self::assertNull($body['automaticTemplateId']);
                    self::assertArrayNotHasKey('template', $body);
                } elseif (! $selected['signature'] && $selected['template']) {
                    self::assertArrayNotHasKey('signature', $body);
                    self::assertArrayNotHasKey('template', $body);
                    self::assertArrayNotHasKey('version', $body);
                    self::assertCount(1, $body['templates']);
                    foreach (['signature', 'html', 'media', 'combinedComposeHtml', 'combinedComposeMedia', 'mobileComposeHtml', 'mobileComposeMedia', 'signatureVersion'] as $field) {
                        self::assertArrayNotHasKey($field, $body['templates'][0]);
                    }
                    self::assertStringContainsString('RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE', $body['templates'][0]['composeHtml']);
                    self::assertStringNotContainsString('RT-SIGNATURE-', $body['templates'][0]['composeHtml']);
                } else {
                    self::assertArrayHasKey('signature', $body);
                    self::assertArrayHasKey('template', $body);
                    self::assertArrayHasKey('html', $body['templates'][0]);
                }
            }
            $legacy = $this->getJson('/api/outlook-addin/bootstrap', $headers)->assertOk()->json();
            self::assertSame($mask === 15, $legacy['automaticInsertionEnabled']);
            self::assertSame($mask === 15, array_key_exists('signature', $legacy));
            $unknown = $this->getJson('/api/outlook-addin/bootstrap', $headers + ['X-RailTime-Automatic-Policy' => '2', 'X-RailTime-Compose-Type' => 'unknown'])->assertOk()->json();
            self::assertFalse($unknown['automaticInsertionEnabled']);
            self::assertArrayNotHasKey('signature', $unknown);
            self::assertArrayNotHasKey('templates', $unknown);
        }
        self::assertSame($cached, Storage::disk('private')->get($path));
    }

    public function test_mobile_v2_partial_artifacts_respect_context_etag_and_keep_cached_and_published_bytes(): void
    {
        $this->withoutMiddleware();
        $this->publishedLibrary();
        $headers = $this->headers(EmployeeIdentityAccount::findOrFail(1)) + [
            'X-RailTime-Outlook-Context' => 'event', 'X-RailTime-Automatic-Policy' => '2',
            'X-RailTime-Outlook-Profile' => 'mobile-ledger-v1',
        ];
        $snapshots = app(OutlookAddinUserSnapshotStore::class);
        $snapshots->currentForUser(User::findOrFail(5));
        $cached = Storage::disk('private')->get($snapshots->pathForUser(5));
        $published = DB::table('mail_documents')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        foreach ([[true, false], [false, true]] as [$signature, $template]) {
            Setting::setValue('outlook_addin', 'automatic_insertion_user_5', [
                'schema' => 2, 'newMessages' => ['signature' => $signature, 'template' => $template],
                'replies' => ['signature' => false, 'template' => false],
            ]);
            $newHeaders = $headers + ['X-RailTime-Compose-Type' => 'newMail'];
            $response = $this->getJson('/api/outlook-addin/bootstrap', $newHeaders)->assertOk();
            $body = $response->json();
            self::assertSame($signature, array_key_exists('signature', $body));
            if ($template) {
                self::assertArrayHasKey('composeHtml', $body['templates'][0]);
                self::assertArrayNotHasKey('combinedComposeHtml', $body['templates'][0]);
                self::assertArrayNotHasKey('mobileComposeHtml', $body['templates'][0]);
                self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($body['templates'][0]['composeHtml'], 'UTF-16LE', 'UTF-8')), 2) + 57);
            }
            $etag = $response->headers->get('ETag');
            $this->getJson('/api/outlook-addin/bootstrap', $newHeaders + ['If-None-Match' => $etag])->assertNoContent(304);
            $reply = $this->getJson('/api/outlook-addin/bootstrap', $headers + ['X-RailTime-Compose-Type' => 'reply', 'If-None-Match' => $etag])->assertOk()->assertJsonPath('automaticInsertionEnabled', false);
            self::assertNotSame($etag, $reply->headers->get('ETag'));
            $this->getJson('/api/outlook-addin/bootstrap', $headers + ['X-RailTime-Compose-Type' => 'forward'])->assertOk()
                ->assertJsonPath('automaticInsertion.signature', $signature)->assertJsonPath('automaticInsertion.template', $template);
        }
        self::assertSame($cached, Storage::disk('private')->get($snapshots->pathForUser(5)));
        self::assertSame($published, DB::table('mail_documents')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());
    }

    public function test_mobile_template_only_guard_reserves_the_exact_native_wrapper_budget(): void
    {
        $prefix = '<!-- RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE -->';
        $html = $prefix.str_repeat('x', 29944 - strlen($prefix));
        $payload = ['automaticTemplateId' => 't', 'templates' => [[
            'id' => 't', 'key' => 't', 'isDefault' => true, 'signatureMode' => 'native', 'composeHtml' => $html, 'composeMedia' => [],
        ]]];
        try {
            (new ReflectionMethod(OutlookAddinBootstrapController::class, 'templateOnlyPayload'))
                ->invoke(new OutlookAddinBootstrapController, $payload, true);
            self::fail('A 29,944-unit template cannot fit the additional 57-unit native wrapper.');
        } catch (OutlookAddinException $error) {
            self::assertSame(409, $error->httpStatus);
        }
    }

    public function test_cli_disable_and_enable_override_every_selective_field_without_losing_other_metadata(): void
    {
        Setting::setValue('outlook_addin', 'automatic_insertion_user_5', [
            'schema' => 2, 'newMessages' => ['signature' => false, 'template' => true],
            'replies' => ['signature' => false, 'template' => false], 'keep' => 'metadata',
        ]);
        $this->change(false);
        $policy = app(OutlookAddinAutomaticInsertionPolicy::class);
        self::assertSame(['signature' => false, 'template' => false], $policy->preferencesForUser(User::findOrFail(5))['newMessages']);
        $this->change(true);
        $preferences = $policy->preferencesForUser(User::findOrFail(5));
        self::assertSame(['signature' => true, 'template' => true], $preferences['newMessages']);
        self::assertSame($preferences['newMessages'], $preferences['replies']);
        self::assertSame('metadata', Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_5')['keep']);
    }

    private function publishedLibrary(): void
    {
        foreach ([
            '2026_08_09_000100_create_mail_documents_table.php', '2026_08_22_000200_create_mail_document_versions_table.php',
            '2026_08_27_000100_add_design_slots_to_mail_documents.php', '2026_09_06_010000_add_outlook_library_to_mail_documents.php',
            '2026_09_07_190000_separate_mail_document_delivery_channels.php', '2026_09_08_120000_add_mail_document_signature_pairing.php',
        ] as $migration) {
            (include database_path('migrations/'.$migration))->up();
        }
        $case = new EmailTemplatesPageTest('test_outlook_addin_validates_a_real_entra_rs256_access_token');
        (new ReflectionMethod($case, 'createCanonicalMailDocuments'))->invoke($case);
        DB::table('mail_documents')->update(['outlook_default' => true]);
        DB::table('mail_documents')->where('kind', 'template')->update(['outlook_released' => true]);
    }

    private function change(bool $enabled): void
    {
        $this->artisan('outlook-addin:automatic-insertion', ['mailbox' => ' TARGET@COMPANY.TEST ', $enabled ? '--enable' : '--disable' => true, '--apply' => true, '--actor' => 1])->assertSuccessful();
    }

    private function identity(int $userId, string $principal, string $object): EmployeeIdentityAccount
    {
        return EmployeeIdentityAccount::create(['user_id' => $userId, 'provider' => AccountProvider::Microsoft365, 'principal' => $principal, 'email' => $principal, 'tenant_id' => self::TENANT, 'external_id' => $object, 'lifecycle_status' => 'active']);
    }

    private function employeeRows(): array
    {
        return [
            DB::table('users')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            DB::table('employee_identity_accounts')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        ];
    }

    private function headers(EmployeeIdentityAccount $identity): array
    {
        if ($this->privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertNotFalse($key);
            self::assertTrue(openssl_pkey_export($key, $private));
            $this->privateKey = $private;
            $details = openssl_pkey_get_details($key);
            $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            Http::fake(['https://login.microsoftonline.com/'.self::TENANT.'/discovery/v2.0/keys' => Http::response(['keys' => [[
                'kty' => 'RSA', 'use' => 'sig', 'kid' => 'automatic-insertion-test', 'alg' => 'RS256',
                'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
            ]]])]);
        }
        $token = JWT::encode([
            'aud' => config('outlook_addin.entra.client_id'), 'azp' => config('outlook_addin.entra.client_id'),
            'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0',
            'iat' => time() - 5, 'nbf' => time() - 5, 'exp' => time() + 300,
            'tid' => self::TENANT, 'oid' => $identity->external_id, 'scp' => 'Signature.Read', 'preferred_username' => $identity->principal,
        ], $this->privateKey, 'RS256', 'automatic-insertion-test');

        return ['Authorization' => 'Bearer '.$token, 'X-RailTime-Outlook-Mailbox' => $identity->principal, 'X-RailTime-Outlook-Sender' => $identity->principal, 'X-RailTime-Compose-Contract' => 'native-signature-v1'];
    }
}
