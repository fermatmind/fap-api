<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class Eq60IntroFrozenMysqlPreflightTest extends TestCase
{
    private const WRITER_SHA256 = '54e70b84bd982b4c123a622769fc6347d53722a29245d4fe282405f115fb5340';

    private function fixture(string $file): string
    {
        return dirname(__DIR__, 2).'/Fixtures/Q02EqIntro/'.$file;
    }

    public function test_snapshot_is_exact_current_prepared_writer(): void
    {
        self::assertSame(self::WRITER_SHA256, hash_file('sha256', $this->fixture('ScaleRegistryWriter-frozen.php.txt')));
    }

    public static function bootstrapIsolationModes(): array
    {
        return [['normal'], ['server-drift']];
    }

    #[DataProvider('bootstrapIsolationModes')]
    public function test_real_inherited_superglobals_are_isolated_before_bootstrap(string $mode): void
    {
        $process = new Process([PHP_BINARY, $this->fixture('mysql-worker.php'), 'isolation-probe', $mode], null, [
            'DB_DATABASE' => 'fap_ci', 'APP_CONFIG_CACHE' => 'bootstrap/cache/config-testing.php',
            'DB_URL' => 'mysql://synthetic.example.invalid/shared', 'DATABASE_URL' => 'synthetic-url',
            'DB_SOCKET' => '/tmp/synthetic-shared.sock', 'CACHE_STORE' => 'redis',
            'PUBLIC_SCALE_CACHE_STORE' => 'redis', 'APP_ENV' => 'production',
        ], null, 10);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $result['db_connections']);
        self::assertFalse($result['application_bootstrap']);
        self::assertSame($mode === 'normal' ? 'ISOLATION_PASS' : 'ISOLATION_REJECTED', $result['status']);
        if ($mode === 'normal') {
            foreach (['real_inherited_server_observed', 'owned_schema_visible_to_laravel',
                'fresh_config_cache_visible_to_laravel', 'array_cache_visible_to_laravel', 'urls_removed_and_socket_disabled',
                'unowned_connector_rejected', 'external_cache_rejected', 'array_cache_realstore_ready'] as $key) {
                self::assertTrue($result[$key]);
            }
        }
    }

    public static function unsafeEnvironments(): array
    {
        $ci = ['GITHUB_ACTIONS' => 'true', 'GITHUB_WORKFLOW' => 'Nightly', 'GITHUB_JOB' => 'full-phpunit',
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'fap_ci'];

        return [
            [['GITHUB_ACTIONS' => 'false'], 'NOT_EPHEMERAL_NIGHTLY'],
            [[...$ci, 'DB_DATABASE' => 'other_project'], 'UNEXPECTED_TEST_ENDPOINT'],
            [[...$ci, 'DB_HOST' => 'unexpected.example.invalid'], 'UNEXPECTED_TEST_ENDPOINT'],
        ];
    }

    public function test_real_public_cache_construction_entries_allow_only_native_array_resources(): void
    {
        $process = new Process([PHP_BINARY, $this->fixture('mysql-worker.php'), 'cache-entry-probe'], null, null, null, 10);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('CACHE_ENTRIES_PASS', $result['status']);
        self::assertCount(87, $result['rejected_entries']);
        self::assertCount(6, $result['native_array_entries']);
        foreach (['default_facade_array_ready', 'owned_array_memo_ready'] as $key) {
            self::assertTrue($result[$key]);
        }
        foreach (['custom_creator_calls', 'external_resource_resolutions', 'db_connections'] as $key) {
            self::assertSame(0, $result[$key]);
        }
        self::assertFalse($result['file_path_created']);
        self::assertFalse($result['application_bootstrap']);
    }

    #[DataProvider('unsafeEnvironments')]
    public function test_unsafe_environment_is_rejected_before_any_connection(array $environment, string $reason): void
    {
        $process = new Process([PHP_BINARY, $this->fixture('mysql-worker.php'), 'guard-probe'], null,
            $environment, null, 10);
        self::assertSame(0, $process->run());
        self::assertSame(['status' => 'GUARD_REJECTED', 'reason' => $reason, 'db_connections' => 0], json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_mysql8_native_json_leaf_and_protected_values(): void
    {
        $this->consumer('json');
    }

    public function test_mysql8_actual_case_insensitive_identity_is_zero_write(): void
    {
        $this->consumer('identity');
    }

    public function test_mysql8_second_table_failure_rolls_back_first_update(): void
    {
        $this->consumer('rollback');
    }

    public function test_mysql8_independent_connections_prove_lock_cas_and_rollback(): void
    {
        $this->consumer('locks');
    }

    public function test_mysql8_current_generation_cache_closeout_and_committed_failure_are_not_reapplied(): void
    {
        $this->consumer('cache');
    }

    private function consumer(string $name): void
    {
        if (getenv('DB_CONNECTION') !== 'mysql') {
            if (getenv('GITHUB_WORKFLOW') === 'Nightly' && getenv('GITHUB_JOB') === 'full-phpunit') {
                self::fail('MySQL Nightly consumer topology drift; refusing to skip.');
            }
            // Ordinary SQLite CI reports an explicit skip; MySQL Nightly must execute every consumer.
            self::markTestSkipped('Requires existing ephemeral MySQL8 Nightly service; SQLite is not proof.');
        }
        $process = new Process([PHP_BINARY, $this->fixture('mysql-worker.php'), $name], null, null, null, 90);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('PASS', $result['status']);
        self::assertSame($name, $result['consumer']);
        self::assertMatchesRegularExpression('/^8\.0\./', $result['version']);
        self::assertSame('InnoDB', $result['metadata']['ENGINE']);
        self::assertSame(self::WRITER_SHA256, $result['source_sha256']);
        self::assertNotEmpty($result['proof']);
        // Included in Nightly's existing retained PHPUnit console artifact; no new upload path.
        fwrite(STDOUT, 'Q02_MYSQL_PROOF '.json_encode($result, JSON_THROW_ON_ERROR)."\n");
    }
}
