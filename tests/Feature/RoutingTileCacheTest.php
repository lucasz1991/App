<?php

namespace Tests\Feature;

use App\Http\Controllers\RoutingTileController;
use App\Jobs\LogActivityJob;
use App\Models\User;
use App\Services\Routing\RoutingUsageService;
use App\Services\Routing\ServerRoutingCache;
use App\Services\Routing\TomTomRoutingService;
use App\Support\Routing\TileCachePolicy;
use App\Support\Routing\TomTomSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class RoutingTileCacheTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_10_11_120100_create_routing_monthly_usage_table.php'))->up();
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->actor);
        TomTomSettings::save(array_replace(TomTomSettings::DEFAULTS, ['enabled' => true]), 'test-tile-credential');
        Http::preventStrayRequests();
        Queue::fake([LogActivityJob::class]);
        $this->cacheDirectory = storage_path('framework/testing/routing-tiles-'.Str::uuid());
        app()->instance(ServerRoutingCache::class, new ServerRoutingCache($this->cacheDirectory));
    }

    protected function tearDown(): void
    {
        if (isset($this->cacheDirectory) && str_starts_with($this->cacheDirectory, storage_path('framework/testing/routing-tiles-'))) {
            File::deleteDirectory($this->cacheDirectory);
        }
        parent::tearDown();
    }

    public static function cachePolicies(): array
    {
        $date = gmdate('D, d M Y H:i:s \\G\\M\\T', 1700000000);
        $earlier = gmdate('D, d M Y H:i:s \\G\\M\\T', 1699999950);
        $expires = gmdate('D, d M Y H:i:s \\G\\M\\T', 1700000050);
        $future = gmdate('D, d M Y H:i:s \\G\\M\\T', 1700000002);

        return [
            'provider permission and hard ceiling' => [['Cache-Control' => ['public, max-age=7200']], 3600, 1700000000, 1700000000, 3600],
            'configuration ceiling' => [['Cache-Control' => ['max-age=300']], 60, 1700000000, 1700000000, 60],
            'response delay deducted from configuration ceiling' => [['Cache-Control' => ['max-age=300']], 60, 1700000000, 1700000010, 50],
            'valid extended configuration ceiling' => [['Cache-Control' => ['max-age=86400']], 7200, 1700000000, 1700000000, 7200],
            'absolute one-day configuration bound' => [['Cache-Control' => ['max-age=172800']], 172800, 1700000000, 1700000000, 86400],
            'quoted valid delta' => [['Cache-Control' => ['max-age="300"']], 3600, 1700000000, 1700000000, 300],
            'response delay and upstream age' => [['Cache-Control' => ['max-age=120'], 'Age' => ['20']], 3600, 1700000000, 1700000010, 90],
            'older response date' => [['Cache-Control' => ['max-age=120'], 'Date' => [$earlier]], 3600, 1700000000, 1700000000, 70],
            'earlier expiry' => [['Cache-Control' => ['max-age=120'], 'Date' => [$date], 'Expires' => [$expires]], 3600, 1700000000, 1700000020, 30],
            'fractional elapsed time rounds down' => [['Cache-Control' => ['max-age=120']], 3600, 1700000000, 1700000000.5, 119],
            'expired age' => [['Cache-Control' => ['max-age=30'], 'Age' => ['31']], 3600, 1700000000, 1700000000, 0],
            'missing permission' => [[], 3600, 1700000000, 1700000000, 0],
            'shared cache permission insufficient' => [['Cache-Control' => ['public, s-maxage=300']], 3600, 1700000000, 1700000000, 0],
            'duplicate conflicting lifetime' => [['Cache-Control' => ['max-age=300', 'Max-Age=600']], 3600, 1700000000, 1700000000, 0],
            'duplicate equal lifetime' => [['Cache-Control' => ['max-age=300, max-age=300']], 3600, 1700000000, 1700000000, 0],
            'no-cache with explicit lifetime' => [['Cache-Control' => ['public, max-age=300, no-cache']], 3600, 1700000000, 1700000000, 0],
            'field-specific no-cache' => [['Cache-Control' => ['max-age=300, no-cache="Set-Cookie"']], 3600, 1700000000, 1700000000, 0],
            'no-store with explicit lifetime' => [['Cache-Control' => ['max-age=300, no-store']], 3600, 1700000000, 1700000000, 0],
            'private fresh client reuse permitted' => [['Cache-Control' => ['max-age=300, private']], 3600, 1700000000, 1700000000, 300],
            'fresh mandatory revalidation permits reuse until expiry' => [['Cache-Control' => ['max-age=300, must-revalidate']], 3600, 1700000000, 1700000000, 300],
            'shared proxy restriction permits private client reuse' => [['Cache-Control' => ['max-age=300, proxy-revalidate']], 3600, 1700000000, 1700000000, 300],
            'legacy no-cache pragma' => [['Cache-Control' => ['max-age=300'], 'Pragma' => ['no-cache']], 3600, 1700000000, 1700000000, 0],
            'unbounded variance' => [['Cache-Control' => ['max-age=300'], 'Vary' => ['Accept-Encoding, *']], 3600, 1700000000, 1700000000, 0],
            'negative lifetime' => [['Cache-Control' => ['max-age=-1']], 3600, 1700000000, 1700000000, 0],
            'malformed lifetime' => [['Cache-Control' => ['max-age=300seconds']], 3600, 1700000000, 1700000000, 0],
            'unclosed quoted lifetime' => [['Cache-Control' => ['max-age="300']], 3600, 1700000000, 1700000000, 0],
            'overflow lifetime' => [['Cache-Control' => ['max-age=999999999999999999']], 3600, 1700000000, 1700000000, 0],
            'malformed upstream age' => [['Cache-Control' => ['max-age=300'], 'Age' => ['unknown']], 3600, 1700000000, 1700000000, 0],
            'duplicate upstream age' => [['Cache-Control' => ['max-age=300'], 'Age' => ['1', '2']], 3600, 1700000000, 1700000000, 0],
            'malformed response date' => [['Cache-Control' => ['max-age=300'], 'Date' => ['yesterday']], 3600, 1700000000, 1700000000, 0],
            'future response date fails closed' => [['Cache-Control' => ['max-age=300'], 'Date' => [$future]], 3600, 1700000000, 1700000000, 0],
            'malformed expires' => [['Cache-Control' => ['max-age=300'], 'Expires' => ['0']], 3600, 1700000000, 1700000000, 0],
            'disabled ceiling' => [['Cache-Control' => ['max-age=300']], 0, 1700000000, 1700000000, 0],
            'backwards request clock' => [['Cache-Control' => ['max-age=300']], 3600, 1700000010, 1700000000, 0],
        ];
    }

    #[DataProvider('cachePolicies')]
    public function test_provider_cache_permission_is_conservative_and_excludes_all_expired_time(array $headers, int $ceiling, float $started, float $received, int $expected): void
    {
        $this->assertSame($expected, TileCachePolicy::remainingSeconds($headers, $ceiling, $started, $received));
    }

    private function request(array $query = []): Request
    {
        $request = Request::create('/operations/routing/tiles/1/0/0', 'GET', $query);
        $session = app('session.store');
        $session->start();
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $this->actor);

        return $request;
    }

    private function fakeTile(array $headers = []): void
    {
        Http::fake(['api.tomtom.com/map/*' => Http::response('tile-fixture', 200, array_replace(['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=300'], $headers))]);
    }

    public function test_authorized_tile_response_keeps_browser_no_store_and_no_cache_metadata(): void
    {
        $this->fakeTile();
        $request = $this->request();
        $response = app(RoutingTileController::class)($request, 1, 0, 0);
        $this->assertFalse($response->headers->has('X-RailTime-Tile-Cache-Seconds'));
        $this->assertFalse($response->headers->has('X-RailTime-Tile-Cache-Scope'));
        $this->assertSame('Cookie', $response->headers->get('Vary'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame(1, app(RoutingUsageService::class)->current()['map_tiles']);
        Http::assertSentCount(1);
    }

    public function test_legacy_browser_scope_parameters_do_not_partition_the_global_server_cache(): void
    {
        $this->fakeTile();
        foreach ([[], ['cache_scope' => 'invalid'], ['cache_scope' => ['invalid']]] as $query) {
            $response = app(RoutingTileController::class)($this->request($query), 1, 0, 0);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertFalse($response->headers->has('X-RailTime-Tile-Cache-Seconds'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        Http::assertSentCount(1);
    }

    public function test_disabling_only_the_server_cache_preserves_counted_map_requests(): void
    {
        $this->fakeTile();
        $request = $this->request();
        TomTomSettings::save(array_replace(TomTomSettings::all(), ['server_cache_enabled' => false]));
        $response = app(RoutingTileController::class)($request, 1, 0, 0);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('X-RailTime-Tile-Cache-Seconds'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        app(RoutingTileController::class)($request, 1, 0, 0);
        Http::assertSentCount(2);
    }

    public function test_provider_restrictions_prevent_server_tile_reuse(): void
    {
        $this->fakeTile(['Cache-Control' => 'max-age=300, no-cache']);
        $routing = app(TomTomRoutingService::class);
        $this->assertSame('known', $routing->tile(1, 0, 0)['status']);
        $this->assertSame('known', $routing->tile(1, 0, 0)['status']);
        Http::assertSentCount(2);
        $this->assertSame(2, app(RoutingUsageService::class)->current()['map_tiles']);
    }

    public function test_non_raster_provider_content_is_not_served_or_given_cache_permission(): void
    {
        Http::fake(['api.tomtom.com/map/*' => Http::sequence()
            ->push('svg', 200, ['Content-Type' => 'image/svg+xml'])
            ->push('html', 200, ['Content-Type' => 'text/html'])
            ->push('json', 200, ['Content-Type' => 'application/json'])
            ->push('jpeg', 200, ['Content-Type' => 'image/jpeg'])]);
        foreach (['image/svg+xml', 'text/html', 'application/json'] as $contentType) {
            $tile = app(TomTomRoutingService::class)->tile(1, 0, 0);
            $this->assertSame('unknown', $tile['status']);
            $this->assertArrayNotHasKey('body', $tile);
            $this->assertArrayNotHasKey('cache_seconds', $tile);
        }
        $this->assertSame('image/jpeg', app(TomTomRoutingService::class)->tile(1, 0, 0)['content_type']);
    }

    public function test_key_change_during_the_provider_call_discards_the_response(): void
    {
        $request = $this->request();
        Http::fake(function () {
            TomTomSettings::save(TomTomSettings::all(), 'new-tile-credential');

            return Http::response('tile-fixture', 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=300']);
        });
        $response = app(RoutingTileController::class)($request, 1, 0, 0);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($response->headers->has('X-RailTime-Tile-Cache-Seconds'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_invalid_tiles_disabled_integration_and_database_locks_never_call_tomtom(): void
    {
        $this->fakeTile();
        $routing = app(TomTomRoutingService::class);
        $this->assertSame('invalid_tile', $routing->tile(1, 2, 0)['reason']);
        DB::transaction(function () use ($routing): void {
            $this->assertSame('transaction_requires_preflight', $routing->tile(1, 0, 0)['reason']);
        });
        TomTomSettings::save(array_replace(TomTomSettings::all(), ['enabled' => false]));
        $this->assertSame('disabled', $routing->tile(1, 0, 0)['reason']);
        Http::assertNothingSent();
        $this->assertSame(0, app(RoutingUsageService::class)->current()['map_tiles']);
    }

    public function test_failed_provider_attempts_and_exhausted_budget_are_never_cacheable(): void
    {
        TomTomSettings::save(array_replace(TomTomSettings::all(), ['tile_monthly_limit' => 1]));
        Http::fake(['api.tomtom.com/map/*' => Http::response('failure', 503, ['Cache-Control' => 'public, max-age=300'])]);
        $request = $this->request();
        foreach ([1, 2] as $attempt) {
            $response = app(RoutingTileController::class)($request, 1, 0, 0);
            $this->assertSame(503, $response->getStatusCode());
            $this->assertFalse($response->headers->has('X-RailTime-Tile-Cache-Seconds'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        Http::assertSentCount(1);
        $this->assertSame(1, app(RoutingUsageService::class)->current()['map_tiles']);
    }

    public function test_unauthorized_employee_cannot_obtain_map_tiles_or_consume_provider_budget(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->actingAs($employee)->get('/operations/routing/tiles/1/0/0')->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame(0, app(RoutingUsageService::class)->current()['map_tiles']);
    }

    public function test_authorized_legacy_http_route_returns_raster_and_no_store_headers(): void
    {
        $this->fakeTile();
        $this->get('/operations/routing/tiles/1/0/0')->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeaderMissing('X-RailTime-Tile-Cache-Seconds');
        Http::assertSentCount(1);
    }
}
