<?php

declare(strict_types=1);

namespace Tests\Sre;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerFullReleaseLedgerService;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use FermatMind\Operations\Career1046ImmutableCandidateArtifactFailure;
use FermatMind\Operations\Career1046ImmutableCandidateArtifactProducer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/operations/career_1046_immutable_candidate_artifact.php';

final class Career1046ImmutableCandidateArtifactProducerTest extends TestCase
{
    public function test_it_emits_the_exact_task5_single_document_contract_with_task3b_binding(): void
    {
        $candidate = Career1046ImmutableCandidateArtifactProducer::produceFromSource($this->source(), $this->task3b());
        self::assertSame($candidate, Career1046ImmutableCandidateArtifactProducer::produceFromSource($this->source(), $this->task3b()));
        self::assertSame('career.1046.immutable_candidate.v2', $candidate['schema_version']);
        self::assertSame(1046, $candidate['counts']['unique_slugs']);
        self::assertSame(2092, $candidate['counts']['locale_rows']);
        self::assertSame($candidate['candidate_receipt'], $candidate['documents']['candidate-receipt.json']);
        self::assertSame(1016, $candidate['candidate_receipt']['producer_authority']['receipt_covered_slug_count']);
        self::assertTrue($candidate['candidate_receipt']['producer_authority']['production_read_only']);
        self::assertSame(0, $candidate['candidate_receipt']['producer_authority']['cache_write_count']);
        self::assertNotContains('software-developers', array_column($candidate['documents']['career-directory-en.json']['items'], 'slug'));
        self::assertNotContains('database-administrators-and-architects', array_column($candidate['documents']['career-directory-zh.json']['items'], 'slug'));
    }

    public function test_it_fails_closed_when_task3b_binding_or_source_authority_drifts(): void
    {
        $binding = $this->task3b();
        $binding['database_state_sha256'] = 'invalid';
        $this->expectException(Career1046ImmutableCandidateArtifactFailure::class);
        Career1046ImmutableCandidateArtifactProducer::produceFromSource($this->source(), $binding);
    }

