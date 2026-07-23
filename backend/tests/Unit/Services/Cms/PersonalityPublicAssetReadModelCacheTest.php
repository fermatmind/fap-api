<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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

    public function test_active_collection_reads_are_lock_free_and_do_not_refresh_cache_state(): void
    {
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $cacheManager = Cache::getFacadeRoot();
        $version = 'collection:v1:projection:public-review-contract-v1';
        $payload = ['ok' => true, 'items' => [['code' => 'openness']]];

        try {
            Cache::shouldReceive('get')
                ->times(10)
                ->ordered()
                ->andReturn(
                    'baseline',
                    $version,
                    $payload,
                    $version,
                    'baseline',
                    'baseline',
                    $version,
                    $payload,
                    $version,
                    'baseline',
                );
            Cache::shouldReceive('lock')->never();
            Cache::shouldReceive('put')->never();
            Cache::shouldReceive('forget')->never();

            for ($attempt = 0; $attempt < 2; $attempt++) {
                self::assertSame([
                    'state' => 'fresh',
                    'payload' => $payload,
                ], $cache->readActiveCollection(
                    'big_five',
                    'all',
                    'page:1:per-page:100',
                    'en',
                    0,
                    'public-review-contract-v1',
                ));
            }
        } finally {
            Cache::swap($cacheManager);
        }
    }

    public function test_active_collection_read_rejects_pointer_and_fence_drift(): void
    {
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $cacheManager = Cache::getFacadeRoot();
        $version = 'collection:v1:projection:public-review-contract-v1';
        $payload = ['ok' => true, 'items' => [['code' => 'openness']]];

        try {
            Cache::shouldReceive('get')
                ->times(5)
                ->ordered()
                ->andReturn('baseline', $version, $payload, 'collection:v2:projection:public-review-contract-v1', 'baseline');

            self::assertSame('miss', $cache->readActiveCollection(
                'big_five',
                'all',
                'page:1:per-page:100',
                'en',
                0,
                'public-review-contract-v1',
            )['state']);
        } finally {
            Cache::swap($cacheManager);
        }

        $cacheManager = Cache::getFacadeRoot();

        try {
            Cache::shouldReceive('get')
                ->times(5)
                ->ordered()
                ->andReturn('baseline', $version, $payload, $version, 'rotated-fence');

            self::assertSame('miss', $cache->readActiveCollection(
                'big_five',
                'all',
                'page:1:per-page:100',
                'en',
                0,
                'public-review-contract-v1',
            )['state']);
        } finally {
            Cache::swap($cacheManager);
        }
    }

    public function test_collection_reads_reject_legacy_projection_versions_and_accept_current_lkg(): void
    {
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $selector = 'page:1:per-page:100';
        $payload = ['ok' => true, 'items' => [['code' => 'openness']]];
        $version = 'collection:v1:projection:public-review-contract-v1';

        $cache->put('index', 'big_five', 'all', $selector, 'en', 0, $version, $payload);
        $cache->invalidateCollections('big_five', 'all', 'en', 0, true);

        self::assertSame([
            'state' => 'stale',
            'payload' => $payload,
        ], $cache->staleCollection(
            'big_five',
            'all',
            $selector,
            'en',
            0,
            'public-review-contract-v1',
        ));

        Cache::put(
            $cache->lkgKey('index', 'big_five', 'all', $selector, 'en'),
            'collection:legacy',
        );

        self::assertSame('miss', $cache->staleCollection(
            'big_five',
            'all',
            $selector,
            'en',
            0,
            'public-review-contract-v1',
        )['state']);
    }

    public function test_signed_verify_only_requests_bypass_active_and_stale_collection_cache_reads(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('v', 32))]);
        $url = 'https://api.fermatmind.com/api/v0.5/personality-content-assets?framework=big_five&locale=en';
        $headers = PersonalityPublicAssetReadModelCache::signedVerifyOnlyHeaders('GET', $url);
        $request = Request::create($url, 'GET', server: [
            'HTTP_X_FERMAT_VERIFY_ONLY_TIMESTAMP' => $headers[PersonalityPublicAssetReadModelCache::VERIFY_ONLY_TIMESTAMP_HEADER],
            'HTTP_X_FERMAT_VERIFY_ONLY_SIGNATURE' => $headers[PersonalityPublicAssetReadModelCache::VERIFY_ONLY_SIGNATURE_HEADER],
            'HTTP_AUTHORIZATION' => $headers['Authorization'],
        ]);
        $this->app->instance('request', $request);
        Cache::spy();
        $cache = app(PersonalityPublicAssetReadModelCache::class);

        self::assertSame([
            'state' => 'bypass',
            'payload' => null,
        ], $cache->readActiveCollection(
            'big_five',
            'all',
            'page:1:per-page:100',
            'en',
            0,
            'public-review-contract-v1',
        ));
        self::assertSame([
            'state' => 'bypass',
            'payload' => null,
        ], $cache->staleCollection(
            'big_five',
            'all',
            'page:1:per-page:100',
            'en',
            0,
            'public-review-contract-v1',
        ));

        Cache::shouldNotHaveReceived('get');
        Cache::shouldNotHaveReceived('put');
        Cache::shouldNotHaveReceived('lock');
        Cache::shouldNotHaveReceived('forget');
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

    public function test_withdrawal_fence_blocks_in_flight_detail_and_collection_pointer_repair(): void
    {
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $detailPayload = ['asset' => ['title' => 'Openness']];
        $detailFence = $cache->captureFence(
            'detail-code', 'big_five', 'domain', 'openness', 'en', 0
        );
        $cache->put(
            'detail-code', 'big_five', 'domain', 'openness', 'en', 0, 'detail-v1', $detailPayload, $detailFence
        );

        $cache->invalidate('detail-code', 'big_five', 'domain', 'openness', 'en', 0, false);

        self::assertSame('miss', $cache->read(
            'detail-code', 'big_five', 'domain', 'openness', 'en', 0, 'detail-v1', $detailFence
        )['state']);
        $cache->put(
            'detail-code', 'big_five', 'domain', 'openness', 'en', 0, 'detail-v1', $detailPayload, $detailFence
        );
        self::assertSame('miss', $cache->stale(
            'detail-code', 'big_five', 'domain', 'openness', 'en', 0
        )['state']);

        $selector = 'page:1:per-page:50';
        $collectionPayload = ['items' => [['code' => 'openness']]];
        $collectionFence = $cache->captureFence(
            'index', 'big_five', 'domain', $selector, 'en', 0
        );

        $cache->invalidateCollections('big_five', 'domain', 'en', 0, false);
        $cache->put(
            'index',
            'big_five',
            'domain',
            $selector,
            'en',
            0,
            'collection-v1',
            $collectionPayload,
            $collectionFence,
        );

        self::assertSame('miss', $cache->stale(
            'index', 'big_five', 'domain', $selector, 'en', 0
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

    public function test_collection_registry_eviction_clears_stale_pointers_before_withdrawal(): void
    {
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $payload = ['items' => [['code' => 'openness']]];

        for ($page = 1; $page <= 201; $page++) {
            $selector = "page:{$page}:per-page:1";
            $cache->put('index', 'big_five', 'domain', $selector, 'en', 0, "domains-v{$page}", $payload);
        }

        self::assertSame('miss', $cache->stale(
            'index', 'big_five', 'domain', 'page:1:per-page:1', 'en', 0
        )['state']);
        self::assertSame('stale', $cache->stale(
            'index', 'big_five', 'domain', 'page:2:per-page:1', 'en', 0
        )['state']);

        $cache->invalidateCollections('big_five', 'domain', 'en', 0, false);

        self::assertSame('miss', $cache->stale(
            'index', 'big_five', 'domain', 'page:2:per-page:1', 'en', 0
        )['state']);
        self::assertSame('miss', $cache->stale(
            'index', 'big_five', 'domain', 'page:201:per-page:1', 'en', 0
        )['state']);
    }

    public function test_asset_invalidation_reports_cache_failures_without_throwing(): void
    {
        $cacheManager = Cache::getFacadeRoot();

        try {
            Cache::partialMock()
                ->shouldReceive('forget')
                ->andThrow(new RuntimeException('simulated cache failure'));

            $invalidated = app(PersonalityPublicAssetReadModelCache::class)->invalidateAsset(
                'big_five',
                'domain',
                'openness',
                'big-five/openness',
                'en',
                0,
                false,
            );

            self::assertFalse($invalidated);
        } finally {
            Cache::swap($cacheManager);
        }
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
