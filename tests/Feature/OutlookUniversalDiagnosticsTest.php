<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Services\DeviceManagement\EmployeeWorkplaceProvisioner;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookUniversalDiagnostics;
use App\Support\OutlookAddin\OutlookUniversalPayloadPresentation;
use App\Support\OutlookAddin\OutlookUniversalPresentation;
use App\Support\OutlookAddin\OutlookUniversalSourceDescriptor;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Psr\Log\AbstractLogger;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookUniversalDiagnosticsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const URL = '/api/outlook-addin/diagnostics';

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const CLIENT = '22222222-2222-4222-8222-222222222222';

    private const OBJECT = '33333333-3333-4333-8333-333333333333';

    private ?string $privateKey = null;

    private AbstractLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->buildMinimalRailTimeSchema();
        config(['app.url' => 'https://mail.example.test', 'cache.default' => 'array',
            'outlook_addin.enabled' => true, 'outlook_addin.deployed' => true,
            'outlook_addin.addin_id' => self::CLIENT, 'outlook_addin.base_url' => 'https://mail.example.test',
            'outlook_addin.entra.tenant_id' => self::TENANT, 'outlook_addin.entra.client_id' => self::CLIENT,
            'outlook_addin.entra.audience' => self::CLIENT, 'outlook_addin.entra.scope' => 'Signature.Read',
            'outlook_addin.entra.scope_uri' => 'api://'.self::CLIENT.'/Signature.Read']);
        Cache::store('array')->flush();
        Http::preventStrayRequests();
        Mail::fake();
        $this->logger = new class extends AbstractLogger
        {
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        Log::swap($this->logger);
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
        User::factory()->create(['id' => 5, 'email' => 'pilot@example.test', 'role' => 'staff', 'status' => true, 'email_verified_at' => now()]);
        User::factory()->create(['id' => 6, 'email' => 'admin@example.test', 'role' => 'admin', 'status' => true, 'email_verified_at' => now()]);
        EmployeeIdentityAccount::create(['user_id' => 5, 'provider' => AccountProvider::Microsoft365,
            'tenant_id' => self::TENANT, 'external_id' => self::OBJECT, 'principal' => 'own@company.test',
            'email' => 'alias@company.test', 'lifecycle_status' => 'active']);
        $this->app->bind(EmployeeWorkplaceProvisioner::class, static fn () => throw new \LogicException('Diagnostics must never activate accounts.'));
        $this->app->bind(OutlookAddinPayloadService::class, static fn () => throw new \LogicException('Diagnostics must never compile or send mail.'));
    }

    public function test_admission_and_readback_are_user_scoped_and_preserve_the_latest_failure(): void
    {
        $service = app(OutlookUniversalDiagnostics::class);
        $user = User::findOrFail(5);
        $service->recordAdmission($user, $this->admission());
        $service->recordReadback($user, $this->readback(false));
        $service->recordAdmission($user, $this->admission('admitted'));
        $status = $service->statusFor($user);
        self::assertSame('admitted', $status['admission']['status']);
        self::assertSame('profile_identity_mismatch', $status['lastAdmissionFailure']['reason']);
        self::assertCount(1, $status['readbackErrors']);
        self::assertSame('SIGNATURE_LAYOUT_UNCONFIRMED', $status['readbackErrors'][0]['code']);
        self::assertArrayHasKey('at', $status['admission']);
        self::assertNull($service->statusFor(User::findOrFail(6))['admission']);
        self::assertNotEmpty($this->logger->records);
        Mail::assertNothingOutgoing();
    }

    public function test_invalid_or_private_service_records_are_rejected_whole_without_overwriting_status(): void
    {
        $service = app(OutlookUniversalDiagnostics::class);
        $user = User::findOrFail(5);
        $service->recordAdmission($user, $this->admission());
        $service->recordReadback($user, $this->readback(false));
        $before = $service->statusFor($user);
        $recordCount = count($this->logger->records);
        foreach ([['html' => '<p>PRIVATE-BODY</p>'], ['token' => 'PRIVATE-TOKEN'], ['reason' => 'private@example.test'],
            ['code' => 'PRIVATE-BODY'], ['phase' => 'private@example.test'], ['host' => 'PRIVATE-BODY'],
            ['sourceLength' => -1], ['outputLength' => PHP_INT_MAX], ['sourceLength' => true]] as $change) {
            $service->recordAdmission($user, array_replace($this->admission(), $change));
            self::assertSame($before, $service->statusFor($user));
        }
        foreach ([['html' => '<p>PRIVATE-BODY</p>'], ['mailbox' => 'private@example.test'], ['code' => 'PRIVATE-BODY'],
            ['phase' => 'private@example.test'], ['host' => 'PRIVATE-BODY'], ['sourceLength' => 1.5],
            ['bodyLength' => -1], ['sourceLength' => PHP_INT_MAX], ['bodyLength' => '35']] as $change) {
            $service->recordReadback($user, array_replace($this->readback(false), $change));
            self::assertSame($before, $service->statusFor($user));
        }
        self::assertSame($recordCount, count($this->logger->records));
        self::assertDoesNotMatchRegularExpression('/PRIVATE-BODY|PRIVATE-TOKEN|private@example/', json_encode($this->logger->records));
    }

    public function test_profile_mismatch_has_a_specific_safe_diagnostic_without_author_or_person_values(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/mail/outlook-v17-office-actions/configured-default.json')), true, 512, JSON_THROW_ON_ERROR);
        $profile = ['VORNAME_NACHNAME' => 'Different synthetic person', 'POSITION' => 'Disposition', 'E_MAIL' => 'mara@example.test',
            'DURCHWAHL' => '+49 30 5550101', 'DURCHWAHL_TEL' => '+49305550101', 'MOBIL' => '+49 170 5550101', 'MOBIL_TEL' => '+491705550101',
            'FIRMENNAME' => 'Example GmbH', 'FIRMEN_WEBSITE_HREF' => 'https://example.test', 'FIRMEN_WEBSITE_LABEL' => 'example.test',
            'FIRMENSTRASSE' => 'Musterweg 12', 'FIRMEN_PLZ_ORT' => '10115 Berlin'];
        $inspection = OutlookUniversalSourceDescriptor::inspectFromCompiled($fixture['source']['combined'], $profile, str_repeat('a', 64));
        self::assertNull($inspection['projection']);
        self::assertSame('profile_identity_mismatch', $inspection['diagnostic']['reason']);
        self::assertSame('profile_identity', $inspection['diagnostic']['phase']);
        self::assertSame('U2E_SOURCE_REJECTED', $inspection['diagnostic']['code']);
        self::assertDoesNotMatchRegularExpression('/Different synthetic person|mara@example|design-spacer/', json_encode($inspection['diagnostic']));
        $service = app(OutlookUniversalDiagnostics::class);
        $subject = User::findOrFail(5);
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $canonical = ['signature' => ['html' => $fixture['source']['standalone']], 'automaticTemplateId' => $id,
            'templates' => [['id' => $id, 'active' => true, 'combinedComposeHtml' => $fixture['source']['combined']]]];
        $projection = new OutlookUniversalPayloadPresentation(new OutlookUniversalPresentation, $service);
        $review = $projection->review($canonical, $profile, null, 'editor', $subject);
        self::assertNull($review['draft']);
        self::assertSame('profile_identity_mismatch', $service->statusFor($subject)['lastAdmissionFailure']['reason']);
        self::assertStringContainsString('profile_identity_mismatch', json_encode($this->logger->records));
        self::assertDoesNotMatchRegularExpression('/Different synthetic person|mara@example|design-spacer/', json_encode($this->logger->records));
        $this->stubApplicationLayout();
        $this->actingAs(User::findOrFail(6));
        $html = view('mail-documents.u2e-status', ['subject' => $subject, 'users' => User::query()->get(),
            'admission' => $review['admission'], 'reviewAdmission' => $review['admission'], 'history' => $service->statusFor($subject),
            'mode' => 'editor', 'templateId' => null, 'previewAvailable' => false])->render();
        self::assertStringContainsString('Rückfall V17', $html);
        self::assertStringContainsString('profile_identity_mismatch', $html);
        self::assertStringNotContainsString('Different synthetic person', $html);
    }

    public function test_history_is_bounded_and_logger_or_cache_failures_do_not_escape(): void
    {
        $service = app(OutlookUniversalDiagnostics::class);
        $user = User::findOrFail(5);
        for ($length = 0; $length < 14; $length++) {
            $service->recordReadback($user, array_replace($this->readback(false), ['bodyLength' => $length]));
        }
        $history = $service->statusFor($user)['readbackErrors'];
        self::assertCount(10, $history);
        self::assertSame(13, $history[0]['bodyLength']);
        self::assertSame(4, $history[9]['bodyLength']);
        Log::swap(new class extends AbstractLogger
        {
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                throw new \RuntimeException('Synthetic logger failure');
            }
        });
        $service->recordAdmission($user, $this->admission());
        self::assertSame('profile_identity_mismatch', $service->statusFor($user)['admission']['reason']);
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('Synthetic cache failure'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('Synthetic cache failure'));
        $service->recordReadback($user, $this->readback(false));
        self::assertSame(['admission' => null, 'lastAdmissionFailure' => null, 'readbackErrors' => []], $service->statusFor($user));
    }

    public function test_authenticated_json_records_only_the_resolved_account_without_business_writes(): void
    {
        $before = $this->businessRows();
        $response = $this->postJson(self::URL, $this->readback(), $this->headers())->assertSuccessful();
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame($before, $this->businessRows());
        $service = app(OutlookUniversalDiagnostics::class);
        self::assertCount(1, $service->statusFor(User::findOrFail(5))['readbackErrors']);
        self::assertCount(0, $service->statusFor(User::findOrFail(6))['readbackErrors']);
        $serialized = json_encode($this->logger->records);
        self::assertStringNotContainsString('Bearer ', $serialized);
        self::assertStringNotContainsString('<p>', $serialized);
        Mail::assertNothingOutgoing();
    }

    public function test_diagnostic_post_requires_token_compose_contract_context_and_sender_binding(): void
    {
        $before = $this->businessRows();
        $this->postJson(self::URL, $this->readback())->assertUnauthorized();
        $headers = $this->headers();
        foreach ([['Authorization' => 'Bearer invalid'], ['X-RailTime-Compose-Contract' => 'old'],
            ['X-RailTime-Outlook-Context' => 'account-settings'], ['X-RailTime-Outlook-Mailbox' => 'foreign@company.test'],
            ['X-RailTime-Outlook-Sender' => 'foreign@company.test']] as $changes) {
            $response = $this->postJson(self::URL, $this->readback(), array_replace($headers, $changes));
            self::assertContains($response->status(), [401, 403, 409]);
        }
        self::assertSame($before, $this->businessRows());
        self::assertCount(0, app(OutlookUniversalDiagnostics::class)->statusFor(User::findOrFail(5))['readbackErrors']);
    }

    public function test_extra_fields_unknown_values_and_invalid_lengths_never_enter_the_log_or_status(): void
    {
        $headers = $this->headers();
        foreach ([['schema' => 2], ['schema' => '1'], ['schema' => true], ['user_id' => 6], ['html' => '<p>PRIVATE-BODY</p>'], ['token' => 'PRIVATE-TOKEN'],
            ['host' => 'private@example.test'], ['code' => 'PRIVATE-BODY'], ['phase' => 'private@example.test'],
            ['sourceLength' => -1], ['bodyLength' => PHP_INT_MAX], ['sourceLength' => '13817'], ['bodyLength' => true]] as $changes) {
            $this->postJson(self::URL, array_replace($this->readback(), $changes), $headers)->assertUnprocessable();
        }
        self::assertCount(0, app(OutlookUniversalDiagnostics::class)->statusFor(User::findOrFail(5))['readbackErrors']);
        self::assertDoesNotMatchRegularExpression('/PRIVATE-BODY|PRIVATE-TOKEN|private@example/', json_encode($this->logger->records));
    }

    public function test_inactive_identity_cannot_be_activated_by_a_diagnostic_post(): void
    {
        $headers = $this->headers();
        User::findOrFail(5)->forceFill(['status' => false])->saveQuietly();
        $before = $this->businessRows();
        $this->postJson(self::URL, $this->readback(), $headers)->assertForbidden();
        self::assertSame($before, $this->businessRows());
    }

    public function test_admin_status_displays_the_selected_account_reason_and_denies_other_actors(): void
    {
        $this->stubApplicationLayout();
        $service = app(OutlookUniversalDiagnostics::class);
        $service->recordAdmission(User::findOrFail(5), $this->admission());
        $service->recordReadback(User::findOrFail(5), $this->readback(false));
        $url = '/administrator/mail-vorlagen/u2e-entwurf?status=1&user=5';
        $this->get($url)->assertForbidden();
        $this->actingAs(User::findOrFail(5))->get($url)->assertForbidden();
        $this->actingAs(User::findOrFail(6))->get($url)->assertOk()
            ->assertSee('Rückfall V17')->assertSee('outside_pilot')->assertSee('SIGNATURE_LAYOUT_UNCONFIRMED');
        $this->get('/administrator/mail-vorlagen/u2e-entwurf?status=1&user=6')->assertOk()
            ->assertDontSee('profile_identity_mismatch')->assertDontSee('SIGNATURE_LAYOUT_UNCONFIRMED');
    }

    private function stubApplicationLayout(): void
    {
        $directory = sys_get_temp_dir().'/u2e-diagnostics-layout-'.bin2hex(random_bytes(8));
        mkdir($directory.'/layouts', 0777, true);
        file_put_contents($directory.'/layouts/master.blade.php', '@yield("content")');
        $this->app['view']->getFinder()->prependLocation($directory);
        $this->app['view']->getFinder()->flush();
        $this->beforeApplicationDestroyed(static function () use ($directory): void {
            unlink($directory.'/layouts/master.blade.php');
            rmdir($directory.'/layouts');
            rmdir($directory);
        });
    }

    private function admission(string $status = 'fallback'): array
    {
        return ['status' => $status, 'reason' => $status === 'admitted' ? 'admitted' : 'profile_identity_mismatch',
            'phase' => $status === 'admitted' ? 'complete' : 'profile_identity', 'host' => 'server',
            'code' => $status === 'admitted' ? 'U2E_ADMITTED' : 'U2E_SOURCE_REJECTED', 'sourceLength' => 31232, 'outputLength' => $status === 'admitted' ? 13817 : null];
    }

    private function readback(bool $schema = true): array
    {
        return ($schema ? ['schema' => 1] : []) + ['host' => 'OutlookIOS', 'code' => 'SIGNATURE_LAYOUT_UNCONFIRMED',
            'phase' => 'readback', 'sourceLength' => 13817, 'bodyLength' => 35];
    }

    private function businessRows(): array
    {
        $rows = [];
        foreach (['users', 'user_profiles', 'employee_identity_accounts', 'settings'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
        }

        return $rows;
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
                'kty' => 'RSA', 'use' => 'sig', 'kid' => 'u2e-diagnostic-test', 'alg' => 'RS256',
                'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
            ]]])]);
        }
        $token = JWT::encode(['aud' => self::CLIENT, 'azp' => self::CLIENT, 'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0',
            'iat' => time() - 5, 'nbf' => time() - 5, 'exp' => time() + 300, 'tid' => self::TENANT, 'oid' => self::OBJECT,
            'scp' => 'Signature.Read', 'preferred_username' => 'own@company.test'], $this->privateKey, 'RS256', 'u2e-diagnostic-test');

        return ['Authorization' => 'Bearer '.$token, 'X-RailTime-Outlook-Mailbox' => 'own@company.test',
            'X-RailTime-Outlook-Sender' => 'alias@company.test', 'X-RailTime-Compose-Contract' => 'native-signature-v1',
            'X-RailTime-Outlook-Context' => 'event'];
    }
}
