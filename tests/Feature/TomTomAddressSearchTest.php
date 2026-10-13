<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Routing\RoutingUsageService;
use App\Services\Routing\ServerRoutingCache;
use App\Services\Routing\TomTomAddressSearchService;
use App\Support\Routing\TomTomSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TomTomAddressSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['cache.default' => 'array']);
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('key');
            $table->json('value');
            $table->timestamps();
            $table->unique(['type', 'key']);
        });
        (require database_path('migrations/2026_10_11_120100_create_routing_monthly_usage_table.php'))->up();
        $this->travelTo(now()->setDate(2027, 5, 12)->startOfDay());
        Http::preventStrayRequests();
        $this->enable();
    }

    private function enable(array $settings = []): void
    {
        TomTomSettings::save(array_replace(TomTomSettings::DEFAULTS, ['enabled' => true], $settings), 'address-test-secret-012345');
    }

    private function row(string $id = 'qa-address', string $type = 'Point Address', array $replace = []): array
    {
        return array_replace_recursive([
            'id' => $id, 'type' => $type,
            'address' => ['countryCode' => 'DE', 'freeformAddress' => 'Musterstraße 1, 20095 Hamburg'],
            'position' => ['lat' => 53.55, 'lon' => 10.0],
        ], $replace);
    }

    public function test_search_uses_server_credential_bounded_german_typeahead_and_shared_atomic_budget(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertStringContainsString('/search/2/search/Musterstra%C3%9Fe%201.json?', $request->url());
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame(['key' => 'address-test-secret-012345', 'typeahead' => 'true', 'limit' => '6', 'countrySet' => 'DE', 'language' => 'de-DE', 'idxSet' => 'PAD,Addr,Str,Geo'], $query);
            $this->assertSame(4, $options['timeout']);
            $this->assertSame(2, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(['results' => [$this->row()]]);
        });
        $result = app(TomTomAddressSearchService::class)->search('  Musterstraße   1  ');
        $this->assertSame('known', $result['status']);
        $this->assertSame(['label' => 'Musterstraße 1, 20095 Hamburg', 'latitude' => 53.55, 'longitude' => 10.0, 'provider' => 'tomtom', 'provider_id' => 'qa-address', 'type' => 'Point Address', 'precision' => 'address'], $result['results'][0]);
        $this->assertStringNotContainsString('address-test-secret', json_encode($result));
        $this->assertSame(1, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertSame(0, app(RoutingUsageService::class)->current()['map_tiles']);
        Http::assertSentCount(1);
    }

    public function test_short_invalid_and_oversized_queries_never_call_provider_or_reserve_usage(): void
    {
        Http::fake();
        foreach (['', '  ', 'ab', str_repeat('x', 201), "Hamburg\nBerlin", "Hamburg\0", "\xFFbad"] as $query) {
            $result = app(TomTomAddressSearchService::class)->search($query);
            $this->assertSame('unknown', $result['status']);
            $this->assertSame([], $result['results']);
        }
        Http::assertNothingSent();
        $this->assertSame(0, app(RoutingUsageService::class)->current()['routing_transactions']);
    }

    public function test_precision_and_provider_type_aliases_are_explicit_without_confirmation(): void
    {
        Http::fake(['api.tomtom.com/*' => Http::response(['results' => [
            $this->row('a', 'PointAddress'), $this->row('b', 'AddressRange'),
            $this->row('c', 'Street'), $this->row('d', 'Geography'),
        ]])]);
        $results = app(TomTomAddressSearchService::class)->search('Hamburg')['results'];
        $this->assertSame(['address', 'interpolated', 'street', 'locality'], array_column($results, 'precision'));
        $this->assertSame(['Point Address', 'Address Range', 'Street', 'Geography'], array_column($results, 'type'));
        foreach ($results as $result) {
            $this->assertArrayNotHasKey('confirmed', $result);
        }
    }

    public function test_invalid_foreign_poi_outside_and_duplicate_results_are_filtered_and_capped(): void
    {
        $rows = [
            $this->row('at', replace: ['address' => ['countryCode' => 'AT']]),
            $this->row('far', replace: ['position' => ['lat' => 40]]),
            $this->row('east', replace: ['position' => ['lon' => 17]]),
            $this->row('nan', replace: ['position' => ['lat' => 'NaN']]),
            $this->row('poi', 'POI'),
            $this->row(str_repeat('i', 201)),
            $this->row('bad-label', replace: ['address' => ['freeformAddress' => str_repeat('x', 201)]]),
            $this->row('control', replace: ['address' => ['freeformAddress' => "Ort\nOrt"]]),
            $this->row('duplicate'), $this->row('duplicate'),
        ];
        foreach (range(1, 12) as $index) {
            $rows[] = $this->row('valid-'.$index);
        }
        Http::fake(['api.tomtom.com/*' => Http::response(['results' => $rows])]);
        $results = app(TomTomAddressSearchService::class)->search('Hamburg')['results'];
        $this->assertCount(6, $results);
        $this->assertSame(['duplicate', 'valid-1', 'valid-2', 'valid-3', 'valid-4', 'valid-5'], array_column($results, 'provider_id'));
    }

    public function test_empty_and_malformed_provider_payloads_remain_distinct(): void
    {
        Http::fakeSequence()->push(['results' => []])->push(['results' => ['wrong' => 'object']])->push('not-json')->push(str_repeat('x', 262145));
        $service = app(TomTomAddressSearchService::class);
        $this->assertSame(['status' => 'known', 'reason' => null, 'results' => []], $service->search('Hamburg'));
        foreach (range(1, 3) as $attempt) {
            $this->assertSame('invalid_response', $service->search('Hamburg')['reason']);
        }
        $this->assertSame(4, app(RoutingUsageService::class)->current()['routing_transactions']);
    }

    public function test_failures_are_counted_once_without_retry_or_sensitive_error_payload(): void
    {
        Http::fakeSequence()->push([], 403)->push([], 429)->push([], 503)->push([], 302);
        foreach (['provider_forbidden', 'provider_limit', 'provider_error', 'provider_error'] as $reason) {
            $this->assertSame(['status' => 'unknown', 'reason' => $reason, 'results' => []], app(TomTomAddressSearchService::class)->search('Hamburg'));
        }
        Http::assertSentCount(4);
        $this->assertSame(4, app(RoutingUsageService::class)->current()['routing_transactions']);
    }

    public function test_transport_failure_does_not_log_or_expose_the_request_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('Sensitive full URL and address-test-secret-012345'));
        $result = app(TomTomAddressSearchService::class)->search('Hamburg');
        $this->assertSame(['status' => 'unknown', 'reason' => 'provider_unavailable', 'results' => []], $result);
        $this->assertSame(1, app(RoutingUsageService::class)->current()['routing_transactions']);
    }

    public function test_disabled_missing_key_missing_schema_and_outer_transactions_never_call_provider(): void
    {
        Http::fake();
        TomTomSettings::save(array_replace(TomTomSettings::all(), ['enabled' => false]));
        $this->assertSame('disabled', app(TomTomAddressSearchService::class)->search('Hamburg')['reason']);
        Setting::setValue('routing', 'tomtom', array_replace(TomTomSettings::DEFAULTS, ['enabled' => true]));
        $this->assertSame('missing_key', app(TomTomAddressSearchService::class)->search('Hamburg')['reason']);
        $this->enable();
        $result = DB::transaction(fn () => app(TomTomAddressSearchService::class)->search('Hamburg'));
        $this->assertSame('transaction_requires_preflight', $result['reason']);
        $this->assertSame(0, app(RoutingUsageService::class)->current()['routing_transactions']);
        Schema::drop('routing_monthly_usage');
        $this->assertSame('schema_missing', app(TomTomAddressSearchService::class)->search('Hamburg')['reason']);
        Http::assertNothingSent();
    }

    public function test_search_cannot_exceed_the_remaining_shared_routing_budget(): void
    {
        $this->enable(['routing_monthly_limit' => 2]);
        $this->assertTrue(app(RoutingUsageService::class)->reserve('routing_transactions'));
        Http::fake(['api.tomtom.com/*' => Http::response(['results' => [$this->row()]])]);
        $this->assertSame('known', app(TomTomAddressSearchService::class)->search('Hamburg')['status']);
        $this->assertSame('budget_exhausted', app(TomTomAddressSearchService::class)->search('Hamburg')['reason']);
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
        Http::assertSentCount(1);
    }

    public function test_changed_or_disabled_credentials_discard_in_flight_results(): void
    {
        Http::fake(function () {
            TomTomSettings::save(TomTomSettings::all(), 'replaced-test-secret');

            return Http::response(['results' => [$this->row()]]);
        });
        $this->assertSame('settings_changed', app(TomTomAddressSearchService::class)->search('Hamburg')['reason']);
        Http::fake(function () {
            TomTomSettings::save(array_replace(TomTomSettings::all(), ['enabled' => false]));

            return Http::response(['results' => [$this->row()]]);
        });
        $this->assertSame('disabled', app(TomTomAddressSearchService::class)->search('Hamburg')['reason']);
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
    }

    public function test_queries_and_suggestions_are_not_written_to_the_server_routing_cache(): void
    {
        $this->app->bind(ServerRoutingCache::class, fn () => throw new \RuntimeException('Search must not access route cache.'));
        Http::fake(['api.tomtom.com/*' => Http::response(['results' => [$this->row()]])]);
        $service = app(TomTomAddressSearchService::class);
        $this->assertSame($service->search('Hamburg'), $service->search('Hamburg'));
        Http::assertSentCount(2);
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
    }
}
