<?php

declare(strict_types=1);
use App\Support\Mail\OutlookUniversalTransitTransformer;
use Illuminate\Foundation\Application;

// Offline subprocess bridge only. No Laravel kernel, .env, DB, queue or network
// boot. Trust/projection here are local fixture assertions, never SMTP proof.
require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    new Application(dirname(__DIR__, 2));
    $raw = file_get_contents('php://stdin', false, null, 0, 4000001);
    if (! is_string($raw) || strlen($raw) > 4000000) {
        throw new RuntimeException('input_budget');
    }
    $request = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (! is_array($request) || ! is_string($request['html'] ?? null)
        || ! is_array($request['headers'] ?? null) || ! is_array($request['projection'] ?? null)
        || ($request['offlineTrustedFixture'] ?? false) !== true || ! is_string($request['envelopeSender'] ?? null)) {
        throw new RuntimeException('input_contract');
    }
    $result = (new OutlookUniversalTransitTransformer)->transform(
        $request['html'], $request['headers'], $request['projection'], true, $request['envelopeSender']
    );
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    // A bounded error code, never a private input or stack trace.
    echo '{"changed":false,"reason":"offline_bridge_failed","html":""}';
    exit(1);
}
