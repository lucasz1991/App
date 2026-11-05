<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Services\DeviceManagement\EmployeeWorkplaceProvisioner;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticFixtureStore;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticFixtureValidator;
use App\Support\OutlookAddin\OutlookUniversalDiagnostics;
use App\Support\OutlookAddin\OutlookUniversalPayloadPresentation;
use App\Support\OutlookAddin\OutlookUniversalPresentation;
use App\Support\OutlookAddin\OutlookUniversalSourceDescriptor;
use Firebase\JWT\JWT;
use Illuminate\Cache\ArrayStore;
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

    public function test_bundle_contract_failure_has_finite_diagnostics_without_reclassifying_source_errors(): void
    {
        $payload = array_replace($this->readback(), ['code' => 'U2E_BUNDLE_STALE', 'phase' => 'preflight',
            'step' => 'bundle.contract', 'checkpoint' => 'bundle.contract',
            'difference' => ['key' => 'bundle.contract', 'index' => null, 'expected' => 2, 'actual' => 1]]);
        $this->postJson(self::URL, $payload, $this->headers())->assertStatus(202);
        $service = app(OutlookUniversalDiagnostics::class);
        $user = User::findOrFail(5);
        $event = $service->statusFor($user)['readbackErrors'][0];
        self::assertSame('U2E_BUNDLE_STALE', $event['code']);
        self::assertSame('bundle.contract', $event['checkpoint']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $event['serverBuildRevision']);
        $this->postJson(self::URL, array_replace($this->readback(), ['code' => 'U2E_SOURCE_INVALID']), $this->headers())->assertStatus(202);
        $original = $service->statusFor($user)['readbackErrors'][0];
        self::assertSame('U2E_SOURCE_INVALID', $original['code']);
        self::assertArrayNotHasKey('serverBuildRevision', $original);
        $this->postJson(self::URL, $payload + ['sourceHash' => str_repeat('a', 64)], $this->headers())->assertUnprocessable();
        Mail::assertNothingSent();
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

    public function test_failures_are_warnings_and_successful_admission_remains_notice(): void
    {
        $service = app(OutlookUniversalDiagnostics::class);
        $user = User::findOrFail(5);
        $service->recordAdmission($user, $this->admission());
        $service->recordAdmission($user, array_replace($this->admission(), [
            'reason' => 'outside_pilot', 'phase' => 'pilot', 'code' => 'U2E_OUTSIDE_PILOT',
        ]));
        $service->recordReadback($user, $this->readback(false));
        $service->recordAdmission($user, $this->admission('admitted'));

        self::assertSame(['warning', 'warning', 'warning', 'notice'], array_column($this->logger->records, 'level'));
        self::assertSame(['admission', 'admission', 'readback', 'admission'], array_column(array_column($this->logger->records, 'context'), 'kind'));
        self::assertSame('admitted', $service->statusFor($user)['admission']['status']);
        self::assertSame('profile_identity_mismatch', $service->statusFor($user)['lastAdmissionFailure']['reason']);
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

    public function test_checkpoint_and_hashed_difference_are_logged_without_raw_values(): void
    {
        $payload = $this->readback() + ['checkpoint' => 'evidence.text', 'difference' => [
            'key' => 'evidence.text.sha256', 'index' => null, 'expected' => 'sha256:'.str_repeat('a', 64).':13',
            'actual' => 'sha256:'.str_repeat('b', 64).':14']];
        $this->postJson(self::URL, $payload, $this->headers())->assertStatus(202);
        $event = app(OutlookUniversalDiagnostics::class)->statusFor(User::findOrFail(5))['readbackErrors'][0];
        self::assertSame($payload['checkpoint'], $event['checkpoint']);
        self::assertSame($payload['difference'], $event['difference']);
        self::assertSame('warning', $this->logger->records[0]['level']);
        foreach ([['checkpoint' => 'PRIVATE-BODY'], ['difference' => ['key' => 'evidence.text', 'index' => null, 'expected' => 'PRIVATE-BODY', 'actual' => null]],
            ['difference' => ['key' => 'evidence.text', 'index' => 4096, 'expected' => 1, 'actual' => 2]], ['body' => 'PRIVATE-BODY']] as $change) {
            $this->postJson(self::URL, array_replace($payload, $change), $this->headers())->assertUnprocessable();
        }
        self::assertDoesNotMatchRegularExpression('/PRIVATE-BODY/', json_encode($this->logger->records));
    }

    public function test_fixture_upload_requires_the_server_resolved_pilot_and_keeps_html_out_of_logs(): void
    {
        $html = '<table id="rt-sig-u2-'.str_repeat('a', 24).'"><tr>'.str_repeat('<td>rt-redacted</td>', 4)
            .'</tr><tr><td colspan="3"></td></tr><tr><td colspan="4">rt-redacted</td></tr></table>';
        $fixture = ['schema' => 1, 'format' => 'u2e-redacted-html-v1', 'html' => $html, 'expectedHtml' => $html,
            'sourceLength' => strlen($html), 'sha256' => hash('sha256', $html), 'expectedSha256' => hash('sha256', $html)];
        $payload = $this->readback() + ['checkpoint' => 'images.src', 'fixture' => $fixture];
        $this->postJson(self::URL, $payload, $this->headers())->assertForbidden();
        self::assertSame([], app(OutlookUniversalDiagnosticFixtureStore::class)->listingFor(User::findOrFail(5)));

        $headers = $this->headers(true);
        $before = $this->businessRows();
        $response = $this->postJson(self::URL, $payload, $headers)->assertStatus(202);
        $id = $response->json('fixture.id');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $id);
        self::assertSame($fixture, app(OutlookUniversalDiagnosticFixtureStore::class)->get(User::findOrFail(5), $id)['fixture']);
        self::assertSame($before, $this->businessRows());
        self::assertSame($id, app(OutlookUniversalDiagnostics::class)->statusFor(User::findOrFail(5))['readbackErrors'][0]['fixtureId']);
        self::assertDoesNotMatchRegularExpression('/<table|rt-redacted|expectedHtml/', json_encode($this->logger->records));

        $unsafe = array_replace($fixture, ['html' => str_replace('rt-redacted', 'PRIVATE-BODY', $html)]);
        $unsafe['sha256'] = hash('sha256', $unsafe['html']);
        $this->postJson(self::URL, array_replace($payload, ['fixture' => $unsafe]), $headers)->assertUnprocessable();
        $this->postJson(self::URL, array_replace($payload, ['fixture' => $fixture + ['body' => 'PRIVATE-BODY']]), $headers)->assertUnprocessable();
        $this->postJson(self::URL, $this->readback() + ['fixture' => $fixture, 'padding' => str_repeat('x', 262145)], $headers)->assertUnprocessable();
    }

    public function test_valid_checkpoint_survives_an_invalid_fixture_without_storing_raw_html(): void
    {
        $headers = $this->headers(true);
        $payload = $this->readback() + ['checkpoint' => 'images.src', 'difference' => ['key' => 'images.src', 'index' => 0, 'expected' => 13, 'actual' => 12],
            'fixture' => ['schema' => 1, 'format' => 'u2e-redacted-html-v1', 'html' => '<p>PRIVATE-BODY</p>']];
        $this->postJson(self::URL, $payload, $headers)->assertUnprocessable();
        self::assertSame([], app(OutlookUniversalDiagnosticFixtureStore::class)->listingFor(User::findOrFail(5)));
        $event = app(OutlookUniversalDiagnostics::class)->statusFor(User::findOrFail(5))['readbackErrors'][0];
        self::assertSame('images.src', $event['checkpoint']);
        self::assertSame($payload['difference'], $event['difference']);
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertDoesNotMatchRegularExpression('/PRIVATE-BODY|<p>|fixture/', json_encode($this->logger->records));
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

    public function test_operation_metadata_preserves_original_code_and_validated_context_without_business_writes(): void
    {
        $headers = $this->headers();
        $before = $this->businessRows();
        $payload = array_replace($this->readback(), ['code' => 'U2E_INSERT_UNCERTAIN', 'originalCode' => 'U2E_NEW_DRAFT_REQUIRED',
            'step' => 'snapshot.region', 'bodyLengthOrigin' => 'actual', 'composeType' => 'reply']);
        $this->postJson(self::URL, $payload, $headers)->assertStatus(202);
        $event = app(OutlookUniversalDiagnostics::class)->statusFor(User::findOrFail(5))['readbackErrors'][0];
        self::assertSame('U2E_INSERT_UNCERTAIN', $event['code']);
        self::assertSame('U2E_NEW_DRAFT_REQUIRED', $event['originalCode']);
        self::assertSame('snapshot.region', $event['step']);
        self::assertSame('actual', $event['bodyLengthOrigin']);
        self::assertSame('reply', $event['composeType']);
        self::assertSame('event', $event['context']);
        self::assertSame($before, $this->businessRows());
        foreach (['originalCode', 'step', 'bodyLengthOrigin', 'composeType', 'automaticReason', 'context'] as $key) {
            $this->postJson(self::URL, array_replace($payload, [$key => 'PRIVATE-VALUE']), $headers)->assertUnprocessable();
        }
        self::assertCount(1, app(OutlookUniversalDiagnostics::class)->statusFor(User::findOrFail(5))['readbackErrors']);
        self::assertDoesNotMatchRegularExpression('/PRIVATE-VALUE/', json_encode($this->logger->records));
        $this->stubApplicationLayout();
        $this->actingAs(User::findOrFail(6))->get('/administrator/mail-vorlagen/u2e-entwurf?status=1&user=5')
            ->assertOk()->assertSee('Originalcode: U2E_NEW_DRAFT_REQUIRED')->assertSee('snapshot.region')
            ->assertSee('Rücklesen nach Schreiben')->assertSee('Verfassen: reply');
        Mail::assertNothingOutgoing();
    }

    public function test_automatic_skip_requires_a_reason_and_the_server_resolved_pilot(): void
    {
        $payload = array_replace($this->readback(), ['code' => 'U2E_AUTOMATIC_SKIPPED', 'phase' => 'preflight',
            'automaticReason' => 'compose_type_unknown', 'composeType' => 'unknown']);
        $this->postJson(self::URL, $payload, $this->headers())->assertForbidden();
        $headers = $this->headers(true);
        $before = $this->businessRows();
        $this->postJson(self::URL, $payload, $headers)->assertStatus(202);
        $event = app(OutlookUniversalDiagnostics::class)->statusFor(User::findOrFail(5))['readbackErrors'][0];
        self::assertSame('compose_type_unknown', $event['automaticReason']);
        self::assertSame('event', $event['context']);
        unset($payload['automaticReason']);
        $this->postJson(self::URL, $payload, $headers)->assertUnprocessable();
        self::assertSame($before, $this->businessRows());
        $service = app(OutlookUniversalDiagnostics::class);
        $service->recordReadback(User::findOrFail(6), array_replace($this->readback(false), ['code' => 'U2E_AUTOMATIC_SKIPPED', 'automaticReason' => 'automatic_disabled']));
        self::assertSame([], $service->statusFor(User::findOrFail(6))['readbackErrors']);
        Mail::assertNothingOutgoing();
    }

    public function test_diagnostic_receipt_suppresses_repeated_endpoint_side_effects_without_logging_the_identifier(): void
    {
        $headers = $this->headers();
        $id = str_repeat('a', 64);
        $payload = $this->readback() + ['checkpoint' => 'colors.line', 'diagnosticId' => $id];
        $before = $this->businessRows();
        $first = $this->postJson(self::URL, $payload, $headers)->assertStatus(202);
        self::assertNull($first->json('duplicate'));
        $this->postJson(self::URL, $payload, $headers)->assertStatus(202)->assertJson(['accepted' => true, 'duplicate' => true]);
        $events = app(OutlookUniversalDiagnostics::class)->statusFor(User::findOrFail(5))['readbackErrors'];
        self::assertCount(1, $events);
        self::assertCount(1, $this->logger->records);
        self::assertArrayNotHasKey('diagnosticId', $events[0]);
        self::assertStringNotContainsString($id, json_encode($this->logger->records));
        self::assertSame($before, $this->businessRows());
        $this->postJson(self::URL, array_replace($payload, ['diagnosticId' => str_repeat('b', 64)]), $headers)->assertStatus(202);
        self::assertCount(2, $this->logger->records);
        Mail::assertNothingOutgoing();
    }

    public function test_the_same_diagnostic_identifier_is_independent_for_different_authenticated_users(): void
    {
        $headers = $this->headers();
        $otherObject = '44444444-4444-4444-8444-444444444444';
        EmployeeIdentityAccount::create(['user_id' => 6, 'provider' => AccountProvider::Microsoft365,
            'tenant_id' => self::TENANT, 'external_id' => $otherObject, 'principal' => 'admin@example.test',
            'email' => 'admin@example.test', 'lifecycle_status' => 'active']);
        $other = $headers;
        $other['Authorization'] = 'Bearer '.JWT::encode(['aud' => self::CLIENT, 'azp' => self::CLIENT,
            'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0', 'iat' => time() - 5,
            'nbf' => time() - 5, 'exp' => time() + 300, 'tid' => self::TENANT, 'oid' => $otherObject,
            'scp' => 'Signature.Read', 'preferred_username' => 'admin@example.test'], $this->privateKey, 'RS256', 'u2e-diagnostic-test');
        $other['X-RailTime-Outlook-Mailbox'] = $other['X-RailTime-Outlook-Sender'] = 'admin@example.test';
        $payload = $this->readback() + ['diagnosticId' => str_repeat('a', 64)];
        $this->postJson(self::URL, $payload, $headers)->assertStatus(202);
        $response = $this->postJson(self::URL, $payload, $other)->assertStatus(202);
        self::assertNull($response->json('duplicate'));
        foreach ([5, 6] as $id) {
            self::assertCount(1, app(OutlookUniversalDiagnostics::class)->statusFor(User::findOrFail($id))['readbackErrors']);
        }
        self::assertCount(2, $this->logger->records);
    }

    public function test_invalid_identifiers_schema_and_failed_authentication_cannot_reserve_a_receipt(): void
    {
        $headers = $this->headers();
        $valid = $this->readback() + ['diagnosticId' => str_repeat('a', 64)];
        $this->postJson(self::URL, $valid)->assertUnauthorized();
        $this->postJson(self::URL, $valid, array_replace($headers, ['X-RailTime-Outlook-Sender' => 'foreign@example.test']))->assertForbidden();
        foreach ([null, true, 123, [], str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64),
            str_repeat('a', 64)."\n", str_repeat('a', 64)."\r\n", 'pending:PRIVATE-TOKEN'] as $id) {
            $this->postJson(self::URL, array_replace($valid, ['diagnosticId' => $id]), $headers)->assertUnprocessable();
        }
        $this->postJson(self::URL, array_replace($valid, ['code' => 'PRIVATE-CODE']), $headers)->assertUnprocessable();
        self::assertSame([], $this->logger->records);
        $response = $this->postJson(self::URL, $valid, $headers)->assertStatus(202);
        self::assertNull($response->json('duplicate'));
        self::assertCount(1, $this->logger->records);
    }

    public function test_a_duplicate_does_not_store_another_different_valid_fixture_or_extend_the_receipt_ttl(): void
    {
        $headers = $this->headers(true);
        $html = '<table id="rt-sig-u2-'.str_repeat('a', 24).'" width="1040"><tr>'.str_repeat('<td>rt-redacted</td>', 4)
            .'</tr><tr><td colspan="3"></td></tr><tr><td colspan="4">rt-redacted</td></tr></table>';
        $fixture = ['schema' => 1, 'format' => 'u2e-redacted-html-v1', 'html' => $html, 'expectedHtml' => $html,
            'sourceLength' => strlen($html), 'sha256' => hash('sha256', $html), 'expectedSha256' => hash('sha256', $html)];
        $payload = $this->readback() + ['checkpoint' => 'images.src', 'fixture' => $fixture, 'diagnosticId' => str_repeat('a', 64)];
        $first = $this->postJson(self::URL, $payload, $headers)->assertStatus(202);
        $capture = $first->json('fixture');
        $receipts = array_filter(Cache::getStore()->all(), static fn (string $key): bool => str_starts_with($key, 'outlook:u2e:diagnostic-receipts:v1:'), ARRAY_FILTER_USE_KEY);
        self::assertCount(1, $receipts);
        $receiptKey = array_key_first($receipts);
        $expires = $receipts[$receiptKey]['expiresAt'];
        $changed = str_replace('width="1040"', 'width="1041"', $html);
        $other = array_replace($fixture, ['html' => $changed, 'sha256' => hash('sha256', $changed)]);
        $this->travel(6)->days();
        $this->postJson(self::URL, array_replace($payload, ['fixture' => $other]), $headers)->assertStatus(202)->assertJson(['duplicate' => true]);
        self::assertSame($expires, Cache::getStore()->all()[$receiptKey]['expiresAt']);
        $store = app(OutlookUniversalDiagnosticFixtureStore::class);
        self::assertSame([$capture], $store->listingFor(User::findOrFail(5)));
        self::assertSame($fixture, $store->get(User::findOrFail(5), $capture['id'])['fixture']);
        self::assertCount(1, $this->logger->records);
        $this->travel(2)->days();
        self::assertNull(Cache::get($receiptKey));
        $response = $this->postJson(self::URL, $payload, $headers)->assertStatus(202);
        self::assertNull($response->json('duplicate'));
        self::assertCount(2, $this->logger->records);
    }

    public function test_an_invalid_fixture_does_not_consume_the_identifier_for_a_corrected_diagnostic(): void
    {
        $headers = $this->headers(true);
        $payload = $this->readback() + ['checkpoint' => 'images.src', 'diagnosticId' => str_repeat('a', 64)];
        $this->postJson(self::URL, $payload + ['fixture' => ['html' => 'PRIVATE-BODY']], $headers)->assertUnprocessable();
        self::assertCount(1, $this->logger->records, 'Existing safe metadata remains recorded when the HTML pair is rejected.');
        self::assertStringNotContainsString('PRIVATE-BODY', json_encode($this->logger->records));
        $response = $this->postJson(self::URL, $payload, $headers)->assertStatus(202);
        self::assertNull($response->json('duplicate'));
        self::assertCount(2, $this->logger->records);
    }

    public function test_unavailable_atomic_receipt_storage_returns_503_without_claiming_a_duplicate_or_recording(): void
    {
        $headers = $this->headers();
        foreach ([false, true] as $throws) {
            $driver = $throws ? 'receipt-throws-test' : 'receipt-false-test';
            Cache::extend($driver, fn () => Cache::repository(new class($throws) extends ArrayStore
            {
                public function __construct(private readonly bool $throws)
                {
                    parent::__construct();
                }

                public function add($key, $value, $seconds = 0): bool
                {
                    if ($this->throws) {
                        throw new \RuntimeException('Synthetic unavailable cache.');
                    }

                    return false;
                }
            }));
            config(['cache.default' => $driver, 'cache.stores.'.$driver => ['driver' => $driver]]);
            $payload = $this->readback() + ['diagnosticId' => str_repeat('a', 64)];
            $this->postJson(self::URL, $payload, $headers)->assertStatus(503)->assertJson(['error' => 'outlook_addin_diagnostics_storage_unavailable']);
            self::assertSame([], $this->logger->records);
            self::assertSame([], app(OutlookUniversalDiagnostics::class)->statusFor(User::findOrFail(5))['readbackErrors']);
        }
    }

    public function test_legacy_diagnostics_without_an_identifier_keep_the_existing_endpoint_behavior(): void
    {
        $headers = $this->headers();
        $this->postJson(self::URL, $this->readback(), $headers)->assertStatus(202);
        $this->postJson(self::URL, $this->readback(), $headers)->assertStatus(202);
        self::assertCount(2, $this->logger->records);
    }

    public function test_boundary_fixtures_are_private_pilot_only_and_accept_only_mobile_or_web_quote_preflight(): void
    {
        $signature = '<table id="rt-sig-u2-'.str_repeat('a', 24).'"><tr>'.str_repeat('<td>rt-redacted</td>', 4)
            .'</tr><tr><td colspan="3"></td></tr><tr><td colspan="4">rt-redacted</td></tr></table>';
        $html = '<div data-rt-boundary="1"><div id="ms-outlook-mobile-signature"></div><hr><div id="divrplyfwdmsg"><p></p></div></div>';
        $fixture = ['schema' => 1, 'format' => 'u2e-redacted-compose-boundary-v1', 'html' => $html,
            'expectedHtml' => $signature, 'sourceLength' => 1234, 'sha256' => hash('sha256', $html), 'expectedSha256' => hash('sha256', $signature)];
        $payload = array_replace($this->readback(), ['code' => 'U2E_NEW_DRAFT_REQUIRED', 'phase' => 'preflight',
            'step' => 'snapshot.region', 'composeType' => 'forward', 'fixture' => $fixture]);
        $this->postJson(self::URL, $payload, $this->headers())->assertForbidden();
        $headers = $this->headers(true);
        $business = $this->businessRows();
        foreach (['OutlookIOS', 'OutlookAndroid', 'OutlookWebApp'] as $host) {
            foreach (['reply', 'forward'] as $composeType) {
                $response = $this->postJson(self::URL, array_replace($payload, compact('host', 'composeType')), $headers)->assertStatus(202);
                $capture = app(OutlookUniversalDiagnosticFixtureStore::class)->get(User::findOrFail(5), $response->json('fixture.id'));
                self::assertSame($fixture, $capture['fixture']);
            }
        }
        foreach ([['host' => 'Outlook'], ['composeType' => 'newMail'], ['step' => 'source.state'],
            ['phase' => 'readback'], ['code' => 'SIGNATURE_LAYOUT_UNCONFIRMED']] as $change) {
            $this->postJson(self::URL, array_replace($payload, $change), $headers)->assertUnprocessable();
        }
        $unsafe = str_replace('<p></p>', '<p>PRIVATE-BODY</p>', $html);
        $this->postJson(self::URL, array_replace($payload, ['fixture' => array_replace($fixture,
            ['html' => $unsafe, 'sha256' => hash('sha256', $unsafe)])]), $headers)->assertUnprocessable();
        self::assertSame($business, $this->businessRows());
        self::assertDoesNotMatchRegularExpression('/PRIVATE-BODY|data-rt-boundary|<hr>/', json_encode($this->logger->records));
    }

    public function test_boundary_validation_rejects_every_noncanonical_token_spelling_and_gap(): void
    {
        $signature = '<table id="rt-sig-u2-'.str_repeat('a', 24).'"><tr>'.str_repeat('<td>rt-redacted</td>', 4)
            .'</tr><tr><td colspan="3"></td></tr><tr><td colspan="4">rt-redacted</td></tr></table>';
        $fixture = static function (string $inner) use ($signature): array {
            $html = '<div data-rt-boundary="1">'.$inner.'</div>';

            return ['schema' => 1, 'format' => 'u2e-redacted-compose-boundary-v1', 'html' => $html,
                'expectedHtml' => $signature, 'sourceLength' => 1234, 'sha256' => hash('sha256', $html), 'expectedSha256' => hash('sha256', $signature)];
        };
        foreach (['<p></p><br>', '<blockquote id="divrplyfwdmsg" type="cite"></blockquote>',
            '<blockquote type="cite" id="divrplyfwdmsg"><hr></blockquote>'] as $inner) {
            self::assertTrue(OutlookUniversalDiagnosticFixtureValidator::valid($fixture($inner)), $inner);
        }
        foreach (['<p></p> <br>', "<p></p>\n<br>", "<p></p>\t<br>", '<p></p> ', '<P></P>',
            '<div id=divrplyfwdmsg></div>', "<div id='divrplyfwdmsg'></div>", '<div id="div&#114;plyfwdmsg"></div>',
            '<blockquote type="c&#105;te"></blockquote>', '<br/>', '<hr />', '<p/>', '<span />',
            '<p></p id="divrplyfwdmsg">', '<br></br>', '<div id="divrplyfwdmsg" id="stopspelling"></div>'] as $inner) {
            self::assertFalse(OutlookUniversalDiagnosticFixtureValidator::valid($fixture($inner)), $inner);
        }
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

    private function headers(bool $pilot = false): array
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
        if ($pilot) {
            User::findOrFail(5)->forceFill(['email' => 'it-technik@rail-time.de'])->saveQuietly();
            EmployeeIdentityAccount::query()->where('user_id', 5)->update(['principal' => 'it-technik@rail-time.de', 'email' => 'it-technik@rail-time.de']);
        }
        $mailbox = $pilot ? 'it-technik@rail-time.de' : 'own@company.test';
        $token = JWT::encode(['aud' => self::CLIENT, 'azp' => self::CLIENT, 'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0',
            'iat' => time() - 5, 'nbf' => time() - 5, 'exp' => time() + 300, 'tid' => self::TENANT, 'oid' => self::OBJECT,
            'scp' => 'Signature.Read', 'preferred_username' => $mailbox], $this->privateKey, 'RS256', 'u2e-diagnostic-test');

        return ['Authorization' => 'Bearer '.$token, 'X-RailTime-Outlook-Mailbox' => $mailbox,
            'X-RailTime-Outlook-Sender' => $pilot ? $mailbox : 'alias@company.test', 'X-RailTime-Compose-Contract' => 'native-signature-v1',
            'X-RailTime-Outlook-Context' => 'event'];
    }
}
