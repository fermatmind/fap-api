<?php

declare(strict_types=1);

namespace Tests\Fixtures\Q02EqIntro;

// Test-only worker. The prepared current writer is frozen before Laravel autoloads it; runtime is not deployed.
const Q02_WRITER_SHA256 = '54e70b84bd982b4c123a622769fc6347d53722a29245d4fe282405f115fb5340';
const Q02_CACHE_SHA256 = '8872ffd273de522207ffcc108f759a414278e64d3bfcda1bffecd550aa67f95c';
const Q02_CACHE_KEYS_SHA256 = 'ec8f4da184c5aa2b1d6bdaa3e7d3b51a9962fa73958d9838e18a988a4ffff25c';

use App\Services\Scale\PublicScaleCatalogCache;
use App\Services\Scale\ScaleRegistryWriter;
use App\Support\CacheKeys;
use DomainException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\MemoizedStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use stdClass;
use Symfony\Component\Process\Process;
use Throwable;

function ensure(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new RuntimeException($reason);
    }
}

function guard(): void
{
    ensure(getenv('GITHUB_ACTIONS') === 'true' && getenv('GITHUB_WORKFLOW') === 'Nightly'
        && getenv('GITHUB_JOB') === 'full-phpunit', 'NOT_EPHEMERAL_NIGHTLY');
    ensure(getenv('APP_ENV') === 'testing' && getenv('DB_CONNECTION') === 'mysql'
        && getenv('DB_HOST') === '127.0.0.1' && getenv('DB_PORT') === '3306'
        && getenv('DB_DATABASE') === 'fap_ci', 'UNEXPECTED_TEST_ENDPOINT');
    $backend = dirname(__DIR__, 3);
    foreach (['.env', '.env.testing'] as $name) {
        ensure(! is_file($backend.'/'.$name) || filesize($backend.'/'.$name) === 0, 'NONEMPTY_ENV_FORBIDDEN');
    }
    ensure(hash_file('sha256', __DIR__.'/ScaleRegistryWriter-frozen.php.txt') === Q02_WRITER_SHA256, 'FROZEN_SOURCE_MISMATCH');
    ensure(hash_file('sha256', $backend.'/app/Services/Scale/PublicScaleCatalogCache.php') === Q02_CACHE_SHA256, 'CURRENT_CACHE_SOURCE_MISMATCH');
    ensure(hash_file('sha256', $backend.'/app/Support/CacheKeys.php') === Q02_CACHE_KEYS_SHA256, 'CURRENT_CACHE_KEYS_MISMATCH');
}

