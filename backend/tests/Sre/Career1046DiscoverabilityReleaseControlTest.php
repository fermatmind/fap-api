<?php

declare(strict_types=1);

namespace Tests\Sre;

use App\Domain\Career\Publish\Career1046DiscoverabilityReleaseGate;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerRolloutReportAuthoritySigner;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class Career1046DiscoverabilityReleaseControlTest extends TestCase
{
    private string $storageRoot;

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStoragePath = storage_path();
        $this->storageRoot = sys_get_temp_dir().'/career-1046-discoverability-'.bin2hex(random_bytes(8));
        mkdir($this->storageRoot.'/framework/cache', 0750, true);
        app()->useStoragePath($this->storageRoot);
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStoragePath);
        if (isset($this->storageRoot)) {
            $this->remove($this->storageRoot);
        }
        parent::tearDown();
    }

    public function test_exact_same_generation_permit_releases_only_the_1046_bilingual_set(): void
    {
        $fixture = $this->fixture();
        $gate = new Career1046DiscoverabilityReleaseGate;

        self::assertTrue($gate->allows($fixture['slugs'][0], 'en'));
        self::assertTrue($gate->allows($fixture['slugs'][0], 'zh-CN'));
        self::assertTrue($gate->allows('not-in-generation', 'en'));
        self::assertSame(1, $gate->validationCount());
    }

    public function test_pointer_document_count_and_cross_generation_drift_fail_closed(): void
    {
        $fixture = $this->fixture();
        $gate = new Career1046DiscoverabilityReleaseGate;
        $permitPath = $fixture['root'].'/discoverability-releases/'.$fixture['generation'].'/release.json';
        $permit = json_decode((string) file_get_contents($permitPath), true, 512, JSON_THROW_ON_ERROR);

        $permit['payload']['locale_row_count'] = 2091;
        $permit['payload_sha256'] = CareerGenerationCanonicalJson::sha256($permit['payload']);
        file_put_contents($permitPath, CareerGenerationCanonicalJson::encode($permit)."\n");
        self::assertFalse($gate->allows($fixture['slugs'][0], 'en'));

        $permit = $fixture['permit'];
        $permit['payload']['generation_id'] = 'career-1046-'.str_repeat('b', 32);
        $permit['payload_sha256'] = CareerGenerationCanonicalJson::sha256($permit['payload']);
        file_put_contents($permitPath, CareerGenerationCanonicalJson::encode($permit)."\n");
        $gate = new Career1046DiscoverabilityReleaseGate;
        self::assertFalse($gate->allows($fixture['slugs'][0], 'en'));
    }

    public function test_missing_or_malformed_authority_withholds_only_the_frozen_cohort(): void
    {
        $fixture = $this->fixture(false);
        $target = $fixture['slugs'][0];
        $permitPath = $fixture['root'].'/discoverability-releases/'.$fixture['generation'].'/release.json';

        foreach (['missing-permit', 'malformed-permit', 'missing-pointer', 'malformed-pointer'] as $case) {
            $this->restorePointer($fixture);
            @unlink($permitPath);
            if ($case === 'malformed-permit') {
                mkdir(dirname($permitPath), 0750, true);
                file_put_contents($permitPath, '{');
            } elseif ($case === 'missing-pointer') {
                unlink($fixture['root'].'/active-generation.json');
            } elseif ($case === 'malformed-pointer') {
                file_put_contents($fixture['root'].'/active-generation.json', '{');
            }

            $gate = new Career1046DiscoverabilityReleaseGate;
            self::assertFalse($gate->allows($target, 'en'), $case);
            self::assertTrue($gate->allows('existing-non-target-career', 'en'), $case);
        }
    }

    public function test_one_gate_instance_validates_the_complete_authority_only_once(): void
    {
        $fixture = $this->fixture();
        $gate = new Career1046DiscoverabilityReleaseGate;

        foreach ($fixture['slugs'] as $slug) {
            self::assertTrue($gate->allows($slug, 'en'));
            self::assertTrue($gate->allows($slug, 'zh-CN'));
        }

        self::assertSame(1, $gate->validationCount());
    }

    public function test_real_runner_executes_preflight_then_receipt_bound_no_clobber_apply(): void
    {
        $fixture = $this->fixture(false);
        $database = $this->prepareRunnerDatabase();
        $this->writeRunnerRolloutReceipt();
        $common = $this->runnerEnvironment($fixture, $database);

        try {
            $preflight = $this->runControl('preflight', $common);
            self::assertSame('PASS_PREFLIGHT_DISCOVERABILITY_RELEASE', $preflight['status']);
            self::assertFalse($preflight['production_write_execution']);
            self::assertSame('confirmed_zero_write', $preflight['write_commit_state']);
            self::assertNull($preflight['preflight_receipt_sha256']);

            $preflightBytes = CareerGenerationCanonicalJson::encode($preflight)."\n";
            $preflightSha = hash('sha256', $preflightBytes);
            $applyEnvironment = [
                ...$common,
                'CAREER_DISCOVERABILITY_PREFLIGHT_RECEIPT_SHA256' => $preflightSha,
                'CAREER_DISCOVERABILITY_APPLY_AUTHORIZED' => '1',
                'CAREER_DISCOVERABILITY_OPERATOR_APPROVAL_PHRASE' => 'I explicitly approve Career 1046 discoverability release for generation '.$fixture['generation'].' from preflight receipt '.$preflightSha.'; release exactly 2092 sitemap locale URLs and 1046 llms slugs, keep Search, IndexNow, GSC, and URL Inspection disabled.',
            ];
            $apply = $this->runControl('apply', $applyEnvironment);
            self::assertSame('PASS_APPLY_DISCOVERABILITY_RELEASE', $apply['status']);
            self::assertSame($preflightSha, $apply['preflight_receipt_sha256']);
            self::assertTrue($apply['production_write_execution']);
            self::assertTrue($apply['writes_committed']);
            self::assertSame('committed', $apply['write_commit_state']);
            self::assertFileExists($fixture['root'].'/discoverability-releases/'.$fixture['generation'].'/release.json');

            $failure = $this->runControl('apply', $applyEnvironment, 1);
            self::assertSame('FAIL_DISCOVERABILITY_RELEASE_CONTROL', $failure['status']);
            self::assertSame('DISCOVERABILITY_RELEASE_ALREADY_EXISTS', $failure['failed_stage']);
            self::assertFalse($failure['production_write_execution']);
            self::assertSame(1, count(glob($fixture['root'].'/discoverability-releases/'.$fixture['generation'].'/release.json')));
        } finally {
            $this->restoreDefaultDatabase();
            @unlink($database);
        }
    }

    public function test_workflow_rejects_bad_or_expired_task7a_evidence_and_keeps_search_disabled(): void
    {
        $workflow = $this->repo('.github/workflows/career-1046-discoverability-release-control.yml');
        $runner = $this->repo('backend/scripts/operations/career_1046_discoverability_release_control.php');
        $gate = $this->repo('backend/app/Domain/Career/Publish/Career1046DiscoverabilityReleaseGate.php');

        foreach ([
            'workflow_dispatch:', 'actions: read', 'contents: read', 'environment: production',
            'and .expired==false and .digest==$digest', 'sha256sum "$task7a"',
            '.status=="PASS_PUBLIC_PRODUCT_VERIFY_ONLY"', '.counts.directory_en==1046',
            '.counts.detail_http_200==2092', '.search_submission_count==0',
            'PREFLIGHT_RECEIPT', 'I explicitly approve Career 1046 discoverability release',
            'Search, IndexNow, GSC, and URL Inspection disabled.',
            'runner_receipt="$(mktemp "$RUNNER_TEMP/career-1046-discoverability-runner.XXXXXX")"',
            'remote_apply_transport_ambiguous', '> "$runner_receipt" 2>/dev/null',
            'mv "$runner_receipt" "$receipt"', '.preflight_receipt_sha256==$preflight',
            '.write_commit_state=="committed"',
        ] as $required) {
            self::assertStringContainsString($required, $workflow);
        }
        foreach (['gh api', 'workflow_dispatch', 'Search', 'IndexNow', 'GSC', 'URL Inspection'] as $required) {
            self::assertStringContainsString($required, $workflow.$runner);
        }
        self::assertStringContainsString('return false;', $gate);
        self::assertStringContainsString('DISCOVERABILITY_RELEASE_ALREADY_EXISTS', $runner);
        self::assertStringContainsString('use FermatMind\\Operations\\CareerPublicationIndexReconciliationPreflight;', $runner);
        self::assertStringContainsString('@review-surface career_trust_manifest', $runner);
        self::assertStringNotContainsString('data_get(', $runner);
        self::assertStringNotContainsString('googleapis.com', $workflow.$runner);
    }

    /** @return array{root:string,generation:string,slugs:list<string>,permit:array<string,mixed>} */
    private function fixture(bool $withPermit = true): array
    {
        $manifest = json_decode((string) file_get_contents($this->repoPath('backend/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $slugs = array_values(array_unique([...$manifest['baseline_slugs'], ...$manifest['delta_slugs']]));
        sort($slugs, SORT_STRING);
        $rows = [];
        foreach ($slugs as $slug) {
            $rows[] = $slug.'|en';
            $rows[] = $slug.'|zh';
        }
        sort($rows, SORT_STRING);
        $generation = 'career-1046-'.str_repeat('a', 32);
        $root = storage_path('app/private/career_generation_authority');
        $generationRoot = $root.'/generations/'.$generation;
        mkdir($generationRoot, 0750, true);
        $documents = [];
        foreach (['generation-manifest.json', 'career-directory-en.json', 'career-directory-zh.json', 'career-job-details-en.json', 'career-job-details-zh.json'] as $filename) {
            $document = ['generation_id' => $generation, 'file' => $filename];
            if (str_starts_with($filename, 'career-directory-')) {
                $document['items'] = array_map(static fn (string $slug): array => ['slug' => $slug], $slugs);
            }
            $bytes = CareerGenerationCanonicalJson::encode($document)."\n";
            file_put_contents($generationRoot.'/'.$filename, $bytes);
            $documents[$filename] = hash('sha256', $bytes);
        }
        ksort($documents, SORT_STRING);
        $artifacts = [];
        foreach (['generation_manifest' => 'generation-manifest.json', 'directory_en' => 'career-directory-en.json', 'directory_zh' => 'career-directory-zh.json', 'detail_en' => 'career-job-details-en.json', 'detail_zh' => 'career-job-details-zh.json'] as $key => $filename) {
            $artifacts[$key] = ['path' => 'generations/'.$generation.'/'.$filename, 'sha256' => $documents[$filename]];
        }
        $pointerPayload = ['generation_id' => $generation, 'artifacts' => $artifacts, 'discoverability' => ['sitemap_mutated' => false, 'llms_mutated' => false, 'search_mutated' => false]];
        $pointer = ['schema_version' => 'career.generation_pointer.v1', 'payload' => $pointerPayload, 'payload_sha256' => CareerGenerationCanonicalJson::sha256($pointerPayload)];
        $pointerBytes = CareerGenerationCanonicalJson::encode($pointer)."\n";
        file_put_contents($root.'/active-generation.json', $pointerBytes);
        file_put_contents($generationRoot.'/generation-pointer.json', $pointerBytes);
        $permitPayload = [
            'generation_id' => $generation, 'active_pointer_sha256' => hash('sha256', $pointerBytes), 'immutable_pointer_sha256' => hash('sha256', $pointerBytes),
            'task7a_run_id' => 1, 'task7a_run_attempt' => 1, 'task7a_artifact_digest' => 'sha256:'.str_repeat('b', 64), 'task7a_receipt_sha256' => str_repeat('c', 64), 'database_state_sha256' => str_repeat('d', 64),
            'target_slug_set_sha256' => Career1046DiscoverabilityReleaseGate::TARGET_SLUG_SET_SHA256, 'target_locale_row_set_sha256' => Career1046DiscoverabilityReleaseGate::TARGET_LOCALE_ROW_SET_SHA256,
            'slug_count' => 1046, 'locale_row_count' => 2092, 'document_sha256' => $documents, 'released_locale_rows' => $rows,
            'sitemap_released' => true, 'llms_released' => true, 'search_submission_enabled' => false,
        ];
        $permit = ['schema_version' => Career1046DiscoverabilityReleaseGate::SCHEMA_VERSION, 'payload' => $permitPayload, 'payload_sha256' => CareerGenerationCanonicalJson::sha256($permitPayload)];
        if ($withPermit) {
            $permitRoot = $root.'/discoverability-releases/'.$generation;
            mkdir($permitRoot, 0750, true);
            file_put_contents($permitRoot.'/release.json', CareerGenerationCanonicalJson::encode($permit)."\n");
        }

        return compact('root', 'generation', 'slugs', 'permit');
    }

    /** @param array{root:string,generation:string,permit:array<string,mixed>} $fixture */
    private function restorePointer(array $fixture): void
    {
        copy(
            $fixture['root'].'/generations/'.$fixture['generation'].'/generation-pointer.json',
            $fixture['root'].'/active-generation.json',
        );
    }

    private function prepareRunnerDatabase(): string
    {
        $database = $this->storageRoot.'/runner.sqlite';
        touch($database);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $manifest = json_decode((string) file_get_contents($this->repoPath('backend/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $slugs = $manifest['delta_slugs'];
        $now = '2026-08-12 00:00:00';
        DB::table('occupation_families')->insert(['id' => 'runner-family', 'canonical_slug' => 'runner-family', 'title_en' => 'Runner', 'title_zh' => 'Runner', 'created_at' => $now, 'updated_at' => $now]);
        foreach (array_chunk($slugs, 100) as $chunkIndex => $chunk) {
            $occupations = [];
            $states = [];
            foreach ($chunk as $offset => $slug) {
                $index = $chunkIndex * 100 + $offset;
                $id = 'runner-occ-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
                $occupations[] = ['id' => $id, 'family_id' => 'runner-family', 'parent_id' => null, 'canonical_slug' => $slug, 'entity_level' => 'occupation', 'truth_market' => 'global', 'display_market' => 'global', 'crosswalk_mode' => 'canonical', 'canonical_title_en' => $slug, 'canonical_title_zh' => $slug, 'search_h1_zh' => $slug, 'created_at' => $now, 'updated_at' => $now];
                $states[] = ['id' => 'runner-state-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT), 'occupation_id' => $id, 'index_state' => 'indexed', 'index_eligible' => true, 'canonical_path' => '/en/career/jobs/'.$slug, 'canonical_target' => null, 'reason_codes' => json_encode(['canonical_rollout_batch_promotion'], JSON_THROW_ON_ERROR), 'changed_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('occupations')->insert($occupations);
            DB::table('index_states')->insert($states);
        }

        return $database;
    }

    private function writeRunnerRolloutReceipt(): void
    {
        $manifest = json_decode((string) file_get_contents($this->repoPath('backend/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $slugs = $manifest['delta_slugs'];
        $payload = [
            'status' => 'promoted_success', 'batch_id' => 'runner-1046', 'promoted_slugs' => $slugs,
            'promoted_locale_rows' => 2032, 'dry_run' => false, 'writes_database' => true, 'write_verified' => true,
            'persistence_check' => ['expected' => 2032, 'found_published' => 2032, 'not_published_count' => 0],
            'post_promotion_validation' => ['status' => 'pass'],
            'release_gate' => ['release_gate_pass_count' => 2032, 'release_gate_blocked_count' => 0],
            'remediation' => ['attempted' => false], 'rollback_required' => false, 'quarantine_required' => false,
        ];
        $payload['authority'] = app(CareerRolloutReportAuthoritySigner::class)->sign($payload);
        $root = $this->storageRoot.'/app/private/career_canonical_rollout_batch_executions';
        mkdir($root, 0750, true);
        file_put_contents($root.'/runner.json', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array{root:string,generation:string} $fixture @return array<string,string> */
    private function runnerEnvironment(array $fixture, string $database): array
    {
        $pointer = hash_file('sha256', $fixture['root'].'/active-generation.json');
        self::assertIsString($pointer);

        return [
            'APP_ENV' => 'testing', 'APP_KEY' => 'fap_api_phpunit_test_key_32bytes', 'LARAVEL_STORAGE_PATH' => $this->storageRoot,
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array',
            'CAREER_DISCOVERABILITY_BACKEND_ROOT' => $this->repoPath('backend'), 'CAREER_DISCOVERABILITY_AUTHORITY_ROOT' => $fixture['root'],
            'CAREER_DISCOVERABILITY_CONTROL_PLANE_SHA' => str_repeat('a', 40), 'CAREER_DISCOVERABILITY_RELEASE_SHA' => str_repeat('a', 40), 'CAREER_DISCOVERABILITY_RELEASE_NAME' => 'runner-release',
            'CAREER_DISCOVERABILITY_GENERATION_ID' => $fixture['generation'], 'CAREER_DISCOVERABILITY_ACTIVE_POINTER_SHA256' => $pointer,
            'CAREER_DISCOVERABILITY_TASK7A_RUN_ID' => '7', 'CAREER_DISCOVERABILITY_TASK7A_RUN_ATTEMPT' => '1', 'CAREER_DISCOVERABILITY_TASK7A_ARTIFACT_DIGEST' => 'sha256:'.str_repeat('b', 64), 'CAREER_DISCOVERABILITY_TASK7A_RECEIPT_SHA256' => str_repeat('c', 64),
            'CAREER_DISCOVERABILITY_WORKFLOW_RUN_ID' => '11', 'CAREER_DISCOVERABILITY_WORKFLOW_RUN_ATTEMPT' => '1',
        ];
    }

    /** @param array<string,string> $environment @return array<string,mixed> */
    private function runControl(string $mode, array $environment, int $expectedExit = 0): array
    {
        $process = new Process(['php', $this->repoPath('backend/scripts/operations/career_1046_discoverability_release_control.php'), $mode], null, $environment);
        $process->setTimeout(120);
        $process->run();
        self::assertSame($expectedExit, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function restoreDefaultDatabase(): void
    {
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
    }

    private function repo(string $path): string
    {
        return (string) file_get_contents($this->repoPath($path));
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 3).'/'.$path;
    }

    private function remove(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
}
