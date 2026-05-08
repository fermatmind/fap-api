<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Expansion\CanonicalExpansionManifestService;
use App\Domain\Career\Expansion\CanonicalExpansionManifestValidator;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use Tests\TestCase;

final class CanonicalExpansionManifestServiceTest extends TestCase
{
    public function test_it_builds_manifest_from_published_candidate_canonical_truth_rows(): void
    {
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($this->ledger([
            [
                'source_slug' => 'actors',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'noindex',
            ],
            [
                'source_slug' => 'actuaries',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'noindex',
            ],
            [
                'source_slug' => 'accountants-and-auditors',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'indexable',
            ],
        ]));
        $truth = app(CareerCanonicalRuntimeTruthExporter::class)->buildFromProjectionArray($projection);

        $manifest = app(CanonicalExpansionManifestService::class)->buildFromTruthArray($truth, batchSize: 1, batchId: 'batch-001');

        $this->assertSame('career_canonical_expansion_manifest', $manifest['manifest_kind']);
        $this->assertSame('batch-001', data_get($manifest, 'manifest.batch_id'));
        $this->assertSame(1, data_get($manifest, 'manifest.batch_size'));
        $this->assertSame(['actors'], data_get($manifest, 'manifest.slugs'));
        $this->assertSame(['en', 'zh'], data_get($manifest, 'manifest.locales'));
        $this->assertSame(CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE, data_get($manifest, 'manifest.projection_state'));
        $this->assertTrue(data_get($manifest, 'manifest.release_gate_required'));
        $this->assertTrue(data_get($manifest, 'manifest.surface_equality_required'));
        $this->assertSame(['actors'], data_get($manifest, 'manifest.rollback_group'));
        $this->assertSame(CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE, data_get($manifest, 'manifest.rollout_state'));
    }

    public function test_validator_rejects_forbidden_rows_and_missing_gates(): void
    {
        $manifest = [
            'batch_id' => 'batch-001',
            'batch_size' => 2,
            'slugs' => ['software-developers', 'cn-2-06-03-00'],
            'locales' => ['en'],
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            'release_gate_required' => false,
            'surface_equality_required' => false,
            'rollback_group' => ['software-developers', 'cn-2-06-03-00'],
            'rollout_state' => 'unknown',
        ];

        $result = app(CanonicalExpansionManifestValidator::class)->validate($manifest);

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('projection_state_must_be_published_candidate', $reasons);
        $this->assertContains('release_gate_required_must_be_true', $reasons);
        $this->assertContains('surface_equality_required_must_be_true', $reasons);
        $this->assertContains('software_developers_forbidden', $reasons);
        $this->assertContains('cn_proxy_forbidden', $reasons);
        $this->assertContains('invalid_rollout_state', $reasons);
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
