<?php

declare(strict_types=1);
use App\Support\Mail\OutlookUniversalTransitSnapshotProvider;
use Illuminate\Contracts\Console\Kernel;

// This private CLI emits personal HTML and image bytes to its protected local pipe only.
// Never expose it as a route or log its stdout. It does not submit or send mail.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    $raw = file_get_contents('php://stdin', false, null, 0, 2049);
    if (! is_string($raw) || strlen($raw) > 2048) {
        throw new RuntimeException('U2E_TRANSIT_INPUT_INVALID');
    }
    $request = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    $keys = is_array($request) ? array_keys($request) : [];
    sort($keys);
    if ($keys !== ['envelopeSender', 'schema', 'templateHeader', 'tenantId', 'userId']
        || $request['schema'] !== 1 || ! is_int($request['userId'])
        || ! is_string($request['tenantId']) || ! is_string($request['envelopeSender']) || ! is_string($request['templateHeader'])) {
        throw new RuntimeException('U2E_TRANSIT_INPUT_INVALID');
    }
    $application = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $application->make(Kernel::class)->bootstrap();
    $result = $application->make(OutlookUniversalTransitSnapshotProvider::class)->resolve(
        $request['tenantId'], $request['userId'], $request['envelopeSender'], $request['templateHeader'],
    );
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    $known = ['U2E_TRANSIT_INPUT_INVALID', 'U2E_TRANSIT_BINDING_INVALID', 'U2E_TRANSIT_SOURCE_REJECTED',
        'U2E_TRANSIT_VERSION_NOT_CURRENT', 'U2E_TRANSIT_SOURCE_CHANGED', 'U2E_TRANSIT_ASSET_INVALID'];
    $code = in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'U2E_TRANSIT_RESOLVER_UNAVAILABLE';
    $retryable = ! in_array($code, ['U2E_TRANSIT_SOURCE_REJECTED', 'U2E_TRANSIT_VERSION_NOT_CURRENT'], true);
    echo json_encode(['schema' => 1, 'ok' => false, 'code' => $code, 'retryable' => $retryable], JSON_THROW_ON_ERROR);
    exit($retryable ? 75 : 0);
}
