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
        $cache['alpha|en']['subject']['title'] = 'drift';

        $this->expectException(CareerCurrentAuthorityExportFailure::class);
        $this->expectExceptionMessage('PUBLIC_CONTENT_HASH_MISMATCH');
        $exporter->buildDocuments($rows, $database, $cache, $database, 1);
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
