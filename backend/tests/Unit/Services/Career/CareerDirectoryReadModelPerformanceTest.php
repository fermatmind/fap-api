<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentIdentity;
use App\Models\OccupationFamily;
use App\Services\Career\CareerDirectoryAuthorityService;
use App\Services\Career\CareerDirectoryReadModelBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerDirectoryReadModelPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_installed_corpus_qualification_is_reused_by_fresh_request_readers(): void
    {
        $authority = app(CareerContentV3CanonicalReader::class)->authority();
        $rows = array_map(static fn (string $slug): array => [
            'identity' => ['canonical_slug' => $slug],
            'titles' => ['canonical_en' => $slug, 'canonical_zh' => $slug],
            'seo_contract' => ['index_eligible' => true, 'index_state' => 'indexable', 'robots_policy' => 'index,follow'],
        ], $authority['slugs']);
        $observations = [];
        foreach (['zh-CN', 'en'] as $locale) {
            $model = app(CareerDirectoryReadModelBuilder::class)->build($rows, $locale, static fn (): bool => true);
            Cache::forever(PublicCareerAuthorityResponseCache::DIRECTORY_READ_MODEL_CACHE_KEY_PREFIX.':'.$locale, $model);
            $times = [];
            for ($i = 0; $i < 5; $i++) {
                $reader = new CareerContentV3CanonicalReader(new CareerContentV3AuthorityPackage, base_path());
                $service = new CareerDirectoryAuthorityService(app(PublicCareerAuthorityResponseCache::class), $reader);
                $started = hrtime(true);
                $payload = $service->payload($locale);
                $times[] = $this->elapsedMs($started);
                self::assertArrayNotHasKey('body_qualification', $payload);
            }
            self::assertLessThan(400, max($times), 'Installed-corpus request exceeded the existing cold-request budget.');
            $observations[$locale] = ['request_ms' => $times, 'indexable' => $payload['public_truth']['public_detail_indexable_count']];
            // The installed corpus can legitimately gain or lose authored locale bodies.
            // Check source semantics outside the timed request instead of freezing editorial state.
            foreach (['accountants-and-auditors', 'actuaries', 'actors'] as $slug) {
                $source = (new CareerContentV3AuthorityPackage)->pageFromIndexForRuntime($authority, $slug, $locale);
                $expected = CareerContentV3CanonicalReader::sourceHasPublicBody($source);
                self::assertSame($expected, $authority['entries'][$slug][$locale]['body_qualification']['has_public_body']);
                $selected = $service->payload($locale, query: $slug);
                $item = array_values(array_filter($selected['items'], static fn (array $item): bool => $item['slug'] === $slug))[0];
                self::assertSame($expected, $item['indexable']);
                self::assertSame($expected ? 'index,follow' : 'noindex,follow', $item['robots_policy']);
            }
        }
        fwrite(STDOUT, "\ncareer_installed_directory_metrics=".json_encode($observations, JSON_THROW_ON_ERROR)."\n");
    }

    public function test_untrusted_cache_qualification_cannot_override_installed_body_qualification(): void
    {
        $root = sys_get_temp_dir().'/career-directory-qualification-'.bin2hex(random_bytes(8));
        try {
            foreach ([false, true] as $hasBody) {
                foreach (['en', 'zh-CN'] as $locale) {
                    foreach (['legacy', 'active', 'lkg'] as $cacheState) {
                        Cache::flush();
                        $this->writeQualificationFixture($root, $hasBody);
                        $reader = $this->qualificationReader($root);
                        app()->instance(CareerCurrentIdentity::class, new CareerCurrentIdentity($reader));
                        $model = app(CareerDirectoryReadModelBuilder::class)->build([[
                            'identity' => ['canonical_slug' => 'actors'],
                            'titles' => ['canonical_en' => 'Actors', 'canonical_zh' => '演员'],
                            'seo_contract' => ['index_eligible' => true, 'index_state' => 'indexable', 'robots_policy' => 'index,follow'],
                        ]], $locale, static fn (): bool => true);
                        $model['body_qualification']['items']['actors'] = [
                            'file_sha256' => str_repeat('0', 64),
                            'source_content_sha256' => str_repeat('0', 64),
                            'has_public_body' => ! $hasBody,
                        ];
                        if ($cacheState === 'legacy') {
                            Cache::forever(PublicCareerAuthorityResponseCache::DIRECTORY_READ_MODEL_CACHE_KEY_PREFIX.':'.$locale, $model);
                        } else {
                            $base = PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':'.$locale;
                            Cache::forever($base.':'.$cacheState, 'old-source');
                            Cache::forever($base.':versions:old-source', $model);
                        }
                        $this->assertQualificationPayload($reader, $locale, $hasBody);

                        // Keep the entire old cache, but change the installed source in either direction.
                        $oldHash = $reader->fileEntry('actors', $locale)['source_content_sha256'];
                        $this->writeQualificationFixture($root, ! $hasBody);
                        $fresh = $this->qualificationReader($root);
                        app()->instance(CareerCurrentIdentity::class, new CareerCurrentIdentity($fresh));
                        self::assertNotSame($oldHash, $fresh->fileEntry('actors', $locale)['source_content_sha256']);
                        $this->assertQualificationPayload($fresh, $locale, ! $hasBody);
                    }
                }
            }
        } finally {
            File::deleteDirectory($root);
        }
    }

    private function assertQualificationPayload(CareerContentV3CanonicalReader $reader, string $locale, bool $hasBody): void
    {
        $payload = (new CareerDirectoryAuthorityService(app(PublicCareerAuthorityResponseCache::class), $reader))->payload($locale);
        self::assertSame(1, $payload['public_truth']['directory_member_count']);
        self::assertSame((int) $hasBody, $payload['public_truth']['public_detail_indexable_count']);
        self::assertTrue($payload['items'][0]['detail_ready']);
        self::assertSame($hasBody, $payload['items'][0]['indexable']);
        self::assertSame($hasBody ? 'indexable' : 'noindex', $payload['items'][0]['indexability_state']);
        self::assertSame($hasBody ? 'index,follow' : 'noindex,follow', $payload['items'][0]['robots_policy']);
        self::assertArrayNotHasKey('body_qualification', $payload);
    }

    private function qualificationReader(string $root): CareerContentV3CanonicalReader
    {
        return new CareerContentV3CanonicalReader(
            new CareerContentV3AuthorityPackage(1, 2, null, CareerCurrentAuthorityPackage::hashValue(['actors'])),
            $root,
        );
    }

    private function writeQualificationFixture(string $backendRoot, bool $hasBody): void
    {
        $root = $backendRoot.'/content_assets/career/current';
        File::ensureDirectoryExists($root.'/careers/actors');
        $files = [];
        foreach (['en', 'zh-CN'] as $locale) {
            $page = [
                'contract_version' => 'career.detail.content.v3', 'locale' => $locale,
                'subject' => ['canonical_slug' => 'actors', 'name' => $locale === 'en' ? 'Actors' : '演员', 'summary' => null],
                'content_state' => $hasBody ? 'enhanced' : 'legacy',
                'blocks' => $hasBody ? [[
                    'id' => 'profile', 'copy_key' => 'career.block.profile', 'content_state' => 'enhanced',
                    'availability' => 'available', 'items' => [[
                        'id' => 'profile-1', 'copy_key' => 'career.item.definition', 'type' => 'prose',
                        'availability' => 'available', 'data' => ['paragraphs' => ['Independent fixture body.']],
                    ]],
                ]] : [],
            ];
            $page['source_content_sha256'] = CareerCurrentAuthorityPackage::hashValue($page);
            $bytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($page);
            $path = 'careers/actors/'.$locale.'.json';
            File::put($root.'/'.$path, $bytes);
            $files[] = [
                'bytes' => strlen($bytes), 'canonical_slug' => 'actors', 'locale' => $locale, 'path' => $path,
                'sha256' => hash('sha256', $bytes), 'source_content_sha256' => $page['source_content_sha256'],
                'body_qualification' => [
                    'version' => CareerContentV3CanonicalReader::BODY_QUALIFICATION_VERSION,
                    'source_content_sha256' => $page['source_content_sha256'],
                    'has_public_body' => CareerContentV3CanonicalReader::sourceHasPublicBody($page),
                ],
            ];
        }
        $manifest = [
            'authority_path' => 'backend/content_assets/career/current',
            'compiler_version' => CareerContentV3AuthorityPackage::COMPILER_VERSION,
            'contract_version' => CareerContentV3AuthorityPackage::CONTRACT_VERSION,
            'coverage' => ['slugs' => 1, 'locales' => 2, 'locale_pages' => 2, 'files' => 2,
                'enhanced_locale_pages' => $hasBody ? 2 : 0, 'legacy_locale_pages' => $hasBody ? 0 : 2],
            'files' => $files, 'locales' => ['en', 'zh-CN'], 'schema_version' => CareerContentV3AuthorityPackage::SCHEMA_VERSION,
            'source_registry_sha256' => str_repeat('c', 64),
            'set_hashes' => [
                'slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue(['actors']),
                'locale_page_set_sha256' => CareerCurrentAuthorityPackage::hashValue(['actors|en', 'actors|zh-CN']),
                'source_semantic_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue(array_column($files, 'source_content_sha256')),
            ],
        ];
        $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($manifest);
        File::put($root.'/manifest.json', CareerCurrentAuthorityPackage::encodePrettyCanonical($manifest));
    }

    public function test_real_1046_and_10000_row_read_models_stay_inside_warm_budgets(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'synthetic-family',
            'title_en' => 'Synthetic family',
            'title_zh' => '合成行业',
        ]);

        $observations = [];
        foreach ([1046 => 300.0, 10000 => 500.0] as $count => $warmP95BudgetMs) {
            Cache::flush();
            $rows = $this->rows($count, (string) $family->id);
            $responseCache = app(PublicCareerAuthorityResponseCache::class);
            foreach ($rows as $row) {
                $slug = (string) data_get($row, 'identity.canonical_slug');
                $responseCache->publishJobDetailReadModel($slug, 'en', [
                    'identity' => ['canonical_slug' => $slug],
                    'fixture' => true,
                ]);
            }
            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            $memoryBefore = memory_get_usage(true);
            $buildStarted = hrtime(true);
            $readModel = app(CareerDirectoryReadModelBuilder::class)->build(
                $rows,
                'en',
                static fn (string $slug, string $locale): bool => true,
            );
            $buildElapsedMs = $this->elapsedMs($buildStarted);
            $queryCount = count(DB::getQueryLog());
            DB::connection()->disableQueryLog();
            $peakMemoryBytes = max(0, memory_get_peak_usage(true) - $memoryBefore);

            Cache::forever(
                PublicCareerAuthorityResponseCache::DIRECTORY_READ_MODEL_CACHE_KEY_PREFIX.':en',
                $readModel,
            );

            $samples = [];
            $lastPayload = [];
            for ($iteration = 0; $iteration < 20; $iteration++) {
                $started = hrtime(true);
                $lastPayload = app(CareerDirectoryAuthorityService::class)->payload('en', 1, 50);
                $samples[] = $this->elapsedMs($started);
            }

            sort($samples);
            $p95 = $samples[(int) ceil(count($samples) * 0.95) - 1];
            $p99 = $samples[(int) ceil(count($samples) * 0.99) - 1];
            $payloadBytes = strlen((string) json_encode($lastPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $this->assertCount($count, $readModel['items']);
            $this->assertSame($count, $lastPayload['public_truth']['directory_member_count']);
            $this->assertCount(50, $lastPayload['items']);
            $this->assertLessThanOrEqual($warmP95BudgetMs, $p95, "{$count}-row warm p95 exceeded budget");
            $this->assertLessThanOrEqual($warmP95BudgetMs, $p99, "{$count}-row warm p99 exceeded budget");
            $this->assertLessThanOrEqual(1, $queryCount, "{$count}-row read-model build issued unexpected queries");
            $this->assertLessThan(262144, $payloadBytes, "{$count}-row first-page payload exceeded 256 KiB");
            $this->assertLessThan(134217728, $peakMemoryBytes, "{$count}-row read-model build exceeded 128 MiB");
            $this->assertLessThan(2000.0, $buildElapsedMs, "{$count}-row read-model build exceeded offline build budget");

            $observations[(string) $count] = [
                'warm_p95_ms' => $p95,
                'warm_p99_ms' => $p99,
                'build_ms' => $buildElapsedMs,
                'db_queries' => $queryCount,
                'first_page_bytes' => $payloadBytes,
                'peak_memory_delta_bytes' => $peakMemoryBytes,
            ];
        }

        fwrite(STDOUT, "\ncareer_directory_read_model_metrics=".json_encode($observations, JSON_THROW_ON_ERROR)."\n");
    }

    public function test_directory_visibility_and_detail_readiness_remain_distinct(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'distinct-truth-family',
            'title_en' => 'Distinct truth family',
            'title_zh' => '独立真值行业',
        ]);
        $rows = $this->rows(2, (string) $family->id);
        $readySlug = (string) data_get($rows, '0.identity.canonical_slug');

        $readModel = app(CareerDirectoryReadModelBuilder::class)->build(
            $rows,
            'en',
            static fn (string $slug): bool => $slug === $readySlug,
        );

        $this->assertCount(2, $readModel['items']);
        $this->assertTrue($readModel['items'][0]['indexable']);
        $this->assertTrue($readModel['items'][0]['detail_ready']);
        $this->assertTrue($readModel['items'][1]['indexable']);
        $this->assertFalse($readModel['items'][1]['detail_ready']);
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $count, string $familyId): array
    {
        $rows = [];
        for ($index = 1; $index <= $count; $index++) {
            $slug = sprintf('synthetic-career-%05d', $index);
            $rows[] = [
                'identity' => [
                    'canonical_slug' => $slug,
                    'family_uuid' => $familyId,
                ],
                'titles' => [
                    'canonical_en' => sprintf('Synthetic Career %05d', $index),
                    'canonical_zh' => sprintf('合成职业 %05d', $index),
                ],
                'seo_contract' => [
                    'index_eligible' => true,
                    'index_state' => 'indexable',
                    'robots_policy' => 'index,follow',
                ],
                'provenance_meta' => [
                    'compiled_at' => '2026-07-11T00:00:00Z',
                ],
            ];
        }

        return $rows;
    }

    private function elapsedMs(int $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 3);
    }
}
