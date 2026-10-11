<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Routing\ServerRoutingCache;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ServerRoutingCacheTest extends TestCase
{
    private string $directory;

    private ServerRoutingCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::dropAllTables();
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-11T12:00:00Z'));
        $this->settings();
        $this->directory = storage_path('framework/testing/routing-cache-'.bin2hex(random_bytes(10)));
        $this->cache = new ServerRoutingCache($this->directory);
    }

    protected function tearDown(): void
    {
        // Only delete flat files inside this test's explicit, random namespace.
        if (isset($this->directory) && is_dir($this->directory) && ! is_link($this->directory)) {
            $base = realpath(storage_path('framework/testing'));
            $resolved = realpath($this->directory);
            if ($base !== false && $resolved !== false && str_starts_with($resolved, $base.DIRECTORY_SEPARATOR.'routing-cache-')) {
                foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                    if (is_file($path) || is_link($path)) {
                        unlink($path);
                    }
                }
                foreach (['.namespace.lock', '.state', '.dirty'] as $name) {
                    $file = $this->directory.DIRECTORY_SEPARATOR.$name;
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($this->directory);
            }
        }
        parent::tearDown();
    }

    private function settings(array $overrides = []): void
    {
        Setting::setValue('routing', 'tomtom', array_replace(TomTomSettings::DEFAULTS, [
            'server_cache_enabled' => true, 'server_tile_cache_enabled' => true,
            'server_route_cache_enabled' => true, 'server_tile_cache_minutes' => 60,
            'server_route_cache_minutes' => 5, 'server_geometry_cache_minutes' => 15,
            'server_cache_max_mb' => 128,
        ], $overrides));
    }

    private function route(): array
    {
        return ['status' => 'known', 'reason' => null, 'duration_seconds' => 1800, 'distance_meters' => 20000];
    }

    private function tile(string $body = "\x89PNG\r\n\x1a\n\xFF\x00payload"): array
    {
        return ['status' => 'known', 'body' => $body, 'content_type' => 'image/png'];
    }

    private function files(): array
    {
        return glob($this->directory.DIRECTORY_SEPARATOR.'entry-*.cache') ?: [];
    }

    public function test_separate_worker_instances_share_encrypted_known_data_with_utc_timestamps(): void
    {
        $this->assertTrue($this->cache->put('summary', 'keyhash:canonicalroute', $this->route(), 300, ['etag' => 'version-1']));
        $otherWorker = new ServerRoutingCache($this->directory);
        $entry = $otherWorker->get('summary', 'keyhash:canonicalroute');
        $this->assertSame($this->route(), $entry['data']);
        $this->assertSame('2026-10-11T12:00:00+00:00', $entry['fetched_at']);
        $this->assertSame('2026-10-11T12:05:00+00:00', $entry['expires_at']);
        $this->assertSame(['etag' => 'version-1'], $entry['metadata']);
        $this->assertSame(1, $this->cache->stats()['entries']);
        $this->assertGreaterThan(0, $this->cache->stats()['bytes']);
        $this->assertArrayNotHasKey('path', $this->cache->stats());
    }

    public function test_encrypted_tile_bytes_and_hashed_identity_never_expose_provider_or_request_values(): void
    {
        $identity = 'secret-api-key|52.520008,13.404954|v1';
        $tile = $this->tile();
        $this->assertTrue($this->cache->put('tile', $identity, $tile, 3600));
        $file = $this->files()[0];
        $this->assertMatchesRegularExpression('/entry-tile-[a-f0-9]{64}\.cache$/', $file);
        $this->assertStringNotContainsString('secret-api-key', $file);
        $this->assertStringNotContainsString('52.520008', file_get_contents($file));
        $this->assertStringNotContainsString('payload', file_get_contents($file));
        $this->assertSame($tile, $this->cache->get('tile', $identity)['data']);
    }

    public function test_provider_failure_and_nonpositive_lifetime_never_create_entries(): void
    {
        $this->assertFalse($this->cache->put('summary', 'failed', ['status' => 'unknown', 'reason' => 'provider_unavailable'], 300));
        $this->assertFalse($this->cache->put('summary', 'zero', $this->route(), 0));
        $this->assertFalse($this->cache->put('summary', 'negative', $this->route(), -1));
        $this->assertFalse($this->cache->put('wrong-kind', 'test', $this->route(), 300));
        $this->assertFalse($this->cache->put('summary', '', $this->route(), 300));
        $this->assertSame(0, $this->cache->stats()['entries']);
    }

    public function test_expired_data_is_unreadable_at_exact_deadline_before_physical_purge(): void
    {
        $this->assertTrue($this->cache->put('summary', 'deadline', $this->route(), 60));
        $this->travel(59)->seconds();
        $this->assertNotNull($this->cache->get('summary', 'deadline'));
        $this->travel(1)->seconds();
        $this->assertNull($this->cache->get('summary', 'deadline'));
        $this->assertCount(1, $this->files());
        $this->assertSame(1, $this->cache->stats()['expired_entries']);
        $this->assertSame(1, $this->cache->purgeExpired());
        $this->assertCount(0, $this->files());
    }

    public function test_each_category_clips_requested_ttl_to_current_settings(): void
    {
        $this->settings(['server_tile_cache_minutes' => 2, 'server_route_cache_minutes' => 1, 'server_geometry_cache_minutes' => 3]);
        $geometry = $this->route() + ['points' => [[52.52, 13.4], [53.55, 10.0]]];
        $this->assertTrue($this->cache->put('tile', 'one', $this->tile(), 999999));
        $this->assertTrue($this->cache->put('summary', 'one', $this->route(), 999999));
        $this->assertTrue($this->cache->put('geometry', 'one', $geometry, 999999));
        $this->assertSame('2026-10-11T12:01:00+00:00', $this->cache->get('summary', 'one')['expires_at']);
        $this->assertSame('2026-10-11T12:02:00+00:00', $this->cache->get('tile', 'one')['expires_at']);
        $this->assertSame('2026-10-11T12:03:00+00:00', $this->cache->get('geometry', 'one')['expires_at']);
        $this->travel(61)->seconds();
        $this->assertNull($this->cache->get('summary', 'one'));
        $this->assertNotNull($this->cache->get('geometry', 'one'));
    }

    public function test_fresh_disable_and_ttl_reduction_apply_without_a_settings_cache(): void
    {
        $this->assertTrue($this->cache->put('summary', 'known', $this->route(), 300));
        $this->travel(61)->seconds();
        $this->settings(['server_route_cache_minutes' => 1]);
        $this->assertNull($this->cache->get('summary', 'known'));
        $this->assertSame(1, $this->cache->purgeExpired());
        $this->assertCount(0, $this->files());
        $this->settings(['server_cache_enabled' => false]);
        $this->assertFalse($this->cache->put('summary', 'new', $this->route(), 300));
        $this->assertNull($this->cache->get('summary', 'known'));
        $this->settings(['server_route_cache_enabled' => false]);
        $this->assertFalse($this->cache->put('geometry', 'new', $this->route() + ['points' => [[1, 2], [3, 4]]], 900));
        $this->assertTrue($this->cache->put('tile', 'allowed', $this->tile(), 3600));
        $this->settings(['server_tile_cache_enabled' => false]);
        $this->assertNull($this->cache->get('tile', 'allowed'));
    }

    public function test_only_provider_allowlists_persist_and_summary_never_contains_geometry(): void
    {
        $private = ['employee_id' => 44, 'name' => 'Private employee', 'api_key' => 'not-stored', 'calculated_at' => now()->toIso8601String(), 'points' => [[1, 2], [3, 4]]];
        $this->assertTrue($this->cache->put('summary', 'known', $this->route() + $private, 300, ['etag' => 'accepted', 'employee_id' => '44', 'cache_control' => "bad\r\nheader"]));
        $entry = $this->cache->get('summary', 'known');
        $this->assertSame($this->route(), $entry['data']);
        $this->assertSame(['etag' => 'accepted'], $entry['metadata']);
        $plain = Crypt::decryptString(explode("\n", file_get_contents($this->files()[0]), 2)[1]);
        $this->assertStringNotContainsString('Private employee', $plain);
        $this->assertStringNotContainsString('not-stored', $plain);
        $this->assertStringNotContainsString('employee_id', $plain);
    }

    public function test_invalid_geometry_or_numeric_data_and_oversized_ciphertext_are_rejected(): void
    {
        $this->assertFalse($this->cache->put('geometry', 'one', $this->route(), 900));
        $this->assertFalse($this->cache->put('geometry', 'one', $this->route() + ['points' => [[91, 2], [1, 2]]], 900));
        $this->assertFalse($this->cache->put('summary', 'one', array_replace($this->route(), ['duration_seconds' => INF]), 300));
        $this->assertFalse($this->cache->put('tile', 'large', $this->tile(str_repeat('x', 1048576)), 3600));
        $this->assertSame(0, $this->cache->stats()['entries']);
    }

    public function test_corrupt_and_cross_identity_copies_are_never_returned_and_are_purged(): void
    {
        $this->assertTrue($this->cache->put('summary', 'original', $this->route(), 300));
        $original = $this->files()[0];
        $copied = $this->directory.DIRECTORY_SEPARATOR.'entry-summary-'.hash('sha256', 'other').'.cache';
        copy($original, $copied);
        $this->assertNull($this->cache->get('summary', 'other'));
        file_put_contents($original, 'corrupt');
        $this->assertNull($this->cache->get('summary', 'original'));
        $this->assertSame(0, $this->cache->purgeExpired());
        $this->assertSame(0, $this->cache->stats()['entries']);
    }

    public function test_capacity_evicts_oldest_entries_under_a_shared_namespace_cap(): void
    {
        $this->settings(['server_cache_max_mb' => 1]);
        $this->assertTrue($this->cache->put('tile', 'older', $this->tile(str_repeat('x', 350000)), 3600));
        $this->travel(1)->seconds();
        $this->assertTrue($this->cache->put('tile', 'newer', $this->tile(str_repeat('y', 350000)), 3600));
        $this->assertNull($this->cache->get('tile', 'older'));
        $this->assertNotNull($this->cache->get('tile', 'newer'));
        $this->assertSame(1, $this->cache->stats()['entries']);
        $this->assertLessThanOrEqual(1048576, $this->cache->stats()['bytes']);
    }

    public function test_namespace_clear_leaves_unrelated_files_and_application_cache_untouched(): void
    {
        $this->assertTrue($this->cache->put('summary', 'one', $this->route(), 300));
        $sentinel = $this->directory.DIRECTORY_SEPARATOR.'other-feature-cache.txt';
        file_put_contents($sentinel, 'preserve');
        cache()->put('unrelated.routing.test', 'preserve', 600);
        $this->assertSame(1, $this->cache->clear());
        $this->assertSame('preserve', file_get_contents($sentinel));
        $this->assertSame('preserve', cache()->get('unrelated.routing.test'));
        $this->assertSame(0, $this->cache->clear());
    }

    public function test_entry_forget_removes_one_identity_even_when_cache_is_disabled(): void
    {
        $this->assertTrue($this->cache->put('summary', 'one', $this->route(), 300));
        $this->assertTrue($this->cache->put('summary', 'two', $this->route(), 300));
        $this->settings(['server_cache_enabled' => false]);
        $this->assertTrue($this->cache->forget('summary', 'one'));
        $this->assertSame(1, $this->cache->stats()['entries']);
    }

    public function test_paths_outside_private_framework_or_blocked_by_a_file_fail_open(): void
    {
        $outside = new ServerRoutingCache(base_path('public/cache-should-not-exist'));
        $this->assertFalse($outside->put('summary', 'one', $this->route(), 300));
        $this->assertNull($outside->get('summary', 'one'));
        $this->assertSame(0, $outside->clear());
        $this->assertDirectoryDoesNotExist(base_path('public/cache-should-not-exist'));
        $this->assertSame('provider-direct', $outside->synchronized('summary', 'one', fn () => 'provider-direct'));
        mkdir($this->directory, 0700, true);
        $blocked = $this->directory.DIRECTORY_SEPARATOR.'blocked';
        file_put_contents($blocked, 'sentinel');
        $cache = new ServerRoutingCache($blocked);
        $this->assertFalse($cache->put('summary', 'one', $this->route(), 300));
        $this->assertSame('provider-direct', $cache->synchronized('summary', 'one', fn () => 'provider-direct'));
        $this->assertSame('sentinel', file_get_contents($blocked));
    }

    public function test_synchronized_callback_rechecks_shared_entry_and_only_computes_once(): void
    {
        $calls = 0;
        $callback = function () use (&$calls): array {
            $entry = $this->cache->get('summary', 'one');
            if ($entry !== null) {
                return $entry['data'];
            }
            $calls++;
            $result = $this->route();
            $this->assertTrue($this->cache->put('summary', 'one', $result, 300));

            return $result;
        };
        $this->assertSame($this->route(), $this->cache->synchronized('summary', 'one', $callback));
        $this->assertSame($this->route(), $this->cache->synchronized('summary', 'one', $callback));
        $this->assertSame(1, $calls);
    }

    public function test_sorted_batch_locks_deduplicate_colliding_and_repeated_stripes(): void
    {
        $seen = [];
        $collision = null;
        for ($i = 0; $i < 1000; $i++) {
            $identity = 'identity-'.$i;
            $stripe = (int) hexdec(substr(hash('sha256', 'summary:'.$identity), 0, 2)) % 64;
            if (isset($seen[$stripe])) {
                $collision = [$identity, $seen[$stripe]];
                break;
            }
            $seen[$stripe] = $identity;
        }
        $this->assertNotNull($collision);
        $started = microtime(true);
        $this->assertSame('one-callback', $this->cache->synchronizedMany('summary', [$collision[1], $collision[0], $collision[1]], fn () => 'one-callback'));
        $this->assertLessThan(0.8, microtime(true) - $started);
        $this->assertCount(1, glob($this->directory.DIRECTORY_SEPARATOR.'stripe-*.lock'));
    }

    public function test_callback_failure_propagates_without_retry_and_releases_its_locks(): void
    {
        $calls = 0;
        try {
            $this->cache->synchronized('summary', 'one', function () use (&$calls): void {
                $calls++;
                throw new RuntimeException('synthetic failure');
            });
            $this->fail('Expected callback failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic failure', $exception->getMessage());
        }
        $this->assertSame(1, $calls);
        $this->assertSame('unlocked', $this->cache->synchronized('summary', 'one', fn () => 'unlocked'));
    }

    public function test_contended_stripe_has_a_bounded_wait_and_executes_the_producer_once(): void
    {
        $this->cache->generation();
        $identity = 'contended';
        $stripe = (int) hexdec(substr(hash('sha256', 'summary:'.$identity), 0, 2)) % 64;
        $path = $this->directory.DIRECTORY_SEPARATOR.'stripe-'.str_pad((string) $stripe, 2, '0', STR_PAD_LEFT).'.lock';
        $holder = fopen($path, 'c+b');
        $this->assertTrue(flock($holder, LOCK_EX));
        $calls = 0;
        $started = microtime(true);
        try {
            $result = $this->cache->synchronized('summary', $identity, function () use (&$calls): string {
                $calls++;

                return 'provider-direct';
            });
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }
        $this->assertSame('provider-direct', $result);
        $this->assertSame(1, $calls);
        $this->assertLessThan(3.0, microtime(true) - $started);
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started);
    }

    public function test_cache_producer_is_not_executed_inside_a_database_transaction(): void
    {
        $calls = 0;
        DB::beginTransaction();
        try {
            $this->cache->synchronized('summary', 'one', function () use (&$calls): void {
                $calls++;
            });
            $this->fail('Transaction must refuse a provider producer.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('outside-transaction', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, $calls);
    }

    public function test_clock_rollback_never_makes_a_future_fetched_entry_eligible(): void
    {
        $this->assertTrue($this->cache->put('summary', 'one', $this->route(), 300));
        $this->travelBack();
        $this->travelTo(CarbonImmutable::parse('2026-10-11T11:59:59Z'));
        $this->assertNull($this->cache->get('summary', 'one'));
    }

    public function test_manual_clear_invalidates_a_running_producer_from_a_different_worker(): void
    {
        $result = $this->cache->synchronized('summary', 'one', function (): bool {
            $otherWorker = new ServerRoutingCache($this->directory);
            $otherWorker->clear();

            return $this->cache->put('summary', 'one', $this->route(), 300);
        });
        $this->assertFalse($result);
        $this->assertNull($this->cache->get('summary', 'one'));
        $this->assertSame(0, $this->cache->stats()['entries']);
    }

    public function test_explicit_generation_token_rejects_late_writes_after_an_empty_clear(): void
    {
        $generation = $this->cache->generation();
        $this->assertNotNull($generation);
        $this->assertSame(0, $this->cache->clear());
        $this->assertNotSame($generation, $this->cache->generation());
        $this->assertFalse($this->cache->put('summary', 'old-response', $this->route(), 300, [], $generation));
        $this->assertTrue($this->cache->put('summary', 'new-response', $this->route(), 300, [], $this->cache->generation()));
    }

    public function test_dirty_marker_recovers_capacity_after_interrupted_state_updates(): void
    {
        $this->settings(['server_cache_max_mb' => 1]);
        $this->assertTrue($this->cache->put('tile', 'one', $this->tile(str_repeat('x', 350000)), 3600));
        $stateFile = $this->directory.DIRECTORY_SEPARATOR.'.state';
        $state = json_decode(Crypt::decryptString(file_get_contents($stateFile)), true, flags: JSON_THROW_ON_ERROR);
        $state['entries'] = 0;
        $state['bytes'] = 0;
        file_put_contents($stateFile, Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)));
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'.dirty', '1');
        $this->assertTrue($this->cache->put('tile', 'two', $this->tile(str_repeat('y', 350000)), 3600));
        $this->assertSame(1, $this->cache->stats()['entries']);
        $this->assertLessThanOrEqual(1048576, $this->cache->stats()['bytes']);
        $this->assertCount(1, $this->files());
        $this->assertFileDoesNotExist($this->directory.DIRECTORY_SEPARATOR.'.dirty');
    }

    public function test_body_corruption_is_removed_without_waiting_for_ttl(): void
    {
        $this->assertTrue($this->cache->put('summary', 'one', $this->route(), 300));
        $file = $this->files()[0];
        $header = explode("\n", file_get_contents($file), 2)[0];
        file_put_contents($file, $header."\ninvalid-ciphertext");
        $this->assertNull($this->cache->get('summary', 'one'));
        $this->assertFileDoesNotExist($file);
        $this->assertSame(0, $this->cache->stats()['entries']);
    }

    public function test_maintenance_removes_only_owned_interrupted_atomic_write_files(): void
    {
        $this->cache->generation();
        $temporary = $this->directory.DIRECTORY_SEPARATOR.'write-'.str_repeat('a', 24).'.tmp';
        $sentinel = $this->directory.DIRECTORY_SEPARATOR.'other-feature.tmp';
        file_put_contents($temporary, 'encrypted-partial-cache-write');
        file_put_contents($sentinel, 'preserve');
        $this->assertSame(0, $this->cache->purgeExpired());
        $this->assertFileDoesNotExist($temporary);
        $this->assertSame('preserve', file_get_contents($sentinel));
    }

    public function test_cache_namespace_and_entries_never_follow_symlinks(): void
    {
        $this->cache->generation();
        $target = $this->directory.DIRECTORY_SEPARATOR.'sentinel';
        file_put_contents($target, 'preserve');
        $link = $this->directory.DIRECTORY_SEPARATOR.'entry-summary-'.hash('sha256', 'one').'.cache';
        if (! @symlink($target, $link)) {
            $this->markTestSkipped('Host does not permit test symlinks.');
        }
        $this->assertNull($this->cache->get('summary', 'one'));
        $this->assertFalse($this->cache->put('summary', 'one', $this->route(), 300));
        $this->assertSame(0, $this->cache->clear());
        $this->assertSame('preserve', file_get_contents($target));
        $outside = $this->directory.DIRECTORY_SEPARATOR.'outside-link';
        $this->assertTrue(symlink(storage_path('logs'), $outside));
        $linkedNamespace = new ServerRoutingCache($outside.DIRECTORY_SEPARATOR.'must-not-exist');
        $this->assertFalse($linkedNamespace->put('summary', 'one', $this->route(), 300));
        $this->assertDirectoryDoesNotExist(storage_path('logs/must-not-exist'));
    }
}
