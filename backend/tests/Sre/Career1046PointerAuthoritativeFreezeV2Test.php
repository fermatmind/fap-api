<?php

declare(strict_types=1);

namespace Tests\Sre;

use App\Domain\Career\Publish\Career1046ImmutableCandidateGenerator;
use PHPUnit\Framework\TestCase;

final class Career1046PointerAuthoritativeFreezeV2Test extends TestCase
{
    private const V1_SHA256 = 'b570ec0cdda65278aa543431886b3529d072de8d67a8e79f1cafbb1c4c8dfc0e';

    private const V2_SHA256 = 'ef4d43eeaa0300534b36fd77d7806bcbe065de1fb13f158ceda1517f259207c5';

    public function test_v1_remains_immutable_and_v2_freezes_verified_pointer_partition(): void
    {
        $root = dirname(__DIR__, 2).'/docs/seo/generated';
        $v1Bytes = file_get_contents($root.'/detail-ready-1046-rollout-manifest.v1.json');
        $v2Bytes = file_get_contents($root.'/detail-ready-1046-rollout-manifest.v2.json');
        self::assertIsString($v1Bytes);
        self::assertIsString($v2Bytes);
        self::assertSame(self::V1_SHA256, hash('sha256', $v1Bytes));
        self::assertSame(self::V2_SHA256, hash('sha256', $v2Bytes));

        $v1 = json_decode($v1Bytes, true, flags: JSON_THROW_ON_ERROR);
        $v2 = json_decode($v2Bytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('detail_ready_1046_rollout_manifest.v2', $v2['schema_version']);
        self::assertSame($v1['baseline_slugs'], $v2['baseline_slugs']);
        self::assertSame($v1['delta_slugs'], $v2['delta_slugs']);
        self::assertSame($v1['rollback_group'], $v2['rollback_group']);

        $baseline = $this->set($v2['baseline_slugs']);
        $delta = $this->set($v2['delta_slugs']);
        $target = $this->set([...$baseline, ...$delta]);
        $localeRows = [];
        foreach ($target as $slug) {
            $localeRows[] = $slug.'|en';
            $localeRows[] = $slug.'|zh';
        }
        sort($localeRows, SORT_STRING);

        self::assertCount(30, $baseline);
        self::assertCount(1016, $delta);
        self::assertCount(1046, $target);
        self::assertSame(Career1046ImmutableCandidateGenerator::BASELINE_SET_SHA256, $this->hash($baseline));
        self::assertSame(Career1046ImmutableCandidateGenerator::RECEIPT_SET_SHA256, $this->hash($delta));
        self::assertSame(Career1046ImmutableCandidateGenerator::TARGET_SET_SHA256, $this->hash($target));
        self::assertSame(Career1046ImmutableCandidateGenerator::TARGET_LOCALE_ROW_SET_SHA256, $this->hash($localeRows));
        foreach (Career1046ImmutableCandidateGenerator::FORBIDDEN_SLUGS as $slug) {
            self::assertNotContains($slug, $target);
        }

        $freeze = $v2['pointer_authority_freeze'];
        self::assertSame('career.pointer_authoritative_baseline_delta_freeze.v2', $freeze['contract_version']);
        self::assertSame(31661629423, $freeze['diagnostic']['workflow_run_id']);
        self::assertSame('a1fa264dec36ce212e18f5a4229471f534cdf45281d1f2d6459a0c1677487314', $freeze['diagnostic']['receipt_sha256']);
        self::assertSame('sha256:e50cec788379bd28ace60a4447543b2678e183f3f32a765f8231e06f701a7824', $freeze['diagnostic']['artifact_digest']);
        self::assertTrue($freeze['diagnostic']['legacy_locale_hash_normalization_error']);
        self::assertFalse($freeze['diagnostic']['projection_published_set_invalid']);
        self::assertSame(0, $freeze['delta']['receipt_missing_count']);
        self::assertSame(0, $freeze['delta']['receipt_outside_count']);
        self::assertSame(0, $freeze['target']['forbidden_count']);
        self::assertTrue($freeze['public_directory_transport']['tls_verified']);
        self::assertTrue($freeze['public_directory_transport']['sets_equal']);
        self::assertFalse($freeze['public_directory_transport']['authority_source']);
        self::assertSame(array_fill_keys(array_keys($freeze['writes']), 0), $freeze['writes']);
    }

    public function test_v2_identity_is_propagated_through_baseline_repair_and_task_3a_to_7b(): void
    {
        $repository = dirname(__DIR__, 3);
        $paths = [
            '.github/workflows/career-baseline-index-state-authority-repair.yml',
            '.github/workflows/career-publication-index-reconciliation-preflight-production-ops.yml',
            '.github/workflows/career-publication-index-reconciliation-apply-production-ops.yml',
            '.github/workflows/career-1046-immutable-candidate-artifact-producer.yml',
            '.github/workflows/career-1046-product-data-staging-production-ops.yml',
            '.github/workflows/career-1046-root-generation-activation-production-ops.yml',
            '.github/workflows/career-1046-public-product-verify-only.yml',
            '.github/workflows/career-1046-discoverability-release-control.yml',
            'backend/scripts/operations/career_baseline_index_state_authority_repair.php',
            'backend/scripts/operations/career_publication_index_reconciliation_preflight.php',
            'backend/scripts/operations/career_publication_index_reconciliation_apply.php',
            'backend/scripts/operations/career_1046_immutable_candidate_artifact.php',
            'backend/scripts/operations/career_1046_product_data_staging.php',
            'backend/scripts/operations/career_1046_root_generation_activation.php',
            'backend/scripts/operations/career_1046_public_product_verify_only.php',
            'backend/scripts/operations/career_1046_discoverability_release_control.php',
            'backend/app/Domain/Career/Publish/Career1046ImmutableCandidateGenerator.php',
            'backend/app/Domain/Career/Publish/Career1046DiscoverabilityReleaseGate.php',
        ];
        $combined = '';
        foreach ($paths as $path) {
            $source = file_get_contents($repository.'/'.$path);
            self::assertIsString($source, $path);
            $combined .= $source;
        }

        self::assertStringContainsString(self::V2_SHA256, $combined);
        self::assertStringContainsString('detail-ready-1046-rollout-manifest.v2.json', $combined);
        self::assertStringNotContainsString(self::V1_SHA256, $combined);
        self::assertStringNotContainsString('detail-ready-1046-rollout-manifest.v1.json', $combined);
        foreach ([
            'career.baseline_index_state_authority_repair.v2',
            'career.publication_index_reconciliation_preflight.v2',
            'career.publication_index_reconciliation_apply.v2',
            'career.1046.immutable_candidate_artifact_producer.v2',
            'career.1046.immutable_candidate.v2',
            'career.1046.product_data_staging.v2',
            'career.1046.root_generation_activation.v2',
            'career.1046.public_product_verify_only.v2',
            'career.1046.discoverability_release_control.v2',
        ] as $contract) {
            self::assertStringContainsString($contract, $combined);
            self::assertStringNotContainsString(str_replace('.v2', '.v1', $contract), $combined);
        }
    }

    /** @return list<string> */
    private function set(mixed $values): array
    {
        self::assertIsArray($values);
        $result = array_values(array_unique(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $values,
        )));
        sort($result, SORT_STRING);

        return $result;
    }

    /** @param list<string> $values */
    private function hash(array $values): string
    {
        return hash('sha256', implode("\n", $values)."\n");
    }
}
