<?php

declare(strict_types=1);

namespace Tests\Sre;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\Career1046ImmutableCandidateGenerator;
use App\Domain\Career\Publish\CareerFullReleaseLedgerService;
use App\Domain\Career\Publish\CareerGenerationAuthorityLoader;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use FermatMind\Operations\Career1046RootActivationFailure;
use FermatMind\Operations\Career1046RootGenerationActivation;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/scripts/operations/career_1046_root_generation_activation.php';

final class Career1046RootGenerationActivationWorkflowTest extends TestCase
{
    private string $root;

    private string $privateRoot;

    private string $originalStoragePath;

    /** @var array<string, mixed> */
    private array $expected;

    /** @var array<string, mixed> */
    private array $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/career-1046-root-activation-'.bin2hex(random_bytes(8));
        $this->originalStoragePath = $this->app->storagePath();
        $this->app->useStoragePath($this->root.'/storage');
        $this->privateRoot = $this->root.'/storage/app/private';
        $release = $this->root.'/releases/release-1046';
        File::ensureDirectoryExists($release.'/backend');
        File::put($release.'/REVISION', str_repeat('a', 40)."\n");
        self::assertTrue(symlink($release, $this->root.'/current'));
        $authorityRoot = $this->privateRoot.'/career_generation_authority';
        $previous = 'career-current-342-30-bootstrap-v1';
        File::ensureDirectoryExists($authorityRoot.'/generations/'.$previous);

