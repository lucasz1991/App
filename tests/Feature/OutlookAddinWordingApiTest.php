<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\EmployeeWorkplaceProvisioner;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookAddinWordingPolicy;
use Firebase\JWT\JWT;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookAddinWordingApiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const URL = '/api/outlook-addin/account-wording';

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
        Schema::create('mail_documents', static function (Blueprint $table): void {
            $table->id();
            $table->text('html');
            $table->unsignedInteger('version');
        });
        DB::table('mail_documents')->insert(['id' => 1, 'html' => '<p>Synthetic immutable mail source</p>', 'version' => 7]);
        foreach ([5, 6] as $id) {
            User::factory()->create(['id' => $id, 'role' => 'staff', 'status' => true, 'email_verified_at' => now()]);
        }
        EmployeeIdentityAccount::create(['user_id' => 5, 'provider' => AccountProvider::Microsoft365,
            'tenant_id' => self::TENANT, 'external_id' => self::OBJECT, 'principal' => 'own@company.test',
            'email' => 'alias@company.test', 'lifecycle_status' => 'active']);
        $this->app->bind(EmployeeWorkplaceProvisioner::class, static fn () => throw new \LogicException('Wording API must never activate accounts.'));
        $this->app->bind(OutlookAddinPayloadService::class, static fn () => throw new \LogicException('Wording API must never compile mail.'));
    }

    public function test_read_is_own_account_only_without_writes_or_mail_artifacts_and_sender_is_ignored(): void
    {
        $before = $this->rows();
        $response = $this->getJson(self::URL, $this->headers() + ['X-RailTime-Outlook-Sender' => 'foreign@other.test'])->assertOk();
        self::assertSame(['wordingPreferences', 'binding'], array_keys($response->json()));
        self::assertSame(['schema', 'greeting', 'farewell', 'revision'], array_keys($response->json('wordingPreferences')));
        $response->assertJsonPath('wordingPreferences.schema', 1)->assertJsonPath('wordingPreferences.greeting', 'Guten Tag,')
            ->assertJsonPath('wordingPreferences.farewell', 'Mit freundlichen Grüßen,')
            ->assertJsonPath('binding.mailboxAddress', 'own@company.test')->assertJsonPath('binding.senderAddress', 'own@company.test');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $response->json('wordingPreferences.revision'));
        self::assertSame($before, $this->rows());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_patch_and_stale_retry_change_only_own_texts_and_hash_only_audit(): void
    {
        Setting::setValue('outlook_addin', 'automatic_insertion_user_5', ['enabled' => false, 'keep' => ['exact' => true]]);
        Setting::setValue('outlook_addin', 'template_wording_user_6', ['schema' => 1, 'greeting' => 'Anderes Hallo,', 'farewell' => 'Anderer Gruß,']);
        Setting::setValue('outlook_addin', 'template_wording_user_5', ['schema' => 1, 'greeting' => 'Guten Tag,', 'farewell' => 'Mit freundlichen Grüßen,', 'keep' => ['exact' => 'metadata']]);
        $before = $this->rows();
        $revision = $this->revision();
        $data = ['greeting' => 'Hallo & willkommen,', 'farewell' => 'Freundliche Grüße,', 'revision' => $revision];
        $response = $this->patchJson(self::URL, $data, $this->headers())->assertOk()
            ->assertJsonPath('wordingPreferences.greeting', $data['greeting'])->assertJsonPath('wordingPreferences.farewell', $data['farewell']);
        self::assertNotSame($revision, $response->json('wordingPreferences.revision'));
        $written = $this->rows();
        $this->patchJson(self::URL, $data, $this->headers())->assertStatus(409)
            ->assertJsonPath('error', 'outlook_addin_wording_conflict')
            ->assertJsonPath('wordingPreferences.greeting', $data['greeting'])
            ->assertJsonPath('binding.mailboxAddress', 'own@company.test');
        self::assertSame($written, $this->rows());
        foreach (['users', 'identities', 'documents'] as $table) {
            self::assertSame($before[$table], $written[$table]);
        }
        $untouched = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $row): bool => $row['key'] !== 'template_wording_user_5'));
        self::assertSame($untouched($before['settings']), $untouched($written['settings']));
        self::assertSame(['exact' => 'metadata'], Setting::getValueUncached('outlook_addin', 'template_wording_user_5')['keep']);
        $audit = DB::table('activity_log')->sole();
        self::assertSame('outlook_addin_template_wording_changed', $audit->description);
        self::assertSame(5, $audit->causer_id);
        self::assertSame(5, $audit->subject_id);
        self::assertStringNotContainsString($data['greeting'], $audit->properties);
        self::assertStringNotContainsString($data['farewell'], $audit->properties);
        $properties = json_decode($audit->properties, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['employee_id', 'greeting_changed', 'farewell_changed', 'revision_before', 'revision_after'], array_keys($properties));
    }

    public function test_maximum_unicode_plain_values_are_preserved_and_a_noop_has_no_audit(): void
    {
        $data = ['greeting' => str_repeat('A', 120), 'farewell' => str_repeat('😀', 120), 'revision' => $this->revision()];
        $response = $this->patchJson(self::URL, $data, $this->headers())->assertOk()
            ->assertJsonPath('wordingPreferences.greeting', $data['greeting'])->assertJsonPath('wordingPreferences.farewell', $data['farewell']);
        $before = $this->rows();
        $data['revision'] = $response->json('wordingPreferences.revision');
        $this->patchJson(self::URL, $data, $this->headers())->assertOk();
        self::assertSame($before, $this->rows());
    }

    public static function invalidRequests(): array
    {
        return [
            'empty' => [['greeting' => '']], 'markup' => [['greeting' => '<b>Hallo</b>']],
            'encoded markup' => [['farewell' => '&lt;img src=x&gt;']], 'template token' => [['greeting' => '{{OUTLOOK_GRUSS}}']],
            'line break' => [['greeting' => "Hallo,\r\nZweite Zeile"]], 'over limit' => [['farewell' => str_repeat('x', 121)]],
            'non string' => [['farewell' => ['Gruß']]], 'foreign target' => [['user_id' => 6]],
            'automation field' => [['newMessages' => ['signature' => false]]], 'unknown schema' => [['schema' => 2]],
            'bad revision' => [['revision' => str_repeat('A', 64)]],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_or_extra_values_never_write_partially(array $changes): void
    {
        $data = array_replace(['greeting' => 'Hallo,', 'farewell' => 'Viele Grüße,', 'revision' => $this->revision()], $changes);
        $before = $this->rows();
        $this->patchJson(self::URL, $data, $this->headers())->assertUnprocessable()->assertJsonPath('error', 'outlook_addin_wording_invalid');
        self::assertSame($before, $this->rows());
    }

    public function test_token_exact_context_contract_and_json_are_required_even_with_a_valid_web_session(): void
    {
        $this->actingAs(User::findOrFail(5));
        $this->getJson(self::URL)->assertUnauthorized();
        $headers = $this->headers();
        foreach (['Authorization' => 'Bearer invalid', 'X-RailTime-Outlook-Context' => 'taskpane', 'X-RailTime-Compose-Contract' => 'old'] as $field => $value) {
            $this->getJson(self::URL, array_replace($headers, [$field => $value]))->assertStatus(match ($field) {
                'Authorization' => 401, 'X-RailTime-Outlook-Context' => 403, default => 409,
            });
        }
        $before = $this->rows();
        $this->getJson(self::URL.'?user_id=6', $headers)->assertUnprocessable();
        $this->patch(self::URL, ['greeting' => 'Hallo,', 'farewell' => 'Viele Grüße,', 'revision' => $this->revision()], $headers)->assertUnprocessable();
        self::assertSame($before, $this->rows());
    }

    public static function unavailableAccounts(): array
    {
        return [
            'inactive' => ['user', ['status' => false]], 'unverified' => ['user', ['email_verified_at' => null]],
            'guest' => ['user', ['role' => 'guest']], 'revoked link' => ['identity', ['lifecycle_status' => 'inactive']],
            'unlinked' => ['identity', ['user_id' => null]], 'foreign tenant' => ['identity', ['tenant_id' => '44444444-4444-4444-8444-444444444444']],
        ];
    }

    #[DataProvider('unavailableAccounts')]
    public function test_ineligible_accounts_cannot_read_or_write_and_never_activate(string $kind, array $changes): void
    {
        $data = ['greeting' => 'Hallo,', 'farewell' => 'Viele Grüße,', 'revision' => $this->revision()];
        ($kind === 'user' ? User::findOrFail(5) : EmployeeIdentityAccount::findOrFail(1))->forceFill($changes)->saveQuietly();
        $before = $this->rows();
        $this->getJson(self::URL, $this->headers())->assertForbidden();
        $this->patchJson(self::URL, $data, $this->headers())->assertForbidden();
        self::assertSame($before, $this->rows());
    }

    public function test_mailbox_and_ambiguous_binding_cannot_switch_target_from_received_sender(): void
    {
        $before = $this->rows();
        $this->getJson(self::URL, array_replace($this->headers(), ['X-RailTime-Outlook-Mailbox' => 'foreign@company.test']))->assertForbidden();
        self::assertSame($before, $this->rows());
        EmployeeIdentityAccount::create(['user_id' => 6, 'provider' => AccountProvider::Microsoft365,
            'tenant_id' => self::TENANT, 'external_id' => self::OBJECT, 'principal' => 'other@company.test', 'lifecycle_status' => 'active']);
        $before = $this->rows();
        $this->getJson(self::URL, $this->headers())->assertForbidden();
        self::assertSame($before, $this->rows());
    }

    public function test_revoked_binding_between_resolver_and_transaction_is_checked_again_before_write(): void
    {
        $data = ['greeting' => 'Hallo,', 'farewell' => 'Viele Grüße,', 'revision' => $this->revision()];
        $triggered = false;
        Event::listen(TransactionBeginning::class, static function () use (&$triggered): void {
            if (! $triggered) {
                $triggered = true;
                DB::table('employee_identity_accounts')->where('id', 1)->update(['lifecycle_status' => 'inactive']);
            }
        });
        $this->patchJson(self::URL, $data, $this->headers())->assertForbidden();
        self::assertTrue($triggered);
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    private function revision(): string
    {
        return app(OutlookAddinWordingPolicy::class)->preferencesForUser(User::findOrFail(5))['revision'];
    }

    private function rows(): array
    {
        $result = [];
        foreach (['users' => 'users', 'identities' => 'employee_identity_accounts', 'settings' => 'settings', 'audits' => 'activity_log', 'documents' => 'mail_documents'] as $key => $table) {
            $result[$key] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $result;
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
                'kty' => 'RSA', 'use' => 'sig', 'kid' => 'wording-api-test', 'alg' => 'RS256',
                'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
            ]]])]);
        }
        $token = JWT::encode(['aud' => self::CLIENT, 'azp' => self::CLIENT, 'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0',
            'iat' => time() - 5, 'nbf' => time() - 5, 'exp' => time() + 300, 'tid' => self::TENANT, 'oid' => self::OBJECT,
            'scp' => 'Signature.Read', 'preferred_username' => 'own@company.test'], $this->privateKey, 'RS256', 'wording-api-test');

        return ['Authorization' => 'Bearer '.$token, 'X-RailTime-Outlook-Mailbox' => 'own@company.test',
            'X-RailTime-Compose-Contract' => 'native-signature-v1', 'X-RailTime-Outlook-Context' => 'account-settings'];
    }
}
