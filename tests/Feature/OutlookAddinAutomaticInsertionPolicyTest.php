<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
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
        self::assertSame(['enabled' => false], Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_5'));
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
        self::assertSame($expected, $second->json(), 'Manual signature/template contents and binding remain available unchanged.');
        self::assertNotSame($etag, $second->headers->get('ETag'));
        self::assertSame($snapshotBytes, Storage::disk('private')->get($snapshotPath));
        self::assertArrayNotHasKey('automaticInsertionEnabled', app(OutlookAddinUserSnapshotStore::class)->currentForUser(User::findOrFail(5)));
        $this->getJson('/api/outlook-addin/bootstrap', $headers + ['If-None-Match' => $second->headers->get('ETag')])->assertNoContent(304);
        $this->getJson('/api/outlook-addin/bootstrap', $this->headers(EmployeeIdentityAccount::findOrFail(2)))->assertOk()->assertJsonPath('automaticInsertionEnabled', true);
        $this->getJson('/api/outlook-addin/bootstrap', array_replace($headers, ['X-RailTime-Outlook-Mailbox' => 'other@company.test']))
            ->assertForbidden()->assertJsonMissingPath('automaticInsertionEnabled');
    }

    public function test_malformed_preference_is_not_silently_treated_as_permission_to_insert(): void
    {
        Setting::setValue('outlook_addin', 'automatic_insertion_user_5', ['enabled' => 'false']);
        $this->expectException(RuntimeException::class);
        app(OutlookAddinAutomaticInsertionPolicy::class)->enabledForUser(User::findOrFail(5));
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