        $fixture = $this->candidateFixture();
        $candidate = (new Career1046ImmutableCandidateGenerator)->generate(...$fixture);
        $baseline = $fixture['baselineAuthoritySlugs'];
        $previousLedger = $fixture['ledger'];
        $previousLedger['public_resolution']['rows'] = array_values(array_filter(
            $previousLedger['public_resolution']['rows'],
            static fn (array $row): bool => in_array($row['source_slug'], $baseline, true),
        ));
        $previousProjection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($previousLedger);
        $projectionRelativePath = 'career_runtime_publish_projection/'.$previous.'/career-runtime-publish-projection.json';
        $ledgerRelativePath = 'career_release_ledger/'.$previous.'/career-full-release-ledger.json';
        File::ensureDirectoryExists(dirname($this->privateRoot.'/'.$projectionRelativePath));
        File::ensureDirectoryExists(dirname($this->privateRoot.'/'.$ledgerRelativePath));
        $projectionBytes = CareerGenerationCanonicalJson::encode($previousProjection)."\n";
        $ledgerBytes = CareerGenerationCanonicalJson::encode($previousLedger)."\n";
        File::put($this->privateRoot.'/'.$projectionRelativePath, $projectionBytes);
        File::put($this->privateRoot.'/'.$ledgerRelativePath, $ledgerBytes);
        $localeRows = [];
        foreach ($baseline as $slug) {
            $localeRows[] = $slug.'|en';
            $localeRows[] = $slug.'|zh';
        }
        $payload = [
            'generation_id' => $previous,
            'artifact_format' => 'legacy_exact_bytes_v1',
            'artifacts' => [
                'projection' => [
                    'identity' => 'career-runtime-publish-projection@'.$previous,
                    'path' => $projectionRelativePath,
                    'sha256' => hash('sha256', $projectionBytes),
                ],
                'ledger' => [
                    'identity' => 'career-full-release-ledger@'.$previous,
                    'path' => $ledgerRelativePath,
                    'sha256' => hash('sha256', $ledgerBytes),
                ],
            ],
            'authority' => [
                'frozen_manifest_sha256' => Career1046ImmutableCandidateGenerator::MANIFEST_SHA256,
                'target_slug_set_sha256' => CareerGenerationCanonicalJson::setSha256($baseline),
                'target_locale_row_set_sha256' => CareerGenerationCanonicalJson::setSha256($localeRows),
                'receipt_set_sha256' => Career1046ImmutableCandidateGenerator::RECEIPT_SET_SHA256,
            ],
            'counts' => ['public_slug_count' => 30, 'public_locale_row_count' => 60],
            'lineage' => ['previous_generation_id' => null, 'previous_pointer_sha256' => null],
            'timestamps' => [
                'created_at' => '2026-08-11T00:00:00Z',
                'activated_at' => '2026-08-11T00:00:00Z',
            ],
            'activation_receipt' => [
                'identity' => 'activation:'.$previous,
                'sha256' => str_repeat('9', 64),
            ],
            'rollback' => ['eligible' => false, 'previous_generation_id' => null],
            'discoverability' => [
                'sitemap_mutated' => false,
                'llms_mutated' => false,
                'search_mutated' => false,
            ],
            'revocation_receipt' => null,
        ];
        $pointer = [
            'schema_version' => 'career.generation_pointer.v1',
            'payload_sha256' => hash('sha256', CareerGenerationCanonicalJson::encode($payload)),
            'payload' => $payload,
        ];
        $pointerBytes = json_encode(
            $pointer,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
        File::put($authorityRoot.'/active-generation.json', $pointerBytes);
        File::put($authorityRoot.'/generations/'.$previous.'/generation-pointer.json', $pointerBytes);

        $generationRoot = $authorityRoot.'/generations/'.$candidate['generation_id'];
        File::ensureDirectoryExists($generationRoot);
        $documentHashes = [];
        foreach ($candidate['documents'] as $filename => $document) {
            $bytes = CareerGenerationCanonicalJson::encode($document)."\n";
            File::put($generationRoot.'/'.$filename, $bytes);
            $documentHashes[$filename] = hash('sha256', $bytes);
        }
        ksort($documentHashes, SORT_STRING);

        $this->expected = [
            'mode' => 'apply',
            'backend_root' => $release.'/backend',
            'private_root' => $this->privateRoot,
            'active_release_link' => $this->root.'/current',
            'control_plane_sha' => str_repeat('a', 40),
            'release_sha' => str_repeat('a', 40),
            'release_name' => 'release-1046',
            'workflow_run_id' => 900,
            'workflow_run_attempt' => 1,
            'activation_timestamp' => '2026-08-12T00:00:00Z',
            'generation_id' => $candidate['generation_id'],
            'previous_generation_id' => $previous,
            'previous_pointer_sha256' => hash('sha256', $pointerBytes),
            'staging_receipt_sha256' => str_repeat('b', 64),
            'staging_artifact_digest' => 'sha256:'.str_repeat('c', 64),
            'candidate_receipt_sha256' => CareerGenerationCanonicalJson::sha256($candidate['candidate_receipt']),
            'generation_manifest_sha256' => CareerGenerationCanonicalJson::sha256(
                $candidate['documents']['generation-manifest.json'],
            ),
            'document_hashes' => $documentHashes,
        ];
        $this->database = [
            'receipt_covered_count' => 1016,
            'matching_count' => 1016,
            'missing_or_mismatching_count' => 0,
            'outside_target_count' => 0,
            'current_state_sha256' => str_repeat('d', 64),
            'query_count' => 2,
            'query_verb_set_sha256' => str_repeat('e', 64),
        ];
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStoragePath);
        if (is_link($this->root.'/current')) {
            unlink($this->root.'/current');
        }
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_exact_staged_generation_and_complete_rollback_allow_one_root_pointer_switch(): void
    {
        $current = Career1046RootGenerationActivation::inspectCurrentAndRollback($this->expected);
        $generation = Career1046RootGenerationActivation::inspectStagedGeneration($this->expected);
        $activePath = $current['active_path'];
        $before = hash_file('sha256', $activePath);

        $result = Career1046RootGenerationActivation::activate(
            $this->expected,
            $current,
            $generation,
            $this->database,
            str_repeat('f', 64),
        );

        self::assertSame($before, $result['active_sha256_before']);
        self::assertNotSame($before, $result['active_sha256_after']);
        self::assertSame($result['active_sha256_after'], hash_file('sha256', $activePath));
        self::assertFileDoesNotExist(dirname($activePath).'/.active-generation.json.candidate.900.1');
        $immutablePath = $this->privateRoot.'/career_generation_authority/generations/'
            .$this->expected['generation_id'].'/generation-pointer.json';
        self::assertFileExists($immutablePath);
        self::assertSame(hash_file('sha256', $activePath), hash_file('sha256', $immutablePath));
        $active = json_decode((string) file_get_contents($activePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->expected['generation_id'], $active['payload']['generation_id']);
        self::assertSame('generation_native_v1', $active['payload']['artifact_format']);
        self::assertSame(1046, $active['payload']['counts']['public_slug_count']);
        self::assertSame(2092, $active['payload']['counts']['public_locale_row_count']);
        self::assertTrue($active['payload']['rollback']['eligible']);
        self::assertSame($this->expected['previous_generation_id'], $active['payload']['rollback']['previous_generation_id']);
        self::assertFalse($active['payload']['discoverability']['sitemap_mutated']);
        self::assertFalse($active['payload']['discoverability']['llms_mutated']);
        self::assertFalse($active['payload']['discoverability']['search_mutated']);
        self::assertCount(8, $active['payload']['artifacts']);
        self::assertSame(
            'career-runtime-publish-projection@'.$this->expected['generation_id'],
            $active['payload']['artifacts']['projection']['identity'],
        );
        self::assertSame(
            'career-full-release-ledger@'.$this->expected['generation_id'],
            $active['payload']['artifacts']['ledger']['identity'],
        );
        self::assertSame(
            'activation:'.$this->expected['generation_id'],
            $active['payload']['activation_receipt']['identity'],
        );
        self::assertSame('2026-08-12T00:00:00Z', $active['payload']['timestamps']['created_at']);
        self::assertSame('2026-08-12T00:00:00Z', $active['payload']['timestamps']['activated_at']);
        self::assertNotSame(
            $this->expected['previous_pointer_sha256'],
            $active['payload']['lineage']['previous_pointer_sha256'],
        );
        $previousDocument = json_decode(
            (string) file_get_contents($this->privateRoot.'/career_generation_authority/generations/'
                .$this->expected['previous_generation_id'].'/generation-pointer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            CareerGenerationCanonicalJson::sha256($previousDocument),
            $active['payload']['lineage']['previous_pointer_sha256'],
        );

        $loader = new CareerGenerationAuthorityLoader;
        $loaded = $loader->loadStrict();
        self::assertSame($this->expected['generation_id'], $loaded['pointer']['generation_id']);
        self::assertCount(2092, $loaded['projection']['items']);
    }

    public function test_tampered_staging_and_incomplete_rollback_fail_closed_before_activation(): void
    {
        $generationPath = $this->privateRoot.'/career_generation_authority/generations/'
            .$this->expected['generation_id'].'/career-directory-en.json';
        File::append($generationPath, "\n");
        try {
            Career1046RootGenerationActivation::inspectStagedGeneration($this->expected);
            self::fail('Tampered staged bytes must fail closed.');
        } catch (Career1046RootActivationFailure $failure) {
            self::assertSame('STAGED_DOCUMENT_READBACK_MISMATCH', $failure->safeCode);
        }

        $rollbackProjectionPath = $this->privateRoot.'/career_runtime_publish_projection/'
            .$this->expected['previous_generation_id'].'/career-runtime-publish-projection.json';
        $rollbackProjectionBytes = (string) file_get_contents($rollbackProjectionPath);
        File::append($rollbackProjectionPath, "\n");
        try {
            Career1046RootGenerationActivation::inspectCurrentAndRollback($this->expected);
            self::fail('Unreadable rollback authority artifacts must fail closed.');
        } catch (Career1046RootActivationFailure $failure) {
            self::assertSame('ROLLBACK_AUTHORITY_UNREADABLE', $failure->safeCode);
        }
        File::put($rollbackProjectionPath, $rollbackProjectionBytes);

        File::delete($this->privateRoot.'/career_generation_authority/generations/'
            .$this->expected['previous_generation_id'].'/generation-pointer.json');
        try {
            Career1046RootGenerationActivation::inspectCurrentAndRollback($this->expected);
            self::fail('Incomplete rollback generation must fail closed.');
        } catch (Career1046RootActivationFailure $failure) {
            self::assertSame('FILE_BOUNDARY_INVALID', $failure->safeCode);
        }
    }

    public function test_deploy_lock_fails_closed_before_any_pointer_write(): void
    {
        $current = Career1046RootGenerationActivation::inspectCurrentAndRollback($this->expected);
        $generation = Career1046RootGenerationActivation::inspectStagedGeneration($this->expected);
        $activePath = $current['active_path'];
        $before = hash_file('sha256', $activePath);
        File::ensureDirectoryExists($this->root.'/.dep');
        File::put($this->root.'/.dep/deploy.lock', "locked\n");

        try {
            Career1046RootGenerationActivation::activate(
                $this->expected,
                $current,
                $generation,
                $this->database,
                str_repeat('f', 64),
            );
            self::fail('A deploy lock must fail closed before activation writes.');
        } catch (Career1046RootActivationFailure $failure) {
            self::assertSame('DEPLOY_LOCK_PRESENT', $failure->safeCode);
        }

        self::assertSame($before, hash_file('sha256', $activePath));
        self::assertFileDoesNotExist($this->privateRoot.'/career_generation_authority/generations/'
            .$this->expected['generation_id'].'/generation-pointer.json');
    }

    public function test_workflow_is_manual_receipt_bound_single_pointer_only_and_keeps_discoverability_closed(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-1046-root-generation-activation-production-ops.yml');
        $runner = $this->repoFile('backend/scripts/operations/career_1046_root_generation_activation.php');
        $combined = $workflow.$runner;

        foreach ([
            'workflow_dispatch:',
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'staging_run_id:',
            'expected_staging_receipt_sha256:',
            'expected_staging_artifact_digest:',
            'expected_previous_pointer_sha256:',
            'expected_database_state_sha256:',
            'expected_preflight_receipt_sha256:',
            'operator_approval_phrase:',
            'actions: read',
            'contents: read',
            'environment: production',
            'group: deploy-${{ github.repository }}-production',
            'PASS_PREFLIGHT_ACTIVATION_ELIGIBLE',
            'PASS_APPLY_ROOT_GENERATION_ACTIVATED',
            'ROLLBACK_AUTHORITY_UNREADABLE',
            'DEPLOY_LOCK_PRESENT',
            'CONFLICTING_AUTHORITY_PROCESS_PRESENT',
            'career.1046.root_generation_activation.v1',
            'receipt_covered_count == 1016',
            'matching_count == 1016',
            'missing_or_mismatching_count == 0',
            'outside_target_count == 0',
            'pointer_write_count == 2',
            'root_pointer_switch_count == 1',
            'write_state="indeterminate"',
            'if: always()',
            'automatic_retry_allowed',
            'automatic_cleanup_allowed',
            'automatic_rollback_allowed',
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

        self::assertGreaterThanOrEqual(2, substr_count($workflow, 'git fetch --no-tags origin main:refs/remotes/origin/main'));
        self::assertSame(2, substr_count($runner, 'self::assertNoConflictingOperation($expected);'));
    }

    /** @return array<string, mixed> */
    private function candidateFixture(): array
    {
        $manifestPath = dirname(__DIR__, 2).'/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json';
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

    private function repoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($contents);

        return $contents;
    }
}
