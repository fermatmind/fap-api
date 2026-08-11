<?php

declare(strict_types=1);

namespace Tests\Sre;

use FermatMind\Operations\CareerPublicationIndexReconciliationApply;
use FermatMind\Operations\CareerPublicationIndexReconciliationApplyFailure;
use FermatMind\Operations\CareerPublicationIndexReconciliationPreflight;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/operations/career_publication_index_reconciliation_preflight.php';
require_once dirname(__DIR__, 2).'/scripts/operations/career_publication_index_reconciliation_apply.php';

final class CareerPublicationIndexReconciliationApplyWorkflowTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $manifest;

    /** @var list<string> */
    private array $baselineSlugs;

    /** @var list<string> */
    private array $deltaSlugs;

    /** @var list<string> */
    private array $targetSlugs;

    protected function setUp(): void
    {
        parent::setUp();

        $bytes = file_get_contents(dirname(__DIR__, 2).'/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json');
        self::assertIsString($bytes);
        self::assertSame(CareerPublicationIndexReconciliationApply::MANIFEST_SHA256, hash('sha256', $bytes));
        $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $this->manifest = $manifest;
        $this->baselineSlugs = $this->normalized($manifest['baseline_slugs'] ?? []);
        $this->deltaSlugs = $this->normalized($manifest['delta_slugs'] ?? []);
        $this->targetSlugs = $this->normalized([...$this->baselineSlugs, ...$this->deltaSlugs]);
    }

    public function test_prewrite_plan_binds_the_task_3a_current_state_hash_and_preserves_baseline(): void
    {
        $occupations = $this->occupations();
        $states = $this->baselineStates($occupations);
        $analysis = CareerPublicationIndexReconciliationApply::analyze(
            $this->manifest,
            $this->deltaSlugs,
            $occupations,
            $states,
        );
        $preflight = CareerPublicationIndexReconciliationPreflight::analyze(
            $this->manifest,
            $this->deltaSlugs,
            array_values(array_filter(
                $occupations,
                fn (array $occupation): bool => in_array($occupation['canonical_slug'], $this->deltaSlugs, true),
            )),
            [],
        );

        self::assertSame(30, $analysis['manifest']['baseline_count']);
        self::assertSame(CareerPublicationIndexReconciliationApply::BASELINE_SET_SHA256, $analysis['manifest']['baseline_set_sha256']);
        self::assertSame(1016, $analysis['manifest']['delta_count']);
        self::assertSame(CareerPublicationIndexReconciliationApply::RECEIPT_SET_SHA256, $analysis['manifest']['delta_set_sha256']);
        self::assertSame(1046, $analysis['manifest']['target_count']);
        self::assertSame(CareerPublicationIndexReconciliationApply::TARGET_SET_SHA256, $analysis['manifest']['target_set_sha256']);
        self::assertSame(1016, $analysis['receipt_authority']['receipt_covered_count']);
        self::assertSame(0, $analysis['receipt_authority']['missing_receipt_count']);
        self::assertSame(0, $analysis['receipt_authority']['outside_target_count']);
        self::assertSame(30, $analysis['baseline_latest_index_state']['preserved_count']);
        self::assertSame(30, $analysis['baseline_latest_index_state']['matching_count']);
        self::assertSame(0, $analysis['database_latest_index_state']['matching_count']);
        self::assertSame(1016, $analysis['database_latest_index_state']['missing_or_mismatching_count']);
        self::assertSame(1016, $analysis['database_latest_index_state']['latest_state_missing_count']);
        self::assertSame(1016, $analysis['write_plan']['insert_count']);
        self::assertSame(CareerPublicationIndexReconciliationApply::RECEIPT_SET_SHA256, $analysis['write_plan']['insert_slug_set_sha256']);
        self::assertSame(0, $analysis['write_plan']['outside_target_count']);
        self::assertSame(
            $preflight['database_latest_index_state']['current_state_sha256'],
            $analysis['database_latest_index_state']['current_state_sha256'],
        );
    }

    public function test_postwrite_readback_is_exact_1016_plus_preserved_30(): void
    {
        $occupations = $this->occupations();
        $states = $this->baselineStates($occupations);
        foreach ($this->deltaSlugs as $index => $slug) {
            $states[] = $this->matchingState($slug, 'delta-state-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT));
        }

        $analysis = CareerPublicationIndexReconciliationApply::analyze(
            $this->manifest,
            $this->deltaSlugs,
            $occupations,
            $states,
        );

        self::assertTrue($analysis['database_latest_index_state']['full_delta_match']);
        self::assertSame(1016, $analysis['database_latest_index_state']['matching_count']);
        self::assertSame(CareerPublicationIndexReconciliationApply::RECEIPT_SET_SHA256, $analysis['database_latest_index_state']['matching_set_sha256']);
        self::assertSame(0, $analysis['database_latest_index_state']['missing_or_mismatching_count']);
        self::assertSame(CareerPublicationIndexReconciliationApply::EMPTY_SET_SHA256, $analysis['database_latest_index_state']['missing_or_mismatching_set_sha256']);
        self::assertSame(0, $analysis['database_latest_index_state']['latest_state_missing_count']);
        self::assertSame(0, $analysis['database_latest_index_state']['latest_state_tie_count']);
        self::assertSame(30, $analysis['baseline_latest_index_state']['preserved_count']);
        self::assertSame(CareerPublicationIndexReconciliationApply::BASELINE_SET_SHA256, $analysis['baseline_latest_index_state']['preserved_set_sha256']);
        self::assertSame(30, $analysis['baseline_latest_index_state']['matching_count']);
        self::assertSame(0, $analysis['write_plan']['insert_count']);
        self::assertSame(0, $analysis['write_plan']['update_count']);
        self::assertSame(0, $analysis['write_plan']['delete_count']);
    }

    public function test_receipt_or_target_drift_is_fail_closed(): void
    {
        $receipts = $this->deltaSlugs;
        array_shift($receipts);
        $receipts[] = 'outside-frozen-target';

        $this->expectException(CareerPublicationIndexReconciliationApplyFailure::class);
        $this->expectExceptionMessage('RECEIPT_AUTHORITY_NOT_EXACT');

        CareerPublicationIndexReconciliationApply::analyze(
            $this->manifest,
            $receipts,
            $this->occupations(),
            [],
        );
    }

    public function test_baseline_state_hash_detects_any_baseline_mutation(): void
    {
        $occupations = $this->occupations();
        $beforeStates = $this->baselineStates($occupations);
        $before = CareerPublicationIndexReconciliationApply::analyze(
            $this->manifest,
            $this->deltaSlugs,
            $occupations,
            $beforeStates,
        );
        $afterStates = $beforeStates;
        $afterStates[0]['canonical_path'] = '/career/jobs/drifted-baseline-path';
        $after = CareerPublicationIndexReconciliationApply::analyze(
            $this->manifest,
            $this->deltaSlugs,
            $occupations,
            $afterStates,
        );

        self::assertNotSame(
            $before['baseline_latest_index_state']['current_state_sha256'],
            $after['baseline_latest_index_state']['current_state_sha256'],
        );
        self::assertSame(30, $after['baseline_latest_index_state']['preserved_count']);
        self::assertSame(29, $after['baseline_latest_index_state']['matching_count']);
    }

    public function test_workflow_is_manual_receipt_bound_transactional_and_narrow(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = file_get_contents($root.'/.github/workflows/career-publication-index-reconciliation-apply-production-ops.yml');
        $runner = file_get_contents($root.'/backend/scripts/operations/career_publication_index_reconciliation_apply.php');
        self::assertIsString($workflow);
        self::assertIsString($runner);

        self::assertStringContainsString("on:\n  workflow_dispatch:", $workflow);
        self::assertStringNotContainsString("\n  push:", $workflow);
        self::assertStringNotContainsString("\n  pull_request:", $workflow);
        self::assertStringNotContainsString("\n  schedule:", $workflow);
        self::assertStringNotContainsString('workflow_run:', $workflow);
        self::assertStringContainsString("permissions:\n  actions: read\n  contents: read", $workflow);
        self::assertStringContainsString('environment: production', $workflow);
        self::assertStringContainsString('expected_preflight_artifact_digest:', $workflow);
        self::assertStringContainsString('age_seconds', $workflow);
        self::assertStringContainsString('test "$age_seconds" -le 21600', $workflow);
        self::assertStringContainsString('current_state_sha256 == $current', $workflow);
        self::assertStringContainsString('matching_latest_state_count == 1016', $workflow);
        self::assertStringContainsString('baseline_preserved_count == 30', $workflow);
        self::assertStringContainsString('automatic_retry_allowed == false', $workflow);
        self::assertStringContainsString('if: always()', $workflow);
        self::assertStringContainsString('retention-days: 90', $workflow);

        self::assertStringContainsString('DB::transaction(', $runner);
        self::assertStringContainsString('->lockForUpdate()', $runner);
        self::assertStringContainsString('IndexState::query()->create([', $runner);
        self::assertStringContainsString("private const ALLOWED_WRITE_TABLE = 'index_states';", $runner);
        self::assertStringContainsString("private const TARGET_INDEX_STATE = 'indexed';", $runner);
        self::assertStringContainsString("'database_update_count' => 0", $runner);
        self::assertStringContainsString("'database_delete_count' => 0", $runner);

        foreach (['DB::statement(', 'DB::unprepared(', '->update(', '->delete(', 'Artisan::call(', 'file_put_contents(', 'rename('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $runner);
        }
        foreach (['cms_write_count', 'cache_write_count', 'artifact_write_count', 'projection_write_count', 'ledger_write_count', 'sitemap_write_count', 'llms_write_count', 'search_submission_count'] as $field) {
            self::assertStringContainsString("'{$field}' => 0", $runner);
        }
    }

    /** @return list<array{id:string,canonical_slug:string}> */
    private function occupations(): array
    {
        return array_map(
            static fn (string $slug, int $index): array => [
                'id' => 'occupation-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'canonical_slug' => $slug,
            ],
            $this->targetSlugs,
            array_keys($this->targetSlugs),
        );
    }

    /** @param list<array{id:string,canonical_slug:string}> $occupations @return list<array<string,mixed>> */
    private function baselineStates(array $occupations): array
    {
        $states = [];
        foreach ($this->baselineSlugs as $index => $slug) {
            $states[] = $this->matchingState($slug, 'baseline-state-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }

        return $states;
    }

    /** @return array<string, mixed> */
    private function matchingState(string $slug, string $id): array
    {
        $occupationIndex = array_search($slug, $this->targetSlugs, true);
        self::assertIsInt($occupationIndex);

        return [
            'id' => $id,
            'occupation_id' => 'occupation-'.str_pad((string) $occupationIndex, 4, '0', STR_PAD_LEFT),
            'index_state' => 'indexed',
            'index_eligible' => true,
            'canonical_path' => '/career/jobs/'.$slug,
            'canonical_target' => '',
            'reason_codes' => ['canonical_rollout_batch_promotion'],
            'changed_at' => '2026-08-12T00:00:00.000000+00:00',
            'created_at' => '2026-08-12T00:00:00.000000+00:00',
        ];
    }

    /** @return list<string> */
    private function normalized(mixed $values): array
    {
        self::assertIsArray($values);
        $result = array_values(array_unique(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $values,
        )));
        sort($result, SORT_STRING);

        return $result;
    }
}
