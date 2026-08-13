<?php

declare(strict_types=1);

namespace Tests\Sre;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\Career1046ImmutableCandidateGenerator;
use App\Domain\Career\Publish\CareerFullReleaseLedgerService;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class Career1046ProductDataStagingWorkflowTest extends TestCase
{
    private string $root;

    private string $privateRoot;

    /** @var array<string, mixed> */
    private array $candidate;

    private string $candidateBytes;

    /** @var array<string, string> */
    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/career-1046-staging-'.bin2hex(random_bytes(8));
        $this->privateRoot = $this->root.'/storage/app/private';
        $authorityRoot = $this->privateRoot.'/career_generation_authority';
        File::ensureDirectoryExists($authorityRoot.'/generations/career-current-342-30-bootstrap-v1');

        $payload = [
            'generation_id' => 'career-current-342-30-bootstrap-v1',
            'counts' => ['public_slug_count' => 30, 'public_locale_row_count' => 60],
            'discoverability' => [
                'sitemap_mutated' => false,
                'llms_mutated' => false,
                'search_mutated' => false,
            ],
        ];
        $active = [
            'schema_version' => 'career.generation_pointer.v1',
            'payload_sha256' => hash('sha256', CareerGenerationCanonicalJson::encode($payload)),
            'payload' => $payload,
        ];
        $activePath = $authorityRoot.'/active-generation.json';
        File::put($activePath, CareerGenerationCanonicalJson::encode($active)."\n");

        $this->candidate = (new Career1046ImmutableCandidateGenerator)->generate(...$this->candidateFixture());
        $this->candidateBytes = CareerGenerationCanonicalJson::encode($this->candidate)."\n";
        $this->environment = [
            'CAREER_STAGING_PRIVATE_ROOT' => $this->privateRoot,
            'CAREER_STAGING_CONTROL_PLANE_SHA' => str_repeat('a', 40),
            'CAREER_STAGING_RELEASE_SHA' => str_repeat('a', 40),
            'CAREER_STAGING_RELEASE_NAME' => 'release-1046',
            'CAREER_STAGING_WORKFLOW_RUN_ID' => '800',
            'CAREER_STAGING_WORKFLOW_RUN_ATTEMPT' => '1',
            'CAREER_STAGING_GENERATION_ID' => $this->candidate['generation_id'],
            'CAREER_STAGING_CANDIDATE_BUNDLE_SHA256' => hash('sha256', $this->candidateBytes),
            'CAREER_STAGING_CANDIDATE_RECEIPT_SHA256' => CareerGenerationCanonicalJson::sha256(
                $this->candidate['candidate_receipt'],
            ),
            'CAREER_STAGING_CANDIDATE_ARTIFACT_DIGEST' => 'sha256:'.str_repeat('b', 64),
            'CAREER_STAGING_PREVIOUS_GENERATION_ID' => 'career-current-342-30-bootstrap-v1',
            'CAREER_STAGING_PREVIOUS_POINTER_SHA256' => hash_file('sha256', $activePath),
            'CAREER_STAGING_PREFLIGHT_RECEIPT_SHA256' => '',
            'CAREER_STAGING_APPLY_AUTHORIZED' => '0',
        ];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_preflight_is_zero_write_and_apply_stages_exact_generation_without_pointer_switch(): void
    {
        $authorityRoot = $this->privateRoot.'/career_generation_authority';
        $activePath = $authorityRoot.'/active-generation.json';
        $activeBefore = hash_file('sha256', $activePath);
        $before = $this->treeHash($this->privateRoot);

        $preflight = $this->runControl('preflight');
        $preflight->mustRun();
        $receipt = $this->receipt($preflight);
        self::assertSame('PASS_PREFLIGHT_STAGE_ELIGIBLE', $receipt['status']);
        self::assertTrue($receipt['zero_write_guarantee']);
        self::assertFalse($receipt['production_write_execution']);
        self::assertSame(0, $receipt['candidate_file_write_count']);
        self::assertSame(0, $receipt['pointer_write_count']);
        self::assertSame($before, $this->treeHash($this->privateRoot));

        $apply = $this->runControl('apply', [
            'CAREER_STAGING_PREFLIGHT_RECEIPT_SHA256' => str_repeat('c', 64),
            'CAREER_STAGING_APPLY_AUTHORIZED' => '1',
        ]);
        $apply->mustRun();
        $applyReceipt = $this->receipt($apply);
        self::assertSame('PASS_APPLY_PRODUCT_DATA_STAGED', $applyReceipt['status']);
        self::assertTrue($applyReceipt['production_write_execution']);
        self::assertTrue($applyReceipt['writes_committed']);
        self::assertSame(8, $applyReceipt['candidate_file_write_count']);
        self::assertSame(1, $applyReceipt['directory_write_count']);
        self::assertSame(0, $applyReceipt['pointer_write_count']);
        self::assertSame($activeBefore, hash_file('sha256', $activePath));

        $destination = $authorityRoot.'/generations/'.$this->candidate['generation_id'];
        self::assertDirectoryExists($destination);
        self::assertFileDoesNotExist($destination.'/active-generation.json');
        self::assertFileDoesNotExist($destination.'/generation-pointer.json');
        $actualFiles = array_values(array_diff(scandir($destination) ?: [], ['.', '..']));
        sort($actualFiles, SORT_STRING);
        $expectedFiles = array_keys($this->candidate['documents']);
        sort($expectedFiles, SORT_STRING);
        self::assertSame($expectedFiles, $actualFiles);
        foreach ($this->candidate['documents'] as $filename => $document) {
            self::assertSame(
                hash('sha256', CareerGenerationCanonicalJson::encode($document)."\n"),
                hash_file('sha256', $destination.'/'.$filename),
            );
        }

        $retry = $this->runControl('apply', [
            'CAREER_STAGING_PREFLIGHT_RECEIPT_SHA256' => str_repeat('c', 64),
            'CAREER_STAGING_APPLY_AUTHORIZED' => '1',
        ]);
        $retry->run();
        self::assertSame(1, $retry->getExitCode());
        self::assertSame('GENERATION_DESTINATION_CONFLICT', $this->receipt($retry)['failed_stage']);
        self::assertSame($activeBefore, hash_file('sha256', $activePath));
    }

    public function test_tampered_bundle_and_staging_residue_fail_closed_without_writes(): void
    {
        $before = $this->treeHash($this->privateRoot);
        $tampered = json_decode($this->candidateBytes, true, 512, JSON_THROW_ON_ERROR);
        $tampered['counts']['unique_slugs'] = 1048;
        $tamperedBytes = CareerGenerationCanonicalJson::encode($tampered)."\n";
        $process = $this->runControl('preflight', [
            'CAREER_STAGING_CANDIDATE_BUNDLE_SHA256' => hash('sha256', $tamperedBytes),
        ], $tamperedBytes);
        $process->run();
        self::assertSame(1, $process->getExitCode());
        self::assertSame('CANDIDATE_AUTHORITY_INVALID', $this->receipt($process)['failed_stage']);
        self::assertSame($before, $this->treeHash($this->privateRoot));

        $residue = $this->privateRoot.'/career_generation_authority/generations/.'
            .$this->candidate['generation_id'].'.staging.700.1';
        File::ensureDirectoryExists($residue);
        $residueBefore = $this->treeHash($this->privateRoot);
        $residueProcess = $this->runControl('preflight');
        $residueProcess->run();
        self::assertSame(1, $residueProcess->getExitCode());
        self::assertSame('GENERATION_STAGING_RESIDUE_CONFLICT', $this->receipt($residueProcess)['failed_stage']);
        self::assertSame($residueBefore, $this->treeHash($this->privateRoot));
    }

    public function test_workflow_is_manual_receipt_bound_no_retry_and_excludes_activation_or_discoverability(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-1046-product-data-staging-production-ops.yml');
        $runner = $this->repoFile('backend/scripts/operations/career_1046_product_data_staging.php');
        $combined = $workflow.$runner;

        foreach ([
            'workflow_dispatch:',
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'candidate_artifact_id:',
            'expected_candidate_artifact_digest:',
            'expected_candidate_bundle_sha256:',
            'expected_candidate_receipt_sha256:',
            'expected_previous_generation_id:',
            'expected_previous_pointer_sha256:',
            'expected_preflight_receipt_sha256:',
            'operator_approval_phrase:',
            'actions: read',
            'contents: read',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'PASS_PREFLIGHT_STAGE_ELIGIBLE',
            'PASS_APPLY_PRODUCT_DATA_STAGED',
            'career.1046.product_data_staging.v2',
            'career.1046.immutable_candidate.v2',
            'unique_slugs == 1046',
            'locale_rows == 2092',
            'group: deploy-${{ github.repository }}-production',
            'environment: production',
            'if: always()',
            'automatic_retry_allowed',
            'automatic_cleanup_allowed',
            'automatic_rollback_allowed',
            'write_state="indeterminate"',
            'pointer_write_count',
            'search_submission_count',
        ] as $required) {
            self::assertStringContainsString($required, $combined);
        }

        foreach ([
            'schedule:',
            'push:',
            'repository_dispatch:',
            'workflow_run:',
            'gh workflow run',
            'php artisan migrate',
            'queue:restart',
            'deploy:symlink',
            'indexnow',
            'googleapis',
            'sitemap:submit',
            'Storage::put(',
            'File::put(',
            'data_get(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $combined);
        }
    }

    /** @return array<string, mixed> */
    private function candidateFixture(): array
    {
        $manifestPath = dirname(__DIR__, 2).'/docs/seo/generated/detail-ready-1046-rollout-manifest.v2.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $baseline = $manifest['baseline_slugs'];
        $receipts = $manifest['delta_slugs'];
        $target = array_values(array_unique([...$baseline, ...$receipts]));
        sort($target, SORT_STRING);
        $rows = array_map(static fn (string $slug): array => [
            'source_slug' => $slug,
            'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
            'public_eligible' => true,
            'indexability' => 'indexable',
        ], $target);
        $ledger = [
            'ledger_kind' => CareerFullReleaseLedgerService::LEDGER_KIND,
            'ledger_version' => 'career.release_ledger.1046.candidate.v1',
            'scope' => 'career_exact_1046',
            'public_resolution' => ['rows' => $rows],
        ];
        $details = [];
        foreach ($target as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $details[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'payload' => [
                        'identity' => ['canonical_slug' => $slug],
                        'titles' => ['canonical' => $slug.'-'.$locale],
                    ],
                ];
            }
        }

        return [
            'manifestPath' => $manifestPath,
            'baselineAuthoritySlugs' => $baseline,
            'databaseMatchingReceiptSlugs' => $receipts,
            'ledger' => $ledger,
            'projection' => (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($ledger),
            'detailRows' => $details,
        ];
    }

    /** @param array<string, string> $overrides */
    private function runControl(string $mode, array $overrides = [], ?string $input = null): Process
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2).'/scripts/operations/career_1046_product_data_staging.php', $mode],
            null,
            array_merge($this->environment, $overrides),
        );
        $process->setInput($input ?? $this->candidateBytes);

        return $process;
    }

    /** @return array<string, mixed> */
    private function receipt(Process $process): array
    {
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function repoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($contents);

        return $contents;
    }

    private function treeHash(string $root): string
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files, SORT_STRING);

        return hash('sha256', CareerGenerationCanonicalJson::encode($files));
    }
}
