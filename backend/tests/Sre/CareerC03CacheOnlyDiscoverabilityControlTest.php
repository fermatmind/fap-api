<?php

declare(strict_types=1);

namespace Tests\Sre;

use FermatMind\Operations\CareerC03CacheOnlyDiscoverabilityControl;
use FermatMind\Operations\CareerC03ControlFailure;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/scripts/operations/career_c03_cache_only_discoverability_control.php';

final class CareerC03CacheOnlyDiscoverabilityControlTest extends TestCase
{
    #[Test]
    public function authority_inventory_and_current_published_cohort_are_distinct_and_dynamic(): void
    {
        $snapshot = CareerC03CacheOnlyDiscoverabilityControl::authoritySnapshot([
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'career.runtime_publish_projection.v1',
            'items' => [
                $this->authorityItem('published-job', 'en', true),
                $this->authorityItem('published-job', 'zh-CN', true),
                $this->authorityItem('future-job', 'en', false),
                $this->authorityItem('future-job', 'zh-CN', false),
            ],
        ]);

        self::assertSame(2, $snapshot['inventory']['unique_slug_count']);
        self::assertSame(4, $snapshot['inventory']['locale_row_count']);
        self::assertSame(1, $snapshot['slug_count']);
        self::assertSame(2, $snapshot['row_count']);
        self::assertSame(['published-job|en', 'published-job|zh-CN'], $snapshot['rows']);
    }

    #[Test]
    public function exact_bilingual_set_is_order_independent(): void
    {
        $first = CareerC03CacheOnlyDiscoverabilityControl::snapshotFromRows([
            'beta-job|zh-CN',
            'alpha-job|en',
            'alpha-job|zh-CN',
            'beta-job|en',
        ], 'TEST');
        $second = CareerC03CacheOnlyDiscoverabilityControl::snapshotFromRows([
            'alpha-job|zh-CN',
            'beta-job|en',
            'beta-job|zh-CN',
            'alpha-job|en',
        ], 'TEST');

        self::assertSame($first['slug_set_sha256'], $second['slug_set_sha256']);
        self::assertSame($first['row_set_sha256'], $second['row_set_sha256']);
        self::assertSame(2, $first['slug_count']);
        self::assertSame(4, $first['row_count']);
    }

    #[Test]
    public function duplicate_authority_identity_fails_closed(): void
    {
        $this->expectException(CareerC03ControlFailure::class);
        $this->expectExceptionMessage('AUTHORITY_DUPLICATE_IDENTITY');

        CareerC03CacheOnlyDiscoverabilityControl::authoritySnapshot([
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'career.runtime_publish_projection.v1',
            'items' => [
                $this->authorityItem('duplicate-job', 'en', true),
                $this->authorityItem('duplicate-job', 'en', true),
                $this->authorityItem('duplicate-job', 'zh-CN', true),
            ],
        ]);
    }

    #[Test]
    public function locale_mismatch_fails_closed(): void
    {
        $this->expectException(CareerC03ControlFailure::class);
        $this->expectExceptionMessage('TEST_BILINGUAL_SET_INVALID');

        CareerC03CacheOnlyDiscoverabilityControl::snapshotFromRows([
            'one-sided-job|en',
        ], 'TEST');
    }

    #[Test]
    public function published_authority_with_misaligned_surface_flags_fails_closed(): void
    {
        $item = $this->authorityItem('hidden-job', 'en', true);
        $item['llms_live'] = false;

        $this->expectException(CareerC03ControlFailure::class);
        $this->expectExceptionMessage('AUTHORITY_SURFACE_FLAGS_MISALIGNED');

        CareerC03CacheOnlyDiscoverabilityControl::authoritySnapshot([
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'career.runtime_publish_projection.v1',
            'items' => [$item, $this->authorityItem('hidden-job', 'zh-CN', true)],
        ]);
    }

    #[Test]
    public function runner_contains_only_bounded_cache_recovery_commands(): void
    {
        $runner = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/operations/career_c03_cache_only_discoverability_control.php',
        );

