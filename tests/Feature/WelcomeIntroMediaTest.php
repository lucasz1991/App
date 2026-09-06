<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\WelcomeIntroCatalog;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class WelcomeIntroMediaTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_media_requires_an_active_verified_session(): void
    {
        $url = route('welcome-intro.media', ['module' => 'intro', 'asset' => 'video']);

        $this->get($url)->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified)
            ->get($url)
            ->assertRedirect(route('verification.notice'));
    }

    public function test_admin_can_stream_an_original_video_with_private_security_headers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('welcome-intro.media', [
            'module' => 'devices',
            'asset' => 'video',
        ]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('Cookie', (string) $response->headers->get('Vary'));
    }

    public function test_video_route_supports_http_range_requests(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->withHeaders(['Range' => 'bytes=0-99'])
            ->get(route('welcome-intro.media', [
                'module' => 'intro',
                'asset' => 'video',
            ]));

        $response
            ->assertStatus(206)
            ->assertHeader('Content-Length', '100')
            ->assertHeader('Content-Range', 'bytes 0-99/822618');
    }

    public function test_role_filter_prevents_direct_access_to_hidden_modules(): void
    {
        $guest = User::factory()->create(['role' => 'editor']);

        foreach (['devices', 'orders', 'shifts', 'wagon-lists', 'integrations'] as $module) {
            $this->actingAs($guest)
                ->get(route('welcome-intro.media', ['module' => $module, 'asset' => 'video']))
                ->assertNotFound();
        }

        $this->actingAs($guest)
            ->get(route('welcome-intro.media', ['module' => 'intro', 'asset' => 'video']))
            ->assertOk();
    }

    public function test_captions_and_posters_are_protected_and_use_the_expected_mime_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('welcome-intro.media', ['module' => 'communication', 'asset' => 'captions-en']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/vtt; charset=UTF-8');

        $this->actingAs($admin)
            ->get(route('welcome-intro.media', ['module' => 'communication', 'asset' => 'poster']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_unknown_or_malformed_media_targets_are_not_resolved_as_paths(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/onboarding/media/not-a-module/video')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/onboarding/media/intro/not-an-asset')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/onboarding/media/..%2F..%2F.env/video')
            ->assertNotFound();
    }

    public function test_committed_videos_match_the_fixed_catalog_checksums_and_sizes(): void
    {
        $catalog = app(WelcomeIntroCatalog::class);

        foreach (['intro', 'devices', 'orders', 'shifts', 'communication', 'wagon-lists', 'files', 'support', 'integrations'] as $module) {
            $media = $catalog->media($module, 'video');

            $this->assertNotNull($media);
            $this->assertFileExists($media['path']);
            $this->assertSame($media['expectedSize'], filesize($media['path']));
            $this->assertSame(strtolower($media['etag']), hash_file('sha256', $media['path']));
        }
    }
}
