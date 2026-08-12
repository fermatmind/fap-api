<?php

declare(strict_types=1);

namespace Tests\Sre;

use App\Domain\Career\Publish\Career1046DiscoverabilityReleaseGate;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
        self::assertFalse($gate->allows('not-in-generation', 'en'));
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
        self::assertFalse($gate->allows($fixture['slugs'][0], 'en'));
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
        ] as $required) {
            self::assertStringContainsString($required, $workflow);
        }
        foreach (['gh api', 'workflow_dispatch', 'Search', 'IndexNow', 'GSC', 'URL Inspection'] as $required) {
            self::assertStringContainsString($required, $workflow.$runner);
        }
        self::assertStringContainsString('return false;', $gate);
        self::assertStringContainsString('DISCOVERABILITY_RELEASE_ALREADY_EXISTS', $runner);
        self::assertStringNotContainsString('data_get(', $runner);
        self::assertStringNotContainsString('googleapis.com', $workflow.$runner);
    }

    /** @return array{root:string,generation:string,slugs:list<string>,permit:array<string,mixed>} */
    private function fixture(): array
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
            $bytes = '{"generation_id":"'.$generation.'","file":"'.$filename.'"}'."\n";
            file_put_contents($generationRoot.'/'.$filename, $bytes);
            $documents[$filename] = hash('sha256', $bytes);
        }
        ksort($documents, SORT_STRING);
        $pointerPayload = ['generation_id' => $generation, 'discoverability' => ['sitemap_mutated' => false, 'llms_mutated' => false, 'search_mutated' => false]];
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
        $permitRoot = $root.'/discoverability-releases/'.$generation;
        mkdir($permitRoot, 0750, true);
        file_put_contents($permitRoot.'/release.json', CareerGenerationCanonicalJson::encode($permit)."\n");

        return compact('root', 'generation', 'slugs', 'permit');
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
