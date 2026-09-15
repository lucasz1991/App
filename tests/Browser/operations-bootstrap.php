<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;

// Isolated local browser acceptance only. Never loaded by the application.
$qaDirectory = getenv('RAILTIME_OPERATIONS_QA_DIR');
if (! $qaDirectory || ! is_dir($qaDirectory) || ! str_contains(str_replace('\\', '/', $qaDirectory), '/.lmzdev/artifacts/temp/operations-qa-')) {
    throw new RuntimeException('Explicit isolated QA directory required.');
}
// Set the environment before service-provider boot, including auth/session resolution.
foreach (['APP_ENV' => 'local', 'APP_URL' => 'http://127.0.0.1:5087', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('q', 32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $qaDirectory.'/operations.sqlite', 'DB_URL' => '', 'DATABASE_URL' => '', 'SESSION_DRIVER' => 'file', 'SESSION_CONNECTION' => 'sqlite', 'SESSION_COOKIE' => 'railtime_operations_qa', 'CACHE_STORE' => 'array', 'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'log', 'BROADCAST_DRIVER' => 'log'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://127.0.0.1:5087',
    'app.key' => 'base64:'.base64_encode(str_repeat('q', 32)),
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $qaDirectory.'/operations.sqlite', 'database.connections.sqlite.url' => null,
    'cache.default' => 'array', 'session.driver' => 'file', 'session.files' => $qaDirectory.'/sessions', 'session.cookie' => 'railtime_operations_qa', 'session.domain' => null, 'session.secure' => false,
    'queue.default' => 'sync', 'mail.default' => 'array', 'broadcasting.default' => 'log', 'webpush.automatic_notifications' => false,
    'view.compiled' => $qaDirectory.'/views', 'logging.default' => 'single', 'logging.channels.single.path' => $qaDirectory.'/qa.log',
    'filesystems.disks.local.root' => $qaDirectory.'/private',
]);
DB::purge();
// Fail closed even for legacy code that explicitly names the mysql connection.
config(['database.connections.mysql' => config('database.connections.sqlite')]);
app('session')->forgetDrivers();
$app->forgetInstance('session.store');
app('auth')->forgetGuards();
app(Vite::class)->useBuildDirectory('operations-qa-build');

return $app;
