<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionValidator;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Career\CareerJobDetailExposureReadinessFixture;

final class CareerRuntimePublishProjectionServiceTest extends TestCase
{
    public function test_it_projects_public_canonical_jobs_as_runtime_published_by_locale(): void
    {
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($this->ledger([
            [
                'source_slug' => 'actors',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'indexable',
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'llms_full_eligible' => true,
            ],
        ]));

        $this->assertSame(2, data_get($projection, 'counts.projection_rows'));
        $this->assertSame(2, data_get($projection, 'counts.published'));
        $this->assertSame(2, data_get($projection, 'counts.canonical_published'));
        $this->assertSame(2, data_get($projection, 'counts.dataset_visible'));
        $this->assertSame(2, data_get($projection, 'counts.sitemap_live'));

        foreach ($projection['items'] as $item) {
            $this->assertSame('actors', $item['slug']);
            $this->assertSame(CareerRuntimePublishProjectionService::STATE_PUBLISHED, $item['runtime_publish_state']);
            $this->assertTrue($item['detail_route_enabled']);
            $this->assertTrue($item['dataset_visible']);
            $this->assertTrue($item['search_visible']);
            $this->assertTrue($item['canonical_self']);
            $this->assertTrue($item['robots_indexable']);
            $this->assertTrue($item['release_gate_pass']);
        }
    }

    public function test_it_aligns_public_canonical_runtime_surfaces_from_release_gate(): void
    {
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($this->ledger([
            [
                'source_slug' => 'actors',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'indexable',
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
            ],
            [
                'source_slug' => 'noindex-canonical',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'noindex',
            ],
        ]));

        $publishedRows = array_values(array_filter(
            $projection['items'],
            static fn (array $item): bool => $item['slug'] === 'actors',
        ));
        $candidateRows = array_values(array_filter(
            $projection['items'],
            static fn (array $item): bool => $item['slug'] === 'noindex-canonical',
        ));

        foreach ($publishedRows as $item) {
            $this->assertSame(CareerRuntimePublishProjectionService::STATE_PUBLISHED, $item['runtime_publish_state']);
            $this->assertTrue($item['detail_route_enabled']);
            $this->assertTrue($item['dataset_visible']);
            $this->assertTrue($item['search_visible']);
            $this->assertTrue($item['sitemap_live']);
            $this->assertTrue($item['llms_live']);
            $this->assertTrue($item['llms_full_live']);
        }

        foreach ($candidateRows as $item) {
            $this->assertSame(CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE, $item['runtime_publish_state']);
            $this->assertFalse($item['detail_route_enabled']);
            $this->assertFalse($item['dataset_visible']);
            $this->assertFalse($item['search_visible']);
            $this->assertFalse($item['sitemap_live']);
            $this->assertFalse($item['llms_live']);
            $this->assertFalse($item['llms_full_live']);
        }
    }

    public function test_it_blocks_non_public_rows_and_hard_blocks_software_developers(): void
    {
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($this->ledger([
            [
                'source_slug' => 'software-developers',
                'current_status' => 'manual_hold',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                'public_eligible' => false,
                'indexability' => 'not_public',
            ],
            [
                'source_slug' => 'cn-2-06-03-00',
                'current_status' => 'CN_proxy_hold',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::BLOCKED_UNTIL_GOVERNANCE_APPROVAL,
                'public_eligible' => false,
                'indexability' => 'not_public',
            ],
        ]));

        $this->assertSame(4, data_get($projection, 'counts.projection_rows'));
        $this->assertSame(2, data_get($projection, 'counts.quarantined'));
        $this->assertSame(2, data_get($projection, 'counts.blocked'));
        $this->assertSame(0, data_get($projection, 'counts.dataset_visible'));
        $this->assertSame(0, data_get($projection, 'counts.sitemap_live'));

        foreach ($projection['items'] as $item) {
            $this->assertFalse($item['dataset_visible']);
            $this->assertFalse($item['search_visible']);
            $this->assertFalse($item['sitemap_live']);
            $this->assertFalse($item['llms_live']);
            $this->assertFalse($item['llms_full_live']);
            $this->assertFalse($item['release_gate_pass']);
        }
    }

