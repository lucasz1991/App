<?php

namespace Tests\Feature;

use App\Services\SystemHealth\BoundedInfrastructureConnections;
use App\Services\SystemHealth\InfrastructureChecks;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PDO;
use RuntimeException;
use Tests\TestCase;

class SystemHealthInfrastructureTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->temporaryRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'railtime-system-health-'.bin2hex(random_bytes(12));
        (new Filesystem)->ensureDirectoryExists($this->temporaryRoot);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->temporaryRoot) && preg_match('/\Arailtime-system-health-[a-f0-9]{24}\z/D', basename($this->temporaryRoot))) {
                (new Filesystem)->deleteDirectory($this->temporaryRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_unknown_ids_cannot_select_arbitrary_operations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(InfrastructureChecks::class)->run('migrate:fresh');
    }

    public function test_production_debug_is_an_error_and_key_values_are_never_returned(): void
    {
        config(['app.env' => 'production', 'app.debug' => true, 'app.url' => 'https://example.invalid', 'app.key' => 'base64:'.base64_encode(str_repeat('q', 32))]);

        $result = app(InfrastructureChecks::class)->run('application');

        $this->assertSame('error', $result['status']);
        $this->assertSame('configuration', $result['evidence']);
        $this->assertStringContainsString('Debugmodus', implode(' ', $result['details']));
        $this->assertStringNotContainsString(config('app.key'), json_encode($result));
        $this->assertTrue(config('app.debug'));
    }

    public function test_invalid_application_key_is_reported_without_generating_a_replacement(): void
    {
        config(['app.key' => 'invalid-key-for-this-test', 'app.debug' => false]);

        $result = app(InfrastructureChecks::class)->run('application');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Anwendungsschlüssel', implode(' ', $result['details']));
        $this->assertSame('invalid-key-for-this-test', config('app.key'));
        $this->assertStringNotContainsString('invalid-key-for-this-test', json_encode($result));
    }

    public function test_database_checks_select_schema_and_migration_names_only(): void
    {
        $connection = DB::connection();
        foreach (['users', 'settings', 'teams', 'team_user', 'files', 'devices', 'device_assignments', 'employee_identity_accounts'] as $table) {
            $connection->getSchemaBuilder()->create($table, fn (Blueprint $schema) => $schema->id());
        }
        $migrator = Mockery::mock();
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('getMigrationFiles')->once()->andReturn(['001_complete' => '/not-executed/001.php']);
        $this->app->instance('migrator', $migrator);
        $this->createMigrationHistory();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        $result = app(InfrastructureChecks::class)->run('database');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('connection', $result['evidence']);
        foreach ($connection->getQueryLog() as $query) {
            $this->assertMatchesRegularExpression('/\A\s*(select|pragma)\b/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/from\s+["`]?users/i', $query['query']);
        }
    }

    public function test_database_reports_missing_core_tables_without_repairing_them(): void
    {
        $result = app(InfrastructureChecks::class)->run('database');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Kerntabellen fehlen', $result['message']);
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('users'));
    }

    public function test_database_errors_do_not_expose_sql_connection_secrets(): void
    {
        $helper = Mockery::mock(BoundedInfrastructureConnections::class);
        $helper->shouldReceive('database')->once()->andThrow(new RuntimeException('mysql://user:private-password@host/db sensitive query'));
        $this->app->instance(BoundedInfrastructureConnections::class, $helper);

        $result = app(InfrastructureChecks::class)->run('database');

        $this->assertSame('error', $result['status']);
        $this->assertStringNotContainsString('private-password', json_encode($result));
        $this->assertStringNotContainsString('sensitive query', json_encode($result));
    }

    public function test_pending_migrations_are_warned_about_and_never_executed(): void
    {
        foreach (['users', 'settings', 'teams', 'team_user', 'files', 'devices', 'device_assignments', 'employee_identity_accounts'] as $table) {
            DB::connection()->getSchemaBuilder()->create($table, fn (Blueprint $schema) => $schema->id());
        }
        $migrator = Mockery::mock();
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('getMigrationFiles')->once()->andReturn(['001_complete' => '/not-executed/a.php', '002_pending' => '/not-executed/b.php']);
        $migrator->shouldNotReceive('run');
        $this->app->instance('migrator', $migrator);
        $this->createMigrationHistory();

        $result = app(InfrastructureChecks::class)->run('database');

        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString('1 ausstehende Migrationen', implode(' ', $result['details']));
    }

    public function test_real_isolated_file_cache_probe_preserves_existing_entries(): void
    {
        config(['cache.default' => 'health_test', 'cache.stores.health_test' => ['driver' => 'file', 'path' => $this->temporaryRoot.'/cache']]);
        $cache = Cache::store('health_test');
        $cache->put('existing-business-value', 'untouched', 600);
        $before = count((new Filesystem)->allFiles($this->temporaryRoot.'/cache'));

        $result = app(InfrastructureChecks::class)->run('cache');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('runtime', $result['evidence']);
        $this->assertSame('untouched', $cache->get('existing-business-value'));
        $this->assertCount($before, (new Filesystem)->allFiles($this->temporaryRoot.'/cache'));
    }

    public function test_cache_cleanup_is_attempted_even_when_read_fails(): void
    {
        config(['cache.default' => 'health_test', 'cache.stores.health_test.driver' => 'file']);
        $cache = Mockery::mock(Repository::class);
        $key = null;
        $cache->shouldReceive('has')->once()->with(Mockery::on(function ($value) use (&$key) {
            $key = $value;

            return (bool) preg_match('/\Arailtime:system-health:probe:[a-f0-9]{40}\z/D', $value);
        }))->andReturnFalse();
        $cache->shouldReceive('put')->once()->andReturnTrue();
        $cache->shouldReceive('get')->once()->andThrow(new RuntimeException('private-cache-secret'));
        $cache->shouldReceive('forget')->once()->with(Mockery::on(function ($value) use (&$key) {
            return $value === $key;
        }))->andReturnTrue();
        $cache->shouldReceive('has')->once()->andReturnFalse();
        $cache->shouldNotReceive('flush');
        Cache::shouldReceive('store')->with('health_test')->once()->andReturn($cache);

        $result = app(InfrastructureChecks::class)->run('cache');

        $this->assertSame('error', $result['status']);
        $this->assertStringNotContainsString('private-cache-secret', json_encode($result));
    }

    public function test_array_cache_is_not_presented_as_production_ready(): void
    {
        config(['cache.default' => 'array']);

        $result = app(InfrastructureChecks::class)->run('cache');

        $this->assertSame('warning', $result['status']);
        $this->assertSame('configuration', $result['evidence']);
    }

    public function test_storage_probes_only_own_files_and_skips_disabled_recording_storage(): void
    {
        $this->configureTemporaryDisks();
        config(['call_recording.enabled' => false, 'filesystems.disks.call_recordings' => ['driver' => 'unsupported']]);
        Storage::disk('private')->put('existing-business-file.txt', 'unchanged');

        $result = app(InfrastructureChecks::class)->run('storage');

        $this->assertContains($result['status'], ['ok', 'warning']);
        $this->assertSame('runtime', $result['evidence']);
        $this->assertSame('unchanged', Storage::disk('private')->get('existing-business-file.txt'));
        foreach (['local', 'private', 'public'] as $name) {
            $this->assertSame([], Storage::disk($name)->allFiles('system-health/probes'));
        }
        $this->assertStringNotContainsString('call_recordings', json_encode($result));
        $this->assertStringNotContainsString($this->temporaryRoot, json_encode($result));
    }

    public function test_missing_storage_root_is_not_created_automatically(): void
    {
        $this->configureTemporaryDisks();
        $missing = $this->temporaryRoot.'/missing-private';
        config(['filesystems.disks.private.root' => $missing]);

        $result = app(InfrastructureChecks::class)->run('storage');

        $this->assertSame('error', $result['status']);
        $this->assertDirectoryDoesNotExist($missing);
    }

    public function test_remote_storage_has_bounded_throwaway_adapter_and_cleanup_on_failure(): void
    {
        $this->configureTemporaryDisks();
        config(['filesystems.default' => 'remote_test', 'filesystems.disks.remote_test' => ['driver' => 's3', 'bucket' => 'test-bucket', 'region' => 'eu-central-1', 'key' => 'test-key', 'secret' => 'private-storage-secret']]);
        $manager = Storage::getFacadeRoot();
        $localDisks = [];
        foreach (['private', 'public'] as $name) {
            $localDisks[$name] = $manager->disk($name);
        }
        $remote = Mockery::mock(FilesystemContract::class);
        $path = null;
        $remote->shouldReceive('exists')->once()->with(Mockery::on(function ($value) use (&$path) {
            $path = $value;

            return (bool) preg_match('~\Asystem-health/probes/[a-f0-9]{40}\.txt\z~D', $value);
        }))->andReturnFalse();
        $remote->shouldReceive('put')->once()->andReturnTrue();
        $remote->shouldReceive('get')->once()->andThrow(new RuntimeException('private-storage-secret'));
        $remote->shouldReceive('delete')->once()->with(Mockery::on(function ($value) use (&$path) {
            return $value === $path;
        }))->andReturnTrue();
        $remote->shouldReceive('exists')->once()->andReturnFalse();
        Storage::shouldReceive('build')->once()->with(Mockery::on(fn ($config) => $config['http']['connect_timeout'] === 2 && $config['http']['timeout'] === 3 && $config['retries'] === 0))->andReturn($remote);
        foreach ($localDisks as $name => $disk) {
            Storage::shouldReceive('disk')->with($name)->andReturn($disk);
        }

        $result = app(InfrastructureChecks::class)->run('storage');

        $this->assertSame('error', $result['status']);
        $this->assertStringNotContainsString('private-storage-secret', json_encode($result));
        $this->assertArrayNotHasKey('http', config('filesystems.disks.remote_test'));
    }

    public function test_session_database_checks_schema_without_reading_session_payloads(): void
    {
        config(['session.driver' => 'database', 'session.connection' => 'sqlite', 'session.secure' => true]);
        DB::connection()->getSchemaBuilder()->create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->text('payload');
            $table->integer('last_activity');
        });
        DB::table('sessions')->insert(['id' => 'existing-session', 'payload' => 'private-payload', 'last_activity' => time()]);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        $result = app(InfrastructureChecks::class)->run('session');

        $queries = DB::connection()->getQueryLog();
        $this->assertSame('ok', $result['status']);
        $this->assertSame('configuration', $result['evidence']);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/\A\s*(select|pragma)\b/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/from\s+["`]?sessions/i', $query['query']);
        }
        $this->assertSame('private-payload', DB::table('sessions')->value('payload'));
        $this->assertStringNotContainsString('private-payload', json_encode($result));
    }

    public function test_session_none_without_secure_cookie_is_not_marked_ready(): void
    {
        config(['session.driver' => 'cookie', 'session.same_site' => 'none', 'session.secure' => false]);

        $result = app(InfrastructureChecks::class)->run('session');

        $this->assertSame('error', $result['status']);
        $this->assertSame('none', config('session.same_site'));
    }

    public function test_assets_verify_complete_manifest_graph_without_executing_files(): void
    {
        $this->configureTemporaryBuild();

        $result = app(InfrastructureChecks::class)->run('assets');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('configuration', $result['evidence']);
        $this->assertStringContainsString('3 eindeutige Build-Dateien', implode(' ', $result['details']));
    }

    public function test_missing_imported_asset_is_an_error(): void
    {
        $this->configureTemporaryBuild();
        unlink(public_path('build/assets/lazy.js'));

        $result = app(InfrastructureChecks::class)->run('assets');

        $this->assertSame('error', $result['status']);
    }

    public function test_manifest_cannot_read_outside_build_directory(): void
    {
        $this->configureTemporaryBuild();
        $files = new Filesystem;
        $files->put(public_path('not-an-asset.txt'), 'private-file-value');
        $manifest = ['resources/js/app.js' => ['file' => '../not-an-asset.txt'], 'resources/css/app.css' => ['file' => 'assets/app.css']];
        $files->put(public_path('build/manifest.json'), json_encode($manifest));

        $result = app(InfrastructureChecks::class)->run('assets');

        $this->assertSame('error', $result['status']);
        $this->assertStringNotContainsString('private-file-value', json_encode($result));
    }

    public function test_unresolved_module_reference_and_hot_marker_are_not_false_green(): void
    {
        $this->configureTemporaryBuild();
        $files = new Filesystem;
        $files->put(public_path('hot'), 'http://localhost:5173');
        $this->assertSame('warning', app(InfrastructureChecks::class)->run('assets')['status']);
        $manifest = json_decode($files->get(public_path('build/manifest.json')), true);
        $manifest['resources/js/app.js']['imports'][] = 'missing-entry';
        $files->put(public_path('build/manifest.json'), json_encode($manifest));
        $this->assertSame('error', app(InfrastructureChecks::class)->run('assets')['status']);
    }

    public function test_unsupported_database_and_session_drivers_do_not_open_unbounded_connections(): void
    {
        config(['database.default' => 'unsupported', 'database.connections.unsupported' => ['driver' => 'pgsql'], 'session.driver' => 'database', 'session.connection' => 'unsupported']);
        DB::shouldReceive('connection')->never();

        $this->assertSame('not_checked', app(InfrastructureChecks::class)->run('database')['status']);
        $this->assertSame('not_checked', app(InfrastructureChecks::class)->run('session')['status']);
    }

    public function test_unsupported_cache_driver_does_not_resolve_shared_network_client(): void
    {
        config(['cache.default' => 'unbounded', 'cache.stores.unbounded' => ['driver' => 'memcached']]);
        Cache::shouldReceive('store')->never();

        $this->assertSame('not_checked', app(InfrastructureChecks::class)->run('cache')['status']);
    }

    public function test_database_cache_probe_uses_isolated_repository_and_preserves_rows(): void
    {
        DB::connection()->getSchemaBuilder()->create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });
        DB::table('cache')->insert(['key' => 'existing-cache-key', 'value' => 'unchanged', 'expiration' => time() + 600]);
        config(['cache.default' => 'database', 'cache.stores.database.connection' => 'sqlite']);
        Cache::shouldReceive('store')->never();

        $this->assertSame('ok', app(InfrastructureChecks::class)->run('cache')['status']);
        $this->assertSame(1, DB::table('cache')->count());
        $this->assertSame('unchanged', DB::table('cache')->value('value'));
    }

    public function test_mysql_limits_are_scoped_and_configuration_is_not_changed(): void
    {
        if (! extension_loaded('mysqlnd')) {
            $this->markTestSkipped('The scoped mysqlnd transport branch requires mysqlnd.');
        }
        $configuration = ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'test', 'username' => 'test', 'options' => [PDO::ATTR_TIMEOUT => 90, PDO::ATTR_PERSISTENT => true]];
        config(['database.connections.bounded_mysql' => $configuration]);
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->once()->andReturn('8.0.45');
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldReceive('statement')->once()->with('SET SESSION max_execution_time = 3000, lock_wait_timeout = 3, innodb_lock_wait_timeout = 3')->andReturnTrue();
        $connection->shouldReceive('disconnect')->once();
        $helper = $this->databaseHelper($connection);
        $before = ini_get('mysqlnd.net_read_timeout');

        $result = $helper->database('bounded_mysql', function ($actual) use ($connection) {
            $this->assertSame($connection, $actual);
            $this->assertSame('3', ini_get('mysqlnd.net_read_timeout'));

            return 'checked';
        });

        $this->assertSame('checked', $result);
        $this->assertSame(2, $helper->received['options'][PDO::ATTR_TIMEOUT]);
        $this->assertFalse($helper->received['options'][PDO::ATTR_PERSISTENT]);
        $this->assertSame($before, ini_get('mysqlnd.net_read_timeout'));
        $this->assertSame($configuration, config('database.connections.bounded_mysql'));
    }

    public function test_mariadb_limit_and_ini_restore_survive_failed_callback(): void
    {
        if (! extension_loaded('mysqlnd')) {
            $this->markTestSkipped('The scoped mysqlnd transport branch requires mysqlnd.');
        }
        config(['database.connections.bounded_maria' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'test']]);
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->once()->andReturn('10.11.14-MariaDB');
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldReceive('statement')->once()->with('SET SESSION max_statement_time = 3, lock_wait_timeout = 3, innodb_lock_wait_timeout = 3')->andReturnTrue();
        $connection->shouldReceive('disconnect')->once();
        $helper = $this->databaseHelper($connection);
        $before = ini_get('mysqlnd.net_read_timeout');
        try {
            $helper->database('bounded_maria', fn () => throw new RuntimeException('synthetic probe failure'));
            $this->fail('The callback exception must remain visible to the safe caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic probe failure', $exception->getMessage());
        }
        $this->assertSame($before, ini_get('mysqlnd.net_read_timeout'));
    }

    public function test_failed_mysql_connection_restores_ini_without_touching_shared_database(): void
    {
        if (! extension_loaded('mysqlnd')) {
            $this->markTestSkipped('The scoped mysqlnd transport branch requires mysqlnd.');
        }
        config(['database.connections.bounded_fail' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'test']]);
        DB::shouldReceive('connection')->never();
        $helper = new class extends BoundedInfrastructureConnections
        {
            protected function makeDatabase(array $configuration): Connection
            {
                throw new RuntimeException('synthetic connection failure');
            }
        };
        $before = ini_get('mysqlnd.net_read_timeout');
        try {
            $helper->database('bounded_fail', fn () => $this->fail('No callback after failed connection.'));
            $this->fail('Expected connection failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic connection failure', $exception->getMessage());
        }
        $this->assertSame($before, ini_get('mysqlnd.net_read_timeout'));
    }

    public function test_redis_limits_override_saved_timeouts_only_in_new_manager(): void
    {
        $configuration = ['url' => 'redis://example.invalid:6379/0?timeout=90&read_timeout=90', 'options' => ['persistent' => true, 'read_write_timeout' => 120]];
        config(['database.redis.client' => 'phpredis', 'database.redis.health_test' => $configuration, 'cache.stores.health_redis' => ['driver' => 'redis', 'connection' => 'health_test']]);
        $manager = Mockery::mock(RedisManager::class);
        $manager->shouldReceive('connections')->once()->andReturn([]);
        $helper = new class($manager) extends BoundedInfrastructureConnections
        {
            public array $received = [];

            public function __construct(private RedisManager $manager) {}

            protected function makeRedis(string $client, array $configuration): RedisManager
            {
                $this->received = $configuration;

                return $this->manager;
            }
        };

        $this->assertTrue($helper->cache('health_redis', fn () => true));
        $this->assertSame(2, $helper->received['default']['timeout']);
        $this->assertSame(3, $helper->received['default']['read_timeout']);
        $this->assertSame(3, $helper->received['default']['options']['read_write_timeout']);
        $this->assertFalse($helper->received['default']['options']['persistent']);
        $this->assertSame(0, $helper->received['default']['options']['max_retries']);
        $this->assertSame($configuration, config('database.redis.health_test'));
    }

    public function test_split_database_targets_and_cluster_only_redis_are_not_probed(): void
    {
        config(['database.connections.split' => ['driver' => 'mysql', 'read' => ['host' => 'readonly'], 'write' => ['host' => 'writer']], 'database.redis.cluster_only' => null, 'cache.stores.health_cluster' => ['driver' => 'redis', 'connection' => 'cluster_only']]);
        $helper = new BoundedInfrastructureConnections;

        $this->assertNull($helper->database('split', fn () => $this->fail('No unbounded replica callback.')));
        $this->assertNull($helper->cache('health_cluster', fn () => $this->fail('No unbounded cluster callback.')));
    }

    private function databaseHelper(Connection $connection): BoundedInfrastructureConnections
    {
        return new class($connection) extends BoundedInfrastructureConnections
        {
            public array $received = [];

            public function __construct(private Connection $connection) {}

            protected function makeDatabase(array $configuration): Connection
            {
                $this->received = $configuration;

                return $this->connection;
            }
        };
    }

    private function configureTemporaryDisks(): void
    {
        $this->app->usePublicPath($this->temporaryRoot.'/web');
        $files = new Filesystem;
        foreach (['local' => '/local', 'private' => '/private', 'public' => '/web/storage'] as $name => $suffix) {
            $root = $this->temporaryRoot.$suffix;
            $files->ensureDirectoryExists($root);
            config(['filesystems.disks.'.$name => ['driver' => 'local', 'root' => $root, 'throw' => true]]);
            Storage::forgetDisk($name);
        }
        config(['filesystems.default' => 'local', 'call_recording.enabled' => false, 'livewire.temporary_file_upload.disk' => null]);
    }

    private function createMigrationHistory(): void
    {
        DB::connection()->getSchemaBuilder()->create('migrations', function (Blueprint $table) {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });
        DB::table('migrations')->insert(['migration' => '001_complete', 'batch' => 1]);
    }

    private function configureTemporaryBuild(): void
    {
        $this->app->usePublicPath($this->temporaryRoot.'/web');
        $files = new Filesystem;
        $files->ensureDirectoryExists(public_path('build/assets'));
        $files->put(public_path('build/assets/app.js'), 'throw new Error("must not execute");');
        $files->put(public_path('build/assets/app.css'), 'body{}');
        $files->put(public_path('build/assets/lazy.js'), '// immutable test file');
        $manifest = ['resources/js/app.js' => ['file' => 'assets/app.js', 'dynamicImports' => ['lazy']], 'resources/css/app.css' => ['file' => 'assets/app.css'], 'lazy' => ['file' => 'assets/lazy.js']];
        $files->put(public_path('build/manifest.json'), json_encode($manifest));
    }
}
