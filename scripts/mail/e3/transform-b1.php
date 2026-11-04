<?php

declare(strict_types=1);

// Local pipe callback only. Its caller first validates the private admission
// ticket. No HTTP endpoint, Laravel kernel, configuration, DB or mail transport.
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

require dirname(__DIR__, 3).'/vendor/autoload.php';
define('RAILTIME_CID_BRIDGE_LIBRARY_ONLY', true);
require dirname(__DIR__).'/railtime_transit_cid_html.php';

try {
    $application = new Application(dirname(__DIR__, 3));
    $application->instance('config', new Repository(['app' => ['url' => 'https://app.rail-time.de']]));
    $raw = file_get_contents('php://stdin', false, null, 0, 4000001);
    if (! is_string($raw) || strlen($raw) > 4000000) {
        throw new RuntimeException('input_budget');
    }
    $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (! is_array($data) || array_diff(array_keys($data), ['schema', 'html', 'headers', 'projection', 'cidUrls', 'envelopeSender']) !== []
        || ($data['schema'] ?? null) !== 1 || ! is_string($data['html'] ?? null)
        || ! is_array($data['headers'] ?? null) || ! is_array($data['projection'] ?? null)
        || ! is_array($data['cidUrls'] ?? null) || ($data['envelopeSender'] ?? null) !== 'it-technik@rail-time.de') {
        throw new RuntimeException('input_invalid');
    }
    $result = RailTimeTransitCidProjection::transform($data['html'], $data['headers'], $data['projection'], $data['cidUrls'], true, $data['envelopeSender']);
    echo json_encode(['schema' => 1, 'changed' => $result['changed'], 'reason' => $result['reason'], 'html' => $result['html']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    echo '{"schema":1,"ok":false,"code":"E3_TRANSFORM_CALLBACK_FAILED"}';
    exit(75);
}
