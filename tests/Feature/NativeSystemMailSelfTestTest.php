<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Support\Mail\NativeSignatureMimeComposer;
use App\Support\Mail\NativeSystemMailSelfTest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;
use Throwable;

final class NativeSystemMailSelfTestTest extends TestCase
{
    private const OPERATION = '01888abc-1234-4123-8123-123456789abc';

    private string $storage;

    private MailDocument $signature;

    private EsmtpTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $fixture = new PublishedStaticSystemDeliveryTest('test_effective_published_system_pair_uses_remote_pngs_through_the_real_inliner_and_embedder');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        $this->signature = (new ReflectionProperty($fixture, 'linked'))->getValue($fixture);
        $template = (new ReflectionProperty($fixture, 'template'))->getValue($fixture);
        $marker = new OutlookLinkedAnimatedDeliveryTest('test_actual_system_default_and_explicit_published_testmail_keep_the_gif_link_without_inline_attachments');
        $mark = new ReflectionMethod($marker, 'mark');
        foreach (MailDocument::query()->where('kind', 'signature')->get() as $document) {
            $document->forceFill(['is_active' => null])->saveQuietly();
        }
        $source = $mark->invoke($marker, $this->signature->published_html, true);
        $this->signature->forceFill(['html' => $source, 'published_html' => $source, 'is_active' => true, 'status' => 'published'])->saveQuietly();
        $source = $mark->invoke($marker, $template->published_html, false);
        $template->forceFill(['html' => $source, 'published_html' => $source, 'published_signature_document_id' => $this->signature->id])->saveQuietly();
        app()->forgetScopedInstances();
        $this->storage = sys_get_temp_dir().'/railtime-native-selftest-'.bin2hex(random_bytes(12));
        mkdir($this->storage, 0700);
        app()->useStoragePath($this->storage);
        config(['app.url' => 'https://mail.example.test', 'mail.default' => 'smtp', 'mail.from.address' => 'system@example.invalid', 'mail.from.name' => 'Muster System',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.example.invalid', 'port' => 587, 'encryption' => 'tls', 'username' => 'system@example.invalid', 'password' => 'synthetic-not-a-credential', 'url' => null]]);
        Http::preventStrayRequests();
        $this->transport = new class extends EsmtpTransport
        {
            public array $calls = [];

            public bool $throw = false;

            public function __construct()
            {
                parent::__construct('smtp.example.invalid', 587);
                $this->setUsername('system@example.invalid');
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->calls[] = [$message->toString(), $envelope];
                if ($this->throw) {
                    throw new RuntimeException('private transport detail');
                }

                return new SentMessage($message, $envelope);
            }
        };
        $mailer = Mockery::mock();
        $mailer->shouldReceive('getSymfonyTransport')->andReturn($this->transport);
        $manager = Mockery::mock();
        $manager->shouldReceive('mailer')->with('smtp')->andReturn($mailer);
        app()->instance('mail.manager', $manager);
    }

    protected function tearDown(): void
    {
        if (isset($this->storage) && str_starts_with($this->storage, sys_get_temp_dir().'/railtime-native-selftest-')) {
            app('files')->deleteDirectory($this->storage);
        }
        parent::tearDown();
    }

    public function test_default_command_prepares_only_configured_system_from_fixed_it_full_head_mime_without_sending_or_changing_publications(): void
    {
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $this->artisan('mail:native-system-selftest', ['operation' => self::OPERATION])->assertExitCode(0);
        self::assertCount(0, $this->transport->calls);
        $record = json_decode(file_get_contents($this->path('record.json')), true);
        $mime = file_get_contents($this->path('message.eml'));
        self::assertSame('prepared', $record['state']);
        self::assertSame(hash('sha256', $mime), $record['mimeHash']);
        self::assertStringContainsString('From: Muster System <system@example.invalid>', $mime);
        self::assertStringContainsString('<'.NativeSignatureMimeComposer::MAILBOX.'>', $mime);
        self::assertStringContainsString('Content-Type: multipart/alternative', $mime);
        self::assertFileDoesNotExist($this->path('send.claim'));
        self::assertSame($record, (new NativeSystemMailSelfTest)->prepare(self::OPERATION));
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_explicit_send_submits_exact_prepared_mime_once_with_configured_envelope_and_durable_claim(): void
    {
        $service = new NativeSystemMailSelfTest;
        $record = $service->prepare(self::OPERATION);
        $expected = file_get_contents($this->path('message.eml'));
        $result = $service->send(self::OPERATION);
        self::assertSame('submitted', $result['state']);
        self::assertSame($record['mimeHash'], $result['mimeHash']);
        self::assertCount(1, $this->transport->calls);
        self::assertSame($expected, $this->transport->calls[0][0]);
        self::assertSame('system@example.invalid', $this->transport->calls[0][1]->getSender()->getAddress());
        self::assertSame(NativeSignatureMimeComposer::MAILBOX, $this->transport->calls[0][1]->getRecipients()[0]->getAddress());
        self::assertFileExists($this->path('send.claim'));
        $this->assertDenied(fn () => $service->send(self::OPERATION));
        self::assertCount(1, $this->transport->calls);
    }

    public function test_unknown_smtp_outcome_retains_uncertain_result_and_never_retries(): void
    {
        $service = new NativeSystemMailSelfTest;
        $service->prepare(self::OPERATION);
        $this->transport->throw = true;
        $this->assertDenied(fn () => $service->send(self::OPERATION));
        self::assertSame('uncertain', json_decode(file_get_contents($this->path('result.json')), true)['state']);
        self::assertFileExists($this->path('send.claim'));
        $this->assertDenied(fn () => (new NativeSystemMailSelfTest)->send(self::OPERATION));
        self::assertCount(1, $this->transport->calls);
    }

    public function test_missing_prepare_changed_publication_or_configuration_and_modified_mime_never_reach_smtp(): void
    {
        $service = new NativeSystemMailSelfTest;
        $this->assertDenied(fn () => $service->send(self::OPERATION));
        $service->prepare(self::OPERATION);
        config(['mail.from.address' => 'changed@example.invalid']);
        $this->assertDenied(fn () => $service->send(self::OPERATION));
        config(['mail.from.address' => 'system@example.invalid']);
        $this->signature->forceFill(['published_css' => '.changed{color:red}'])->saveQuietly();
        $this->assertDenied(fn () => $service->send(self::OPERATION));
        self::assertCount(0, $this->transport->calls);
        self::assertFileDoesNotExist($this->path('send.claim'));
    }

    public function test_mime_and_record_tampering_are_rejected_even_when_the_attacker_updates_the_plain_hash(): void
    {
        $service = new NativeSystemMailSelfTest;
        $service->prepare(self::OPERATION);
        $changed = str_replace('From: Muster System <system@example.invalid>', 'From: foreign@example.invalid', file_get_contents($this->path('message.eml')));
        file_put_contents($this->path('message.eml'), $changed);
        $record = json_decode(file_get_contents($this->path('record.json')), true);
        $record['mimeHash'] = hash('sha256', $changed);
        $record['bytes'] = strlen($changed);
        file_put_contents($this->path('record.json'), json_encode($record));
        $this->assertDenied(fn () => $service->send(self::OPERATION));
        self::assertCount(0, $this->transport->calls);
    }

    public function test_insecure_alternative_transport_header_injection_and_non_uuid_operations_are_denied(): void
    {
        $service = new NativeSystemMailSelfTest;
        $this->assertDenied(fn () => $service->prepare('../outside'));
        foreach (['array', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->assertDenied(fn () => $service->prepare(self::OPERATION));
        }
        config(['mail.default' => 'smtp', 'mail.from.address' => "system@example.invalid\r\nBcc:foreign@example.invalid"]);
        $this->assertDenied(fn () => $service->prepare(self::OPERATION));
        config(['mail.from.address' => 'system@example.invalid', 'mail.mailers.smtp.encryption' => null]);
        $this->assertDenied(fn () => $service->prepare(self::OPERATION));
        self::assertCount(0, $this->transport->calls);
    }

    public function test_copied_prepared_operation_cannot_send_the_same_mime_under_a_new_operation_id(): void
    {
        $service = new NativeSystemMailSelfTest;
        $service->prepare(self::OPERATION);
        $other = '02888abc-1234-4123-8123-123456789abc';
        $directory = dirname($this->path('record.json')).'/../'.$other;
        mkdir($directory, 0700);
        foreach (['record.json', 'message.eml'] as $name) {
            copy($this->path($name), $directory.'/'.$name);
        }
        $this->assertDenied(fn () => $service->send($other));
        self::assertCount(0, $this->transport->calls);
        self::assertFileDoesNotExist($directory.'/send.claim');
    }

    public function test_publication_changed_after_the_durable_claim_stays_uncertain_without_a_transport_call(): void
    {
        $service = new NativeSystemMailSelfTest;
        $service->prepare(self::OPERATION);
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed): void {
            if (! $changed && file_exists($this->path('send.claim')) && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'mail_documents')) {
                $changed = true;
                DB::table('mail_documents')->where('id', $this->signature->id)->update(['published_css' => '.changed-after-claim{color:red}']);
            }
        });
        $this->assertDenied(fn () => $service->send(self::OPERATION));
        self::assertTrue($changed);
        self::assertSame('uncertain', json_decode(file_get_contents($this->path('result.json')), true)['state']);
        self::assertFileExists($this->path('send.claim'));
        self::assertCount(0, $this->transport->calls);
    }

