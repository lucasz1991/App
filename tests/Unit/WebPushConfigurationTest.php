<?php

namespace Tests\Unit;

use App\Support\Push\WebPushConfiguration;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

class WebPushConfigurationTest extends TestCase
{
    private string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialsPath = storage_path(
            'framework/testing/webpush-'.Str::uuid().'.json',
        );

        config([
            'webpush.enabled' => false,
            'webpush.auto_provision' => false,
            'webpush.auto_provision_path' => $this->credentialsPath,
            'webpush.vapid.subject' => null,
            'webpush.vapid.public_key' => null,
            'webpush.vapid.private_key' => null,
            'webpush.vapid.pem_file' => null,
        ]);
    }

    protected function tearDown(): void
    {
        File::delete([$this->credentialsPath, $this->credentialsPath.'.lock']);

        parent::tearDown();
    }

    public function test_diagnostics_name_every_missing_requirement_without_exposing_values(): void
    {
        $diagnostics = WebPushConfiguration::diagnostics();

        $this->assertFalse($diagnostics['enabled']);
        $this->assertFalse($diagnostics['configured']);
        $this->assertFalse($diagnostics['ready']);
        $this->assertSame([
            WebPushConfiguration::ISSUE_DISABLED,
            WebPushConfiguration::ISSUE_SUBJECT_MISSING,
            WebPushConfiguration::ISSUE_PUBLIC_KEY_MISSING,
            WebPushConfiguration::ISSUE_PRIVATE_KEY_MISSING,
        ], $diagnostics['issues']);
    }

    public function test_valid_vapid_configuration_is_ready_only_when_push_is_enabled(): void
    {
        $keys = VAPID::createVapidKeys();
        config([
            'webpush.vapid.subject' => 'mailto:push@example.test',
            'webpush.vapid.public_key' => $keys['publicKey'],
            'webpush.vapid.private_key' => $keys['privateKey'],
        ]);

        $disabled = WebPushConfiguration::diagnostics();

        $this->assertTrue($disabled['configured']);
        $this->assertFalse($disabled['ready']);
        $this->assertSame([WebPushConfiguration::ISSUE_DISABLED], $disabled['issues']);

        config(['webpush.enabled' => true]);

        $enabled = WebPushConfiguration::diagnostics();

        $this->assertTrue($enabled['configured']);
        $this->assertTrue($enabled['ready']);
        $this->assertSame([], $enabled['issues']);
        $this->assertTrue(WebPushConfiguration::isConfigured());
    }

    public function test_invalid_subject_and_key_pair_get_distinct_issue_codes(): void
    {
        $keys = VAPID::createVapidKeys();
        config([
            'webpush.enabled' => true,
            'webpush.vapid.subject' => 'http://insecure.example.test',
            'webpush.vapid.public_key' => $keys['publicKey'],
            'webpush.vapid.private_key' => $keys['privateKey'],
        ]);

        $this->assertContains(
            WebPushConfiguration::ISSUE_SUBJECT_INVALID,
            WebPushConfiguration::diagnostics()['issues'],
        );

        config([
            'webpush.vapid.subject' => 'mailto:push@example.test',
            'webpush.vapid.public_key' => 'not-a-public-key',
        ]);

        $this->assertContains(
            WebPushConfiguration::ISSUE_CREDENTIALS_INVALID,
            WebPushConfiguration::diagnostics()['issues'],
        );
    }

    public function test_missing_vapid_credentials_are_provisioned_once_from_https_app_url(): void
    {
        config([
            'app.url' => 'https://app.example.test',
            'webpush.enabled' => true,
            'webpush.auto_provision' => true,
        ]);

        $firstDiagnostics = WebPushConfiguration::diagnostics();
        $firstPublicKey = (string) config('webpush.vapid.public_key');

        $this->assertTrue($firstDiagnostics['ready']);
        $this->assertTrue($firstDiagnostics['auto_provisioned']);
        $this->assertSame('https://app.example.test', config('webpush.vapid.subject'));
        $this->assertNotSame('', $firstPublicKey);
        $this->assertFileExists($this->credentialsPath);

        config([
            'webpush.vapid.subject' => null,
            'webpush.vapid.public_key' => null,
            'webpush.vapid.private_key' => null,
        ]);

        $secondDiagnostics = WebPushConfiguration::diagnostics();

        $this->assertTrue($secondDiagnostics['ready']);
        $this->assertFalse($secondDiagnostics['auto_provisioned']);
        $this->assertSame($firstPublicKey, config('webpush.vapid.public_key'));
    }

    public function test_partial_existing_key_pair_is_never_replaced_automatically(): void
    {
        config([
            'app.url' => 'https://app.example.test',
            'webpush.enabled' => true,
            'webpush.auto_provision' => true,
            'webpush.vapid.public_key' => 'existing-public-key',
            'webpush.vapid.private_key' => null,
        ]);

        $diagnostics = WebPushConfiguration::diagnostics();

        $this->assertFalse($diagnostics['ready']);
        $this->assertFalse($diagnostics['auto_provisioned']);
        $this->assertSame('existing-public-key', config('webpush.vapid.public_key'));
        $this->assertFileDoesNotExist($this->credentialsPath);
    }
}
