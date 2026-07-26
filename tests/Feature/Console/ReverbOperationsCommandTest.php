<?php

namespace Tests\Feature\Console;

use App\Support\Environment\EnvironmentFile;
use Tests\TestCase;

class ReverbOperationsCommandTest extends TestCase
{
    private string $environmentPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->environmentPath = tempnam(sys_get_temp_dir(), 'railtime-reverb-env-');
        file_put_contents(
            $this->environmentPath,
            "APP_URL=https://app.rail-time.de\nREVERB_APP_KEY=\n",
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->environmentPath);

        parent::tearDown();
    }

    public function test_reverb_credentials_are_generated_stored_and_preserved_by_default(): void
    {
        $this->artisan('railtime:reverb-keys', [
            '--env-file' => $this->environmentPath,
            '--no-config-clear' => true,
        ])->assertSuccessful();

        $environment = app(EnvironmentFile::class);
        $first = $environment->read($this->environmentPath);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $first['REVERB_APP_ID']);
        $this->assertSame(24, strlen($first['REVERB_APP_KEY']));
        $this->assertSame(48, strlen($first['REVERB_APP_SECRET']));
        $this->assertSame('app.rail-time.de', $first['REVERB_HOST']);
        $this->assertSame('127.0.0.1', $first['REVERB_SERVER_HOST']);
        $this->assertSame('${REVERB_APP_KEY}', $first['VITE_REVERB_APP_KEY']);

        $this->artisan('railtime:reverb-keys', [
            '--env-file' => $this->environmentPath,
            '--no-config-clear' => true,
        ])->assertSuccessful();

        $this->assertSame($first, $environment->read($this->environmentPath));
    }

    public function test_force_rotates_the_complete_reverb_credential_set(): void
    {
        $arguments = [
            '--env-file' => $this->environmentPath,
            '--no-config-clear' => true,
        ];

        $this->artisan('railtime:reverb-keys', $arguments)->assertSuccessful();
        $environment = app(EnvironmentFile::class);
        $first = $environment->read($this->environmentPath);

        $this->artisan('railtime:reverb-keys', [
            ...$arguments,
            '--force' => true,
        ])->assertSuccessful();

        $rotated = $environment->read($this->environmentPath);

        $this->assertNotSame($first['REVERB_APP_ID'], $rotated['REVERB_APP_ID']);
        $this->assertNotSame($first['REVERB_APP_KEY'], $rotated['REVERB_APP_KEY']);
        $this->assertNotSame($first['REVERB_APP_SECRET'], $rotated['REVERB_APP_SECRET']);
    }

    public function test_supervisor_installer_supports_a_non_mutating_dry_run(): void
    {
        $before = file_get_contents($this->environmentPath);

        $this->artisan('railtime:install-reverb-service', [
            '--dry-run' => true,
            '--user' => 'railtime',
            '--php' => PHP_BINARY,
            '--env-file' => $this->environmentPath,
        ])
            ->expectsOutputToContain('Dry run only')
            ->expectsOutputToContain('[program:railtime-reverb]')
            ->assertSuccessful();

        $this->assertSame($before, file_get_contents($this->environmentPath));
    }

    public function test_plesk_worker_owns_the_default_queue_without_a_second_scheduled_worker(): void
    {
        $kernel = file_get_contents(app_path('Console/Kernel.php'));
        $webPushConfig = file_get_contents(config_path('webpush.php'));

        $this->assertStringContainsString("env('WEBPUSH_QUEUE', 'default')", $webPushConfig);
        $this->assertStringNotContainsString('queue:work', $kernel);
        $this->assertStringContainsString('WEBPUSH_QUEUE=default', file_get_contents(base_path('.env.example')));
    }
}
