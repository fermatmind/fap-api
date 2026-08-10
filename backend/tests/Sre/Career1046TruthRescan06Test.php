<?php

declare(strict_types=1);

namespace Tests\Sre;

use FermatMind\Operations\Career1046TruthRescan06;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/scripts/operations/career_1046_truth_rescan_06.php';

final class Career1046TruthRescan06Test extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = storage_path('framework/testing/career-c06-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_verdict_requires_exact_1046_2092_and_active_c05(): void
    {
        $pass = [
            'source_stable' => true,
            'scan_safe' => true,
            'c05_active' => true,
            'unique_slug_count' => 1046,
            'en_count' => 1046,
            'zh_count' => 1046,
            'localized_total' => 2092,
        ];

        $this->assertSame('PASS', Career1046TruthRescan06::determineVerdict($pass));
        $this->assertSame('PARTIALLY_BLOCKED', Career1046TruthRescan06::determineVerdict([
            ...$pass,
            'unique_slug_count' => 342,
            'en_count' => 30,
            'zh_count' => 30,
            'localized_total' => 60,
        ]));
        $this->assertSame('PARTIALLY_BLOCKED', Career1046TruthRescan06::determineVerdict([
            ...$pass,
            'c05_active' => false,
        ]));
        $this->assertSame('NO_GO', Career1046TruthRescan06::determineVerdict([
            ...$pass,
            'source_stable' => false,
        ]));
        $this->assertSame('NO_GO', Career1046TruthRescan06::determineVerdict([
            ...$pass,
            'scan_safe' => false,
        ]));
    }

    public function test_c03_receipt_must_be_pass_closed_converged_and_zero_write(): void
    {
        $receipt = $this->receipt();
        Career1046TruthRescan06::validateC03Receipt($receipt);
        $this->addToAssertionCount(1);

        foreach ([
            ['cache_write_count', 1],
            ['career_link_publication_gate', 'OPEN'],
            ['public_converged', false],
            ['status', 'PASS_RECOVERY_REQUIRED'],
        ] as [$key, $value]) {
            $invalid = $receipt;
            $invalid[$key] = $value;
            try {
                Career1046TruthRescan06::validateC03Receipt($invalid);
                $this->fail('Expected invalid C03 receipt for '.$key);
            } catch (\RuntimeException $exception) {
                $this->assertContains($exception->getMessage(), ['C03_RECEIPT_INVALID', 'C03_ZERO_WRITE_GUARANTEE_INVALID']);
            }
        }

        $invalidCoverage = $receipt;
        $invalidCoverage['detail_coverage']['row_count'] = 58;
        $this->expectException(\RuntimeException::class);
        Career1046TruthRescan06::validateC03Receipt($invalidCoverage);
    }

    public function test_pre_post_receipts_fail_stability_on_runtime_or_authority_drift(): void
    {
        $pre = $this->receipt();
        $this->assertSame(['stable' => true, 'drift_fields' => []], Career1046TruthRescan06::receiptStability($pre, $pre));

        $post = $pre;
        $post['active_revision'] = str_repeat('b', 40);
        $post['published_cohort']['row_set_sha256'] = str_repeat('c', 64);
        $result = Career1046TruthRescan06::receiptStability($pre, $post);
        $this->assertFalse($result['stable']);
        $this->assertSame(['active_revision', 'published_cohort'], $result['drift_fields']);
    }

    public function test_set_hash_matches_c03_contract_and_is_order_independent(): void
    {
        $this->assertSame(
            hash('sha256', "alpha\nbeta\n"),
            Career1046TruthRescan06::setHash(['beta', 'alpha', 'alpha']),
        );
    }

    public function test_html_metadata_and_surface_rows_are_exact_and_duplicate_safe(): void
    {
        $meta = $this->invoke('htmlMeta', [
            '<html><head>'
            .'<meta content="index, follow" name="robots">'
            .'<link href="https://fermatmind.com/en/career/jobs/actuaries" rel="canonical">'
            .'<link hrefLang="en" href="https://fermatmind.com/en/career/jobs/actuaries" rel="alternate">'
            .'<link rel="alternate" href="https://fermatmind.com/zh/career/jobs/actuaries" hrefLang="zh-CN">'
            .'</head></html>',
        ]);
        $this->assertSame('https://fermatmind.com/en/career/jobs/actuaries', $meta['canonical']);
        $this->assertSame('index, follow', $meta['robots']);
        $this->assertSame([
            'en' => 'https://fermatmind.com/en/career/jobs/actuaries',
            'zh-CN' => 'https://fermatmind.com/zh/career/jobs/actuaries',
        ], $meta['alternates']);

        $expectedRows = ['actuaries|en', 'actuaries|zh-CN'];
        $snapshot = $this->invoke('textSurfaceSnapshot', [
            'llms_full',
            '<loc>https://fermatmind.com/en/career/jobs/actuaries</loc> '
            .'https://fermatmind.com/en/career/jobs/actuaries '
            .'https://fermatmind.com/zh/career/jobs/actuaries',
            $expectedRows,
        ]);
        $this->assertSame('llms_full', $snapshot['surface']);
        $this->assertSame(3, $snapshot['occurrence_count']);
        $this->assertSame(2, $snapshot['unique_identity_count']);
        $this->assertSame(1, $snapshot['duplicate_reference_count']);
        $this->assertSame(0, $snapshot['conflicting_identity_count']);
        $this->assertSame(Career1046TruthRescan06::setHash($expectedRows), $snapshot['row_set_sha256']);
        $this->assertTrue($snapshot['matches_expected']);
        $this->assertSame($expectedRows, $snapshot['rows']);

        $conflicting = $this->invoke('textSurfaceSnapshot', [
            'llms_full',
            'https://fermatmind.com/en/career/jobs/actuaries '
            .'https://fermatmind.com/en/career/jobs/actuaries/',
            ['actuaries|en'],
        ]);
        $this->assertSame(1, $conflicting['conflicting_identity_count']);
        $this->assertFalse($conflicting['matches_expected']);

        $foreignHost = $this->invoke('textSurfaceSnapshot', [
            'llms_full',
            'https://example.com/en/career/jobs/actuaries',
            ['actuaries|en'],
        ]);
        $this->assertSame(1, $foreignHost['conflicting_identity_count']);
        $this->assertFalse($foreignHost['matches_expected']);
    }

    public function test_directory_duplicate_locale_and_unqualified_rows_fail_closed(): void
    {
        $valid = [
            'items' => [[
                'slug' => 'actuaries',
                'indexable' => true,
                'detail_ready' => true,
                'family' => ['slug' => null],
            ]],
        ];
        $this->assertSame([
            'slugs' => ['actuaries'],
            'families' => ['actuaries' => null],
        ], $this->invoke('directorySnapshot', [$valid]));

        $invalid = $valid;
        $invalid['items'][] = $valid['items'][0];
        $this->expectExceptionMessage('DIRECTORY_DUPLICATE_IDENTITY');
        $this->invoke('directorySnapshot', [$invalid]);
    }

    public function test_complete_safe_fixture_finalizes_to_partial_and_validates_sha_chain(): void
    {
        $head = trim((string) shell_exec('git rev-parse HEAD'));
        $receipt = $this->receipt(activeRevision: $head);
        $prePath = $this->temporaryDirectory.'/pre.json';
        $postPath = $this->temporaryDirectory.'/post.json';
        $scanPath = $this->temporaryDirectory.'/scan.json';
        $evidenceDir = $this->temporaryDirectory.'/evidence';
        $this->writeJson($prePath, $receipt);
        $this->writeJson($postPath, [...$receipt, 'workflow_run_id' => 9002]);

        $target = $this->target();
        $round = [
            'complete' => true,
            'errors' => [],
            'slug_set' => ['actuaries'],
            'slug_count' => 1,
            'locale_row_count' => 2,
            'jobs_directory_receipt_set_match' => true,
            'surface_set_match' => true,
            'surface_diagnostics' => $this->surfaceDiagnostics(),
            'private_path_leak_count' => 0,
            'timeout_count' => 0,
            'server_error_count' => 0,
            'target_failure_count' => 0,
            'family_membership' => [
                'en_non_null_count' => 0,
                'zh_CN_non_null_count' => 0,
                'locale_consistent' => true,
            ],
            'industry_membership' => [
                'en_industry_count' => 0,
                'zh_CN_industry_count' => 0,
                'locale_consistent' => true,
            ],
            'targets' => [$target],
        ];
        $scan = [
            'contract_version' => Career1046TruthRescan06::CONTRACT_VERSION,
            'mode' => 'scan',
            'status' => 'SCAN_COMPLETE',
            'started_at' => '2026-08-10T10:00:00Z',
            'completed_at' => '2026-08-10T10:02:00Z',
            'pre_c03_receipt_sha256' => hash_file('sha256', $prePath),
            'pre_c03_artifact_digest' => 'sha256:'.str_repeat('a', 64),
            'active_revision' => $head,
            'authority_artifact_sha256' => str_repeat('2', 64),
            'published_cohort' => $receipt['published_cohort'],
            'rounds' => [
                ['round' => 1, ...$round],
                ['round' => 2, ...$round],
            ],
            'aborted' => false,
            'safe_error' => null,
            'response_bodies_retained' => false,
            'production_write_execution' => false,
        ];
        $this->writeJson($scanPath, $scan);

        $digest = 'sha256:'.str_repeat('a', 64);
        $exit = Career1046TruthRescan06::main([
            __FILE__,
            'finalize',
            '--pre-c03-receipt='.$prePath,
            '--post-c03-receipt='.$postPath,
            '--scan='.$scanPath,
            '--output-dir='.$evidenceDir,
            '--base-main-sha='.$head,
            '--c05-merge-sha=4ad35bd2b15448569a3bafc6bd27f6ad115dc014',
            '--pre-artifact-digest='.$digest,
            '--post-artifact-digest='.$digest,
        ]);
        $this->assertSame(0, $exit);
        $manifest = json_decode((string) file_get_contents($evidenceDir.'/manifest.v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('PARTIALLY_BLOCKED', $manifest['verdict']);
        $this->assertSame('CLOSED', $manifest['career_link_publication_gate']);
        $this->assertFalse($manifest['pr6_allowed']);
        $this->assertSame(0, Career1046TruthRescan06::main([
            __FILE__,
            'validate',
            '--evidence-dir='.$evidenceDir,
        ]));
    }

    public function test_timeout_private_leak_set_drift_and_metadata_failure_make_scan_unsafe(): void
    {
        $scan = [
            'status' => 'SCAN_COMPLETE',
            'aborted' => false,
            'rounds' => [[
                'complete' => true,
                'jobs_directory_receipt_set_match' => false,
                'surface_set_match' => true,
                'surface_diagnostics' => $this->surfaceDiagnostics(),
                'family_membership' => ['locale_consistent' => true],
                'industry_membership' => ['locale_consistent' => true],
                'timeout_count' => 1,
                'server_error_count' => 0,
                'private_path_leak_count' => 1,
                'target_failure_count' => 1,
            ], [
                'complete' => true,
                'jobs_directory_receipt_set_match' => true,
                'surface_set_match' => true,
                'surface_diagnostics' => $this->surfaceDiagnostics(),
                'family_membership' => ['locale_consistent' => true],
                'industry_membership' => ['locale_consistent' => true],
                'timeout_count' => 0,
                'server_error_count' => 0,
                'private_path_leak_count' => 0,
                'target_failure_count' => 0,
            ]],
        ];
        $safety = $this->invoke('scanSafety', [$scan]);
        $this->assertFalse($safety['safe']);
        $this->assertSame(1, $safety['timeout_count']);
        $this->assertSame(1, $safety['private_path_leak_count']);
        $this->assertSame(1, $safety['target_failure_count']);
        $this->assertSame(0, $safety['conflicting_identity_count']);
        $this->assertSame(4, $safety['duplicate_reference_count']);
    }

    public function test_llms_full_structural_references_are_safe_and_retained_as_per_surface_diagnostics(): void
    {
        $diagnostics = $this->surfaceDiagnostics();
        $diagnostics['sitemap']['occurrence_count'] = 60;
        $diagnostics['sitemap']['unique_identity_count'] = 60;
        $diagnostics['llms']['occurrence_count'] = 60;
        $diagnostics['llms']['unique_identity_count'] = 60;
        $diagnostics['llms_full']['occurrence_count'] = 120;
        $diagnostics['llms_full']['unique_identity_count'] = 60;
        $diagnostics['llms_full']['duplicate_reference_count'] = 60;

        $round = [
            'complete' => true,
            'jobs_directory_receipt_set_match' => true,
            'surface_set_match' => true,
            'surface_diagnostics' => $diagnostics,
            'family_membership' => ['locale_consistent' => true],
            'industry_membership' => ['locale_consistent' => true],
            'timeout_count' => 0,
            'server_error_count' => 0,
            'private_path_leak_count' => 0,
            'target_failure_count' => 0,
        ];
        $safety = $this->invoke('scanSafety', [[
            'status' => 'SCAN_COMPLETE',
            'aborted' => false,
            'rounds' => [
                ['round' => 1, ...$round],
                ['round' => 2, ...$round],
            ],
        ]]);

        $this->assertTrue($safety['safe']);
        $this->assertSame(120, $safety['duplicate_reference_count']);
        $this->assertSame(0, $safety['conflicting_identity_count']);
        $this->assertSame(120, $safety['surface_diagnostics']['llms_full'][0]['occurrence_count']);
        $this->assertSame(60, $safety['surface_diagnostics']['llms_full'][0]['unique_identity_count']);
        $this->assertSame(60, $safety['surface_diagnostics']['llms_full'][0]['duplicate_reference_count']);
        $this->assertArrayNotHasKey('url', $safety['surface_diagnostics']['llms_full'][0]);
    }

    public function test_runner_is_get_only_concurrency_two_and_has_no_production_mutator_calls(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/operations/career_1046_truth_rescan_06.php');
        $this->assertStringContainsString('private const MAX_CONCURRENCY = 2;', $source);
        $this->assertStringContainsString("'method' => 'GET'", $source);
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $source);
        $this->assertStringNotContainsString('curl_close(', $source);
        foreach (['Cache::', 'DB::', 'Artisan::call', 'queue:restart', 'deploy.php ', 'migrate --', 'search:submit', 'CURLOPT_POST => true'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    /** @return array<string, mixed> */
    private function receipt(?string $activeRevision = null): array
    {
        $cohort = [
            'slug_count' => 1,
            'row_count' => 2,
            'slug_set_sha256' => Career1046TruthRescan06::setHash(['actuaries']),
            'row_set_sha256' => Career1046TruthRescan06::setHash(['actuaries|en', 'actuaries|zh-CN']),
            'locales' => [
                'en' => ['count' => 1, 'set_sha256' => Career1046TruthRescan06::setHash(['actuaries'])],
                'zh-CN' => ['count' => 1, 'set_sha256' => Career1046TruthRescan06::setHash(['actuaries'])],
            ],
        ];
        $receipt = [
            'contract_version' => 'career.c03.cache_only_discoverability_recovery.v1',
            'mode' => 'verify',
            'status' => 'PASS_C03_REVERIFIED_NO_APPLY_REQUIRED',
            'control_plane_sha' => str_repeat('1', 40),
            'active_revision' => $activeRevision ?? str_repeat('2', 40),
            'runner_sha256' => str_repeat('1', 64),
            'workflow_run_id' => 9001,
            'workflow_run_attempt' => 1,
            'authority_artifact_sha256' => str_repeat('2', 64),
            'authority_inventory' => [
                'unique_slug_count' => 342,
                'locale_row_count' => 684,
                'row_set_sha256' => str_repeat('3', 64),
            ],
            'published_cohort' => $cohort,
            'detail_coverage' => $cohort,
            'target_set_sha256' => str_repeat('4', 64),
            'job_index_converged' => true,
            'directory_converged' => true,
            'sitemap_source_converged' => true,
            'public_converged' => true,
            'public_timeout_count' => 0,
            'public_5xx_count' => 0,
            'private_path_leak_count' => 0,
            'career_link_publication_gate' => 'CLOSED',
        ];
        foreach ([
            'cache_write_count', 'database_write_count', 'publication_write_count', 'indexability_write_count',
            'deploy_count', 'migration_count', 'symlink_write_count', 'process_restart_count',
            'queue_reload_count', 'sitemap_submission_count', 'llms_submission_count', 'search_submission_count',
        ] as $field) {
            $receipt[$field] = 0;
        }

        return $receipt;
    }

    /** @return array<string, mixed> */
    private function target(): array
    {
        return [
            'slug' => 'actuaries',
            'locale' => 'en',
            'path' => '/en/career/jobs/actuaries',
            'api_http_status' => 200,
            'page_http_status' => 200,
            'redirect_count' => 0,
            'api_identity_ok' => true,
            'api_canonical_ok' => true,
            'api_indexability_ok' => true,
            'page_canonical_ok' => true,
            'page_hreflang_ok' => true,
            'page_robots_ok' => true,
            'jobs_member' => true,
            'directory_member' => true,
            'sitemap_member' => true,
            'llms_member' => true,
            'llms_full_member' => true,
            'family_uuid' => null,
            'directory_family_slug' => null,
        ];
    }

    /** @return array<string, array<string, bool|int|string>> */
    private function surfaceDiagnostics(): array
    {
        $rowSetSha = Career1046TruthRescan06::setHash(['actuaries|en', 'actuaries|zh-CN']);

        return [
            'sitemap' => [
                'surface' => 'sitemap',
                'occurrence_count' => 2,
                'unique_identity_count' => 2,
                'duplicate_reference_count' => 0,
                'conflicting_identity_count' => 0,
                'row_set_sha256' => $rowSetSha,
                'matches_expected' => true,
            ],
            'llms' => [
                'surface' => 'llms',
                'occurrence_count' => 2,
                'unique_identity_count' => 2,
                'duplicate_reference_count' => 0,
                'conflicting_identity_count' => 0,
                'row_set_sha256' => $rowSetSha,
                'matches_expected' => true,
            ],
            'llms_full' => [
                'surface' => 'llms_full',
                'occurrence_count' => 4,
                'unique_identity_count' => 2,
                'duplicate_reference_count' => 2,
                'conflicting_identity_count' => 0,
                'row_set_sha256' => $rowSetSha,
                'matches_expected' => true,
            ],
        ];
    }

    private function invoke(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(Career1046TruthRescan06::class, $method);

        return $reflection->invoke(null, ...$arguments);
    }

    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
