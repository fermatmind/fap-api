<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityExporter;
use App\Domain\Career\Display\CareerCurrentAuthorityExportFailure;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CareerCurrentAuthorityExporterTest extends TestCase
{
    public function test_it_builds_deterministic_canonical_documents_and_excludes_environment_fields(): void
    {
        $exporter = $this->exporter();
        $rows = [$this->row('beta'), $this->row('alpha')];
        $projections = $this->projections(['alpha', 'beta']);

        $first = $exporter->buildDocuments($rows, $projections, $projections, $projections, 2);
        $second = $exporter->buildDocuments(array_reverse($rows), $projections, $projections, $projections, 2);

        self::assertSame($first, $second);
        self::assertSame(2, $first['manifest']['counts']['careers']);
        self::assertSame(4, $first['manifest']['counts']['locale_pages']);
        self::assertSame(4, $first['manifest']['counts']['public_projection_locale_pages']);
        self::assertSame(0, $first['manifest']['counts']['manual_hold_locale_pages']);
        self::assertSame(26, $first['manifest']['counts']['components_per_page']);
        self::assertSame(hash('sha256', $first['assets_jsonl']), $first['receipt']['assets_sha256']);
        self::assertStringNotContainsString('occupation_id', $first['assets_jsonl']);
        self::assertStringNotContainsString('import_run_id', $first['assets_jsonl']);
        self::assertStringNotContainsString('updated_at', $first['assets_jsonl']);
        self::assertStringStartsWith('{"asset_role":', $first['assets_jsonl']);
    }

    public function test_it_fails_closed_when_cache_or_api_content_differs(): void
    {
        $exporter = $this->exporter();
        $rows = [$this->row('alpha')];
        $database = $this->projections(['alpha']);
        $cache = $database;
        $cache['alpha|en']['page']['content']['hero']['title'] = 'drift';

        $this->expectException(CareerCurrentAuthorityExportFailure::class);
        $this->expectExceptionMessage('PUBLIC_CONTENT_HASH_MISMATCH');
        $exporter->buildDocuments($rows, $database, $cache, $database, 1);
    }

    public function test_it_compares_content_hash_multisets_without_comparing_identities(): void
    {
        $exporter = $this->exporter();
        $rows = [$this->row('alpha')];
        $database = $this->projections(['alpha']);
        $cache = [
            'generation-one|en' => $database['alpha|zh-CN'],
            'generation-two|zh-CN' => $database['alpha|en'],
        ];

        $documents = $exporter->buildDocuments($rows, $database, $cache, array_reverse($cache, true), 1);

        self::assertTrue($documents['receipt']['hashes_match']);
        self::assertSame(
            $documents['receipt']['database_public_content_sha256'],
            $documents['receipt']['active_cache_public_content_sha256'],
        );
    }

    public function test_it_fails_closed_when_a_projection_has_a_missing_content_hash(): void
    {
        $exporter = $this->exporter();
        $rows = [$this->row('alpha')];
        $database = $this->projections(['alpha']);
        $cache = $database;
        array_pop($cache);

        $this->expectException(CareerCurrentAuthorityExportFailure::class);
        $this->expectExceptionMessage('PUBLIC_CONTENT_PROJECTION_COUNT_MISMATCH');
        $exporter->buildDocuments($rows, $database, $cache, $database, 1);
    }

    public function test_it_fails_closed_when_a_same_size_multiset_substitutes_duplicate_content(): void
    {
        $exporter = $this->exporter();
        $rows = [$this->row('alpha')];
        $database = $this->projections(['alpha']);
        $cache = $database;
        $cache['alpha|zh-CN'] = $cache['alpha|en'];

        $this->expectException(CareerCurrentAuthorityExportFailure::class);
        $this->expectExceptionMessage('PUBLIC_CONTENT_HASH_MISMATCH');
        $exporter->buildDocuments($rows, $database, $cache, $database, 1);
    }

    public function test_it_ignores_occupation_derived_projection_fields(): void
    {
        $exporter = $this->exporter();
        $rows = [$this->row('alpha')];
        $database = $this->projections(['alpha']);
        $cache = $database;
        $cache['alpha|en']['subject']['title'] = 'occupation-derived drift';
        $cache['alpha|en']['claim_permissions']['warnings'] = ['occupation-derived drift'];

        $documents = $exporter->buildDocuments($rows, $database, $cache, $database, 1);

        self::assertTrue($documents['receipt']['hashes_match']);
        self::assertSame(
            $documents['receipt']['database_public_content_sha256'],
            $documents['receipt']['active_cache_public_content_sha256'],
        );
    }

    public function test_it_hashes_display_owned_sources(): void
    {
        $exporter = $this->exporter();
        $rows = [$this->row('alpha')];
        $database = $this->projections(['alpha']);
        $api = $database;
        $api['alpha|zh-CN']['sources']['references'][] = ['title' => 'drift'];

        $this->expectException(CareerCurrentAuthorityExportFailure::class);
        $this->expectExceptionMessage('PUBLIC_CONTENT_HASH_MISMATCH');
        $exporter->buildDocuments($rows, $database, $database, $api, 1);
    }

    public function test_it_rejects_missing_or_misordered_components(): void
    {
        $exporter = $this->exporter();
        $row = $this->row('alpha');
        array_pop($row['component_order_json']);
        $projections = $this->projections(['alpha']);

        $this->expectException(CareerCurrentAuthorityExportFailure::class);
        $this->expectExceptionMessage('COMPONENT_ORDER_MISMATCH');
        $exporter->buildDocuments([$row], $projections, $projections, $projections, 1);
    }

    public function test_it_exports_manual_hold_assets_outside_the_public_projection_hash_cohort(): void
    {
        $documents = $this->exporter()->buildDocuments(
            [$this->row('software-developers')],
            [],
            [],
            [],
            1,
        );

        self::assertSame(1, $documents['manifest']['counts']['careers']);
        self::assertSame(2, $documents['manifest']['counts']['locale_pages']);
        self::assertSame(0, $documents['manifest']['counts']['public_projection_locale_pages']);
        self::assertSame(2, $documents['manifest']['counts']['manual_hold_locale_pages']);
        self::assertSame(
            ['software-developers'],
            $documents['manifest']['structural_contract']['public_projection_excluded_manual_hold_slugs'],
        );
    }

    public function test_it_resolves_published_generation_identities_as_complete_locale_pairs(): void
    {
        $method = (new ReflectionClass(CareerCurrentAuthorityExporter::class))
            ->getMethod('publishedProjectionIdentities');
        $identities = $method->invoke(null, [
            'items' => [
                ['slug' => 'beta', 'locale' => 'zh', 'runtime_publish_state' => 'published'],
                ['slug' => 'alpha', 'locale' => 'en', 'runtime_publish_state' => 'published'],
                ['slug' => 'software-developers', 'locale' => 'en', 'runtime_publish_state' => 'blocked'],
                ['slug' => 'beta', 'locale' => 'en', 'runtime_publish_state' => 'published'],
                ['slug' => 'alpha', 'locale' => 'zh', 'runtime_publish_state' => 'published'],
                ['slug' => 'software-developers', 'locale' => 'zh', 'runtime_publish_state' => 'blocked'],
            ],
        ], 4, 2);

        self::assertSame([
            ['slug' => 'alpha', 'locale' => 'en'],
            ['slug' => 'alpha', 'locale' => 'zh-CN'],
            ['slug' => 'beta', 'locale' => 'en'],
            ['slug' => 'beta', 'locale' => 'zh-CN'],
        ], $identities);
    }

    public function test_it_rejects_incomplete_published_generation_locale_pairs(): void
    {
        $method = (new ReflectionClass(CareerCurrentAuthorityExporter::class))
            ->getMethod('publishedProjectionIdentities');

        $this->expectException(CareerCurrentAuthorityExportFailure::class);
        $this->expectExceptionMessage('PUBLIC_PROJECTION_IDENTITY_SET_INVALID');
        $method->invoke(null, [
            'items' => [
                ['slug' => 'alpha', 'locale' => 'en', 'runtime_publish_state' => 'published'],
                ['slug' => 'beta', 'locale' => 'en', 'runtime_publish_state' => 'published'],
                ['slug' => 'beta', 'locale' => 'zh', 'runtime_publish_state' => 'published'],
            ],
        ], 3, 2);
    }

    public function test_exporter_does_not_read_occupation_authority(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../../app/Domain/Career/Display/CareerCurrentAuthorityExporter.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('use App\\Models\\Occupation;', $source);
        self::assertStringNotContainsString('Occupation::query()', $source);
        self::assertStringNotContainsString('CareerJobDisplaySurfaceBuilder', $source);
        self::assertStringNotContainsString('$asset->occupation_id', $source);
    }

    private function exporter(): CareerCurrentAuthorityExporter
    {
        return (new ReflectionClass(CareerCurrentAuthorityExporter::class))->newInstanceWithoutConstructor();
    }

    /** @return array<string,mixed> */
    private function row(string $slug): array
    {
        $content = [];
        foreach (CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER as $component) {
            $content[$component] = ['component' => $component, 'text' => $slug.' '.$component];
        }

        return [
            'canonical_slug' => $slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
            'page_payload_json' => ['page' => ['en' => $content, 'zh' => $content]],
            'seo_payload_json' => ['en' => ['title' => $slug], 'zh' => ['title' => $slug]],
            'sources_json' => ['references' => []],
            'structured_data_json' => ['faq_page' => []],
            'implementation_contract_json' => ['component_contract_required' => true],
            'metadata_json' => ['source' => 'fixture'],
        ];
    }

    /** @param list<string> $slugs @return array<string,array<string,mixed>> */
    private function projections(array $slugs): array
    {
        $resolved = [];
        foreach ($slugs as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $resolved[$slug.'|'.$locale] = [
                    'surface_version' => 'display.surface.v1',
                    'asset_version' => 'v4.2',
                    'template_version' => 'v4.2',
                    'asset_type' => 'career_job_public_display',
                    'asset_role' => 'formal_pilot_master',
                    'status' => 'ready_for_pilot',
                    'subject' => ['canonical_slug' => $slug, 'title' => $slug],
                    'available_locales' => ['en', 'zh-CN'],
                    'claim_permissions' => [],
                    'page' => ['locale' => $locale, 'content' => ['hero' => ['title' => $slug]]],
                    'component_order' => CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
                    'sources' => [],
                    'structured_data_from_visible_content' => [],
                    'implementation_contract' => [],
                ];
            }
        }
        ksort($resolved, SORT_STRING);

        return $resolved;
    }
}
