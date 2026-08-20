<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityCacheGateway;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerCurrentAuthorityPublisher;
use App\Domain\Career\Display\CareerCurrentAuthorityPublisherFailure;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\Review\CareerJobDetailReaderSafeReviewProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CareerCurrentAuthorityPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_in_place_deletes_historical_rows_switches_cache_and_replays_as_zero_write(): void
    {
        [$authority, $target, $old] = $this->fixture();
        $cache = new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows']);
        $publisher = $this->publisher($authority, $cache);

        $result = $publisher->execute(base_path(), true);

        self::assertSame(1, $result['write_counts']['database_update_count']);
        self::assertSame(0, $result['write_counts']['database_insert_count']);
        self::assertSame(2, $result['write_counts']['database_delete_count']);
        self::assertSame(4, $result['write_counts']['cache_candidate_write_count']);
        self::assertSame(2, $result['write_counts']['cache_pointer_activation_count']);
        self::assertSame(2, $result['public_readback']['verified_locale_page_count']);
        self::assertFalse($result['idempotent_noop']);
        $row = CareerJobDisplayAsset::query()->sole();
        self::assertSame($target['id'], (string) $row->id);
        self::assertSame('new title', data_get($row->page_payload_json, 'en.hero.title'));
        self::assertNotSame('old title', data_get($row->page_payload_json, 'en.hero.title'));

        $replay = $publisher->execute(base_path());
        self::assertTrue($replay['idempotent_noop']);
        self::assertSame(0, array_sum($replay['write_counts']));
        self::assertSame($old['id'], $target['id']);
    }

    public function test_it_inserts_only_when_the_package_slug_has_one_occupation(): void
    {
        [$authority] = $this->fixture();
        $occupation = Occupation::query()->create([
            'family_id' => OccupationFamily::query()->firstOrFail()->id,
            'canonical_slug' => 'new-current-career',
            'entity_level' => 'occupation',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct',
            'canonical_title_en' => 'New Current Career',
            'canonical_title_zh' => '新职业',
            'search_h1_zh' => '新职业',
        ]);
        $authority['rows']['new-current-career'] = $this->row('new-current-career', 'new current title');
        $authority['slugs'][] = 'new-current-career';
        sort($authority['slugs'], SORT_STRING);
        $authority['summary']['career_count'] = 2;
        $authority['summary']['locale_page_count'] = 4;
        $cache = new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows']);

        $result = $this->publisher($authority, $cache)->execute(base_path(), true);

        self::assertSame(1, $result['write_counts']['database_insert_count']);
        self::assertSame(2, CareerJobDisplayAsset::query()->count());
        $inserted = CareerJobDisplayAsset::query()->where('canonical_slug', 'new-current-career')->sole();
        self::assertSame((string) $occupation->id, (string) $inserted->occupation_id);
        self::assertSame('new current title', data_get($inserted->page_payload_json, 'en.hero.title'));
    }

    public function test_full_scan_repairs_active_cache_drift_when_database_is_already_current(): void
    {
        [$authority] = $this->fixture();
        $cache = new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows']);
        $publisher = $this->publisher($authority, $cache);
        $publisher->execute(base_path(), true);
        $cache->serveStaleActive = true;

        $result = $publisher->execute(base_path(), true);

        self::assertSame(0, $result['write_counts']['database_update_count']);
        self::assertSame(4, $result['write_counts']['cache_candidate_write_count']);
        self::assertSame(2, $result['write_counts']['cache_pointer_activation_count']);
        self::assertSame(2, $result['public_readback']['verified_locale_page_count']);
        self::assertFalse($result['idempotent_noop']);
        self::assertFalse($cache->serveStaleActive);
    }

    public function test_it_classifies_cache_candidate_exceptions_and_restores_database(): void
    {
        [$authority, , $old] = $this->fixture();
        $cache = new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows'], 'prepare_exception');

        try {
            $this->publisher($authority, $cache)->execute(base_path(), true);
            self::fail('Expected controlled publisher failure.');
        } catch (CareerCurrentAuthorityPublisherFailure $failure) {
            self::assertSame('CURRENT_CACHE_CANDIDATE_PREPARATION_FAILED', $failure->safeCode);
            self::assertSame('rolled_back', $failure->writeCommitState);
        }

        self::assertSame('old title', data_get(
            CareerJobDisplayAsset::query()->findOrFail($old['id'])->page_payload_json,
            'en.hero.title',
        ));
        self::assertTrue($cache->forgotten);
    }

    public function test_it_rejects_accountants_without_a_non_empty_boundary_notice_before_writes(): void
    {
        [$authority] = $this->fixture();
        $authority['rows']['accountants-and-auditors'] = $this->row('accountants-and-auditors', 'Accountants');
        $authority['slugs'][] = 'accountants-and-auditors';
        sort($authority['slugs'], SORT_STRING);
        $authority['summary']['career_count'] = 2;
        $authority['summary']['locale_page_count'] = 4;

        $this->expectException(CareerCurrentAuthorityPublisherFailure::class);
        $this->expectExceptionMessage('CURRENT_ACCOUNTANTS_BOUNDARY_READBACK_INVALID');

        $this->publisher(
            $authority,
            new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows']),
        )->execute(base_path());
    }

    #[DataProvider('compensatedFailureProvider')]
    public function test_it_restores_database_and_cache_boundary_after_post_commit_failure(string $mode): void
    {
        [$authority, , $old] = $this->fixture();
        $cache = new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows'], $mode);
        $publisher = $this->publisher($authority, $cache);

        try {
            $publisher->execute(base_path(), true);
            self::fail('Expected controlled publisher failure.');
        } catch (CareerCurrentAuthorityPublisherFailure $failure) {
            self::assertSame('rolled_back', $failure->writeCommitState);
        }

        self::assertCount(3, CareerJobDisplayAsset::query()->get());
        $restored = CareerJobDisplayAsset::query()->findOrFail($old['id']);
        self::assertSame('old title', data_get($restored->page_payload_json, 'en.hero.title'));
        self::assertTrue($cache->forgotten);
        self::assertSame($mode === 'readback_failure', $cache->restored);
    }

    /** @return iterable<string,array{string}> */
    public static function compensatedFailureProvider(): iterable
    {
        yield 'candidate preparation' => ['prepare_failure'];
        yield 'candidate content validation' => ['prepared_content_failure'];
        yield 'pointer activation' => ['activation_failure'];
        yield 'post activation readback' => ['readback_failure'];
    }

    /** @return array{array<string,mixed>,array<string,mixed>,array<string,mixed>} */
    private function fixture(): array
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'fixture-family',
            'title_en' => 'Fixture',
            'title_zh' => '测试',
        ]);
        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'actors',
            'entity_level' => 'occupation',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct',
            'canonical_title_en' => 'Actors',
            'canonical_title_zh' => '演员',
            'search_h1_zh' => '演员',
        ]);
        $outsideOccupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'outside-current',
            'entity_level' => 'occupation',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct',
            'canonical_title_en' => 'Outside',
            'canonical_title_zh' => '包外',
            'search_h1_zh' => '包外',
        ]);

        $oldRow = $this->row('actors', 'old title');
        $old = CareerJobDisplayAsset::query()->create($oldRow + ['occupation_id' => $occupation->id]);
        CareerJobDisplayAsset::query()->create($this->row('actors', 'historical', [
            'asset_version' => 'v4.1',
            'template_version' => 'v4.1',
            'asset_role' => 'historical_master',
            'status' => 'retired',
        ]) + ['occupation_id' => $occupation->id]);
        CareerJobDisplayAsset::query()->create($this->row('outside-current', 'outside') + [
            'occupation_id' => $outsideOccupation->id,
        ]);

        $target = $this->row('actors', 'new title');
        $authority = [
            'rows' => ['actors' => $target],
            'slugs' => ['actors'],
            'summary' => [
                'assets_sha256' => str_repeat('a', 64),
                'career_count' => 1,
                'locale_page_count' => 2,
                'components_per_page' => 26,
            ],
        ];

        return [$authority, ['id' => (string) $old->id] + $target, ['id' => (string) $old->id] + $oldRow];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function row(string $slug, string $title, array $overrides = []): array
    {
        $page = array_fill_keys(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER, ['value' => 'verified']);
        $page['hero'] = ['title' => $title];

        return array_replace([
            'canonical_slug' => $slug,
            'surface_version' => CareerCurrentAuthorityPackage::SURFACE_VERSION,
            'asset_version' => CareerCurrentAuthorityPackage::ASSET_VERSION,
            'template_version' => CareerCurrentAuthorityPackage::ASSET_VERSION,
            'asset_type' => CareerCurrentAuthorityPackage::ASSET_TYPE,
            'asset_role' => CareerCurrentAuthorityPackage::ASSET_ROLE,
            'status' => CareerCurrentAuthorityPackage::READY_STATUS,
            'component_order_json' => CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
            'page_payload_json' => ['en' => $page, 'zh' => $page],
            'seo_payload_json' => ['title' => $title],
            'sources_json' => ['references' => []],
            'structured_data_json' => ['@type' => 'Occupation'],
            'implementation_contract_json' => ['version' => 'v1'],
            'metadata_json' => ['authority' => 'fixture'],
            'import_run_id' => null,
        ], $overrides);
    }

    /** @param array<string,mixed> $authority */
    private function publisher(array $authority, FakeCareerCurrentAuthorityCacheGateway $cache): CareerCurrentAuthorityPublisher
    {
        $loader = new class($authority) extends CareerCurrentAuthorityPackageLoader
        {
            /** @param array<string,mixed> $authority */
            public function __construct(private readonly array $authority) {}

            public function load(string $backendRoot): array
            {
                return $this->authority;
            }
        };

        return new CareerCurrentAuthorityPublisher(
            new CareerCurrentAuthorityPackage,
            $loader,
            $cache,
            new CareerJobDetailReaderSafeReviewProjector,
        );
    }
}

