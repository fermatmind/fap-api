<?php

declare(strict_types=1);

namespace Tests\Sre;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\Career1046ImmutableCandidateGenerator;
use App\Domain\Career\Publish\CareerFullReleaseLedgerService;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class Career1046PublicProductVerifyOnlyWorkflowTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeRoot($root);
        }
        parent::tearDown();
    }

    public function test_exact_generation_and_public_products_pass_without_writes(): void
    {
        $fixture = $this->fixture();
        [$status, $receipt] = $this->runControl($fixture);

        self::assertSame(0, $status);
        self::assertSame('PASS_PUBLIC_PRODUCT_VERIFY_ONLY', $receipt['status']);
        self::assertSame(1046, $receipt['counts']['directory_en']);
        self::assertSame(1046, $receipt['counts']['directory_zh']);
        self::assertSame(2092, $receipt['counts']['detail_targets']);
        self::assertSame(2092, $receipt['counts']['detail_http_200']);
        foreach (['missing', 'duplicate', 'extra', 'http_404', 'http_5xx', 'timeout', 'generation_mismatch'] as $key) {
            self::assertSame(0, $receipt['counts'][$key]);
        }
        self::assertSame($fixture['pointer_sha256'], $receipt['active_pointer_sha256_before']);
        self::assertSame($fixture['pointer_sha256'], $receipt['active_pointer_sha256_after']);
        self::assertTrue($receipt['production_read_only_evidence']);
        self::assertFalse($receipt['writes_committed']);
        self::assertFalse($receipt['automatic_retry_allowed']);
    }

    public function test_public_http_failures_and_generation_drift_fail_closed_with_sanitized_counts(): void
    {
        $fixture = $this->fixture();
        $map = json_decode((string) file_get_contents($fixture['http_fixture']), true, 512, JSON_THROW_ON_ERROR);
        $slugs = $fixture['slugs'];
        $map[$fixture['api_base'].'/api/v0.5/career/jobs/'.$slugs[0].'?locale=en'] = [
            'status' => 404,
            'timeout' => false,
            'body' => ['message' => 'not found'],
        ];
        $map[$fixture['api_base'].'/api/v0.5/career/jobs/'.$slugs[1].'?locale=en'] = [
            'status' => 200,
            'timeout' => false,
            'body' => ['identity' => ['canonical_slug' => $slugs[1]], 'titles' => ['canonical' => 'drifted']],
        ];
        $map[$fixture['api_base'].'/api/v0.5/career/jobs/'.$slugs[2].'?locale=zh-CN'] = [
            'status' => 0,
            'timeout' => true,
            'body' => '',
        ];
        file_put_contents($fixture['http_fixture'], json_encode($map, JSON_THROW_ON_ERROR));

        [$status, $receipt] = $this->runControl($fixture);

        self::assertSame(1, $status);
        self::assertSame('FAIL_PUBLIC_PRODUCT_VERIFY_ONLY', $receipt['status']);
        self::assertSame('PUBLIC_PRODUCT_COUNTS_NOT_EXACT', $receipt['failed_stage']);
        self::assertSame(1, $receipt['counts']['http_404']);
        self::assertSame(1, $receipt['counts']['timeout']);
        self::assertSame(1, $receipt['counts']['generation_mismatch']);
        self::assertSame(2090, $receipt['counts']['detail_http_200']);
        self::assertFalse($receipt['writes_committed']);
        self::assertSame(0, $receipt['warm_count']);
        self::assertSame(0, $receipt['repair_count']);
        self::assertSame(0, $receipt['rollback_count']);
    }

    public function test_workflow_is_manual_protected_read_only_and_uploads_every_receipt(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-1046-public-product-verify-only.yml');
        $runner = $this->repoFile('backend/scripts/operations/career_1046_public_product_verify_only.php');
        $controller = $this->repoFile('backend/app/Http/Controllers/API/V0_5/Career/CareerJobDetailController.php');
        $service = $this->repoFile('backend/app/Services/Career/PublicCareerAuthorityResponseCache.php');
        $publicRuntime = $this->repoFile('backend/app/Http/Middleware/RecordPublicContentRuntime.php');
        $careerSlo = $this->repoFile('backend/app/Http/Middleware/RecordCareerRuntimeSlo.php');
        $controlPlane = $workflow.$runner;
        $combined = $workflow.$runner.$controller.$service.$publicRuntime.$careerSlo;

        foreach ([
            'workflow_dispatch:',
            'permissions:',
            'contents: read',
            'environment: production',
            'group: deploy-${{ github.repository }}-production',
            'Initialize sanitized immutable receipt before checkout',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'test "$EXPECTED_RELEASE_SHA" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'I explicitly approve read-only Career 1046 public product verification',
            'StrictHostKeyChecking=yes',
            'career.1046.public_product_verify_only.v1',
            'PASS_PUBLIC_PRODUCT_VERIFY_ONLY',
            'X-Fermat-Career-Verify-Only: 1',
            'jobDetailVerifyOnlyRead',
            '.counts.directory_en == 1046',
            '.counts.directory_zh == 1046',
            '.counts.detail_targets == 2092',
            '.counts.missing == 0',
            '.counts.duplicate == 0',
            '.counts.extra == 0',
            '.counts.http_404 == 0',
            '.counts.http_5xx == 0',
            '.counts.timeout == 0',
            '.counts.generation_mismatch == 0',
            'if: always()',
            'automatic_retry_allowed',
            'writes_committed',
        ] as $required) {
            self::assertStringContainsString($required, $combined);
        }

        foreach ([
            'schedule:',
            'push:',
            'pull_request:',
            'actions: write',
            'contents: write',
            'curl --retry',
            'career:warm',
            'queue:restart',
            'php artisan migrate',
            'deploy:symlink',
            'indexnow',
            'googleapis',
            'sitemap:submit',
            'Storage::put(',
            'File::put(',
            'DB::',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $controlPlane);
        }
        self::assertSame(1, substr_count($workflow, 'uses: actions/upload-artifact@'));
        self::assertSame(2, substr_count($publicRuntime.$careerSlo, "header(self::CAREER_VERIFY_ONLY_HEADER) === '1'"));
        self::assertGreaterThanOrEqual(2, substr_count($workflow, 'git fetch --no-tags origin main:refs/remotes/origin/main'));

        $methodStart = strpos($service, 'public function jobDetailVerifyOnlyRead');
        $methodEnd = strpos($service, 'public function jobDetailCacheReadiness', $methodStart ?: 0);
        self::assertIsInt($methodStart);
        self::assertIsInt($methodEnd);
        $verifyOnlyMethod = substr($service, $methodStart, $methodEnd - $methodStart);
        foreach (['Cache::put', 'Cache::forget', 'Cache::add', 'dispatchJobDetailWarm', 'publishJobDetailReadModel', 'careerJobDetailDegradedShellBuilder'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $verifyOnlyMethod);
        }
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $root = sys_get_temp_dir().'/career-public-verify-'.bin2hex(random_bytes(8));
        $this->roots[] = $root;
        $deployPath = $root.'/deploy';
        $releaseName = '20260812T100000Z-test';
        $releaseRoot = $deployPath.'/releases/'.$releaseName;
        $scriptRelative = '/backend/scripts/operations/career_1046_public_product_verify_only.php';
        mkdir($releaseRoot.dirname($scriptRelative), 0755, true);
        mkdir($releaseRoot.'/backend/storage/app/private/career_generation_authority/generations', 0755, true);
        file_put_contents($releaseRoot.'/REVISION', str_repeat('a', 40)."\n");
        copy($this->repoPath('backend/scripts/operations/career_1046_public_product_verify_only.php'), $releaseRoot.$scriptRelative);
        symlink($releaseRoot, $deployPath.'/current');

        $manifestPath = $this->repoPath('backend/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json');
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $baseline = $manifest['baseline_slugs'];
        $receipts = $manifest['delta_slugs'];
        $slugs = array_values(array_unique([...$baseline, ...$receipts]));
        sort($slugs, SORT_STRING);
        $ledger = [
            'ledger_kind' => CareerFullReleaseLedgerService::LEDGER_KIND,
            'ledger_version' => 'career.release_ledger.1046.candidate.v1',
            'scope' => 'career_exact_1046',
            'public_resolution' => [
                'rows' => array_map(static fn (string $slug): array => [
                    'source_slug' => $slug,
                    'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                    'public_eligible' => true,
                    'indexability' => 'indexable',
                ], $slugs),
            ],
        ];
        $details = [];
        $payloads = ['en' => [], 'zh' => []];
        foreach ($slugs as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $payload = [
                    'identity' => ['canonical_slug' => $slug],
                    'titles' => ['canonical' => $slug.'-'.$locale],
                ];
                $details[] = ['slug' => $slug, 'locale' => $locale, 'payload' => $payload];
                $payloads[$locale][$slug] = $payload;
            }
        }
        $candidate = (new Career1046ImmutableCandidateGenerator)->generate(
            manifestPath: $manifestPath,
            baselineAuthoritySlugs: $baseline,
            databaseMatchingReceiptSlugs: $receipts,
            ledger: $ledger,
            projection: (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($ledger),
            detailRows: $details,
        );
        $generationId = $candidate['generation_id'];
        $generationRoot = $releaseRoot.'/backend/storage/app/private/career_generation_authority/generations/'.$generationId;
        mkdir($generationRoot, 0755);
        $documentHashes = [];
        foreach ($candidate['documents'] as $filename => $document) {
            $bytes = CareerGenerationCanonicalJson::encode($document)."\n";
            file_put_contents($generationRoot.'/'.$filename, $bytes);
            $documentHashes[$filename] = hash('sha256', $bytes);
        }
        $pointerPayload = [
            'generation_id' => $generationId,
            'artifact_format' => 'generation_native_v1',
            'artifacts' => [
                'generation_manifest' => $this->descriptor($generationId, 'generation-manifest.json', $documentHashes),
                'directory_en' => $this->descriptor($generationId, 'career-directory-en.json', $documentHashes),
                'directory_zh' => $this->descriptor($generationId, 'career-directory-zh.json', $documentHashes),
                'detail_en' => $this->descriptor($generationId, 'career-job-details-en.json', $documentHashes),
                'detail_zh' => $this->descriptor($generationId, 'career-job-details-zh.json', $documentHashes),
            ],
            'authority' => [
                'frozen_manifest_sha256' => Career1046ImmutableCandidateGenerator::MANIFEST_SHA256,
                'target_slug_set_sha256' => Career1046ImmutableCandidateGenerator::TARGET_SET_SHA256,
                'target_locale_row_set_sha256' => Career1046ImmutableCandidateGenerator::TARGET_LOCALE_ROW_SET_SHA256,
            ],
            'counts' => ['public_slug_count' => 1046, 'public_locale_row_count' => 2092],
            'discoverability' => ['sitemap_mutated' => false, 'llms_mutated' => false, 'search_mutated' => false],
        ];
        $pointer = [
            'schema_version' => 'career.generation_pointer.v1',
            'payload' => $pointerPayload,
            'payload_sha256' => CareerGenerationCanonicalJson::sha256($pointerPayload),
        ];
        $pointerBytes = CareerGenerationCanonicalJson::encode($pointer)."\n";
        file_put_contents($generationRoot.'/generation-pointer.json', $pointerBytes);
        file_put_contents($releaseRoot.'/backend/storage/app/private/career_generation_authority/active-generation.json', $pointerBytes);

        $apiBase = 'https://api.example.test';
        $http = [];
        foreach (['en' => 'en', 'zh' => 'zh-CN'] as $locale => $queryLocale) {
            foreach (array_chunk($slugs, 100) as $index => $pageSlugs) {
                $page = $index + 1;
                $http[$apiBase.'/api/v0.5/career/directory?locale='.$queryLocale.'&per_page=100&page='.$page] = [
                    'status' => 200,
                    'timeout' => false,
                    'body' => [
                        'pagination' => ['page' => $page, 'per_page' => 100, 'total' => 1046, 'total_pages' => 11],
                        'items' => array_map(static fn (string $slug): array => ['slug' => $slug], $pageSlugs),
                    ],
                ];
            }
            foreach ($slugs as $slug) {
                $http[$apiBase.'/api/v0.5/career/jobs/'.$slug.'?locale='.$queryLocale] = [
                    'status' => 200,
                    'timeout' => false,
                    'body' => $payloads[$locale][$slug],
                ];
            }
        }
        $httpFixture = $root.'/http-fixture.json';
        file_put_contents($httpFixture, json_encode($http, JSON_THROW_ON_ERROR));

        return [
            'deploy_path' => $deployPath,
            'release_name' => $releaseName,
            'generation_id' => $generationId,
            'pointer_sha256' => hash('sha256', $pointerBytes),
            'runner_sha256' => hash_file('sha256', $this->repoPath('backend/scripts/operations/career_1046_public_product_verify_only.php')),
            'api_base' => $apiBase,
            'http_fixture' => $httpFixture,
            'slugs' => $slugs,
        ];
    }

    /** @param array<string, string> $hashes @return array<string, string> */
    private function descriptor(string $generationId, string $filename, array $hashes): array
    {
        return [
            'path' => 'generations/'.$generationId.'/'.$filename,
            'sha256' => $hashes[$filename],
        ];
    }

    /** @param array<string, mixed> $fixture @return array{0:int,1:array<string,mixed>} */
    private function runControl(array $fixture): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->repoPath('backend/scripts/operations/career_1046_public_product_verify_only.php')],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                ...getenv(),
                'CAREER_PUBLIC_VERIFY_DEPLOY_PATH' => $fixture['deploy_path'],
                'CAREER_PUBLIC_VERIFY_CONTROL_PLANE_SHA' => str_repeat('a', 40),
                'CAREER_PUBLIC_VERIFY_RELEASE_SHA' => str_repeat('a', 40),
                'CAREER_PUBLIC_VERIFY_RELEASE_NAME' => $fixture['release_name'],
                'CAREER_PUBLIC_VERIFY_GENERATION_ID' => $fixture['generation_id'],
                'CAREER_PUBLIC_VERIFY_ACTIVE_POINTER_SHA256' => $fixture['pointer_sha256'],
                'CAREER_PUBLIC_VERIFY_RUNNER_SHA256' => $fixture['runner_sha256'],
                'CAREER_PUBLIC_VERIFY_WORKFLOW_RUN_ID' => '123',
                'CAREER_PUBLIC_VERIFY_WORKFLOW_RUN_ATTEMPT' => '1',
                'CAREER_PUBLIC_VERIFY_API_BASE_URL' => $fixture['api_base'],
                'CAREER_PUBLIC_VERIFY_HTTP_FIXTURE_FILE' => $fixture['http_fixture'],
            ],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame('', $stderr);
        $receipt = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($receipt);

        return [$status, $receipt];
    }

    private function repoFile(string $path): string
    {
        return (string) file_get_contents($this->repoPath($path));
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 3).'/'.$path;
    }

    private function removeRoot(string $root): void
    {
        if (! is_dir($root) || is_link($root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                unlink($entry->getPathname());
            } else {
                rmdir($entry->getPathname());
            }
        }
        rmdir($root);
    }
}
