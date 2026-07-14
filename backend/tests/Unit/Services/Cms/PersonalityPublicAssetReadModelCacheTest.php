<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class PersonalityPublicAssetReadModelCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_it_caches_only_anonymous_big_five_and_enneagram_public_assets(): void
    {
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $payload = ['ok' => true, 'asset' => ['code' => 'openness']];

        $cache->put('detail-code', 'big_five', 'domain', 'openness', 'en', 0, 'v1', $payload);
        $cache->put('detail-code', 'big_five', 'domain', 'openness', 'en', 7, 'v1', $payload);
        $cache->put('detail-code', 'mbti', 'domain', 'openness', 'en', 0, 'v1', $payload);

        self::assertSame($payload, $cache->read(
            'detail-code', 'big_five', 'domain', 'openness', 'en', 0, 'v1'
        )['payload']);
        self::assertSame('bypass', $cache->read(
            'detail-code', 'big_five', 'domain', 'openness', 'en', 7, 'v1'
        )['state']);
        self::assertSame('bypass', $cache->read(
            'detail-code', 'mbti', 'domain', 'openness', 'en', 0, 'v1'
        )['state']);
    }

    public function test_version_switch_keeps_previous_payload_as_lkg(): void
    {
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $old = ['asset' => ['title' => 'Old openness']];
        $new = ['asset' => ['title' => 'New openness']];

        $cache->put('detail-code', 'big_five', 'domain', 'openness', 'en', 0, 'v1', $old);
        $cache->put('detail-code', 'big_five', 'domain', 'openness', 'en', 0, 'v2', $new);

        self::assertSame('v2', Cache::get($cache->activeKey(
            'detail-code', 'big_five', 'domain', 'openness', 'en'
        )));
        self::assertSame('v1', Cache::get($cache->lkgKey(
            'detail-code', 'big_five', 'domain', 'openness', 'en'
        )));

        Cache::forget($cache->key('detail-code', 'big_five', 'domain', 'openness', 'en', 'v2'));

        self::assertSame([
            'state' => 'stale',
            'payload' => $old,
        ], $cache->stale('detail-code', 'big_five', 'domain', 'openness', 'en', 0));
    }

    public function test_update_invalidation_preserves_lkg_but_withdrawal_clears_it(): void
    {
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $payload = ['asset' => ['title' => 'Type 1']];
        $cache->put('detail-code', 'enneagram', 'core_type', 'type-1', 'zh-CN', 0, 'v1', $payload);

        $cache->invalidateAsset('enneagram', 'core_type', 'type-1', 'enneagram/type-1', 'zh-CN', 0, true);

        self::assertFalse(Cache::has($cache->activeKey(
            'detail-code', 'enneagram', 'core_type', 'type-1', 'zh-CN'
        )));
        self::assertSame([
            'state' => 'stale',
            'payload' => $payload,
        ], $cache->stale('detail-code', 'enneagram', 'core_type', 'type-1', 'zh-CN', 0));

        $cache->invalidateAsset('enneagram', 'core_type', 'type-1', 'enneagram/type-1', 'zh-CN', 0, false);

        self::assertSame('miss', $cache->stale(
            'detail-code', 'enneagram', 'core_type', 'type-1', 'zh-CN', 0
        )['state']);
    }

    public function test_collection_invalidation_targets_exact_and_all_entity_families(): void
    {
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $domainSelector = 'page:1:per-page:50';
        $allSelector = 'page:1:per-page:100';
        $domainPayload = ['items' => [['code' => 'openness']]];
        $allPayload = ['items' => [['code' => 'openness'], ['code' => 'agreeableness']]];

        $cache->put('index', 'big_five', 'domain', $domainSelector, 'en', 0, 'domains-v1', $domainPayload);
        $cache->put('index', 'big_five', 'all', $allSelector, 'en', 0, 'all-v1', $allPayload);
        $cache->invalidateCollections('big_five', 'domain', 'en', 0, true);

        self::assertSame($domainPayload, $cache->stale(
            'index', 'big_five', 'domain', $domainSelector, 'en', 0
        )['payload']);
        self::assertSame($allPayload, $cache->stale(
            'index', 'big_five', 'all', $allSelector, 'en', 0
        )['payload']);
        self::assertFalse(Cache::has($cache->activeKey(
            'index', 'big_five', 'domain', $domainSelector, 'en'
        )));
        self::assertFalse(Cache::has($cache->activeKey(
            'index', 'big_five', 'all', $allSelector, 'en'
        )));
    }

    public function test_versions_cover_all_persisted_asset_attributes_and_logs_are_low_cardinality(): void
    {
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $asset = (new PersonalityPublicContentAsset)->forceFill([
            'id' => 41,
            'framework' => 'big_five',
            'entity_type' => 'domain',
            'entity_key' => 'openness',
            'locale' => 'en',
            'title' => 'Openness',
        ]);
        $firstVersion = $cache->versionFor($asset);
        $asset->title = 'Updated openness';

        self::assertNotSame($firstVersion, $cache->versionFor($asset));

        $loggedMessage = null;
        $loggedContext = null;
        Log::shouldReceive('info')
            ->once()
            ->andReturnUsing(function (string $message, array $context) use (&$loggedMessage, &$loggedContext): void {
                $loggedMessage = $message;
                $loggedContext = $context;
            });

        $cache->read('detail-code', 'big_five', 'domain', 'openness', 'en', 0, 'missing');

        self::assertSame('personality_public_asset_read_model_cache', $loggedMessage);
        self::assertSame([
            'surface' => 'detail-code',
            'framework' => 'big_five',
            'entity_type' => 'domain',
            'locale' => 'en',
            'cache_state' => 'miss',
        ], $loggedContext);
    }
}
