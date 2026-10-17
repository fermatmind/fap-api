<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Support\PublicProjectionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\UsesCareerDetailCacheFixture;
use Tests\Fixtures\Career\CareerGenerationAuthorityFixture;
use Tests\TestCase;

final class CareerExposurePublicationAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use UsesCareerDetailCacheFixture;

    private IndexState $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installCareerDetailCacheFixture();
        $family = OccupationFamily::query()->create(['canonical_slug' => 'test-family', 'title_en' => 'Test', 'title_zh' => '测试']);
        $occupation = Occupation::query()->create(['family_id' => $family->id, 'canonical_slug' => 'actors',
            'canonical_title_en' => 'Actors', 'canonical_title_zh' => '演员', 'search_h1_zh' => '演员', 'entity_level' => 'market_child',
            'truth_market' => 'US', 'display_market' => 'US', 'crosswalk_mode' => 'global_standard']);
        $this->index = IndexState::query()->create(['occupation_id' => $occupation->id,
            'index_state' => 'indexed', 'index_eligible' => true, 'canonical_path' => '/career/jobs/actors', 'changed_at' => now()]);
        $this->generation('published_candidate');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/career_generation_authority'));
        parent::tearDown();
    }

    public function test_candidate_requires_a_current_generation_and_committed_index_state(): void
    {
        foreach (['en', 'zh-CN'] as $locale) {
            $version = $this->seedSnapshot($locale);
            $binding = PublicProjectionCache::get($this->base($locale).':exposure-projections:'.$version)['exposure_authority'];
            self::assertSame((string) $this->index->id, $binding['index_state_id']);
            self::assertSame(app(CareerRuntimePublishProjectionLookup::class)->publicationAuthoritySha256(), $binding['generation_sha256']);
            $this->assertPublic($locale, true);
        }
        foreach ([['noindex', false], ['promotion_candidate', true], ['indexed', false]] as [$state, $eligible]) {
            $this->index->forceFill(['index_state' => $state, 'index_eligible' => $eligible])->save();
            foreach (['en', 'zh-CN'] as $locale) {
                $this->assertPublic($locale, false);
            }
        }
        $this->index->forceFill(['index_state' => 'indexed', 'index_eligible' => true])->save();
        IndexState::query()->create(['occupation_id' => $this->index->occupation_id, 'index_state' => 'indexed',
            'index_eligible' => true, 'canonical_path' => '/career/jobs/actors', 'changed_at' => now()->addSecond()]);
        foreach (['en', 'zh-CN'] as $locale) {
            $this->assertPublic($locale, false);
        }
    }

    public function test_denied_missing_and_corrupt_authority_never_recovers_from_published_snapshots(): void
    {
        foreach (['blocked', 'quarantined', 'published'] as $state) {
            foreach (['detail_route_enabled', 'robots_indexable', 'release_gate_pass'] as $gate) {
                $this->generation($state, [$gate => false]);
                foreach (['en', 'zh-CN'] as $locale) {
                    $this->seedSnapshot($locale);
                    $this->assertPublic($locale, false);
                }
            }
        }
        $this->generation('published_candidate');
        foreach (['en', 'zh-CN'] as $locale) {
            $this->seedSnapshot($locale);
        }
        $this->generation('published_candidate'); // New generation, same indexed business row.
        foreach (['en', 'zh-CN'] as $locale) {
            $this->assertPublic($locale, false);
        }
        $this->generation('published', slug: 'actuaries');
        foreach (['en', 'zh-CN'] as $locale) {
            $this->assertPublic($locale, false);
        }
        File::put(storage_path('app/private/career_generation_authority/active-generation.json'), '{corrupt');
        $this->resetLookup();
        foreach (['en', 'zh-CN'] as $locale) {
            $this->assertPublic($locale, false);
        }
        $this->generation('quarantined', slug: 'software-developers');
        foreach (['en', 'zh-CN'] as $locale) {
            $cache = app(PublicCareerAuthorityResponseCache::class);
            $cache->publishJobDetailReadModel('software-developers', $locale,
                $this->detailCacheFixture([], 'software-developers', $locale), $this->item('published', $locale, 'software-developers'));
            self::assertFalse($cache->jobDetailProjectionItemIsPublished($cache->filePagePublication('software-developers', $locale)));
        }
    }

    public function test_unbound_legacy_snapshots_cannot_grant_candidate_publication(): void
    {
        foreach (['en', 'zh-CN'] as $locale) {
            $version = $this->seedSnapshot($locale);
            $key = $this->base($locale).':exposure-projections:'.$version;
            $snapshot = PublicProjectionCache::get($key);
            unset($snapshot['exposure_authority']);
            PublicProjectionCache::forever($key, $snapshot);
            $this->assertPublic($locale, false);
        }
    }

    public function test_valid_same_generation_projection_and_current_published_detail_lkg_still_work(): void
    {
        $this->generation('published');
        $root = storage_path('app/private/career_generation_authority');
        $document = json_decode(File::get($root.'/active-generation.json'), true, 512, JSON_THROW_ON_ERROR);
        $descriptor = $document['payload']['artifacts']['projection'];
        $lkg = $descriptor;
        $lkg['path'] = dirname($descriptor['path']).'/lkg-'.basename($descriptor['path']);
        File::copy($root.'/'.$descriptor['path'], $root.'/'.$lkg['path']);
        $document['payload']['artifacts']['projection_lkg'] = $lkg;
        $document['payload_sha256'] = CareerGenerationCanonicalJson::sha256($document['payload']);
        File::put($root.'/active-generation.json', json_encode($document, JSON_THROW_ON_ERROR));
        File::put($root.'/generations/'.$document['payload']['generation_id'].'/generation-pointer.json', json_encode($document, JSON_THROW_ON_ERROR));
        File::put($root.'/'.$descriptor['path'], '{corrupt-primary');
        $this->resetLookup();
        foreach (['en', 'zh-CN'] as $locale) {
            $cache = app(PublicCareerAuthorityResponseCache::class);
            self::assertTrue($cache->jobDetailProjectionItemIsPublished($cache->filePagePublication('actors', $locale)));
            $old = $cache->publishJobDetailReadModel('actors', $locale, $this->detailCacheFixture(['revision' => 1], 'actors', $locale));
            $new = $cache->publishJobDetailReadModel('actors', $locale, $this->detailCacheFixture(['revision' => 2], 'actors', $locale));
            PublicProjectionCache::forget($this->base($locale).':versions:'.$new);
            $read = $cache->jobDetailVerifyOnlyRead('actors', $locale);
            self::assertSame('stale', $read['state']);
            self::assertSame(1, $read['payload']['revision']);
            self::assertSame($old, PublicProjectionCache::get($this->base($locale).':lkg'));
        }
    }

    public function test_database_failure_never_turns_an_old_candidate_snapshot_into_permission(): void
    {
        foreach (['en', 'zh-CN'] as $locale) {
            $this->seedSnapshot($locale);
        }
        $connection = IndexState::resolveConnection();
        $unavailable = true;
        $connection->beforeExecuting(static function (string $query) use (&$unavailable): void {
            if ($unavailable && str_contains($query, 'index_states')) {
                throw new \PDOException('synthetic database unavailable');
            }
        });
        try {
            foreach (['en', 'zh-CN'] as $locale) {
                $cache = app(PublicCareerAuthorityResponseCache::class);
                self::assertFalse($cache->jobDetailProjectionItemIsPublished($cache->filePagePublication('actors', $locale)));
            }
        } finally {
            $unavailable = false;
        }
    }

    public function test_wrong_locale_or_version_directory_cache_uses_valid_lkg_and_otherwise_fails_closed(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $valid = app(\App\Services\Career\CareerDirectoryReadModelBuilder::class)->build([], $locale, static fn (): bool => true);
            foreach (['locale' => 'unknown', 'read_model_version' => 'unknown'] as $field => $invalidValue) {
                $cache->publishDirectoryReadModel($locale, $valid);
                $invalid = $valid;
                $invalid[$field] = $invalidValue;
                $cache->publishDirectoryReadModel($locale, $invalid);
                self::assertSame($valid, $cache->directoryReadModelPayload($locale));
            }
        }
        PublicProjectionCache::forget(PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':en:lkg');
        $this->expectException(\RuntimeException::class);
        $cache->directoryReadModelPayload('en');
    }

    public function test_cli_warm_never_restores_candidate_from_stale_or_unreadable_exposure(): void
    {
        foreach (['en', 'zh-CN'] as $locale) {
            foreach (['lkg_only', 'bad_payload', 'old_generation', 'dataset_hidden', 'revoked'] as $failure) {
                $this->index->forceFill(['index_state' => 'indexed', 'index_eligible' => true])->save();
                $this->generation('published_candidate');
                $cache = app(PublicCareerAuthorityResponseCache::class);
                $version = $this->seedSnapshot($locale);
                $cache->publishDirectoryReadModel($locale, [
                    'read_model_version' => \App\Services\Career\CareerDirectoryReadModelBuilder::READ_MODEL_VERSION,
                    'locale' => $locale,
                    'items' => [['slug' => 'actors', 'canonical_path' => '/career/jobs/actors', 'indexable' => true, 'detail_ready' => true]],
                ]);
                if ($failure === 'lkg_only') {
                    PublicProjectionCache::forever($this->base($locale).':lkg', $version);
                    PublicProjectionCache::forget($this->base($locale).':active');
                } elseif ($failure === 'bad_payload') {
                    PublicProjectionCache::forever($this->base($locale).':versions:'.$version, 'corrupt');
                } elseif ($failure === 'revoked') {
                    $this->index->forceFill(['index_state' => 'noindex', 'index_eligible' => false])->save();
                } else {
                    $key = $this->base($locale).':exposure-projections:'.$version;
                    $snapshot = PublicProjectionCache::get($key);
                    if ($failure === 'old_generation') {
                        $snapshot['exposure_authority']['generation_sha256'] = str_repeat('0', 64);
                    } else {
                        $snapshot['dataset_visible'] = false;
                    }
                    PublicProjectionCache::forever($key, $snapshot);
                }
                $cache->warmDirectoryReadModels([$locale]);
                self::assertSame([], $cache->directoryReadModelPayload($locale)['items'], $locale.':'.$failure);
            }
        }
    }

    public function test_candidate_qualification_stays_batched_in_directory_and_cli_warm(): void
    {
        $items = [];
        $slugs = [];
        for ($i = 0; $i < 64; $i++) {
            $slug = 'exposure-batch-'.$i;
            $occupation = Occupation::query()->create([
                'family_id' => OccupationFamily::query()->value('id'), 'canonical_slug' => $slug,
                'canonical_title_en' => $slug, 'canonical_title_zh' => '测试', 'search_h1_zh' => '测试',
                'entity_level' => 'market_child', 'truth_market' => 'US', 'display_market' => 'US', 'crosswalk_mode' => 'global_standard',
            ]);
            IndexState::query()->create(['occupation_id' => $occupation->id, 'index_state' => 'indexed',
                'index_eligible' => true, 'canonical_path' => '/career/jobs/'.$slug, 'changed_at' => now()]);
            $slugs[] = $slug;
            $items[] = $this->item('published_candidate', 'en', $slug);
        }
        CareerGenerationAuthorityFixture::write($items);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach ($slugs as $slug) {
            $cache->publishJobDetailReadModel($slug, 'en', $this->detailCacheFixture([], $slug, 'en'), $this->item('published', 'en', $slug));
        }
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        try {
            $started = hrtime(true);
            self::assertCount(64, $cache->directoryPublishedSlugs($slugs, 'en'));
            self::assertCount(1, \Illuminate\Support\Facades\DB::getQueryLog());
            self::assertLessThan(400, (hrtime(true) - $started) / 1_000_000);
            $queryCounts = [];
            foreach ([16, 64] as $size) {
                $cache->publishDirectoryReadModel('en', [
                    'read_model_version' => \App\Services\Career\CareerDirectoryReadModelBuilder::READ_MODEL_VERSION,
                    'locale' => 'en',
                    'items' => array_map(static fn (string $slug): array => [
                        'slug' => $slug, 'canonical_path' => '/en/career/jobs/'.$slug,
                        'indexable' => true, 'detail_ready' => true,
                    ], array_slice($slugs, 0, $size)),
                ]);
                \Illuminate\Support\Facades\DB::flushQueryLog();
                $cache->warmDirectoryReadModels(['en']);
                $queryCounts[] = count(\Illuminate\Support\Facades\DB::getQueryLog());
                self::assertCount($size, $cache->directoryReadModelPayload('en')['items']);
                self::assertLessThanOrEqual(10, $queryCounts[array_key_last($queryCounts)]);
            }
            self::assertSame($queryCounts[0], $queryCounts[1], 'CLI queries must not grow with the candidate cohort.');
            IndexState::query()->where('occupation_id', Occupation::query()->where('canonical_slug', $slugs[0])->value('id'))
                ->update(['index_state' => 'noindex', 'index_eligible' => false]);
            $cache->warmDirectoryReadModels(['en']);
            self::assertNotContains($slugs[0], array_column($cache->directoryReadModelPayload('en')['items'], 'slug'));
            self::assertCount(63, $cache->directoryReadModelPayload('en')['items']);
        } finally {
            \Illuminate\Support\Facades\DB::disableQueryLog();
        }
    }

    private function assertPublic(string $locale, bool $expected): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        self::assertSame($expected, $cache->jobDetailProjectionItemIsPublished($cache->filePagePublication('actors', $locale)));
        self::assertSame($expected, $cache->jobDetailPublicationSnapshot(['actors'], [$locale])['actors'][$locale]['published']);
        $this->getJson('/api/v0.5/career/jobs/actors?locale='.$locale.'&projection_contract=career.detail.presence.v1')
            ->assertStatus($expected ? 200 : 404)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v0.5/career-jobs/actors/seo?locale='.$locale.'&org_id=0')->assertStatus($expected ? 200 : 404);
        $model = app(\App\Services\Career\CareerDirectoryReadModelBuilder::class)->build([[
            'identity' => ['canonical_slug' => 'actors'], 'titles' => ['canonical_en' => 'Actors', 'canonical_zh' => '演员'],
            'seo_contract' => ['index_eligible' => true, 'index_state' => 'indexable', 'robots_policy' => 'index,follow'],
        ]], $locale, static fn (): bool => true);
        $cache->publishDirectoryReadModel($locale, $model);
        self::assertSame((int) $expected, app(\App\Services\Career\CareerDirectoryAuthorityService::class)->payload($locale)['public_truth']['directory_member_count']);
    }

    private function seedSnapshot(string $locale): string
    {
        return app(PublicCareerAuthorityResponseCache::class)->publishJobDetailReadModel('actors', $locale,
            $this->detailCacheFixture(['identity' => ['canonical_slug' => 'actors']], 'actors', $locale), $this->item('published', $locale));
    }

    private function generation(string $state, array $overrides = [], string $slug = 'actors'): void
    {
        CareerGenerationAuthorityFixture::write([
            array_merge($this->item($state, 'en', $slug), $overrides),
            array_merge($this->item($state, 'zh', $slug), $overrides),
        ]);
    }

    private function item(string $state, string $locale, string $slug = 'actors'): array
    {
        $published = $state === 'published';

        return ['slug' => $slug, 'locale' => $locale, 'runtime_publish_state' => $state,
            'public_resolution_type' => 'public_canonical_job', 'detail_route_enabled' => $published,
            'robots_indexable' => $published, 'release_gate_pass' => $published, 'dataset_visible' => $published,
            'search_visible' => $published, 'sitemap_live' => $published, 'llms_live' => $published,
            'llms_full_live' => $published, 'canonical_self' => $published, 'blockers' => []];
    }

    private function base(string $locale): string
    {
        return PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':actors:'.$locale;
    }

    private function resetLookup(): void
    {
        app()->forgetInstance(CareerRuntimePublishProjectionLookup::class);
        app()->forgetInstance(CareerRuntimePublishProjectionVisibility::class);
    }
}