    public function test_task5_real_consumer_accepts_the_producer_output_without_manual_assembly(): void
    {
        $candidate = Career1046ImmutableCandidateArtifactProducer::produceFromSource($this->source(), $this->task3b());
        $root = sys_get_temp_dir().'/career-1046-producer-consumer-'.bin2hex(random_bytes(8));
        $privateRoot = $root.'/storage/app/private';
        $authorityRoot = $privateRoot.'/career_generation_authority';
        File::ensureDirectoryExists($authorityRoot.'/generations/career-current-342-30-bootstrap-v1');
        $pointerPayload = ['generation_id' => 'career-current-342-30-bootstrap-v1', 'counts' => ['public_slug_count' => 30, 'public_locale_row_count' => 60], 'discoverability' => ['sitemap_mutated' => false, 'llms_mutated' => false, 'search_mutated' => false]];
        $pointer = ['schema_version' => 'career.generation_pointer.v1', 'payload_sha256' => hash('sha256', CareerGenerationCanonicalJson::encode($pointerPayload)), 'payload' => $pointerPayload];
        $pointerPath = $authorityRoot.'/active-generation.json';
        File::put($pointerPath, CareerGenerationCanonicalJson::encode($pointer)."\n");
        $candidateBytes = CareerGenerationCanonicalJson::encode($candidate)."\n";
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/scripts/operations/career_1046_product_data_staging.php', 'preflight'], null, [
            'CAREER_STAGING_PRIVATE_ROOT' => $privateRoot,
            'CAREER_STAGING_CONTROL_PLANE_SHA' => str_repeat('a', 40),
            'CAREER_STAGING_RELEASE_SHA' => str_repeat('a', 40),
            'CAREER_STAGING_RELEASE_NAME' => 'release-1046',
            'CAREER_STAGING_WORKFLOW_RUN_ID' => '800',
            'CAREER_STAGING_WORKFLOW_RUN_ATTEMPT' => '1',
            'CAREER_STAGING_GENERATION_ID' => $candidate['generation_id'],
            'CAREER_STAGING_CANDIDATE_BUNDLE_SHA256' => hash('sha256', $candidateBytes),
            'CAREER_STAGING_CANDIDATE_RECEIPT_SHA256' => CareerGenerationCanonicalJson::sha256($candidate['candidate_receipt']),
            'CAREER_STAGING_CANDIDATE_ARTIFACT_DIGEST' => 'sha256:'.str_repeat('b', 64),
            'CAREER_STAGING_PREVIOUS_GENERATION_ID' => 'career-current-342-30-bootstrap-v1',
            'CAREER_STAGING_PREVIOUS_POINTER_SHA256' => hash_file('sha256', $pointerPath),
            'CAREER_STAGING_PREFLIGHT_RECEIPT_SHA256' => '',
            'CAREER_STAGING_APPLY_AUTHORIZED' => '0',
        ]);
        $process->setInput($candidateBytes);
        try {
            $process->mustRun();
            $receipt = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('PASS_PREFLIGHT_STAGE_ELIGIBLE', $receipt['status']);
            self::assertTrue($receipt['zero_write_guarantee']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_workflow_is_manual_task3b_receipt_bound_and_uploads_only_the_task5_file(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 3).'/.github/workflows/career-1046-immutable-candidate-artifact-producer.yml');
        self::assertIsString($workflow);
        $runner = file_get_contents(dirname(__DIR__, 2).'/scripts/operations/career_1046_immutable_candidate_artifact.php');
        self::assertIsString($runner);
        $combined = $workflow.$runner;
        foreach ([
            'workflow_dispatch:',
            'expected_task3b_control_plane_sha:',
            'expected_task3b_artifact_digest:',
            'expected_task3b_receipt_sha256:',
            'expected_task3b_database_state_sha256:',
            'expired == false',
            'PASS_EXACT_RECONCILIATION_APPLY',
            'receipt_covered_count == 1016',
            'matching_latest_state_count == 1016',
            'git merge-base --is-ancestor',
            'SET TRANSACTION READ ONLY',
            "ledger['members']",
            "canonical_slug']",
            'TASK3B_DATABASE_STATE_DRIFT',
            'CAREER_1046_APPLICATION_ROOT',
            'CAREER_1046_STREAMED_EXECUTION',
            '--emit-streamed-runner',
            'php /dev/stdin',
            'name: career-1046-immutable-candidate',
            'career-1046-immutable-candidate.json',
        ] as $required) {
            self::assertStringContainsString($required, $combined);
        }
        foreach (['schedule:', 'push:', 'workflow_run:', 'gh workflow run', 'php artisan migrate', 'queue:restart', 'deploy:symlink', 'indexnow', 'sitemap:submit'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $combined);
        }
    }

    public function test_it_emits_a_lintable_control_plane_runner_bundle(): void
    {
        $runner = dirname(__DIR__, 2).'/scripts/operations/career_1046_immutable_candidate_artifact.php';
        $bundle = new Process([PHP_BINARY, $runner, '--emit-streamed-runner']);
        $bundle->mustRun();
        self::assertStringContainsString('Career1046ImmutableCandidateGenerator', $bundle->getOutput());
        self::assertStringContainsString('CareerGenerationCanonicalJson', $bundle->getOutput());
        self::assertStringContainsString('CareerPublicationIndexReconciliationApply', $bundle->getOutput());
        self::assertStringContainsString('exit(\\FermatMind\\Operations\\Career1046ImmutableCandidateArtifactProducer::main());', $bundle->getOutput());

        $lint = new Process([PHP_BINARY, '-l', '/dev/stdin']);
        $lint->setInput($bundle->getOutput());
        $lint->mustRun();
    }

    public function test_real_database_entry_records_observed_select_only_and_cache_zero_write(): void
    {
        Cache::flush();
        $cacheBytesBefore = serialize(Cache::getStore()->all());

        $candidate = $this->withTask3bEnvironment(fn (): array => Career1046ImmutableCandidateArtifactProducer::produceFromDatabase(
            function (string $applicationRoot, array $task3b): array {
                self::assertDirectoryExists($applicationRoot);
                DB::select('select 1 as producer_probe');
                Cache::get('career:producer:read-only-probe');

                return Career1046ImmutableCandidateArtifactProducer::produceFromSource($this->source(), $task3b);
            }
        ));

        $authority = $candidate['candidate_receipt']['producer_authority'];
        self::assertSame(['select'], $authority['database_query_verbs']);
        self::assertSame(1, $authority['database_query_count']);
        self::assertSame(0, $authority['cache_write_count']);
        self::assertSame($cacheBytesBefore, serialize(Cache::getStore()->all()));
        self::assertSame($candidate['candidate_receipt'], $candidate['documents']['candidate-receipt.json']);
    }

    public function test_real_database_entry_fails_closed_on_database_or_cache_write_attempt(): void
    {
        foreach (['database', 'cache'] as $kind) {
            try {
                $this->withTask3bEnvironment(fn (): array => Career1046ImmutableCandidateArtifactProducer::produceFromDatabase(
                    function (string $applicationRoot, array $task3b) use ($kind): array {
                        DB::select('select 1 as producer_probe');
                        if ($kind === 'database') {
                            DB::statement('create table career_producer_write_probe (id integer)');
                        } else {
                            Cache::put('career:producer:write-probe', true, 60);
                        }

                        return Career1046ImmutableCandidateArtifactProducer::produceFromSource($this->source(), $task3b);
                    }
                ));
                self::fail($kind.' write attempt did not fail closed');
            } catch (Career1046ImmutableCandidateArtifactFailure $failure) {
                self::assertSame(
                    $kind === 'database' ? 'DATABASE_WRITE_ATTEMPT' : 'CACHE_WRITE_ATTEMPT',
                    $failure->safeCode,
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        $manifestPath = dirname(__DIR__, 2).'/docs/seo/generated/detail-ready-1046-rollout-manifest.v2.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $slugs = [...$manifest['baseline_slugs'], ...$manifest['delta_slugs']];
        sort($slugs, SORT_STRING);
        $ledger = ['ledger_kind' => CareerFullReleaseLedgerService::LEDGER_KIND, 'ledger_version' => 'candidate.v1', 'scope' => 'career_exact_1046', 'public_resolution' => ['rows' => array_map(static fn (string $slug): array => ['source_slug' => $slug, 'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB, 'public_eligible' => true, 'indexability' => 'indexable'], $slugs)]];
        $details = [];
        foreach ($slugs as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $details[] = ['slug' => $slug, 'locale' => $locale, 'payload' => ['identity' => ['canonical_slug' => $slug], 'titles' => ['canonical' => $slug.'-'.$locale]]];
            }
        }

        return ['manifest_path' => $manifestPath, 'baseline_authority_slugs' => $manifest['baseline_slugs'], 'database_matching_receipt_slugs' => $manifest['delta_slugs'], 'ledger' => $ledger, 'projection' => (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($ledger), 'detail_rows' => $details];
    }

    /** @return array<string, mixed> */
    private function task3b(): array
    {
        return ['run_id' => 1, 'run_attempt' => 1, 'artifact_digest' => 'sha256:'.str_repeat('a', 64), 'receipt_sha256' => str_repeat('b', 64), 'control_plane_sha' => str_repeat('c', 40), 'release_sha' => str_repeat('d', 40), 'release_name_sha256' => str_repeat('e', 64), 'database_state_sha256' => str_repeat('f', 64)];
    }

    private function withTask3bEnvironment(callable $callback): mixed
    {
        $task3b = $this->task3b();
        $environment = [
            'CAREER_1046_APPLICATION_ROOT' => dirname(__DIR__, 2),
            'CAREER_1046_TASK3B_RUN_ID' => (string) $task3b['run_id'],
            'CAREER_1046_TASK3B_RUN_ATTEMPT' => (string) $task3b['run_attempt'],
            'CAREER_1046_TASK3B_ARTIFACT_DIGEST' => $task3b['artifact_digest'],
            'CAREER_1046_TASK3B_RECEIPT_SHA256' => $task3b['receipt_sha256'],
            'CAREER_1046_TASK3B_CONTROL_PLANE_SHA' => $task3b['control_plane_sha'],
            'CAREER_1046_TASK3B_RELEASE_SHA' => $task3b['release_sha'],
            'CAREER_1046_TASK3B_RELEASE_NAME_SHA256' => $task3b['release_name_sha256'],
            'CAREER_1046_TASK3B_DATABASE_STATE_SHA256' => $task3b['database_state_sha256'],
        ];
        foreach ($environment as $name => $value) {
            putenv($name.'='.$value);
        }
        try {
            return $callback();
        } finally {
            foreach (array_keys($environment) as $name) {
                putenv($name);
            }
        }
    }
}