        foreach ([
            'career:verify-job-detail-cache-coverage',
            "'--maximum-sync-repairs' => '250'",
            'career:warm-public-authority-cache',
            "'--directory-only' => true",
            'seo:warm-sitemap-source-cache',
            'PASS_CACHE_APPLY_INTERNAL',
            'HOLD_APPLY_ROLLED_BACK',
            'HOLD_ROLLBACK_INCOMPLETE',
            'PASS_ROLLBACK_VERIFIED',
            'CAREER_C03_CACHE_APPLY_AUTHORIZED',
            'automatic_retry_allowed',
        ] as $required) {
            self::assertStringContainsString($required, $runner);
        }

        foreach ([
            '1046',
            '2092',
            'Cache::flush',
            "call('migrate'",
            "call('migrate:rollback'",
            "call('queue:restart'",
            "call('down'",
            "call('up'",
            'deploy.php',
            'deploy:symlink',
            'supervisorctl',
            'indexnow',
            'googleapis',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($runner));
        }
    }

    #[Test]
    public function public_verify_accepts_an_exactly_converged_bilingual_fixture_without_writes(): void
    {
        [$arguments, $directory] = $this->publicFixture();
        file_put_contents($arguments[9], file_get_contents($arguments[9])."https://fermatmind.com/en/personality/big-five/facets/order\n");
        file_put_contents($arguments[10], file_get_contents($arguments[10])."https://fermatmind.com/zh/personality/big-five/facets/order\n");

        try {
            [$exit, $receipt] = $this->runPublicVerify($arguments);

            self::assertSame(0, $exit);
            self::assertSame('PASS_PUBLIC_CONVERGED', $receipt['status']);
            self::assertTrue($receipt['converged']);
            self::assertSame([], $receipt['surface_mismatches']);
            self::assertSame(
                ['jobs', 'directory', 'sitemap_source', 'sitemap', 'llms', 'llms_full'],
                array_keys($receipt['surface_diagnostics']),
            );
            self::assertTrue($receipt['surface_diagnostics']['jobs']['matches_expected']);
            self::assertSame(1, $receipt['surface_diagnostics']['jobs']['slug_count']);
            self::assertSame(2, $receipt['surface_diagnostics']['jobs']['row_count']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $receipt['surface_diagnostics']['jobs']['slug_set_sha256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $receipt['surface_diagnostics']['jobs']['row_set_sha256']);
            self::assertSame('ok', $receipt['surface_diagnostics']['jobs']['transport_class']);
            self::assertSame('within_5s', $receipt['surface_diagnostics']['jobs']['latency_class']);
            self::assertSame(6, $receipt['shared_surface_readback']['surface_count']);
            self::assertSame(8, $receipt['shared_surface_readback']['request_count']);
            self::assertSame(0, $receipt['private_path_leak_count']);
            self::assertSame(4, $receipt['detail_readback']['network_attempt_count']);
            self::assertSame(0, $receipt['detail_readback']['transport_retry_count']);
            self::assertSame(0, $receipt['detail_readback']['recovered_transport_failure_count']);
            self::assertSame(0, $receipt['detail_readback']['terminal_transport_failure_count']);
            self::assertSame(0, $receipt['cache_write_count']);
            self::assertSame(0, $receipt['database_write_count']);
        } finally {
            $this->removeFixture($directory);
        }
    }

    #[Test]
    public function public_verify_reports_exact_surface_drift(): void
    {
        [$arguments, $directory] = $this->publicFixture();
        file_put_contents($arguments[3], json_encode(['items' => []], JSON_THROW_ON_ERROR));

        try {
            [$exit, $receipt] = $this->runPublicVerify($arguments);

            self::assertSame(2, $exit);
            self::assertSame('HOLD_PUBLIC_DRIFT', $receipt['status']);
            self::assertFalse($receipt['converged']);
            self::assertSame(['jobs'], $receipt['surface_mismatches']);
            self::assertFalse($receipt['surface_diagnostics']['jobs']['matches_expected']);
            self::assertTrue($receipt['surface_diagnostics']['directory']['matches_expected']);
            self::assertSame(0, $receipt['surface_diagnostics']['jobs']['locales']['en']['count']);
        } finally {
            $this->removeFixture($directory);
        }
    }

    #[Test]
    public function public_verify_classifies_shared_surface_retry_latency_and_terminal_transport(): void
    {
        [$arguments, $directory] = $this->publicFixture();
        file_put_contents($arguments[12], implode("\n", [
            "jobs_en\t200\t0\t1\t6200",
            "jobs_zh\t200\t0\t0\t900",
            "directory_en\t200\t0\t0\t700",
            "directory_zh\t200\t0\t0\t800",
            "sitemap_source\t200\t0\t0\t1100",
            "sitemap\t200\t0\t0\t1200",
            "llms\t200\t0\t0\t1300",
            "llms_full\t200\t18\t2\t61000",
        ])."\n");

        try {
            [$exit, $receipt] = $this->runPublicVerify($arguments);

            self::assertSame(2, $exit);
            self::assertSame('recovered_retry', $receipt['surface_diagnostics']['jobs']['transport_class']);
            self::assertSame('within_20s', $receipt['surface_diagnostics']['jobs']['latency_class']);
            self::assertSame('terminal_incomplete_transfer', $receipt['surface_diagnostics']['llms_full']['transport_class']);
            self::assertSame('bounded_retry_window', $receipt['surface_diagnostics']['llms_full']['latency_class']);
            self::assertFalse($receipt['surface_diagnostics']['llms_full']['matches_expected']);
            self::assertSame(1, $receipt['shared_surface_readback']['recovered_retry_count']);
            self::assertSame(1, $receipt['shared_surface_readback']['terminal_transport_failure_count']);
            self::assertSame(1, $receipt['shared_surface_readback']['incomplete_transfer_count']);
        } finally {
            $this->removeFixture($directory);
        }
    }

    #[Test]
    public function public_verify_rejects_unknown_or_duplicate_shared_surface_status_ids(): void
    {
        foreach (['unknown_surface', 'jobs_en'] as $replacement) {
            [$arguments, $directory] = $this->publicFixture();
            $rows = file($arguments[12], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($rows);
            $rows[7] = $replacement."\t200\t0\t0\t1000";
            file_put_contents($arguments[12], implode("\n", $rows)."\n");

            try {
                [$exit, $receipt] = $this->runPublicVerify($arguments);

                self::assertSame(1, $exit);
                self::assertSame('HOLD_CONTROL_FAILED', $receipt['status']);
                self::assertSame('SHARED_SURFACE_STATUS_ROW_INVALID', $receipt['safe_failure_code']);
            } finally {
                $this->removeFixture($directory);
            }
        }
    }

    #[Test]
    public function public_verify_fails_when_a_private_url_enters_llms(): void
    {
        [$arguments, $directory] = $this->publicFixture();
        file_put_contents($arguments[9], "https://fermatmind.com/en/career/jobs/example-job\nhttps://fermatmind.com/zh/career/jobs/example-job\nhttps://fermatmind.com/en/results/private\n");

        try {
            [$exit, $receipt] = $this->runPublicVerify($arguments);

            self::assertSame(2, $exit);
            self::assertSame('HOLD_PUBLIC_DRIFT', $receipt['status']);
            self::assertFalse($receipt['converged']);
            self::assertSame(1, $receipt['private_path_leak_count']);
        } finally {
            $this->removeFixture($directory);
        }
    }

    #[Test]
    public function public_verify_accepts_a_recovered_incomplete_transfer(): void
    {
        [$arguments, $directory] = $this->publicFixture();
        $en = 'https://fermatmind.com/en/career/jobs/example-job';
        $zh = 'https://fermatmind.com/zh/career/jobs/example-job';
        file_put_contents($arguments[11], implode("\n", [
            "1\t{$en}\t200\t0\t2\t18",
            "1\t{$zh}\t200\t0\t1\t0",
            "2\t{$en}\t200\t0\t1\t0",
            "2\t{$zh}\t200\t0\t1\t0",
        ])."\n");

        try {
            [$exit, $receipt] = $this->runPublicVerify($arguments);

            self::assertSame(0, $exit);
            self::assertSame('PASS_PUBLIC_CONVERGED', $receipt['status']);
            self::assertSame(5, $receipt['detail_readback']['network_attempt_count']);
            self::assertSame(1, $receipt['detail_readback']['transport_retry_count']);
            self::assertSame(1, $receipt['detail_readback']['recovered_transport_failure_count']);
            self::assertSame(1, $receipt['detail_readback']['recovered_incomplete_transfer_count']);
            self::assertSame(0, $receipt['detail_readback']['terminal_transport_failure_count']);
            self::assertSame(0, $receipt['detail_readback']['incomplete_transfer_count']);
        } finally {
            $this->removeFixture($directory);
        }
    }

    #[Test]
    public function public_verify_holds_on_a_terminal_incomplete_transfer(): void
    {
        [$arguments, $directory] = $this->publicFixture();
        $en = 'https://fermatmind.com/en/career/jobs/example-job';
        $zh = 'https://fermatmind.com/zh/career/jobs/example-job';
        file_put_contents($arguments[11], implode("\n", [
            "1\t{$en}\t000\t18\t2\t18",
            "1\t{$zh}\t200\t0\t1\t0",
            "2\t{$en}\t200\t0\t1\t0",
            "2\t{$zh}\t200\t0\t1\t0",
        ])."\n");

        try {
            [$exit, $receipt] = $this->runPublicVerify($arguments);

            self::assertSame(2, $exit);
            self::assertSame('HOLD_PUBLIC_DRIFT', $receipt['status']);
            self::assertSame(1, $receipt['detail_readback']['terminal_transport_failure_count']);
            self::assertSame(1, $receipt['detail_readback']['incomplete_transfer_count']);
            self::assertSame(0, $receipt['detail_readback']['timeout_count']);
            self::assertSame(0, $receipt['detail_readback']['other_transport_failure_count']);
        } finally {
            $this->removeFixture($directory);
        }
    }

    #[Test]
    public function public_verify_classifies_timeout_other_transport_and_http_failures_without_retry(): void
    {
        [$arguments, $directory] = $this->publicFixture();
        $en = 'https://fermatmind.com/en/career/jobs/example-job';
        $zh = 'https://fermatmind.com/zh/career/jobs/example-job';
        file_put_contents($arguments[11], implode("\n", [
            "1\t{$en}\t000\t28\t2\t28",
            "1\t{$zh}\t000\t7\t2\t7",
            "2\t{$en}\t500\t0\t1\t0",
            "2\t{$zh}\t200\t0\t1\t0",
        ])."\n");

        try {
            [$exit, $receipt] = $this->runPublicVerify($arguments);

            self::assertSame(2, $exit);
            self::assertSame(2, $receipt['detail_readback']['terminal_transport_failure_count']);
            self::assertSame(1, $receipt['detail_readback']['timeout_count']);
            self::assertSame(1, $receipt['detail_readback']['other_transport_failure_count']);
            self::assertSame(1, $receipt['detail_readback']['server_error_count']);
            self::assertSame(0, $receipt['detail_readback']['non_200_count']);
        } finally {
            $this->removeFixture($directory);
        }
    }

    #[Test]
    public function public_verify_rejects_an_invalid_retry_tuple(): void
    {
        [$arguments, $directory] = $this->publicFixture();
        $en = 'https://fermatmind.com/en/career/jobs/example-job';
        $zh = 'https://fermatmind.com/zh/career/jobs/example-job';
        file_put_contents($arguments[11], implode("\n", [
            "1\t{$en}\t200\t0\t2\t0",
            "1\t{$zh}\t200\t0\t1\t0",
            "2\t{$en}\t200\t0\t1\t0",
            "2\t{$zh}\t200\t0\t1\t0",
        ])."\n");

        try {
            [$exit, $receipt] = $this->runPublicVerify($arguments);

            self::assertSame(1, $exit);
            self::assertSame('HOLD_CONTROL_FAILED', $receipt['status']);
            self::assertSame('DETAIL_STATUS_RETRY_INVALID', $receipt['safe_failure_code']);
        } finally {
            $this->removeFixture($directory);
        }
    }

    #[Test]
    public function backup_restores_exact_allowlisted_values_and_removes_new_version_payloads(): void
    {
        config(['cache.default' => 'array']);
        Cache::clear();
        $prefix = 'career:public-authority:job-detail:v3:example-job:en';
        Cache::forever($prefix.':active', 'version-before');
        Cache::forever($prefix.':versions:version-before', ['title' => 'before']);
        Cache::forever($prefix.':exposure-projections:version-before', ['visible' => true]);
        Cache::put('seo:llms-txt:v1:body', 'before', 600);

        $backup = $this->invokePrivate('createBackup', [[
            'expected_rows' => ['example-job|en', 'example-job|zh-CN'],
            'authority_artifact_sha256' => str_repeat('a', 64),
            'target_set_sha256' => str_repeat('b', 64),
        ]]);
        $path = sys_get_temp_dir().'/career-c03-backup-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($path, json_encode($backup, JSON_THROW_ON_ERROR));

        Cache::forever($prefix.':active', 'version-after');
        Cache::forever($prefix.':versions:version-after', ['title' => 'after']);
        Cache::forever($prefix.':exposure-projections:version-after', ['visible' => false]);
        Cache::put('seo:llms-txt:v1:body', 'after', 600);

        try {
            self::assertTrue($this->invokePrivate('restoreBackup', [$path]));
            self::assertSame('version-before', Cache::get($prefix.':active'));
            self::assertSame(['title' => 'before'], Cache::get($prefix.':versions:version-before'));
            self::assertSame(['visible' => true], Cache::get($prefix.':exposure-projections:version-before'));
            self::assertSame('before', Cache::get('seo:llms-txt:v1:body'));
            self::assertFalse(Cache::has($prefix.':versions:version-after'));
            self::assertFalse(Cache::has($prefix.':exposure-projections:version-after'));
        } finally {
            unlink($path);
            Cache::clear();
        }
    }

    #[Test]
    public function corrupt_backup_is_rollback_incomplete_and_cannot_be_accepted(): void
    {
        config(['cache.default' => 'array']);
        $path = sys_get_temp_dir().'/career-c03-corrupt-backup-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($path, json_encode([
            'state_sha256' => str_repeat('0', 64),
            'snapshots' => ['seo:llms-txt:v1:body' => ['present' => false]],
        ], JSON_THROW_ON_ERROR));

        try {
            self::assertFalse($this->invokePrivate('restoreBackup', [$path]));
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function apply_source_creates_and_verifies_backup_before_the_first_cache_mutation(): void
    {
        $runner = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/operations/career_c03_cache_only_discoverability_control.php',
        );
        $applyStart = strpos($runner, 'private static function runApply(): int');
        $backup = strpos($runner, '$backup = self::createBackup($before);', (int) $applyStart);
        $backupHash = strpos($runner, "hash_file('sha256', \$backupPath)", (int) $applyStart);
        $firstMutation = strpos($runner, "\$kernel->call('career:verify-job-detail-cache-coverage'", (int) $applyStart);

        self::assertIsInt($applyStart);
        self::assertIsInt($backup);
        self::assertIsInt($backupHash);
        self::assertIsInt($firstMutation);
        self::assertLessThan($backupHash, $backup);
        self::assertLessThan($firstMutation, $backupHash);
    }

    /** @return array<string, mixed> */
    private function authorityItem(string $slug, string $locale, bool $published): array
    {
        return [
            'slug' => $slug,
            'locale' => $locale,
            'runtime_publish_state' => $published ? 'published' : 'held',
            'detail_route_enabled' => $published,
            'robots_indexable' => $published,
            'release_gate_pass' => $published,
            'dataset_visible' => $published,
            'search_visible' => $published,
            'sitemap_live' => $published,
            'llms_live' => $published,
        ];
    }

    /** @return array{0: list<string>, 1: string} */
    private function publicFixture(): array
    {
        $directory = sys_get_temp_dir().'/career-c03-control-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $paths = [];
        foreach ([
            'inspection.json',
            'jobs-en.json',
            'jobs-zh.json',
            'directory-en.json',
            'directory-zh.json',
            'sitemap-source.json',
            'sitemap.xml',
            'llms.txt',
            'llms-full.txt',
            'detail-status.tsv',
            'shared-surface-status.tsv',
        ] as $name) {
            $paths[] = $directory.'/'.$name;
        }
        [$inspection, $jobsEn, $jobsZh, $directoryEn, $directoryZh, $sitemapSource,
            $sitemap, $llms, $llmsFull, $detailStatus] = $paths;
        $en = 'https://fermatmind.com/en/career/jobs/example-job';
        $zh = 'https://fermatmind.com/zh/career/jobs/example-job';
        file_put_contents($inspection, json_encode([
            'expected_rows' => ['example-job|en', 'example-job|zh-CN'],
            'expected_urls' => [$en, $zh],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($jobsEn, json_encode(['items' => [['slug' => 'example-job']]], JSON_THROW_ON_ERROR));
        file_put_contents($jobsZh, json_encode(['items' => [['slug' => 'example-job']]], JSON_THROW_ON_ERROR));
        $directoryItem = ['slug' => 'example-job', 'indexable' => true, 'detail_ready' => true];
        file_put_contents($directoryEn, json_encode(['items' => [$directoryItem]], JSON_THROW_ON_ERROR));
        file_put_contents($directoryZh, json_encode(['items' => [$directoryItem]], JSON_THROW_ON_ERROR));
        file_put_contents($sitemapSource, json_encode([
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'items' => [['loc' => $en], ['loc' => $zh], ['loc' => 'https://fermatmind.com/en/about']],
        ], JSON_THROW_ON_ERROR));
        $publicText = $en."\n".$zh."\nhttps://fermatmind.com/en/about\n";
        file_put_contents($sitemap, $publicText);
        file_put_contents($llms, $publicText);
        file_put_contents($llmsFull, $publicText);
        file_put_contents($detailStatus, implode("\n", [
            "1\t{$en}\t200\t0\t1\t0",
            "1\t{$zh}\t200\t0\t1\t0",
            "2\t{$en}\t200\t0\t1\t0",
            "2\t{$zh}\t200\t0\t1\t0",
        ])."\n");
        $sharedSurfaceStatus = $paths[10];
        file_put_contents($sharedSurfaceStatus, implode("\n", [
            "jobs_en\t200\t0\t0\t900",
            "jobs_zh\t200\t0\t0\t1000",
            "directory_en\t200\t0\t0\t1100",
            "directory_zh\t200\t0\t0\t1200",
            "sitemap_source\t200\t0\t0\t1300",
            "sitemap\t200\t0\t0\t1400",
            "llms\t200\t0\t0\t1500",
            "llms_full\t200\t0\t0\t1600",
        ])."\n");

        return [[
            'control',
            'public-verify',
            $inspection,
            $jobsEn,
            $jobsZh,
            $directoryEn,
            $directoryZh,
            $sitemapSource,
            $sitemap,
            $llms,
            $llmsFull,
            $detailStatus,
            $sharedSurfaceStatus,
        ], $directory];
    }

    /** @param list<string> $arguments @return array{0: int, 1: array<string, mixed>} */
    private function runPublicVerify(array $arguments): array
    {
        ob_start();
        $exit = CareerC03CacheOnlyDiscoverabilityControl::main($arguments);
        $output = ob_get_clean();
        self::assertIsString($output);
        $receipt = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($receipt);

        return [$exit, $receipt];
    }

    private function removeFixture(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }

    /** @param list<mixed> $arguments */
    private function invokePrivate(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(CareerC03CacheOnlyDiscoverabilityControl::class, $method);

        return $reflection->invoke(null, ...$arguments);
    }
}
