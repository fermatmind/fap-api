<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Domain\Career\Publish\Career1046DiscoverabilityReleaseGate;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\SEO\SitemapGenerator;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class Career1046DiscoverabilityReleaseCacheTransitionTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStoragePath = storage_path();
        $this->storageRoot = sys_get_temp_dir().'/career-1046-public-surfaces-'.bin2hex(random_bytes(8));
        mkdir($this->storageRoot.'/framework/cache', 0750, true);
        app()->useStoragePath($this->storageRoot);
        config([
            'app.frontend_url' => 'https://example.test',
            'services.seo.public_sitemap_authority' => 'backend',
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        app()->useStoragePath($this->originalStoragePath);
        if (isset($this->storageRoot)) {
            $this->remove($this->storageRoot);
        }
        parent::tearDown();
    }

    public function test_public_surfaces_rebuild_across_hold_release_and_authority_loss(): void
    {
        $fixture = $this->fixture(false);
        $this->publishDirectoryAuthority($fixture['slugs']);
        $target = $fixture['slugs'][0];
        $targetEn = 'https://example.test/en/career/jobs/'.$target;
        $targetZh = 'https://example.test/zh/career/jobs/'.$target;
        $nonTargetEn = 'https://example.test/en/career/jobs/existing-non-target-career';

        $held = $this->surfaceBodies();
        foreach ($held as $surface => $body) {
            self::assertStringNotContainsString($targetEn, $body, $surface);
            self::assertStringNotContainsString($targetZh, $body, $surface);
            self::assertStringContainsString($nonTargetEn, $body, $surface);
        }
        $this->writePermit($fixture);
        $gate = new Career1046DiscoverabilityReleaseGate;
        app()->instance(Career1046DiscoverabilityReleaseGate::class, $gate);
        $released = $this->surfaceBodies();
        self::assertSame(1, $gate->validationCount());
        foreach ($released as $surface => $body) {
            self::assertStringContainsString($targetEn, $body, $surface);
            self::assertStringContainsString($targetZh, $body, $surface);
            self::assertNotSame($held[$surface], $body, $surface);
        }
        self::assertSame(2092, $this->targetCareerUrlCount($released['sitemap'], $fixture['slugs']));
        self::assertSame(2092, $this->targetCareerUrlCount($released['llms'], $fixture['slugs']));
        self::assertSame(2092, $this->targetCareerUrlCount($released['llms-full'], $fixture['slugs']));
        self::assertSame(1046, count($this->targetSlugsInBody($released['llms'], $fixture['slugs'])));
        self::assertSame(1046, count($this->targetSlugsInBody($released['llms-full'], $fixture['slugs'])));

        app()->forgetInstance(Career1046DiscoverabilityReleaseGate::class);
        unlink($fixture['root'].'/active-generation.json');
        $withheldAgain = $this->surfaceBodies();
        foreach ($withheldAgain as $surface => $body) {
            self::assertStringNotContainsString($targetEn, $body, $surface);
            self::assertStringNotContainsString($targetZh, $body, $surface);
            self::assertStringContainsString($nonTargetEn, $body, $surface);
            self::assertNotSame($released[$surface], $body, $surface);
        }
    }

    public function test_malformed_pointer_and_permit_withhold_the_frozen_cohort_on_every_surface(): void
    {
        $fixture = $this->fixture();
        $this->publishDirectoryAuthority($fixture['slugs']);
        $targetUrl = 'https://example.test/en/career/jobs/'.$fixture['slugs'][0];
        $permitPath = $fixture['root'].'/discoverability-releases/'.$fixture['generation'].'/release.json';

        foreach (['pointer', 'permit'] as $malformed) {
            $this->restoreAuthority($fixture);
            file_put_contents($malformed === 'pointer' ? $fixture['root'].'/active-generation.json' : $permitPath, '{');
            $this->forgetSurfaceCaches();
            foreach ($this->surfaceBodies() as $surface => $body) {
                self::assertStringNotContainsString($targetUrl, $body, $malformed.'-'.$surface);
                self::assertStringContainsString('https://example.test/en/career/jobs/existing-non-target-career', $body, $malformed.'-'.$surface);
            }
        }
    }

    /** @return array{sitemap:string,llms:string,llms-full:string} */
    private function surfaceBodies(): array
    {
        $generator = app(SitemapGenerator::class);
        $sitemap = $generator->generate();
        $llms = implode("\n", array_column($generator->generateLlmsUrls(), 'loc'));

        return [
            'sitemap' => (string) ($sitemap['xml'] ?? ''),
            'llms' => $llms,
            'llms-full' => $llms,
        ];
    }

    /** @param list<string> $slugs */
    private function publishDirectoryAuthority(array $slugs): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $segment) {
            $items = [];
            foreach ([...$slugs, 'existing-non-target-career'] as $slug) {
                $items[] = [
                    'slug' => $slug,
                    'canonical_path' => '/'.$segment.'/career/jobs/'.$slug,
                    'indexable' => true,
                    'detail_ready' => true,
                    'updated_at' => '2026-08-12 00:00:00',
                ];
            }
            $cache->publishDirectoryReadModel($locale, ['items' => $items]);
        }
    }

    /** @param list<string> $slugs */
    private function targetCareerUrlCount(string $body, array $slugs): int
    {
        $targets = array_fill_keys($slugs, true);
        preg_match_all('#https://example\.test/(?:en|zh)/career/jobs/([a-z0-9-]+)#', $body, $matches);

        return count(array_filter($matches[1], static fn (string $slug): bool => isset($targets[$slug])));
    }

    /** @param list<string> $slugs @return list<string> */
    private function targetSlugsInBody(string $body, array $slugs): array
    {
        $targets = array_fill_keys($slugs, true);
        preg_match_all('#https://example\.test/(?:en|zh)/career/jobs/([a-z0-9-]+)#', $body, $matches);
        $found = array_values(array_unique(array_filter($matches[1], static fn (string $slug): bool => isset($targets[$slug]))));
        sort($found, SORT_STRING);

        return $found;
    }

    private function forgetSurfaceCaches(): void
    {
        foreach (['seo:sitemap:xml:v6', 'seo:sitemap:xml:v6:etag', 'seo:sitemap:xml:v6:career-authority-identity', 'seo:llms-txt:v1:body', 'seo:llms-txt:v1:body:career-authority-identity', 'seo:llms-full-txt:v1:body', 'seo:llms-full-txt:v1:body:career-authority-identity'] as $key) {
            Cache::forget($key);
        }
    }

    /** @return array{root:string,generation:string,slugs:list<string>,pointer:string,permit:array<string,mixed>} */
    private function fixture(bool $withPermit = true): array
    {
        $manifest = json_decode((string) file_get_contents(base_path('docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json')), true, 512, JSON_THROW_ON_ERROR);
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
        $pointerDocument = ['schema_version' => 'career.generation_pointer.v1', 'payload' => $pointerPayload, 'payload_sha256' => CareerGenerationCanonicalJson::sha256($pointerPayload)];
        $pointer = CareerGenerationCanonicalJson::encode($pointerDocument)."\n";
        file_put_contents($root.'/active-generation.json', $pointer);
        file_put_contents($generationRoot.'/generation-pointer.json', $pointer);
        $permitPayload = [
            'generation_id' => $generation, 'active_pointer_sha256' => hash('sha256', $pointer), 'immutable_pointer_sha256' => hash('sha256', $pointer),
            'task7a_run_id' => 1, 'task7a_run_attempt' => 1, 'task7a_artifact_digest' => 'sha256:'.str_repeat('b', 64), 'task7a_receipt_sha256' => str_repeat('c', 64), 'database_state_sha256' => str_repeat('d', 64),
            'target_slug_set_sha256' => Career1046DiscoverabilityReleaseGate::TARGET_SLUG_SET_SHA256, 'target_locale_row_set_sha256' => Career1046DiscoverabilityReleaseGate::TARGET_LOCALE_ROW_SET_SHA256,
            'slug_count' => 1046, 'locale_row_count' => 2092, 'document_sha256' => $documents, 'released_locale_rows' => $rows,
            'sitemap_released' => true, 'llms_released' => true, 'search_submission_enabled' => false,
        ];
        $permit = ['schema_version' => Career1046DiscoverabilityReleaseGate::SCHEMA_VERSION, 'payload' => $permitPayload, 'payload_sha256' => CareerGenerationCanonicalJson::sha256($permitPayload)];
        if ($withPermit) {
            $this->writePermit(compact('root', 'generation', 'permit'));
        }

        return compact('root', 'generation', 'slugs', 'pointer', 'permit');
    }

    /** @param array{root:string,generation:string,permit:array<string,mixed>} $fixture */
    private function writePermit(array $fixture): void
    {
        $root = $fixture['root'].'/discoverability-releases/'.$fixture['generation'];
        if (! is_dir($root)) {
            mkdir($root, 0750, true);
        }
        file_put_contents($root.'/release.json', CareerGenerationCanonicalJson::encode($fixture['permit'])."\n");
    }

    /** @param array{root:string,generation:string,pointer:string,permit:array<string,mixed>} $fixture */
    private function restoreAuthority(array $fixture): void
    {
        file_put_contents($fixture['root'].'/active-generation.json', $fixture['pointer']);
        $this->writePermit($fixture);
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
