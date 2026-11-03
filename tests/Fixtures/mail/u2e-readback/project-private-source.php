<?php

// Optional fixture regeneration helper. The private path is supplied as argv[1].
// Pipe stdout directly to generate-redacted.mjs; do not persist or print it.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$queryCount = 0;
$app->make('events')->listen(Illuminate\Database\Events\QueryExecuted::class, function () use (&$queryCount): void {
    $queryCount++;
    throw new RuntimeException('OFFLINE_QUERY_FORBIDDEN');
});
$app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app): void {
    $app['config']->set('database.default', 'u2e-offline-probe');
    $app['config']->set('database.connections.u2e-offline-probe', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
    ]);
    $app['config']->set('cache.default', 'array');
    $app['config']->set('logging.default', 'null');
    $app['config']->set('app.url', 'https://app.rail-time.de');
});
try {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Mail::fake();
    Illuminate\Support\Facades\Log::swap(new Psr\Log\NullLogger);
    $bytes = file_get_contents($argv[1] ?? '');
    if (hash('sha256', $bytes) !== '95124735d6302bc8a4e71e73f924ff626be91a16547082a43874f75f9089928d') {
        throw new RuntimeException('IMMUTABLE_SOURCE_INPUT_MISMATCH');
    }
    $input = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    $projection = new App\Support\OutlookAddin\OutlookUniversalPayloadPresentation(new App\Support\OutlookAddin\OutlookUniversalPresentation);
    $wire = $projection->append($input['payload'], $input['payload'], $input['profileValues'],
        (new App\Models\User)->forceFill(['email' => 'it-technik@rail-time.de']));
    echo json_encode(['document' => $wire['templates'][0]['universalCompose'] ?? null, 'queryCount' => $queryCount], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    // Error messages and traces may contain private values.
    fwrite(STDERR, 'OFFLINE_SOURCE_PROJECTION_FAILED '.get_class($error).PHP_EOL);
    exit(2);
}
