<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SystemHealth\IntegrationChecks;
use App\Services\SystemHealth\Transport\BoundedSocket;
use App\Services\SystemHealth\Transport\SmtpProbe;
use App\Services\SystemHealth\Transport\SpeechStatusProbe;
use App\Services\SystemHealth\Transport\WebSocketProbe;
use App\Support\Ai\OpenRouterSettings;
use App\Support\Push\VapidAutoProvisioner;
use App\Support\Push\WebPushConfiguration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class SystemHealthIntegrationsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mock(VapidAutoProvisioner::class)->shouldNotReceive('ensureConfigured');
    }

    public function test_disabled_integrations_do_not_open_connections_or_provision_credentials(): void
    {
        config([
            'broadcasting.default' => 'null', 'outlook_addin.enabled' => false,
            'call_recording.enabled' => false, 'assistant.speech.enabled' => false,
            'webpush.enabled' => false, 'webpush.auto_provision' => true,
            'webpush.auto_provision_path' => storage_path('app/private/health-missing-'.bin2hex(random_bytes(8)).'.json'),
        ]);
        foreach (['realtime', 'outlook', 'recordings', 'speech', 'push'] as $id) {
            $this->assertSame('disabled', app(IntegrationChecks::class)->run($id)['status']);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_push_inspection_never_creates_keys_or_mutates_configuration(): void
    {
        $path = storage_path('app/private/health-missing-'.bin2hex(random_bytes(8)).'.json');
        config(['webpush.enabled' => true, 'webpush.auto_provision' => true, 'webpush.auto_provision_path' => $path,
            'webpush.vapid.subject' => 'https://example.test', 'webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);
        $before = config('webpush');
        $result = app(IntegrationChecks::class)->run('push');
        $this->assertSame('not_configured', $result['status']);
        $this->assertSame($before, config('webpush'));
        $this->assertFileDoesNotExist($path);
        $this->assertFalse(WebPushConfiguration::inspect()['auto_provisioned']);
    }

    public function test_push_can_inspect_existing_persisted_keys_without_rewriting_or_hydrating_config(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rt-health-vapid-');
        $keys = VAPID::createVapidKeys();
        file_put_contents($path, json_encode(['subject' => 'https://example.test', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']]));
        config(['webpush.enabled' => true, 'webpush.auto_provision' => true, 'webpush.auto_provision_path' => $path,
            'webpush.vapid.subject' => '', 'webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);
        try {
            $before = hash_file('sha256', $path);
            $this->assertSame('ok', app(IntegrationChecks::class)->run('push')['status']);
            $this->assertSame($before, hash_file('sha256', $path));
            $this->assertSame('', config('webpush.vapid.private_key'));
        } finally {
            unlink($path);
        }
    }

    public function test_smtp_simulation_is_not_reported_as_delivery(): void
    {
        config(['mail.default' => 'array']);
        $this->mock(SmtpProbe::class)->shouldNotReceive('check');
        $result = app(IntegrationChecks::class)->run('mail');
        $this->assertSame('warning', $result['status']);
        $this->assertSame('configuration', $result['evidence']);
    }

    public function test_smtp_checks_connection_without_a_send_and_does_not_overclaim_missing_authentication(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => null, 'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.username' => 'health-test', 'mail.mailers.smtp.password' => 'test-secret']);
        $this->mock(SmtpProbe::class)->shouldReceive('check')->once()->andReturn(['tls' => true, 'authenticated' => false]);
        $result = app(IntegrationChecks::class)->run('mail');
        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString('nicht nachgewiesen', $result['message']);
        $this->assertStringNotContainsString('test-secret', json_encode($result));
    }

    public function test_smtp_url_configuration_is_resolved_but_never_rendered(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'smtps://health-test:private-value@smtp.example.test:465']);
        $this->mock(SmtpProbe::class)->shouldReceive('check')->once()->withArgs(fn ($config) => $config['host'] === 'smtp.example.test' && $config['scheme'] === 'smtps' && $config['password'] === 'private-value')->andReturn(['tls' => true, 'authenticated' => true]);
        $result = app(IntegrationChecks::class)->run('mail');
        $this->assertSame('ok', $result['status']);
        $this->assertStringNotContainsString('private-value', json_encode($result));
        $this->assertStringNotContainsString('smtp.example.test', json_encode($result));
    }

    public function test_smtp_actual_symfony_handshake_authenticates_and_never_issues_delivery_commands(): void
    {
        $channel = $this->fakeSmtpChannel([
            "220 ESMTP ready\r\n", "250-server\r\n", "250-STARTTLS\r\n", "250 AUTH LOGIN\r\n",
            "220 TLS ready\r\n", "250-server\r\n", "250 AUTH LOGIN\r\n",
            "334 VXNlcm5hbWU6\r\n", "334 UGFzc3dvcmQ6\r\n", "235 Authenticated\r\n", "221 Goodbye\r\n",
        ]);
        $result = (new SmtpProbe($channel))->check(['host' => 'smtp.example.test', 'port' => 587, 'username' => 'test-user', 'password' => 'fixture-only']);
        $this->assertTrue($result['authenticated']);
        $this->assertTrue($result['tls']);
        $this->assertTrue($channel->tlsStarted);
        $this->assertTrue($channel->closed);
        $commands = implode('', $channel->writes);
        $this->assertStringContainsString("AUTH LOGIN\r\n", $commands);
        foreach (['MAIL FROM:', 'RCPT TO:', "DATA\r\n"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $commands);
        }
    }

    public function test_smtp_without_starttls_does_not_send_credentials(): void
    {
        $channel = $this->fakeSmtpChannel(["220 ESMTP ready\r\n", "250-server\r\n", "250 AUTH LOGIN\r\n"]);
        try {
            (new SmtpProbe($channel))->check(['host' => 'smtp.example.test', 'port' => 587, 'username' => 'test-user', 'password' => 'fixture-only']);
            $this->fail('An unencrypted diagnostic channel must fail.');
        } catch (TransportException) {
            $this->assertStringNotContainsString('AUTH ', implode('', $channel->writes));
            $this->assertTrue($channel->closed);
        }
    }

    public function test_smtp_transport_error_is_redacted(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => null, 'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.username' => '', 'mail.mailers.smtp.password' => '']);
        $this->mock(SmtpProbe::class)->shouldReceive('check')->once()->andThrow(new RuntimeException('credential=private-value internal://host'));
        $result = app(IntegrationChecks::class)->run('mail');
        $this->assertSame('error', $result['status']);
        $this->assertStringNotContainsString('private-value', json_encode($result));
        $this->assertStringNotContainsString('internal://host', json_encode($result));
    }

    public function test_realtime_uses_websocket_cluster_not_rest_endpoint_and_publishes_nothing(): void
    {
        config(['app.url' => 'https://app.example.test/subdirectory', 'broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
            'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'private-value', 'app_id' => '123',
            'options' => ['host' => 'api-eu.pusher.com', 'cluster' => 'eu', 'scheme' => 'https', 'port' => 443],
        ]]);
        $this->mock(WebSocketProbe::class)->shouldReceive('check')->once()->with('ws-eu.pusher.com', 443, true, '/app/test-key?protocol=7&client=railtime-health&version=1&flash=false', 'https://app.example.test');
        $result = app(IntegrationChecks::class)->run('realtime');
        $this->assertSame('ok', $result['status']);
        $this->assertSame('connection', $result['evidence']);
        Http::assertNothingSent();
    }

    public function test_websocket_requires_valid_upgrade_and_application_greeting(): void
    {
        $channel = new class extends BoundedSocket
        {
            public array $lines = [];

            public string $bytes = '';

            public bool $closed = false;

            public string $written = '';

            public function open(string $host, int $port, bool $tls, float $seconds = 6): void {}

            public function write(string $bytes): void
            {
                $this->written .= $bytes;
                preg_match('/Sec-WebSocket-Key: ([^\r]+)/', $bytes, $key);
                $accept = base64_encode(sha1($key[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                $this->lines = ["HTTP/1.1 101 Switching Protocols\r\n", "Upgrade: websocket\r\n", "Connection: Upgrade\r\n", "Sec-WebSocket-Accept: {$accept}\r\n", "\r\n"];
                $payload = json_encode(['event' => 'pusher:connection_established', 'data' => json_encode(['socket_id' => '123.456'])]);
                $this->bytes = chr(129).chr(strlen($payload)).$payload;
            }

            public function line(): string
            {
                return array_shift($this->lines);
            }

            public function read(int $length): string
            {
                $out = substr($this->bytes, 0, $length);
                $this->bytes = substr($this->bytes, $length);

                return $out;
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
        (new WebSocketProbe($channel))->check('ws.example.test', 443, true, '/app/test', 'https://app.example.test');
        $this->assertTrue($channel->closed);
        $this->assertStringStartsWith('GET /app/test HTTP/1.1', $channel->written);
        $this->assertStringContainsString("Origin: https://app.example.test\r\n", $channel->written);
        $this->assertStringNotContainsString('subscribe', $channel->written);
    }

    public function test_livekit_uses_only_short_lived_room_list_permission_and_hides_response(): void
    {
        config(['livekit.url' => 'https://livekit.example.test', 'livekit.api_key' => 'test-key', 'livekit.api_secret' => str_repeat('fixture-secret', 3)]);
        Http::fake(function (Request $request, array $options) {
            $this->assertSame('https://livekit.example.test/twirp/livekit.RoomService/ListRooms', $request->url());
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame(5, $options['timeout']);

            return Http::response(['rooms' => [['name' => 'private-meeting']]], 200);
        });
        $result = app(IntegrationChecks::class)->run('livekit');
        $this->assertSame('ok', $result['status']);
        $this->assertSame('connection', $result['evidence']);
        $this->assertStringNotContainsString('private-meeting', json_encode($result));
        Http::assertSent(function (Request $request): bool {
            $jwt = substr($request->header('Authorization')[0], 7);
            $claims = json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
            $this->assertTrue($claims['video']['roomList']);
            foreach (['roomCreate', 'roomJoin', 'roomAdmin', 'roomRecord'] as $grant) {
                $this->assertFalse((bool) ($claims['video'][$grant] ?? false));
            }
            $this->assertLessThanOrEqual(30, $claims['exp'] - time());

            return $request->method() === 'POST' && $request->body() === '{}';
        });
        $this->assertDatabaseCount('rooms', 0);
    }

    #[DataProvider('unsafeLivekitEndpoints')]
    public function test_livekit_rejects_credential_leaking_or_insecure_endpoints_before_network(string $url): void
    {
        config(['livekit.url' => $url, 'livekit.api_key' => 'test', 'livekit.api_secret' => 'fixture-secret']);
        $this->assertSame('error', app(IntegrationChecks::class)->run('livekit')['status']);
        Http::assertNothingSent();
    }

    public static function unsafeLivekitEndpoints(): array
    {
        return [['http://external.example.test'], ['https://user:pass@external.example.test'], ['https://external.example.test?redirect=1'], ['file:///tmp/server']];
    }

    public function test_livekit_redirect_is_not_followed_and_not_success(): void
    {
        config(['livekit.url' => 'https://livekit.example.test', 'livekit.api_key' => 'test', 'livekit.api_secret' => str_repeat('fixture-secret', 3)]);
        Http::fake(['https://livekit.example.test/*' => Http::response('{}', 302, ['Location' => 'https://other.example.test'])]);
        $this->assertSame('error', app(IntegrationChecks::class)->run('livekit')['status']);
        Http::assertSentCount(1);
    }

    public function test_speech_engine_readiness_is_not_inference_and_partial_readiness_warns(): void
    {
        config(['assistant.speech.enabled' => true]);
        $this->mock(SpeechStatusProbe::class, function ($mock): void {
            $mock->shouldReceive('configured')->once()->andReturn(true);
            $mock->shouldReceive('check')->once()->andReturn(['engines' => ['ffmpeg' => true, 'whisper' => 'ready', 'piper' => false], 'internal_uri' => 'secret']);
        });
        $result = app(IntegrationChecks::class)->run('speech');
        $this->assertSame('warning', $result['status']);
        $this->assertSame('connection', $result['evidence']);
        $this->assertStringNotContainsString('secret', json_encode($result));
        Http::assertNothingSent();
    }

    public function test_ai_validates_configuration_without_chargeable_request(): void
    {
        Setting::setValue('assistant', 'enabled', true);
        OpenRouterSettings::save(['api_url' => 'https://openrouter.ai/api/v1/chat/completions', 'api_key' => 'fixture-secret', 'text_model' => 'vendor/model']);
        $before = Setting::query()->orderBy('id')->get()->toJson();
        $result = app(IntegrationChecks::class)->run('ai');
        $this->assertSame('ok', $result['status']);
        $this->assertSame('configuration', $result['evidence']);
        $this->assertSame($before, Setting::query()->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_recordings_and_marketing_never_start_business_jobs(): void
    {
        config(['call_recording.enabled' => true, 'livekit.api_key' => '', 'marketing.renders.node_binary' => 'definitely-missing-railtime-node']);
        $this->assertSame('not_configured', app(IntegrationChecks::class)->run('recordings')['status']);
        $this->assertSame('warning', app(IntegrationChecks::class)->run('marketing')['status']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('room_recordings', 0);
        $this->assertDatabaseCount('marketing_renders', 0);
        Http::assertNothingSent();
    }

    public function test_outlook_checks_the_real_static_assets_and_never_claims_client_rendering(): void
    {
        config([
            'outlook_addin.enabled' => true, 'outlook_addin.deployed' => true,
            'outlook_addin.base_url' => 'https://app.example.test',
            'outlook_addin.entra.tenant_id' => '11111111-1111-4111-8111-111111111111',
            'outlook_addin.entra.client_id' => '22222222-2222-4222-8222-222222222222',
            'outlook_addin.entra.scope' => 'Signature.Read',
            'outlook_addin.entra.scope_uri' => 'api://22222222-2222-4222-8222-222222222222/Signature.Read',
        ]);
        $result = app(IntegrationChecks::class)->run('outlook');
        $this->assertSame('ok', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame('configuration', $result['evidence']);
        $this->assertStringContainsString('nicht live geprüft', implode(' ', $result['details']));
        Http::assertNothingSent();
    }

    public function test_recording_storage_mismatch_is_an_error_without_upload_or_recording(): void
    {
        config([
            'call_recording.enabled' => true, 'livekit.url' => 'https://livekit.example.test',
            'livekit.api_key' => 'test-key', 'livekit.api_secret' => 'fixture-secret',
            'call_recording.s3.key' => 'fixture-key', 'call_recording.s3.secret' => 'fixture-secret',
            'call_recording.s3.region' => 'eu-central-1', 'call_recording.s3.bucket' => 'private-egress',
            'call_recording.storage_disk' => 'call_recordings',
            'filesystems.disks.call_recordings.driver' => 's3', 'filesystems.disks.call_recordings.visibility' => 'private',
            'filesystems.disks.call_recordings.bucket' => 'different-private-bucket',
        ]);
        $result = app(IntegrationChecks::class)->run('recordings');
        $this->assertSame('error', $result['status']);
        $this->assertStringNotContainsString('private-egress', json_encode($result));
        $this->assertDatabaseCount('room_recordings', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_integration_is_inert_and_reports_not_checked(): void
    {
        $this->assertSame('not_checked', app(IntegrationChecks::class)->run('https://arbitrary.example.test')['status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('jobs', 0);
    }

    private function fakeSmtpChannel(array $responses): BoundedSocket
    {
        return new class($responses) extends BoundedSocket
        {
            public array $writes = [];

            public bool $closed = false;

            public bool $tlsStarted = false;

            public function __construct(private array $responses) {}

            public function open(string $host, int $port, bool $tls, float $seconds = 6): void {}

            public function write(string $bytes): void
            {
                $this->writes[] = $bytes;
            }

            public function line(): string
            {
                return array_shift($this->responses) ?? throw new RuntimeException('Fixture exhausted.');
            }

            public function startTls(): bool
            {
                return $this->tlsStarted = true;
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
    }
}