    public function test_it_keeps_alias_family_cn_and_nonindex_types_out_of_dataset_and_llms(): void
    {
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($this->ledger([
            [
                'source_slug' => 'software-engineers',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_ALIAS_REDIRECT,
                'public_eligible' => true,
                'indexability' => 'no_independent_index',
            ],
            [
                'source_slug' => 'computer-and-information-technology',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_FAMILY_HUB,
                'public_eligible' => true,
                'indexability' => 'noindex',
            ],
            [
                'source_slug' => 'cn-2-06-03-00',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CN_PROXY_PAGE,
                'public_eligible' => true,
                'indexability' => 'noindex',
            ],
            [
                'source_slug' => 'reference-only-career',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_NONINDEX_REFERENCE,
                'public_eligible' => true,
                'indexability' => 'noindex',
            ],
        ]));

        $this->assertSame(8, data_get($projection, 'counts.published_candidate'));
        $this->assertSame(0, data_get($projection, 'counts.dataset_visible'));
        $this->assertSame(0, data_get($projection, 'counts.search_visible'));
        $this->assertSame(0, data_get($projection, 'counts.sitemap_live'));
        $this->assertSame(0, data_get($projection, 'counts.llms_live'));

        $aliasRows = array_values(array_filter(
            $projection['items'],
            static fn (array $item): bool => $item['public_resolution_type'] === CareerPublicResolutionTypeMatrix::PUBLIC_ALIAS_REDIRECT,
        ));
        $this->assertSame('redirect_only', $aliasRows[0]['detail_route_enabled']);
    }

    public function test_validator_rejects_seo_live_without_publish_gate(): void
    {
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($this->ledger([
            [
                'source_slug' => 'actors',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'indexable',
            ],
        ]));
        $projection['items'][0]['sitemap_live'] = true;
        $projection['items'][0]['release_gate_pass'] = false;

        $result = (new CareerRuntimePublishProjectionValidator(
            new CareerJobDetailExposureReadinessFixture,
        ))->validate($projection);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('seo_geo_live_without_publish_gate', data_get($result, 'failures.0.reason'));
    }

    public function test_validator_blocks_detail_exposure_until_target_locale_cache_is_ready(): void
    {
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($this->ledger([
            [
                'source_slug' => 'actors',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'indexable',
            ],
        ]));

        $result = (new CareerRuntimePublishProjectionValidator(
            new CareerJobDetailExposureReadinessFixture(
                classifications: ['actors|zh-CN' => 'missing_payload'],
            ),
        ))->validate($projection);

        $this->assertSame('blocked', $result['status']);
        $failure = collect($result['failures'])->firstWhere('reason', 'detail_cache_not_ready_for_exposure');
        $this->assertIsArray($failure);
        $this->assertSame('zh', data_get($failure, 'context.locale'));
        $this->assertSame('missing_payload', data_get($failure, 'context.classification'));
    }

    public function test_validator_rejects_recovery_cache_that_is_not_authorized_for_first_exposure(): void
    {
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($this->ledger([
            [
                'source_slug' => 'actors',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'indexable',
            ],
        ]));

        $result = (new CareerRuntimePublishProjectionValidator(
            new CareerJobDetailExposureReadinessFixture(
                classifications: ['actors|en' => 'ready_lkg'],
                exposureReady: ['actors|en' => false],
            ),
        ))->validate($projection);

        $failure = collect($result['failures'])->firstWhere('reason', 'detail_cache_not_ready_for_exposure');
        $this->assertIsArray($failure);
        $this->assertSame('en', data_get($failure, 'context.locale'));
        $this->assertSame('ready_lkg', data_get($failure, 'context.classification'));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function ledger(array $rows): array
    {
        return [
            'ledger_kind' => 'career_full_release_ledger',
            'ledger_version' => 'test',
            'scope' => 'test',
            'public_resolution' => [
                'rows' => $rows,
            ],
        ];
    }
}
