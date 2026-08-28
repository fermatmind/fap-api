<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerCurrentAuthorityCacheGateway;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerCurrentAuthorityPublisher;
use App\Domain\Career\Display\CareerCurrentAuthorityPublisherFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityStateMachine;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Domain\Career\Display\CareerJobDetailCanonicalCacheReader;
use App\Domain\Career\Display\CareerMaterialDecisionService;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use App\Services\Career\Review\CareerJobDetailReaderSafeReviewProjector;
use App\Services\ReviewGovernance\PublicReviewContract;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class CareerCurrentAuthorityPublisherTest extends TestCase
{
    use RefreshDatabase;

    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if (is_string($this->fixtureRoot) && is_dir($this->fixtureRoot)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->fixtureRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->fixtureRoot);
        }
        parent::tearDown();
    }

    public function test_full_scan_uses_file_authority_without_rewriting_compatibility_rows(): void
    {
        [$authority, $row, $stateMachine] = $this->fixture();
        $cache = new PerPagePublisherCacheGateway(new CareerCurrentAuthorityPackage, ['actors' => $row]);
        $cache->serveStaleActive = true;
        $publisher = $this->publisher($authority, $cache, $stateMachine);

        $result = $publisher->execute(base_path(), true);

        self::assertSame(0, $result['write_counts']['database_update_count']);
        self::assertSame(0, $result['write_counts']['database_insert_count']);
        self::assertSame(0, $result['write_counts']['database_delete_count']);
        self::assertSame(4, $result['write_counts']['cache_candidate_write_count']);
        self::assertSame(2, $result['write_counts']['cache_pointer_activation_count']);
        self::assertSame(2, $result['public_readback']['verified_locale_page_count']);
        self::assertFalse($result['idempotent_noop']);
        self::assertSame('legacy title', data_get(
            CareerJobDisplayAsset::query()->sole()->page_payload_json,
            'en.hero.title',
        ));

        $second = $publisher->execute(base_path(), true);
        self::assertTrue($second['idempotent_noop']);
        self::assertSame(0, $second['write_counts']['cache_candidate_write_count']);
    }

    public function test_manifest_bound_row_drift_fails_before_database_or_cache_writes(): void
    {
        [$authority, $row, $stateMachine] = $this->fixture();
        $asset = CareerJobDisplayAsset::query()->sole();
        $payload = $asset->page_payload_json;
        data_set($payload, 'en.hero.title', 'drifted');
        $asset->forceFill(['page_payload_json' => $payload])->save();
        $cache = new PerPagePublisherCacheGateway(new CareerCurrentAuthorityPackage, ['actors' => $row]);

        try {
            $this->publisher($authority, $cache, $stateMachine)->execute(base_path(), true);
            self::fail('Expected manifest-bound compatibility drift to fail closed.');
        } catch (CareerCurrentAuthorityPublisherFailure $failure) {
            self::assertSame('CURRENT_COMPATIBILITY_ROW_HASH_MISMATCH', $failure->safeCode);
            self::assertSame('confirmed_zero_write', $failure->writeCommitState);
        }
        self::assertSame([], $cache->preparedSlugs);
        self::assertSame('drifted', data_get(CareerJobDisplayAsset::query()->sole()->page_payload_json, 'en.hero.title'));
    }

    public function test_cache_preparation_failure_preserves_database_and_active_state(): void
    {
        [$authority, $row, $stateMachine] = $this->fixture();
        $cache = new PerPagePublisherCacheGateway(new CareerCurrentAuthorityPackage, ['actors' => $row], 'prepare_exception');
        $cache->serveStaleActive = true;

        try {
            $this->publisher($authority, $cache, $stateMachine)->execute(base_path(), true);
            self::fail('Expected cache preparation failure.');
        } catch (CareerCurrentAuthorityPublisherFailure $failure) {
            self::assertSame('CURRENT_CACHE_PREPARATION_RUNTIME_FAILED', $failure->safeCode);
            self::assertSame('confirmed_zero_write', $failure->writeCommitState);
        }
        self::assertFalse($cache->restored);
        self::assertTrue($cache->forgotten);
        self::assertSame('legacy title', data_get(CareerJobDisplayAsset::query()->sole()->page_payload_json, 'en.hero.title'));
    }

    public function test_activation_failure_forgets_candidates_without_touching_database_or_lkg(): void
    {
        [$authority, $row, $stateMachine] = $this->fixture();
        $cache = new PerPagePublisherCacheGateway(new CareerCurrentAuthorityPackage, ['actors' => $row], 'activation_failure');
        $cache->serveStaleActive = true;

        try {
            $this->publisher($authority, $cache, $stateMachine)->execute(base_path(), true);
            self::fail('Expected activation failure.');
        } catch (CareerCurrentAuthorityPublisherFailure $failure) {
            self::assertSame('CURRENT_CACHE_POINTER_ACTIVATION_FAILED', $failure->safeCode);
            self::assertSame('rolled_back', $failure->writeCommitState);
        }
        self::assertFalse($cache->restored);
        self::assertTrue($cache->forgotten);
        self::assertSame('legacy title', data_get(CareerJobDisplayAsset::query()->sole()->page_payload_json, 'en.hero.title'));
    }

    public function test_post_activation_readback_failure_restores_active_and_lkg_boundaries(): void
    {
        [$authority, $row, $stateMachine] = $this->fixture();
        $cache = new PerPagePublisherCacheGateway(new CareerCurrentAuthorityPackage, ['actors' => $row], 'readback_failure');
        $cache->serveStaleActive = true;

        try {
            $this->publisher($authority, $cache, $stateMachine)->execute(base_path(), true);
            self::fail('Expected public readback failure.');
        } catch (CareerCurrentAuthorityPublisherFailure $failure) {
            self::assertSame('CURRENT_ACTIVE_CACHE_READBACK_FAILED', $failure->safeCode);
            self::assertSame('rolled_back', $failure->writeCommitState);
        }
        self::assertTrue($cache->restored);
        self::assertTrue($cache->forgotten);
        self::assertSame('legacy title', data_get(CareerJobDisplayAsset::query()->sole()->page_payload_json, 'en.hero.title'));
    }

    /** @return array{array<string,mixed>,array<string,mixed>,CareerCurrentAuthorityStateMachine} */
    private function fixture(): array
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'fixture-family', 'title_en' => 'Fixture', 'title_zh' => '测试',
        ]);
        $occupation = Occupation::query()->create([
            'family_id' => $family->id, 'canonical_slug' => 'actors', 'entity_level' => 'occupation',
            'truth_market' => 'US', 'display_market' => 'CN', 'crosswalk_mode' => 'direct',
            'canonical_title_en' => 'Actors', 'canonical_title_zh' => '演员', 'search_h1_zh' => '演员',
        ]);
        $row = $this->row('actors', 'legacy title');
        CareerJobDisplayAsset::query()->create(
            ($row + ['asset_version' => 'compatibility-only', 'template_version' => 'compatibility-only'])
            + ['occupation_id' => $occupation->id],
        );

        $root = tempnam(sys_get_temp_dir(), 'career-publisher-v3-');
        self::assertIsString($root);
        unlink($root);
        $current = $root.'/content_assets/career/current';
        self::assertTrue(mkdir($current.'/careers/actors', 0700, true));
        $this->fixtureRoot = $root;
        $legacyPackage = new CareerCurrentAuthorityPackage;
        $manifestFiles = [];
        $semanticHashes = [];
        $projectionHashes = [];
        $canonicalRow = $row;
        unset($canonicalRow['import_run_id']);
        foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
            $surface = $legacyPackage->publicProjection($canonicalRow, $locale);
            $content = $surface['content_v3'];
            unset($surface['content_v3']);
            $path = 'careers/actors/'.$locale.'.json';
            $bytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($content);
            file_put_contents($current.'/'.$path, $bytes);
            $projectionHash = CareerCurrentAuthorityPackage::hashValue($surface);
            $manifestFiles[] = [
                'bytes' => strlen($bytes), 'canonical_slug' => 'actors',
                'legacy_projection_sha256' => $projectionHash,
                'legacy_row_sha256' => CareerCurrentAuthorityPackage::hashValue($canonicalRow),
                'locale' => $locale, 'path' => $path, 'sha256' => hash('sha256', $bytes),
                'source_content_sha256' => $content['source_content_sha256'],
            ];
            $semanticHashes[] = $content['source_content_sha256'];
            $projectionHashes[] = $projectionHash;
        }
        $manifest = [
            'aggregate_sha256' => '', 'authority_path' => 'backend/content_assets/career/current',
            'compiler_version' => CareerContentV3AuthorityPackage::COMPILER_VERSION,
            'contract_version' => CareerContentV3AuthorityPackage::CONTRACT_VERSION,
            'coverage' => ['slugs' => 1, 'locales' => 2, 'locale_pages' => 2, 'files' => 2, 'enhanced_locale_pages' => 0, 'legacy_locale_pages' => 2],
            'files' => $manifestFiles, 'locales' => CareerCurrentAuthorityPackage::LOCALES,
            'schema_version' => CareerContentV3AuthorityPackage::SCHEMA_VERSION,
            'set_hashes' => [
                'legacy_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($projectionHashes),
                'legacy_versionless_projection_sha256' => str_repeat('b', 64),
                'locale_page_set_sha256' => CareerCurrentAuthorityPackage::hashValue(['actors|en', 'actors|zh-CN']),
                'slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue(['actors']),
                'source_semantic_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($semanticHashes),
            ],
            'source_registry_sha256' => str_repeat('c', 64),
        ];
        $aggregateProjection = $manifest;
        unset($aggregateProjection['aggregate_sha256']);
        $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($aggregateProjection);
        file_put_contents($current.'/manifest.json', CareerCurrentAuthorityPackage::encodePrettyCanonical($manifest));

        $contentPackage = new CareerContentV3AuthorityPackage(1, 2, 0, CareerCurrentAuthorityPackage::hashValue(['actors']));
        $authority = $contentPackage->load($root);
        $canonicalReader = new CareerContentV3CanonicalReader($contentPackage, $root);
        $cacheReader = new CareerJobDetailCanonicalCacheReader(
            app(PublicReviewContract::class), $canonicalReader,
        );
        $stateMachine = new CareerCurrentAuthorityStateMachine(
            $legacyPackage, $cacheReader, app(CareerJobDetailReaderSafeReviewProjector::class), $canonicalReader,
        );

        return [$authority, $canonicalRow, $stateMachine];
    }

    /** @return array<string,mixed> */
    private function row(string $slug, string $title): array
    {
        $page = array_fill_keys(CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS, ['value' => 'verified']);
        $page['hero'] = ['title' => $title];
        $page['career_quick_answers_block'] = [
            'availability' => 'published', 'schema_version' => 'career.quick_answers.v1', 'heading' => '职业速答',
            'items' => array_map(static fn (string $key): array => [
                'key' => $key, 'question' => $key.' question', 'answer' => $key.' answer',
                'table' => ['rows' => [[
                    'label' => 'label', 'value' => 'value', 'alternate_value' => null, 'secondary_value' => null,
                ]],
                ],
            ], ['qa3', 'qa2', 'qa1']),
        ];
        $page['onet_structured_fields_block'] = [
            'availability' => 'published', 'schema_version' => 'career.onet_structured_fields.v1',
            'heading' => 'O*NET 结构化字段', 'rows' => [[
                'label' => 'label', 'value' => 'value', 'alternate_value' => null, 'secondary_value' => null,
            ]],
        ];
        $en = $page;
        $en['career_quick_answers_block'] = ['availability' => 'unavailable', 'reason_code' => 'source_locale_unavailable'];
        $en['onet_structured_fields_block'] = ['availability' => 'unavailable', 'reason_code' => 'source_locale_unavailable'];
        $presentation = static fn (string $locale): array => [
            'contract_version' => 'career.detail.presentation.v2',
            'design_authority' => ['id' => 'universal-career-dossier-v2'],
            'template_id' => 'career-dossier-universal-v2', 'locale' => $locale,
            'hero' => ['title' => $title, 'lead' => null, 'badges' => [], 'stats' => [], 'ai_exposure' => null, 'cta' => null],
            'groups' => [[
                'id' => 'fixture', 'label' => 'Fixture group',
                'component_ids' => CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS,
                'content_state' => 'legacy', 'pending_enrichment' => 'display_placeholder',
            ]],
        ];

        return [
            'canonical_slug' => $slug, 'surface_version' => CareerCurrentAuthorityPackage::SURFACE_VERSION,
            'asset_type' => CareerCurrentAuthorityPackage::ASSET_TYPE,
            'asset_role' => CareerCurrentAuthorityPackage::ASSET_ROLE,
            'status' => CareerCurrentAuthorityPackage::READY_STATUS,
            'component_order_json' => CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS,
            'page_payload_json' => ['en' => $en, 'zh' => $page], 'seo_payload_json' => ['title' => $title],
            'sources_json' => ['references' => []], 'structured_data_json' => ['@type' => 'Occupation'],
            'implementation_contract_json' => ['version' => 'v1'],
            'metadata_json' => ['authority' => 'fixture', 'presentation_v2' => [
                'en' => $presentation('en'), 'zh' => $presentation('zh-CN'),
            ]],
            'import_run_id' => null,
        ];
    }

    /** @param array<string,mixed> $authority */
    private function publisher(
        array $authority,
        PerPagePublisherCacheGateway $cache,
        CareerCurrentAuthorityStateMachine $stateMachine,
    ): CareerCurrentAuthorityPublisher {
        $loader = new class($authority) extends CareerCurrentAuthorityPackageLoader
        {
            /** @param array<string,mixed> $authority */
            public function __construct(private readonly array $authority) {}

            public function loadForPublish(string $backendRoot): array
            {
                return $this->authority;
            }
        };

        return new CareerCurrentAuthorityPublisher(
            new CareerCurrentAuthorityPackage,
            $loader,
            $cache,
            app(CareerJobDisplaySurfaceBuilder::class),
            app(CareerMaterialDecisionService::class),
            $stateMachine,
        );
    }
}

final class PerPagePublisherCacheGateway extends CareerCurrentAuthorityCacheGateway
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

        return ['slug' => $slug, 'locale' => $locale, 'version' => 'candidate-'.$locale, 'status' => 'ready', 'classification' => 'ready_staged'];
    }

    public function compactDerivedContentV3(array $slugs, array $locales): int
    {
        return 0;
    }

    public function preparedPayload(array $entry): ?array
    {
        return ['display_surface_v1' => $this->package->publicProjection($this->rows[$entry['slug']], $entry['locale'])];
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
