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
use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use App\Services\Career\Review\CareerJobDetailReaderSafeReviewProjector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_it_rejects_any_duplicate_canonical_slug_before_database_or_cache_writes(): void
    {
        [$authority] = $this->fixture();
        Schema::table('career_job_display_assets', function (Blueprint $table): void {
            $table->dropUnique('career_job_display_assets_canonical_slug_unique');
        });
        $occupation = Occupation::query()->where('canonical_slug', 'actors')->sole();
        CareerJobDisplayAsset::query()->create($this->row('actors', 'duplicate', [
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_role' => 'historical_master',
            'status' => 'retired',
        ]) + ['occupation_id' => $occupation->id]);
        $cache = new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows']);

        try {
            $this->publisher($authority, $cache)->execute(base_path(), true);
            self::fail('Expected duplicate slug rejection.');
        } catch (CareerCurrentAuthorityPublisherFailure $failure) {
            self::assertSame('CURRENT_DISPLAY_SLUG_NOT_UNIQUE', $failure->safeCode);
            self::assertSame('confirmed_zero_write', $failure->writeCommitState);
        }
        self::assertSame([], $cache->preparedSlugs);
        self::assertDatabaseCount('career_job_display_assets', 4);
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

    public function test_it_pins_post_commit_cache_builds_and_readback_to_the_write_connection(): void
    {
        [$authority] = $this->fixture();
        $connection = DB::connection();
        $connection->useWriteConnectionWhenReading(false);

        try {
            $this->publisher(
                $authority,
                new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows']),
            )->execute(base_path(), true);

            $property = new \ReflectionProperty($connection, 'readOnWriteConnection');
            self::assertTrue($property->getValue($connection));
        } finally {
            $connection->useWriteConnectionWhenReading(false);
        }
    }

    public function test_full_scan_keeps_manual_hold_in_database_authority_without_publishing_its_cache(): void
    {
        [$authority] = $this->fixture();
        $family = OccupationFamily::query()->firstOrFail();
        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'software-developers',
            'entity_level' => 'occupation',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct',
            'canonical_title_en' => 'Software Developers',
            'canonical_title_zh' => '软件开发人员',
            'search_h1_zh' => '软件开发人员',
        ]);
        CareerJobDisplayAsset::query()->create(
            $this->row('software-developers', 'old held title') + ['occupation_id' => $occupation->id],
        );
        $authority['rows']['software-developers'] = $this->row('software-developers', 'new held title');
        $authority['slugs'][] = 'software-developers';
        sort($authority['slugs'], SORT_STRING);
        $authority['summary']['career_count'] = 2;
        $authority['summary']['locale_page_count'] = 4;
        $cache = new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows']);

        $result = $this->publisher($authority, $cache)->execute(base_path(), true);

        self::assertSame(2, $result['write_counts']['database_update_count']);
        self::assertSame(2, $result['write_counts']['cache_pointer_activation_count']);
        self::assertSame(2, $result['public_readback']['verified_locale_page_count']);
        self::assertSame('new held title', data_get(
            CareerJobDisplayAsset::query()->where('canonical_slug', 'software-developers')->sole()->page_payload_json,
            'en.hero.title',
        ));
        self::assertSame(['actors'], array_values(array_unique($cache->preparedSlugs)));
    }

    public function test_it_classifies_cache_candidate_exceptions_and_restores_database(): void
    {
        [$authority, , $old] = $this->fixture();
        $cache = new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows'], 'prepare_exception');

        try {
            $this->publisher($authority, $cache)->execute(base_path(), true);
            self::fail('Expected controlled publisher failure.');
        } catch (CareerCurrentAuthorityPublisherFailure $failure) {
            self::assertSame('CURRENT_CACHE_PREPARATION_RUNTIME_FAILED', $failure->safeCode);
            self::assertSame('rolled_back', $failure->writeCommitState);
        }

        self::assertSame('old title', data_get(
            CareerJobDisplayAsset::query()->findOrFail($old['id'])->page_payload_json,
            'en.hero.title',
        ));
        self::assertTrue($cache->forgotten);
    }

    public function test_it_classifies_cache_capacity_exhaustion_and_restores_database(): void
    {
        [$authority, , $old] = $this->fixture();
        $cache = new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows'], 'prepare_capacity_exception');

        try {
            $this->publisher($authority, $cache)->execute(base_path(), true);
            self::fail('Expected controlled publisher failure.');
        } catch (CareerCurrentAuthorityPublisherFailure $failure) {
            self::assertSame('CURRENT_CACHE_CAPACITY_EXHAUSTED', $failure->safeCode);
            self::assertSame('rolled_back', $failure->writeCommitState);
        }

        self::assertSame('old title', data_get(
            CareerJobDisplayAsset::query()->findOrFail($old['id'])->page_payload_json,
            'en.hero.title',
        ));
        self::assertTrue($cache->forgotten);
    }

    public function test_it_reports_the_safe_display_field_that_drifted_before_rollback(): void
    {
        [$authority] = $this->fixture();
        $cache = new FakeCareerCurrentAuthorityCacheGateway(new CareerCurrentAuthorityPackage, $authority['rows'], 'prepared_content_failure');

        try {
            $this->publisher($authority, $cache)->execute(base_path(), true);
            self::fail('Expected controlled publisher failure.');
        } catch (CareerCurrentAuthorityPublisherFailure $failure) {
            self::assertSame('CURRENT_CACHE_SURFACE_VERSION_MISMATCH', $failure->safeCode);
            self::assertSame('rolled_back', $failure->writeCommitState);
        }
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
        CareerJobDisplayAsset::query()->create($this->row('historical-actors', 'historical', [
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
                'components_per_page' => 28,
            ],
        ];

        return [$authority, ['id' => (string) $old->id] + $target, ['id' => (string) $old->id] + $oldRow];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function row(string $slug, string $title, array $overrides = []): array
    {
        $page = array_fill_keys(CareerDisplayAssetComponentContract::CURRENT_V4_3_ORDER, ['value' => 'verified']);
        $page['hero'] = ['title' => $title];
        $page['career_quick_answers_block'] = [
            'availability' => 'published',
            'schema_version' => 'career.quick_answers.v1',
            'heading' => '职业速答',
            'items' => array_map(static fn (string $key): array => [
                'key' => $key,
                'question' => $key.' question',
                'answer' => $key.' answer',
                'table' => ['rows' => [[
                    'label' => 'label', 'value' => 'value',
                    'alternate_value' => null, 'secondary_value' => null,
                ]],
                ],
            ], ['qa3', 'qa2', 'qa1']),
        ];
        $page['onet_structured_fields_block'] = [
            'availability' => 'published',
            'schema_version' => 'career.onet_structured_fields.v1',
            'heading' => 'O*NET 结构化字段',
            'rows' => [[
                'label' => 'label', 'value' => 'value',
                'alternate_value' => null, 'secondary_value' => null,
            ]],
        ];
        $en = $page;
        $en['career_quick_answers_block'] = ['availability' => 'unavailable', 'reason_code' => 'source_locale_unavailable'];
        $en['onet_structured_fields_block'] = ['availability' => 'unavailable', 'reason_code' => 'source_locale_unavailable'];

        return array_replace([
            'canonical_slug' => $slug,
            'surface_version' => CareerCurrentAuthorityPackage::SURFACE_VERSION,
            'asset_version' => CareerCurrentAuthorityPackage::ASSET_VERSION,
            'template_version' => CareerCurrentAuthorityPackage::ASSET_VERSION,
            'asset_type' => CareerCurrentAuthorityPackage::ASSET_TYPE,
            'asset_role' => CareerCurrentAuthorityPackage::ASSET_ROLE,
            'status' => CareerCurrentAuthorityPackage::READY_STATUS,
            'component_order_json' => CareerDisplayAssetComponentContract::CURRENT_V4_3_ORDER,
            'page_payload_json' => ['en' => $en, 'zh' => $page],
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
            app(CareerJobDisplaySurfaceBuilder::class),
        );
    }
}

final class FakeCareerCurrentAuthorityCacheGateway extends CareerCurrentAuthorityCacheGateway
{
    /** @var list<string> */
    public array $preparedSlugs = [];

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
        $this->preparedSlugs[] = $slug;
        if ($this->mode === 'prepare_exception') {
            throw new \RuntimeException('Synthetic cache infrastructure failure.');
        }
        if ($this->mode === 'prepare_capacity_exception') {
            throw new \RuntimeException("OOM command not allowed when used memory > 'maxmemory'.");
        }
        if ($this->mode === 'prepare_failure') {
            return ['status' => 'blocked', 'classification' => 'missing_pointer'];
        }

        return ['slug' => $slug, 'locale' => $locale, 'version' => 'candidate-'.$locale, 'status' => 'ready', 'classification' => 'ready_staged'];
    }

    public function preparedPayload(array $entry): ?array
    {
        if ($this->mode === 'prepared_content_failure') {
            $surface = $this->package->publicProjection(
                $this->rows[$entry['slug']],
                $entry['locale'],
            );
            $surface['surface_version'] = 'drifted';

            return ['display_surface_v1' => $surface];
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
