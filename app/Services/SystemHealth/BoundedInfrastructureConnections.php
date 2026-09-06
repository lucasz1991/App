<?php

namespace App\Services\SystemHealth;

use Closure;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Short-lived diagnostic clients, never registered in a shared connection manager.
 * null means unsupported without a safely bounded transport; no connection was used.
 */
class BoundedInfrastructureConnections
{
    public function database(?string $name, Closure $callback): mixed
    {
        $name ??= (string) config('database.default');
        $configured = config('database.connections.'.$name);
        if (! is_array($configured)) {
            return null;
        }
        $configuration = (new ConfigurationUrlParser)->parseConfiguration($configured);
        $driver = $configuration['driver'] ?? '';
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)
            || isset($configuration['read']) || isset($configuration['write'])
            || is_array($configuration['host'] ?? null)) {
            return null;
        }
        // A private in-memory database has no network or other process to block on.
        if ($driver === 'sqlite' && ($configuration['database'] ?? '') === ':memory:') {
            return $callback(DB::connection($name));
        }
        $oldReadTimeout = null;
        if ($driver !== 'sqlite') {
            if (! extension_loaded('mysqlnd') || ! function_exists('ini_set')) {
                return null;
            }
            $oldReadTimeout = ini_get('mysqlnd.net_read_timeout');
            if ($oldReadTimeout === false || ini_set('mysqlnd.net_read_timeout', '3') === false) {
                return null;
            }
        } elseif (! is_string($configuration['database'] ?? null)
            || str_starts_with(str_replace('\\', '/', $configuration['database']), '//')
            || str_contains($configuration['database'], '?')) {
            return null;
        }
        $connection = null;
        try {
            $configuration['options'] = array_replace((array) ($configuration['options'] ?? []), [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_PERSISTENT => false]);
            if ($driver === 'sqlite') {
                $configuration['busy_timeout'] = 3000;
                unset($configuration['pragmas'], $configuration['journal_mode'], $configuration['synchronous']);
            } else {
                if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                    unset($configuration['options'][constant('PDO::MYSQL_ATTR_INIT_COMMAND')]);
                }
                if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
                    $configuration['options'][constant('PDO::MYSQL_ATTR_MULTI_STATEMENTS')] = false;
                }
            }
            $connection = $this->makeDatabase($configuration);
            if ($driver !== 'sqlite') {
                $version = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
                $limit = str_contains(strtolower($version), 'mariadb') ? 'max_statement_time = 3' : 'max_execution_time = 3000';
                // These settings affect only this newly created, nonpersistent SQL session.
                $connection->statement('SET SESSION '.$limit.', lock_wait_timeout = 3, innodb_lock_wait_timeout = 3');
            }

            return $callback($connection);
        } finally {
            try {
                $connection?->disconnect();
            } finally {
                if (is_string($oldReadTimeout)) {
                    ini_set('mysqlnd.net_read_timeout', $oldReadTimeout);
                }
            }
        }
    }

    public function cache(string $name, Closure $callback): mixed
    {
        $configuration = config('cache.stores.'.$name, []);
        if (($configuration['driver'] ?? '') === 'database') {
            return $this->database($configuration['connection'] ?? null, fn (Connection $connection) => $callback(new Repository(new DatabaseStore(
                $connection, (string) ($configuration['table'] ?? 'cache'),
                (string) ($configuration['prefix'] ?? config('cache.prefix', '')), serializableClasses: false,
            ))));
        }
        if (($configuration['driver'] ?? '') !== 'redis') {
            return null;
        }
        $redisName = (string) ($configuration['connection'] ?? 'default');
        $configured = config('database.redis.'.$redisName);
        $client = (string) config('database.redis.client', 'phpredis');
        if (! is_array($configured) || ! in_array($client, ['phpredis', 'predis'], true)) {
            return null;
        }
        $parsed = (new ConfigurationUrlParser)->parseConfiguration($configured);
        if (is_array($parsed['host'] ?? null)) {
            return null;
        }
        $options = array_merge((array) config('database.redis.options', []), (array) ($parsed['options'] ?? []));
        // Replication, clusters and configurable client factories need a separate bounded adapter.
        if (isset($options['replication']) || isset($options['aggregate']) || isset($options['connections'])) {
            return null;
        }
        $limits = ['timeout' => 2, 'read_timeout' => 3, 'read_write_timeout' => 3, 'persistent' => false, 'max_retries' => 0, 'retry_interval' => 0];
        $options = array_replace($options, $limits);
        $options['parameters'] = array_replace((array) ($options['parameters'] ?? []), $limits);
        $parsed = array_replace($parsed, $limits);
        $parsed['options'] = $options;
        $manager = $this->makeRedis($client, ['default' => $parsed, 'options' => $options]);
        try {
            return $callback(new Repository(new RedisStore(
                $manager, (string) ($configuration['prefix'] ?? config('cache.prefix', '')), serializableClasses: false,
            )));
        } finally {
            // Do not create a connection merely to disconnect an unattempted probe.
            foreach ($manager->connections() ?? [] as $connection) {
                try {
                    $connection->disconnect();
                } catch (Throwable) {
                    // The probe result already records failed I/O; never leak client exceptions.
                }
            }
        }
    }

    protected function makeDatabase(array $configuration): Connection
    {
        return (new ConnectionFactory(app()))->make($configuration, 'system_health_isolated');
    }

    protected function makeRedis(string $client, array $configuration): RedisManager
    {
        return new RedisManager(app(), $client, $configuration);
    }
}