final class FakeCareerCurrentAuthorityCacheGateway extends CareerCurrentAuthorityCacheGateway
{
    public bool $restored = false;

    public bool $forgotten = false;

    public bool $serveStaleActive = false;

    /** @param array<string,array<string,mixed>> $rows */
    public function __construct(
        private readonly CareerCurrentAuthorityPackage $package,
        private readonly array $rows,
        private readonly string $mode = 'pass',
    ) {}

    public function prepare(string $slug, string $locale): array
    {
        if ($this->mode === 'prepare_exception') {
            throw new \RuntimeException('Synthetic cache infrastructure failure.');
        }
        if ($this->mode === 'prepare_failure') {
            return ['status' => 'blocked', 'classification' => 'missing_pointer'];
        }

        return ['slug' => $slug, 'locale' => $locale, 'version' => 'candidate-'.$locale, 'status' => 'ready', 'classification' => 'ready_staged'];
    }

    public function preparedPayload(array $entry): ?array
    {
        if ($this->mode === 'prepared_content_failure') {
            return ['display_surface_v1' => ['surface_version' => 'drifted']];
        }

        return ['display_surface_v1' => $this->package->publicProjection(
            $this->rows[$entry['slug']],
            $entry['locale'],
        )];
    }

    public function activate(array $entries): array
    {
        if ($this->mode === 'activation_failure') {
            return ['status' => 'blocked', 'entries' => [], 'failures' => [['reason' => 'fixture']]];
        }
        $snapshots = [];
        foreach ($entries as $entry) {
            $snapshots[$entry['slug'].'|'.$entry['locale']] = ['fixture' => true];
        }
        $this->serveStaleActive = false;

        return ['status' => 'pass', 'entries' => $entries, 'failures' => [], 'rollback_snapshots' => $snapshots];
    }

    public function restore(array $entries, array $snapshots): void
    {
        $this->restored = true;
    }

    public function forget(array $entries): void
    {
        $this->forgotten = true;
    }

    public function publicationSnapshot(array $slugs, array $locales): array
    {
        $snapshot = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $snapshot[$slug][$locale] = [
                    'published' => true,
                    'classification' => $this->mode === 'readback_failure' ? 'missing_pointer' : 'ready_active',
                    'version' => 'active-'.$locale,
                    'payload' => ['display_surface_v1' => $this->serveStaleActive
                        ? ['surface_version' => 'drifted']
                        : $this->package->publicProjection($this->rows[$slug], $locale)],
                ];
            }
        }

        return $snapshot;
    }

    public function verifyOnlyRead(string $slug, string $locale): array
    {
        if ($slug === 'software-developers') {
            return ['state' => 'not_found', 'payload' => null];
        }

        return [
            'state' => 'fresh',
            'payload' => ['display_surface_v1' => $this->package->publicProjection($this->rows[$slug], $locale)],
        ];
    }
}
