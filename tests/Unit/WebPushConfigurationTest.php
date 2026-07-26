<?php

namespace Tests\Unit;

use App\Support\Push\WebPushConfiguration;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

class WebPushConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'webpush.enabled' => false,
            'webpush.vapid.subject' => null,
            'webpush.vapid.public_key' => null,
            'webpush.vapid.private_key' => null,
            'webpush.vapid.pem_file' => null,
        ]);
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

    public function test_invalid_subject_key_pair_and_unreadable_pem_get_distinct_issue_codes(): void
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

        config([
            'webpush.vapid.public_key' => $keys['publicKey'],
            'webpush.vapid.pem_file' => 'storage/missing-vapid-key.pem',
        ]);

        $this->assertContains(
            WebPushConfiguration::ISSUE_PEM_FILE_UNAVAILABLE,
            WebPushConfiguration::diagnostics()['issues'],
        );
    }
}