    public function test_unpublished_active_source_never_prepares_an_eml_or_sends(): void
    {
        $this->signature->forceFill(['published_at' => null])->saveQuietly();
        $this->assertDenied(fn () => (new NativeSystemMailSelfTest)->prepare(self::OPERATION));
        self::assertFileDoesNotExist($this->path('message.eml'));
        self::assertCount(0, $this->transport->calls);
    }

    public function test_certificate_verification_cannot_be_disabled_in_configuration_or_the_resolved_transport(): void
    {
        $service = new NativeSystemMailSelfTest;
        foreach ([['verify_peer' => false], ['verify_peer_name' => false], ['allow_self_signed' => true]] as $unsafe) {
            config(['mail.mailers.smtp.stream' => ['ssl' => $unsafe]]);
            $this->assertDenied(fn () => $service->prepare(self::OPERATION));
        }
        config(['mail.mailers.smtp.stream' => []]);
        $service->prepare(self::OPERATION);
        $this->transport->getStream()->setStreamOptions(['ssl' => ['verify_peer' => false]]);
        $this->assertDenied(fn () => $service->send(self::OPERATION));
        self::assertCount(0, $this->transport->calls);
        self::assertFileDoesNotExist($this->path('send.claim'));
    }

    public function test_changed_native_template_generation_cannot_submit_a_previously_prepared_mime(): void
    {
        $service = new NativeSystemMailSelfTest;
        $service->prepare(self::OPERATION);
        $mime = file_get_contents($this->path('message.eml'));
        $base = app()->basePath();
        $public = app()->publicPath();
        $isolated = $this->storage.'/generation';
        app('files')->copyDirectory(resource_path('mail-templates'), $isolated.'/resources/mail-templates');
        file_put_contents($isolated.'/resources/mail-templates/native-signature-handoff-v1.html', file_get_contents(resource_path('mail-templates/native-signature-handoff-v1.html'))."\n");
        app()->setBasePath($isolated);
        app()->usePublicPath($public);
        try {
            try {
                $service->send(self::OPERATION);
                self::fail('The old MIME survived a native template generation change.');
            } catch (RuntimeException $error) {
                self::assertSame('NATIVE_SYSTEM_TEST_SOURCE_CHANGED', $error->getMessage());
            }
            self::assertSame($mime, file_get_contents($this->path('message.eml')));
            self::assertFileDoesNotExist($this->path('send.claim'));
            self::assertCount(0, $this->transport->calls);
        } finally {
            app()->setBasePath($base);
            app()->usePublicPath($public);
        }
    }

    private function path(string $name): string
    {
        return $this->storage.'/app/private/native-system-selftest/'.self::OPERATION.'/'.$name;
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('The unsafe or previously claimed operation was admitted.');
        } catch (Throwable $error) {
            self::assertNotInstanceOf(AssertionFailedError::class, $error);
            self::assertInstanceOf(RuntimeException::class, $error);
            self::assertMatchesRegularExpression('/\ANATIVE_SYSTEM_TEST_[A-Z_]+\z/', $error->getMessage());
        }
    }
}
