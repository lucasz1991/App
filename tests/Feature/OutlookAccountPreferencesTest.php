<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\EmployeeWorkplaceProvisioner;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookAccountPreferencesTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const CLIENT = '22222222-2222-4222-8222-222222222222';

    private const OBJECT = '33333333-3333-4333-8333-333333333333';

    private ?string $privateKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        Http::preventStrayRequests();
        config(['outlook_addin.enabled' => true, 'outlook_addin.deployed' => true,
            'outlook_addin.addin_id' => self::CLIENT, 'outlook_addin.base_url' => 'https://example.test',
            'outlook_addin.entra.tenant_id' => self::TENANT, 'outlook_addin.entra.client_id' => self::CLIENT,
            'outlook_addin.entra.audience' => self::CLIENT, 'outlook_addin.entra.scope' => 'Signature.Read',
            'outlook_addin.entra.scope_uri' => 'api://'.self::CLIENT.'/Signature.Read']);
        Schema::create('employee_identity_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            foreach (['external_id', 'tenant_id', 'principal', 'email', 'lifecycle_status'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('activity_log', static function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
        User::factory()->create(['id' => 5, 'role' => 'staff', 'status' => true, 'email_verified_at' => now()]);
        User::factory()->create(['id' => 6, 'role' => 'staff', 'status' => true, 'email_verified_at' => now()]);
        EmployeeIdentityAccount::create(['user_id' => 5, 'provider' => AccountProvider::Microsoft365,
            'tenant_id' => self::TENANT, 'external_id' => self::OBJECT, 'principal' => 'own@company.test',
            'email' => 'alias@company.test', 'lifecycle_status' => 'active']);
        // A settings read must not enter the provisioning/activation path.
        $this->app->bind(EmployeeWorkplaceProvisioner::class, static fn () => throw new \LogicException('Settings must never activate employees.'));
    }

    public function test_get_is_read_only_own_account_data_without_mail_artifacts_or_compose_sender(): void
    {
        $before = $this->rows();
        $response = $this->getJson('/api/outlook-addin/account-preferences', $this->headers() + ['X-RailTime-Outlook-Sender' => 'received-message@foreign.test'])->assertOk();
        self::assertSame(['automaticInsertionPreferences', 'binding'], array_keys($response->json()));
        $response->assertJsonPath('binding.mailboxAddress', 'own@company.test')->assertJsonPath('binding.senderAddress', 'own@company.test');
        $response->assertJsonPath('automaticInsertionPreferences.newMessages.signature', true);
        self::assertSame($before, $this->rows());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_patch_uses_existing_self_cas_preserves_unrelated_values_and_refreshes_conflicts(): void
    {
        Setting::setValue('outlook_addin', 'automatic_insertion_user_6', ['enabled' => false, 'keep' => true]);
        $untouched = Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_6');
        $before = $this->rows();
        $revision = $this->getJson('/api/outlook-addin/account-preferences', $this->headers())->assertOk()->json('automaticInsertionPreferences.revision');
        $data = ['group' => 'replies', 'field' => 'template', 'enabled' => false, 'revision' => $revision];
        $response = $this->patchJson('/api/outlook-addin/account-preferences', $data, $this->headers())->assertOk();
        $response->assertJsonPath('automaticInsertionPreferences.replies.template', false)->assertJsonPath('automaticInsertionPreferences.newMessages.template', true);
        $this->patchJson('/api/outlook-addin/account-preferences', $data, $this->headers())->assertStatus(409)
            ->assertJsonPath('error', 'outlook_addin_preferences_conflict')->assertJsonPath('automaticInsertionPreferences.replies.template', false);
        self::assertSame($before['users'], $this->rows()['users']);
        self::assertSame($before['identities'], $this->rows()['identities']);
        self::assertSame($untouched, Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_6'));
        self::assertSame(1, DB::table('activity_log')->count());
        $audit = DB::table('activity_log')->sole();
        self::assertSame(5, $audit->subject_id);
        self::assertSame(5, $audit->causer_id);
    }

    public static function unavailableAccounts(): array
    {
        return [
            'inactive user' => ['user', ['status' => false]], 'unverified user' => ['user', ['email_verified_at' => null]],
            'guest role' => ['user', ['role' => 'guest']], 'revoked identity' => ['identity', ['lifecycle_status' => 'inactive']],
            'foreign tenant' => ['identity', ['tenant_id' => '44444444-4444-4444-8444-444444444444']],
            'unlinked' => ['identity', ['user_id' => null]], 'wrong provider' => ['identity', ['provider' => AccountProvider::GoogleWorkspace]],
        ];
    }

    #[DataProvider('unavailableAccounts')]
    public function test_ineligible_accounts_are_not_activated_or_modified(string $target, array $changes): void
    {
        $headers = $this->headers();
        ($target === 'user' ? User::findOrFail(5) : EmployeeIdentityAccount::findOrFail(1))->forceFill($changes)->save();
        $before = $this->rows();
        $this->getJson('/api/outlook-addin/account-preferences', $headers)->assertForbidden();
        $this->patchJson('/api/outlook-addin/account-preferences', ['group' => 'replies', 'field' => 'signature', 'enabled' => false, 'revision' => str_repeat('a', 64)], $headers)->assertForbidden();
        self::assertSame($before, $this->rows());
    }

    public function test_foreign_mailbox_or_ambiguous_object_identity_never_selects_another_employee(): void
    {
        $headers = $this->headers();
        $headers['X-RailTime-Outlook-Mailbox'] = 'other@company.test';
        $this->getJson('/api/outlook-addin/account-preferences', $headers)->assertForbidden();
        EmployeeIdentityAccount::create(['user_id' => 6, 'provider' => AccountProvider::Microsoft365, 'tenant_id' => self::TENANT,
            'external_id' => strtoupper(self::OBJECT), 'principal' => 'other@company.test', 'email' => 'other@company.test', 'lifecycle_status' => 'active']);
        $before = $this->rows();
        $this->getJson('/api/outlook-addin/account-preferences', $this->headers())->assertForbidden();
        self::assertSame($before, $this->rows());
    }

    public function test_token_context_contract_and_exact_field_schema_are_required(): void
    {
        $this->getJson('/api/outlook-addin/account-preferences')->assertUnauthorized();
        foreach (['X-RailTime-Outlook-Context' => 'taskpane', 'X-RailTime-Compose-Contract' => 'old'] as $key => $value) {
            $headers = $this->headers();
            $headers[$key] = $value;
            $this->getJson('/api/outlook-addin/account-preferences', $headers)->assertStatus($key === 'X-RailTime-Outlook-Context' ? 403 : 409);
        }
        $data = ['group' => 'newMessages', 'field' => 'signature', 'enabled' => false,
            'revision' => app(OutlookAddinAutomaticInsertionPolicy::class)->preferencesForUser(User::findOrFail(5))['revision']];
        foreach ([$data + ['user_id' => 6], array_replace($data, ['enabled' => 'false']), array_replace($data, ['field' => 'unknown'])] as $invalid) {
            $this->patchJson('/api/outlook-addin/account-preferences', $invalid, $this->headers())->assertUnprocessable();
        }
        $this->getJson('/api/outlook-addin/account-preferences?user_id=6', $this->headers())->assertUnprocessable();
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public function test_public_config_contains_only_static_settings_endpoints_and_mobile_icons_are_real_pngs(): void
    {
        $this->withoutMiddleware();
        $this->getJson('/outlook-addin/config.json')->assertOk()
            ->assertJsonPath('endpoints.accountPreferences', 'https://example.test/api/outlook-addin/account-preferences')
            ->assertJsonPath('urls.profileSettings', 'https://example.test/user/profile?tab=outlook');
        foreach ([25, 48] as $size) {
            $response = $this->get('/outlook-addin/assets/icon-'.$size.'.png')->assertOk()->assertHeader('Content-Type', 'image/png');
            $dimensions = getimagesizefromstring($response->getContent());
            self::assertSame([$size, $size], [$dimensions[0], $dimensions[1]]);
        }
        $this->get('/outlook-addin/assets/icon-27.png')->assertNotFound();
    }

    private function rows(): array
    {
        $tables = [];
        foreach (['users' => 'users', 'identities' => 'employee_identity_accounts', 'settings' => 'settings', 'audits' => 'activity_log'] as $key => $table) {
            $tables[$key] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $tables;
    }

    private function headers(): array
    {
        if ($this->privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertTrue(openssl_pkey_export($key, $private));
            $this->privateKey = $private;
            $details = openssl_pkey_get_details($key);
            $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            Http::fake(['https://login.microsoftonline.com/'.self::TENANT.'/discovery/v2.0/keys' => Http::response(['keys' => [[
                'kty' => 'RSA', 'use' => 'sig', 'kid' => 'account-preferences-test', 'alg' => 'RS256',
                'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
            ]]])]);
        }
        $token = JWT::encode(['aud' => self::CLIENT, 'azp' => self::CLIENT, 'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0',
            'iat' => time() - 5, 'nbf' => time() - 5, 'exp' => time() + 300, 'tid' => self::TENANT, 'oid' => self::OBJECT,
            'scp' => 'Signature.Read', 'preferred_username' => 'own@company.test'], $this->privateKey, 'RS256', 'account-preferences-test');

        return ['Authorization' => 'Bearer '.$token, 'X-RailTime-Outlook-Mailbox' => 'own@company.test',
            'X-RailTime-Compose-Contract' => 'native-signature-v1', 'X-RailTime-Outlook-Context' => 'account-settings'];
    }
}
