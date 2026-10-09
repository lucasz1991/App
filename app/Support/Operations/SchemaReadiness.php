<?php

namespace App\Support\Operations;

use Closure;
use Illuminate\Support\Facades\Schema;

/** Schema facts only, scoped to the current HTTP request; permissions are never cached here. */
final class SchemaReadiness
{
    public static function remember(string $name, Closure $check): bool
    {
        // Console commands can migrate schema between calls and workers outlive a request.
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return $check();
        }

        $request = app('request');
        $connection = Schema::getConnection();
        $key = implode(':', [$name, $connection->getName(), $connection->getDatabaseName(), $connection->getTablePrefix()]);
        $checks = $request->attributes->get('rt.schema-readiness', []);
        if (! array_key_exists($key, $checks)) {
            $checks[$key] = $check();
            $request->attributes->set('rt.schema-readiness', $checks);
        }

        return $checks[$key];
    }
}