function pdo(?string $database): PDO
{
    global $q02PdoConnections;
    $q02PdoConnections++;

    // Existing Nightly service has these public synthetic credentials, never production credentials.
    return new PDO('mysql:host=127.0.0.1;port=3306;'.($database === null ? '' : 'dbname='.$database.';').'charset=utf8mb4', 'root', 'root', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
}

function bindBootstrapEnvironment(string $schema): array
{
    ensure(preg_match('/^q02_eq_[a-f0-9]{24}$/D', $schema) === 1, 'INVALID_BOOTSTRAP_SCHEMA');
    $values = ['APP_ENV' => 'testing', 'APP_KEY' => 'fap_api_phpunit_test_key_32bytes',
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/q02-unused-config-'.bin2hex(random_bytes(12)).'.php',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => $schema,
        'DB_USERNAME' => 'root', 'DB_PASSWORD' => 'root', 'DB_SOCKET' => '', 'DB_URL' => null, 'DATABASE_URL' => null,
        'MYSQL_ATTR_SSL_CA' => null, 'FAP_ATTEMPT_WRITE_CONNECTION' => 'mysql',
        'CACHE_STORE' => 'array', 'CACHE_LIMITER' => 'array', 'PUBLIC_SCALE_CACHE_STORE' => 'array',
        'CONTENT_LOADER_CACHE_STORE' => 'array', 'MBTI_RESPONSE_CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'];
    foreach ($values as $key => $value) {
        if ($value === null) {
            ensure(putenv($key), 'ENV_UNSET_FAILED');
            unset($_SERVER[$key], $_ENV[$key]);
        } else {
            ensure(putenv($key.'='.$value), 'ENV_BIND_FAILED');
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
    }

    return $values;
}

function assertBootstrapIsolation(array $bindings): void
{
    foreach ($bindings as $key => $value) {
        ensure(($value === null ? getenv($key) === false : getenv($key) === $value)
            && ($_SERVER[$key] ?? null) === $value && ($_ENV[$key] ?? null) === $value
            && Env::getRepository()->get($key) === $value, 'ISOLATION_ENV_MISMATCH');
    }
    ensure(! is_file($bindings['APP_CONFIG_CACHE']), 'CONFIG_CACHE_COLLISION');
    $app = new Application(dirname(__DIR__, 3)); // No kernel/provider bootstrap or DB/cache resolution.
    ensure($app->getCachedConfigPath() === $bindings['APP_CONFIG_CACHE'] && ! $app->configurationIsCached(), 'ISOLATION_CONFIG_CACHE_MISMATCH');
}

function installResourceGuards(Application $app, string $schema): void
{
    // Fail closed even if a provider explicitly requests a different connection or cache store.
    $app->extend('db.factory', fn ($unused, $app) => new class($app, $schema) extends ConnectionFactory
    {
        public function __construct($app, private string $ownedSchema)
        {
            parent::__construct($app);
        }

        public function createConnector(array $config)
        {
            ensure(($config['driver'] ?? null) === 'mysql' && ($config['host'] ?? null) === '127.0.0.1'
                && (int) ($config['port'] ?? 0) === 3306 && ($config['database'] ?? null) === $this->ownedSchema
                && empty($config['url']) && empty($config['unix_socket']), 'UNOWNED_DATABASE_CONNECTION');

            return parent::createConnector($config);
        }
    });
    $app->extend('cache', fn ($unused, $app) => new class($app) extends CacheManager
    {
        private ?\WeakMap $ownedMemoStores = null;

        public function resolve($name)
        {
            ensure($name === 'array'
                && $this->app['config']->get('cache.stores.array.driver') === 'array', 'EXTERNAL_CACHE_FORBIDDEN');

            return parent::resolve($name);
        }

        public function build(array $config)
        {
            ensure(($config['driver'] ?? null) === 'array', 'EXTERNAL_CACHE_FORBIDDEN');

            // Use the native constructor, including for public on-demand builds. A custom
            // creator registered under "array" must not substitute an external store.
            return parent::createArrayDriver($config);
        }

        public function repository(Store $store, array $config = [])
        {
            ensure(get_class($store) === ArrayStore::class
                || ($this->ownedMemoStores !== null && isset($this->ownedMemoStores[$store])), 'EXTERNAL_CACHE_FORBIDDEN');

            return parent::repository($store, $config);
        }

        public function memo($driver = null)
        {
            $driver ??= $this->getDefaultDriver();
            ensure($driver === 'array', 'EXTERNAL_CACHE_FORBIDDEN');
            $bindingKey = 'cache.__memoized:array';
            $this->app->scopedIf($bindingKey, function () {
                $store = new MemoizedStore('array', $this->store('array'));
                $this->ownedMemoStores ??= new \WeakMap;
                $this->ownedMemoStores[$store] = true;

                return $this->repository($store, ['events' => false]);
            });

            return $this->app->make($bindingKey);
        }
    });
}

function boot(string $schema): ScaleRegistryWriter
{
    $backend = dirname(__DIR__, 3);
    $bindings = bindBootstrapEnvironment($schema);
    require_once $backend.'/vendor/autoload.php';
    assertBootstrapIsolation($bindings);
    ensure(! class_exists(ScaleRegistryWriter::class, false), 'CLASS_ALREADY_LOADED');
    require __DIR__.'/ScaleRegistryWriter-frozen.php.txt';
    ensure(realpath((new \ReflectionClass(PublicScaleCatalogCache::class))->getFileName())
        === realpath($backend.'/app/Services/Scale/PublicScaleCatalogCache.php'), 'CURRENT_CACHE_AUTOLOAD_MISMATCH');
    ensure(realpath((new \ReflectionClass(CacheKeys::class))->getFileName())
        === realpath($backend.'/app/Support/CacheKeys.php'), 'CURRENT_CACHE_KEYS_AUTOLOAD_MISMATCH');
    $app = require $backend.'/bootstrap/app.php';
    ensure($app->getCachedConfigPath() === $bindings['APP_CONFIG_CACHE'] && ! $app->configurationIsCached(), 'ISOLATION_CONFIG_CACHE_MISMATCH');
    installResourceGuards($app, $schema);
    $app->beforeBootstrapping(RegisterProviders::class, function ($app) use ($schema): void {
        $app['config']->set(['database.default' => 'mysql', 'database.connections' => ['mysql' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => $schema,
            'username' => 'root', 'password' => 'root', 'unix_socket' => '', 'url' => null,
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true]],
            'database.redis' => [], 'cache.default' => 'array', 'cache.limiter' => 'array',
            'cache.stores' => ['array' => ['driver' => 'array', 'serialize' => false]],
            'queue.default' => 'sync', 'content_packs.public_scale_cache_store' => 'array', 'fap.scales_registry.use_v2' => true]);
        ensure($app['config']->get('database.connections.mysql.database') === $schema, 'ISOLATION_CONFIG_MISMATCH');
    });
    $app->make(Kernel::class)->bootstrap();

    return new ScaleRegistryWriter(new PublicScaleCatalogCache);
}

function seed(PDO $pdo): void
{
    // Same native JSON/identity/metadata types as registry migrations; no runtime migrations or seeds.
    $common = 'org_id BIGINT UNSIGNED NOT NULL DEFAULT 0, code VARCHAR(64) NOT NULL,
        primary_slug VARCHAR(127) NOT NULL, slugs_json JSON NOT NULL, driver_type VARCHAR(32) NOT NULL,
        assessment_driver VARCHAR(32) NULL, default_pack_id VARCHAR(64) NULL, default_region VARCHAR(32) NULL,
        default_locale VARCHAR(32) NULL, default_dir_version VARCHAR(128) NULL,
        capabilities_json JSON NULL, view_policy_json JSON NULL, commercial_json JSON NULL,
        seo_schema_json JSON NULL, seo_i18n_json JSON NULL, content_i18n_json JSON NULL,
        report_summary_i18n_json JSON NULL, is_public BOOLEAN NOT NULL, is_active BOOLEAN NOT NULL,
        is_indexable BOOLEAN NOT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL';
    $pdo->exec("CREATE TABLE scales_registry ($common, PRIMARY KEY(code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE scales_registry_v2 (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, $common,
        UNIQUE KEY org_code(org_id,code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec('CREATE TABLE scale_slugs (org_id BIGINT NOT NULL,scale_code VARCHAR(64) NOT NULL,slug VARCHAR(127) NOT NULL,
        is_primary BOOLEAN NOT NULL,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL,PRIMARY KEY(org_id,slug)) ENGINE=InnoDB');
    $content = json_encode(['en' => ['why_choose' => ['intro' => 'Synthetic EQ intro.', 'items' => [['id' => 'keep']]]],
        'zh' => ['intro' => '保留中文', 'float' => 1.0, 'integer' => 1, 'false' => false, 'null' => null,
            'object' => (object) [], 'list' => [], 'ordered' => [2, 1]]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    $row = ['org_id' => 0, 'code' => 'EQ_60', 'primary_slug' => 'eq-test-emotional-intelligence-assessment',
        'slugs_json' => '["eq-test-emotional-intelligence-assessment","eq-test","emotional-intelligence-test"]',
        'driver_type' => 'eq', 'assessment_driver' => 'eq_60', 'default_pack_id' => 'EQ_60', 'default_region' => 'GLOBAL',
        'default_locale' => 'en', 'default_dir_version' => 'v1', 'capabilities_json' => '{"score":"KEEP"}',
        'view_policy_json' => '{"public":true}', 'commercial_json' => '{"mode":"free_only"}',
        'seo_schema_json' => '{"robots":"index,follow"}', 'seo_i18n_json' => '{"en":{"title":"KEEP"}}',
        'content_i18n_json' => $content, 'report_summary_i18n_json' => '{"en":{"summary":"KEEP"}}',
        'is_public' => 1, 'is_active' => 1, 'is_indexable' => 1,
        'created_at' => '2026-08-09 07:16:56', 'updated_at' => '2026-10-07 09:24:12'];
    foreach (['scales_registry', 'scales_registry_v2'] as $table) {
        $values = $table === 'scales_registry_v2' ? ['id' => 3, ...$row] : $row;
        $statement = $pdo->prepare('INSERT INTO '.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
        $statement->execute(array_values($values));
    }
    $statement = $pdo->prepare('INSERT INTO scale_slugs VALUES (0,?,?,?, ?,?)');
    foreach (json_decode($row['slugs_json'], true) as $i => $slug) {
        $statement->execute(['EQ_60', $slug, (int) ($i === 0), $row['created_at'], $row['updated_at']]);
    }
}

function rows(): array
{
    return ['v2' => (array) DB::table('scales_registry_v2')->where('id', 3)->first(),
        'legacy' => (array) DB::table('scales_registry')->where('org_id', 0)->where('code', 'EQ_60')->first(),
        'slugs' => DB::table('scale_slugs')->orderBy('slug')->get()->map(fn ($row) => (array) $row)->all()];
}

function warm(): array
{
    $keys = [CacheKeys::scaleRegistryActive(0), CacheKeys::scaleRegistryByCode(0, 'EQ_60'),
        CacheKeys::scaleRegistryByCode(0, 'EQ_EMOTIONAL_INTELLIGENCE'), CacheKeys::publicScaleRegistryGeneration(0)];
    foreach (['eq-test-emotional-intelligence-assessment', 'eq-test', 'emotional-intelligence-test'] as $slug) {
        foreach ([$slug, 'compat:'.$slug, 'canonical:'.$slug] as $value) {
            $keys[] = CacheKeys::scaleRegistryBySlug(0, $value);
        }
    }
    foreach ($keys as $key) {
        Cache::put($key, 7, 600);
        if ($key !== CacheKeys::publicScaleRegistryGeneration(0)) {
            Cache::put($key.':generation=7', 7, 600);
        }
    }
    Cache::put('q02-unrelated-cache-control', 'KEEP', 600);

    return Cache::store('array')->getStore()->all();
}

function assertCurrentCacheClosed(array $before): void
{
    ensure((new PublicScaleCatalogCache)->generation(0) === 8, 'GENERATION_NOT_ADVANCED');
    $generationKey = CacheKeys::publicScaleRegistryGeneration(0);
    foreach (array_keys($before) as $key) {
        if (! in_array($key, [$generationKey, 'q02-unrelated-cache-control'], true)) {
            ensure(! Cache::has($key), 'TARGET_CACHE_NOT_CLOSED');
        }
    }
    ensure(Cache::store('array')->getStore()->all()['q02-unrelated-cache-control']
        === $before['q02-unrelated-cache-control'], 'UNRELATED_CACHE_CHANGED');
}

function request(ScaleRegistryWriter $writer, string $suffix = 'Only synthetic appendix.'): array
{
    $baseline = $writer->eqIntroBaseline(ScaleRegistryWriter::EQ_INTRO_TARGET);

    return [...$baseline, 'after' => $baseline['before']."\n\n".$suffix];
}

function canonical(mixed $value): mixed
{
    if ($value instanceof stdClass) {
        $items = get_object_vars($value);
        ksort($items);

        return (object) array_map(__NAMESPACE__.'\\canonical', $items);
    }

    return is_array($value) ? array_map(__NAMESPACE__.'\\canonical', $value) : $value;
}

function assertLeaf(array $before, array $after, string $intro): void
{
    ensure($before['slugs'] === $after['slugs'], 'SLUG_PROJECTION_CHANGED');
    foreach (['v2', 'legacy'] as $key) {
        $old = $before[$key];
        $new = $after[$key];
        $expected = json_decode($old['content_i18n_json'], false, 512, JSON_THROW_ON_ERROR);
        $expected->en->why_choose->intro = $intro;
        $actual = json_decode($new['content_i18n_json'], false, 512, JSON_THROW_ON_ERROR);
        $flags = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE;
        ensure(json_encode(canonical($expected), $flags) === json_encode(canonical($actual), $flags), 'JSON_VALUE_OR_TYPE_CHANGED');
        unset($old['content_i18n_json'], $new['content_i18n_json']);
        ensure($old === $new, 'PROTECTED_COLUMN_CHANGED');
    }
    ensure($after['v2']['content_i18n_json'] === $after['legacy']['content_i18n_json'], 'DUAL_TABLE_DIVERGED');
}

function child(string $schema, string $nonce, array $request): Process
{
    $process = new Process([PHP_BINARY, __FILE__, 'contender'], dirname(__DIR__, 3), [
        'Q02_TEST_SCHEMA' => $schema, 'Q02_TEST_NONCE' => $nonce,
        'DB_DATABASE' => 'fap_ci', // Re-enter the same service guard; boot binds the nonce-owned schema.
    ], json_encode($request, JSON_THROW_ON_ERROR), 20);
    $process->start();

    return $process;
}

function connected(Process $process): int
{
    $deadline = microtime(true) + 8;
    do {
        foreach (explode("\n", $process->getOutput()) as $line) {
            $message = json_decode($line, true);
            if (($message['phase'] ?? '') === 'connected') {
                return (int) $message['connection_id'];
            }
        }
        ensure($process->isRunning(), 'CONTENDER_EXITED_BEFORE_CONNECTION');
        usleep(25000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('CONTENDER_CONNECTION_TIMEOUT');
}

function lockWait(PDO $observer, string $schema, int $requester, int $blocker, string $table): array
{
    ensure($requester !== $blocker, 'NOT_INDEPENDENT_CONNECTIONS');
    $sql = 'SELECT r.OBJECT_NAME, r.LOCK_TYPE, r.LOCK_MODE, r.LOCK_STATUS FROM performance_schema.data_lock_waits w
        JOIN performance_schema.data_locks r ON r.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND r.ENGINE=w.ENGINE
        JOIN performance_schema.threads rt ON rt.THREAD_ID=w.REQUESTING_THREAD_ID
        JOIN performance_schema.threads bt ON bt.THREAD_ID=w.BLOCKING_THREAD_ID
        WHERE r.OBJECT_SCHEMA=? AND r.OBJECT_NAME=? AND rt.PROCESSLIST_ID=? AND bt.PROCESSLIST_ID=?';
    $deadline = microtime(true) + 8;
    do {
        $query = $observer->prepare($sql);
        $query->execute([$schema, $table, $requester, $blocker]);
        if ($found = $query->fetch()) {
            ensure($found['LOCK_STATUS'] === 'WAITING' && $found['LOCK_TYPE'] === 'RECORD', 'NO_RECORD_WAIT');

            return ['requester_connection' => $requester, 'blocker_connection' => $blocker, 'wait' => $found];
        }
        usleep(25000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('LOCK_WAIT_NOT_PROVEN');
}

function finished(Process $process, string $expected): array
{
    ensure($process->wait() === 0, 'CONTENDER_FAILED');
    $last = json_decode(trim(array_slice(explode("\n", trim($process->getOutput())), -1)[0]), true, 512, JSON_THROW_ON_ERROR);
    ensure(($last['phase'] ?? '') === 'final' && ($last['status'] ?? '') === $expected, 'UNEXPECTED_CONTENDER_RESULT');

    return $last;
}

function cacheEntryProbe(): array
{
    $app = new Application(dirname(__DIR__, 3)); // Bare container, never kernel/providers.
    $app->instance('config', new \Illuminate\Config\Repository(['cache' => ['default' => 'array',
        'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]]]));
    $resourceResolutions = 0;
    foreach (['files', 'redis', 'db', 'memcached.connector', 'session.store'] as $binding) {
        $app->bind($binding, function () use (&$resourceResolutions) {
            $resourceResolutions++;
            throw new RuntimeException('EXTERNAL_RESOURCE_RESOLVED');
        });
    }
    installResourceGuards($app, 'q02_eq_0123456789abcdef01234567');
    $app->singleton('cache', fn ($app) => new CacheManager($app));
    $manager = $app->make('cache');
    Cache::setFacadeApplication($app);
    Cache::clearResolvedInstances();
    $rejected = [];
    $reject = function (string $entry, callable $call) use (&$rejected): void {
        try {
            $call();
            throw new RuntimeException('EXTERNAL_CACHE_ACCEPTED');
        } catch (RuntimeException $exception) {
            ensure($exception->getMessage() === 'EXTERNAL_CACHE_FORBIDDEN', 'WRONG_CACHE_REJECTION');
            $rejected[] = $entry;
        }
    };
    // Exercise real public entrypoints, including configured named stores and defaults.
    foreach (['file', 'redis', 'database', 'memcached', 'dynamodb', 'apc', 'failover', 'session', 'null', 'unknown'] as $driver) {
        $app['config']->set('cache.stores.'.$driver, ['driver' => $driver]);
        foreach (['driver', 'store', 'resolve', 'memo'] as $method) {
            $reject($method.':'.$driver, fn () => $manager->{$method}($driver));
        }
        $reject('build:'.$driver, fn () => $manager->build(['driver' => $driver]));
        $reject('facade-build:'.$driver, fn () => Cache::build(['driver' => $driver]));
        $reject('facade-store:'.$driver, fn () => Cache::store($driver));
        $reject('facade-memo:'.$driver, fn () => Cache::memo($driver));
    }
    $reject('build:missing-driver', fn () => $manager->build([]));
    $app['config']->set('cache.default', 'file');
    $reject('facade-default', fn () => Cache::get('never-read'));
    $reject('memo-default', fn () => $manager->memo());
    $app['config']->set('cache.default', 'array');
    $app['config']->set('cache.stores.array.driver', 'file');
    $reject('array-config-drift', fn () => $manager->store('array'));
    $app['config']->set('cache.stores.array.driver', 'array');
    // Public repository wrapping cannot admit an external or an unowned memo store.
    // FileStore construction here performs no operation and creates no path.
    $unusedPath = sys_get_temp_dir().'/q02-cache-never-created-'.bin2hex(random_bytes(12));
    $file = new \Illuminate\Cache\FileStore(new \Illuminate\Filesystem\Filesystem, $unusedPath);
    $reject('repository-file', fn () => $manager->repository($file));
    $reject('facade-repository-file', fn () => Cache::repository($file));
    $externalMemo = new MemoizedStore('file', new \Illuminate\Cache\Repository($file));
    $reject('repository-unowned-memo', fn () => $manager->repository($externalMemo));
    $customCalls = 0;
    $manager->extend('array', function () use (&$customCalls) {
        $customCalls++;
        throw new RuntimeException('CUSTOM_ARRAY_CREATOR_CALLED');
    });
    $ready = [];
    foreach (['driver' => fn () => $manager->driver(), 'store' => fn () => $manager->store(),
        'resolve' => fn () => $manager->resolve('array'), 'build' => fn () => $manager->build(['driver' => 'array']),
        'facade-build' => fn () => Cache::build(['driver' => 'array']),
        'repository' => fn () => $manager->repository(new ArrayStore)] as $entry => $make) {
        $repository = $make();
        ensure(get_class($repository->getStore()) === ArrayStore::class, 'NOT_NATIVE_ARRAY_STORE');
        $repository->put('q02-'.$entry, 'synthetic');
        ensure($repository->get('q02-'.$entry) === 'synthetic', 'ARRAY_CACHE_NOT_READY');
        $ready[] = $entry;
    }
    Cache::put('q02-facade-default', 'synthetic');
    ensure(Cache::get('q02-facade-default') === 'synthetic', 'ARRAY_FACADE_NOT_READY');
    $memo = $manager->memo();
    ensure($memo === Cache::memo('array') && $memo->getStore() instanceof MemoizedStore, 'ARRAY_MEMO_NOT_READY');
    $memo->put('q02-memo', 'synthetic');
    ensure($memo->get('q02-memo') === 'synthetic' && $manager->store()->get('q02-memo') === 'synthetic', 'ARRAY_MEMO_NOT_READY');
    ensure($customCalls === 0 && $resourceResolutions === 0 && ! file_exists($unusedPath), 'CACHE_PROBE_SIDE_EFFECT');

    return ['status' => 'CACHE_ENTRIES_PASS', 'rejected_entries' => $rejected, 'native_array_entries' => $ready,
        'default_facade_array_ready' => true, 'owned_array_memo_ready' => true, 'custom_creator_calls' => $customCalls,
        'external_resource_resolutions' => $resourceResolutions, 'file_path_created' => false,
        'db_connections' => 0, 'application_bootstrap' => false];
}

$created = false;
$schema = '';
$observer = null;
$activeChildren = [];
$activeBlocker = null;
$exitCode = 0;
$q02PdoConnections = 0;
try {
    if (($argv[1] ?? '') === 'cache-entry-probe') {
        require_once dirname(__DIR__, 3).'/vendor/autoload.php';
        echo json_encode(cacheEntryProbe(), JSON_THROW_ON_ERROR)."\n";

        return;
    }
    if (($argv[1] ?? '') === 'isolation-probe') {
        // Exercises real inherited superglobals and Laravel resolution without application bootstrap/PDO.
        $inherited = ($_SERVER['DB_DATABASE'] ?? null) === 'fap_ci'
            && ($_SERVER['APP_CONFIG_CACHE'] ?? null) === 'bootstrap/cache/config-testing.php';
        $bindings = bindBootstrapEnvironment('q02_eq_0123456789abcdef01234567');
        require_once dirname(__DIR__, 3).'/vendor/autoload.php';
        if (($argv[2] ?? '') === 'server-drift') {
            $_SERVER['DB_DATABASE'] = 'fap_ci';
        }
        assertBootstrapIsolation($bindings);
        $probeApp = new Application(dirname(__DIR__, 3));
        $probeApp->instance('config', new \Illuminate\Config\Repository(['cache' => ['default' => 'array',
            'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]]]));
        installResourceGuards($probeApp, $bindings['DB_DATABASE']);
        // Match provider ordering: lazy bindings are installed after the extenders.
        $probeApp->singleton('db.factory', fn ($app) => new ConnectionFactory($app));
        $probeApp->singleton('cache', fn ($app) => new CacheManager($app));
        try {
            $probeApp->make('db.factory')->createConnector(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
                'database' => 'fap_ci', 'url' => null, 'unix_socket' => '']);
            throw new RuntimeException('UNOWNED_CONNECTOR_ACCEPTED');
        } catch (RuntimeException $exception) {
            ensure($exception->getMessage() === 'UNOWNED_DATABASE_CONNECTION', 'WRONG_CONNECTOR_REJECTION');
        }
        try {
            $probeApp->make('cache')->driver('redis');
            throw new RuntimeException('EXTERNAL_CACHE_ACCEPTED');
        } catch (RuntimeException $exception) {
            ensure($exception->getMessage() === 'EXTERNAL_CACHE_FORBIDDEN', 'WRONG_CACHE_REJECTION');
        }
        $probeApp->make('cache')->driver('array')->put('q02-probe', 'synthetic');
        ensure($probeApp->make('cache')->driver('array')->get('q02-probe') === 'synthetic', 'ARRAY_CACHE_NOT_READY');
        echo json_encode(['status' => 'ISOLATION_PASS', 'real_inherited_server_observed' => $inherited,
            'owned_schema_visible_to_laravel' => true, 'fresh_config_cache_visible_to_laravel' => true,
            'array_cache_visible_to_laravel' => true, 'urls_removed_and_socket_disabled' => true,
            'unowned_connector_rejected' => true, 'external_cache_rejected' => true, 'array_cache_realstore_ready' => true,
            'db_connections' => $q02PdoConnections, 'application_bootstrap' => false])."\n";

        return;
    }
    guard(); // Must precede every PDO creation, bootstrap, DDL and destructive operation.
    if (($argv[1] ?? '') === 'guard-probe') {
        throw new RuntimeException('GUARD_UNEXPECTEDLY_ACCEPTED');
    }
    $observer = pdo(null); // Server metadata only, never select the shared fap_ci database.
    ensure($observer->query('SELECT DATABASE()')->fetchColumn() === null, 'OBSERVER_DATABASE_SELECTED');
    $version = (string) $observer->query('SELECT VERSION()')->fetchColumn();
    ensure(preg_match('/^8\.0\./D', $version) === 1, 'MYSQL8_REQUIRED');
    if (($argv[1] ?? '') === 'contender') {
        $schema = (string) getenv('Q02_TEST_SCHEMA');
        $nonce = (string) getenv('Q02_TEST_NONCE');
        ensure(preg_match('/^q02_eq_[a-f0-9]{24}$/D', $schema) === 1 && preg_match('/^[a-f0-9]{32}$/D', $nonce) === 1, 'INVALID_OWNER_IDENTITY');
        $owned = pdo($schema);
        ensure($owned->query('SELECT nonce FROM q02_owner')->fetchColumn() === $nonce, 'OWNER_IDENTITY_MISMATCH');
        $writer = boot($schema);
        $cacheBefore = warm();
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            $writes += (int) (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1);
        });
        $request = json_decode(file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
        $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        echo json_encode(['phase' => 'connected', 'connection_id' => $connection])."\n";
        fflush(STDOUT);
        try {
            $result = $writer->updateEqIntro($request, true);
            ensure($result['status'] === 'APPLIED', 'CONTENDER_NOT_APPLIED');
            ensure($writes === 2, 'CONTENDER_WRITE_COUNT');
            $status = 'APPLIED';
        } catch (DomainException $exception) {
            ensure(str_contains($exception->getMessage(), 'Stale'), 'UNEXPECTED_REJECTION');
            ensure($writes === 0 && $cacheBefore === Cache::store('array')->getStore()->all(), 'REJECTION_HAS_SIDE_EFFECT');
            $status = 'STALE';
        }
        echo json_encode(['phase' => 'final', 'status' => $status, 'writes' => $writes, 'connection_id' => $connection])."\n";

        return;
    }
    $schema = 'q02_eq_'.bin2hex(random_bytes(12));
    $nonce = bin2hex(random_bytes(16));
    // No IF NOT EXISTS: collision never grants ownership or authorizes cleanup.
    $observer->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $owned = pdo($schema);
    $owned->exec('CREATE TABLE q02_owner (nonce CHAR(32) PRIMARY KEY) ENGINE=InnoDB');
    $owned->prepare('INSERT INTO q02_owner VALUES (?)')->execute([$nonce]);
    seed($owned);
    $writer = boot($schema);
    $cacheBefore = warm();
    $request = request($writer);
    $before = rows();
    $result = [];
    switch ($argv[1] ?? '') {
        case 'json':
            ensure($writer->updateEqIntro($request, true)['status'] === 'APPLIED', 'NOT_APPLIED');
            assertLeaf($before, rows(), $request['after']);
            $result = ['json_native_leaf_and_types_preserved' => true];
            break;
        case 'cache':
            ensure($writer->updateEqIntro($request, true)['status'] === 'APPLIED', 'NOT_APPLIED');
            assertLeaf($before, rows(), $request['after']);
            assertCurrentCacheClosed($cacheBefore);
            $partialRequest = request($writer, 'Second synthetic cache appendix.');
            $partialBefore = rows();
            $partialWarm = warm();
            $failingWriter = new ScaleRegistryWriter(new class extends PublicScaleCatalogCache
            {
                public function bumpGeneration(int $orgId): int
                {
                    throw new RuntimeException('SYNTHETIC_CACHE_CLOSEOUT_FAILURE');
                }
            });
            $partial = $failingWriter->updateEqIntro($partialRequest, true);
            ensure($partial['status'] === 'PARTIAL_CACHE_CLOSEOUT', 'CACHE_FAILURE_NOT_PARTIAL');
            assertLeaf($partialBefore, rows(), $partialRequest['after']);
            ensure((new PublicScaleCatalogCache)->generation(0) === 7, 'FAILED_GENERATION_CHANGED');
            $committed = rows();
            $committedCache = Cache::store('array')->getStore()->all();
            ensure($committedCache['q02-unrelated-cache-control'] === $partialWarm['q02-unrelated-cache-control'], 'PARTIAL_UNRELATED_CACHE_CHANGED');
            $repeatWrites = 0;
            DB::listen(function ($query) use (&$repeatWrites): void {
                $repeatWrites += (int) (preg_match('/^\s*UPDATE\b/i', $query->sql) === 1);
            });
            try {
                $failingWriter->updateEqIntro($partialRequest, true);
                throw new RuntimeException('COMMITTED_BODY_REAPPLIED');
            } catch (DomainException $exception) {
                ensure(str_contains($exception->getMessage(), 'Stale'), 'WRONG_COMMITTED_REPEAT_REJECTION');
            }
            ensure($repeatWrites === 0 && rows() === $committed
                && Cache::store('array')->getStore()->all() === $committedCache, 'COMMITTED_REPEAT_SIDE_EFFECT');
            $result = ['current_generation_and_versioned_keys_closed' => true,
                'unrelated_cache_preserved' => true, 'cache_failure_committed_as_partial' => true,
                'failed_generation_not_advanced' => true, 'committed_body_repeat_zero_write' => true];
            break;
        case 'identity':
            foreach (['scales_registry_v2', 'scales_registry'] as $table) {
                DB::table($table)->where('code', 'EQ_60')->update(['code' => 'eq_60']);
                ensure(DB::table($table)->where('code', 'EQ_60')->value('code') === 'eq_60', 'NO_CASE_INSENSITIVE_MATCH');
                $drifted = rows();
                $queries = [];
                DB::listen(function ($query) use (&$queries): void {
                    $queries[] = $query->sql;
                });
                foreach (['baseline', 'plan', 'execute'] as $stage) {
                    try {
                        $stage === 'baseline' ? $writer->eqIntroBaseline(ScaleRegistryWriter::EQ_INTRO_TARGET)
                            : $writer->updateEqIntro($request, $stage === 'execute');
                        throw new RuntimeException('IDENTITY_ACCEPTED');
                    } catch (DomainException $exception) {
                        ensure(str_contains($exception->getMessage(), 'identity'), 'WRONG_IDENTITY_REJECTION');
                    }
                    ensure(rows() === $drifted && Cache::store('array')->getStore()->all() === $cacheBefore, 'IDENTITY_SIDE_EFFECT');
                }
                foreach ($queries as $query) {
                    ensure(preg_match('/^\s*(insert|update|delete|replace)\b/i', $query) !== 1, 'IDENTITY_DML');
                }
                DB::table($table)->where('code', 'EQ_60')->update(['code' => 'EQ_60']);
            }
            $result = ['mysql_case_insensitive_actual_identity_rejected' => true];
            break;
        case 'rollback':
            DB::statement("CREATE TRIGGER q02_reject BEFORE UPDATE ON scales_registry FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='q02 second table rejected'");
            $firstWrites = 0;
            DB::listen(function ($query) use (&$firstWrites): void {
                $firstWrites += (int) (preg_match('/^UPDATE scales_registry_v2\b/i', $query->sql) === 1);
            });
            try {
                $writer->updateEqIntro($request, true);
                throw new RuntimeException('SECOND_TABLE_ACCEPTED');
            } catch (\Illuminate\Database\QueryException $exception) {
                ensure(str_contains($exception->getMessage(), 'q02 second table rejected'), 'WRONG_TABLE_FAILURE');
            }
            ensure($firstWrites === 1 && rows() === $before && Cache::store('array')->getStore()->all() === $cacheBefore, 'BATCH_NOT_ROLLED_BACK');
            $result = ['first_update_completed' => true, 'second_failure_entire_batch_rolled_back' => true];
            break;
        case 'locks':
            $proofs = [];
            $started = false;
            $contender = null;
            $parentId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            DB::listen(function ($query) use (&$started, &$contender, &$activeChildren, &$proofs, $observer, $schema, $nonce, $request, $parentId): void {
                if (! $started && str_contains($query->sql, '`scales_registry_v2`') && str_contains(strtolower($query->sql), 'for update')) {
                    $started = true;
                    $contender = child($schema, $nonce, $request);
                    $activeChildren[] = $contender;
                    $proofs['two_candidate_writers'] = lockWait($observer, $schema, connected($contender), $parentId, 'scales_registry_v2');
                }
            });
            ensure($writer->updateEqIntro($request, true)['status'] === 'APPLIED', 'FIRST_WRITER_FAILED');
            ensure($contender instanceof Process, 'NO_CONTENDER');
            $proofs['two_candidate_writers']['result'] = finished($contender, 'STALE');
            assertLeaf($before, rows(), $request['after']);
            // A competing transaction modifies both rows, then rolls back; waiting candidate can apply once.
            $next = request($writer, 'Append after rollback.');
            $nextBefore = rows();
            $activeBlocker = pdo($schema);
            $blockerId = (int) $activeBlocker->query('SELECT CONNECTION_ID()')->fetchColumn();
            $activeBlocker->beginTransaction();
            foreach (['scales_registry_v2', 'scales_registry'] as $table) {
                $activeBlocker->query("SELECT * FROM $table WHERE org_id=0 AND code='EQ_60' FOR UPDATE")->fetchAll();
                $activeBlocker->exec("UPDATE $table SET seo_i18n_json=JSON_OBJECT('test','rollback') WHERE org_id=0 AND code='EQ_60'");
            }
            $contender = child($schema, $nonce, $next);
            $activeChildren[] = $contender;
            $proofs['competitor_rollback'] = lockWait($observer, $schema, connected($contender), $blockerId, 'scales_registry_v2');
            $activeBlocker->rollBack();
            $proofs['competitor_rollback']['result'] = finished($contender, 'APPLIED');
            assertLeaf($nextBefore, rows(), $next['after']);
            // Lock only legacy: prove candidate already holds v2 while waiting for its second lock.
            $next = request($writer, 'Append after legacy lock.');
            $nextBefore = rows();
            $activeBlocker->beginTransaction();
            $activeBlocker->query("SELECT * FROM scales_registry WHERE org_id=0 AND code='EQ_60' FOR UPDATE")->fetchAll();
            $contender = child($schema, $nonce, $next);
            $activeChildren[] = $contender;
            $childId = connected($contender);
            $proofs['v2_then_legacy'] = lockWait($observer, $schema, $childId, $blockerId, 'scales_registry');
            $query = $observer->prepare("SELECT COUNT(*) FROM performance_schema.data_locks l JOIN performance_schema.threads t ON t.THREAD_ID=l.THREAD_ID
                WHERE l.OBJECT_SCHEMA=? AND l.OBJECT_NAME='scales_registry_v2' AND l.LOCK_TYPE='RECORD' AND l.LOCK_STATUS='GRANTED' AND t.PROCESSLIST_ID=?");
            $query->execute([$schema, $childId]);
            ensure((int) $query->fetchColumn() > 0, 'V2_FIRST_LOCK_NOT_PROVEN');
            $activeBlocker->rollBack();
            $proofs['v2_then_legacy']['result'] = finished($contender, 'APPLIED');
            assertLeaf($nextBefore, rows(), $next['after']);
            $result = ['actual_connection_lock_proofs' => $proofs];
            break;
        default:
            throw new RuntimeException('UNKNOWN_CONSUMER');
    }
    $metadata = $owned->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='scales_registry_v2'")->fetch();
    ensure($metadata['ENGINE'] === 'InnoDB', 'INNODB_REQUIRED');
    echo json_encode(['status' => 'PASS', 'version' => $version, 'source_sha256' => Q02_WRITER_SHA256,
        'consumer' => $argv[1], 'metadata' => $metadata, 'proof' => $result], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    // Never print credentials, DB configuration or arbitrary exception payloads.
    if (($argv[1] ?? '') === 'isolation-probe' && $exception instanceof RuntimeException
        && $exception->getMessage() === 'ISOLATION_ENV_MISMATCH') {
        echo json_encode(['status' => 'ISOLATION_REJECTED', 'db_connections' => $q02PdoConnections, 'application_bootstrap' => false])."\n";

        return;
    }
    if (($argv[1] ?? '') === 'guard-probe' && $exception instanceof RuntimeException
        && in_array($exception->getMessage(), ['NOT_EPHEMERAL_NIGHTLY', 'UNEXPECTED_TEST_ENDPOINT'], true)) {
        echo json_encode(['status' => 'GUARD_REJECTED', 'reason' => $exception->getMessage(), 'db_connections' => $q02PdoConnections])."\n";

        return;
    }
    $reason = $exception instanceof RuntimeException && preg_match('/^[A-Z0-9_]+$/D', $exception->getMessage()) === 1
        ? $exception->getMessage() : get_class($exception);
    fwrite(STDERR, 'Q02_MYSQL_TEST_FAILED: '.$reason."\n");
    $exitCode = 1;
} finally {
    foreach ($activeChildren as $process) {
        if ($process->isRunning()) {
            $process->stop(1);
        }
    }
    if ($activeBlocker instanceof PDO && $activeBlocker->inTransaction()) {
        $activeBlocker->rollBack();
    }
    if ($created && $observer instanceof PDO) {
        ensure(preg_match('/^q02_eq_[a-f0-9]{24}$/D', $schema) === 1, 'INVALID_CLEANUP_IDENTITY');
        $observer->exec("DROP DATABASE `$schema`");
    }
}
exit($exitCode);
