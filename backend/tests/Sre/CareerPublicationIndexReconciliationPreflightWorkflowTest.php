<?php

declare(strict_types=1);

namespace Tests\Sre;

use FermatMind\Operations\CareerPublicationIndexReconciliationPreflight;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/operations/career_publication_index_reconciliation_preflight.php';

final class CareerPublicationIndexReconciliationPreflightWorkflowTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $manifest;

    /** @var list<string> */
    private array $deltaSlugs;

    protected function setUp(): void
    {
        parent::setUp();

        $bytes = file_get_contents(dirname(__DIR__, 2).'/docs/seo/generated/detail-ready-1046-rollout-manifest.v2.json');
        self::assertIsString($bytes);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::MANIFEST_SHA256, hash('sha256', $bytes));
        $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $this->manifest = $manifest;
        $this->deltaSlugs = $this->normalized($manifest['delta_slugs'] ?? []);
    }

    public function test_frozen_receipts_and_missing_database_state_have_exact_canonical_hashes(): void
    {
        $analysis = CareerPublicationIndexReconciliationPreflight::analyze(
            $this->manifest,
            $this->deltaSlugs,
            $this->occupations(),
            [],
        );

        self::assertSame(30, $analysis['manifest']['baseline_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::BASELINE_SET_SHA256, $analysis['manifest']['baseline_set_sha256']);
        self::assertSame(1016, $analysis['manifest']['delta_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::RECEIPT_SET_SHA256, $analysis['manifest']['delta_set_sha256']);
        self::assertSame(1046, $analysis['manifest']['target_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::TARGET_SET_SHA256, $analysis['manifest']['target_set_sha256']);

        self::assertTrue($analysis['receipt_authority']['exact_delta_receipt_authority']);
        self::assertSame(1016, $analysis['receipt_authority']['authentic_receipt_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::RECEIPT_SET_SHA256, $analysis['receipt_authority']['authentic_receipt_set_sha256']);
        self::assertSame(0, $analysis['receipt_authority']['missing_receipt_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::EMPTY_SET_SHA256, $analysis['receipt_authority']['missing_receipt_set_sha256']);
        self::assertSame(0, $analysis['receipt_authority']['outside_target_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::EMPTY_SET_SHA256, $analysis['receipt_authority']['outside_target_set_sha256']);
        self::assertSame(0, $analysis['receipt_authority']['baseline_overlap_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::EMPTY_SET_SHA256, $analysis['receipt_authority']['baseline_overlap_set_sha256']);

        self::assertSame(1016, $analysis['database_latest_index_state']['current_state_row_count']);
        self::assertSame(0, $analysis['database_latest_index_state']['matching_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::EMPTY_SET_SHA256, $analysis['database_latest_index_state']['matching_set_sha256']);
        self::assertSame(1016, $analysis['database_latest_index_state']['missing_or_mismatching_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::RECEIPT_SET_SHA256, $analysis['database_latest_index_state']['missing_or_mismatching_set_sha256']);
        self::assertSame(0, $analysis['database_latest_index_state']['occupation_missing_count']);
        self::assertSame(1016, $analysis['database_latest_index_state']['latest_state_missing_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::RECEIPT_SET_SHA256, $analysis['database_latest_index_state']['latest_state_missing_set_sha256']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $analysis['database_latest_index_state']['current_state_sha256']);
    }

    public function test_exact_matching_latest_states_are_order_independent_and_fully_bound(): void
    {
        $occupations = $this->occupations();
        $states = [];
        foreach ($occupations as $index => $occupation) {
            $states[] = [
                'id' => sprintf('state-%04d', $index),
                'occupation_id' => $occupation['id'],
                'index_state' => 'indexed',
                'index_eligible' => true,
                'canonical_path' => '/en/career/jobs/'.$occupation['canonical_slug'],
                'canonical_target' => '',
                'reason_codes' => ['canonical_rollout_batch_promotion'],
                'changed_at' => '2026-08-12T00:00:00.000000+00:00',
                'created_at' => '2026-08-12T00:00:00.000000+00:00',
            ];
        }

        $first = CareerPublicationIndexReconciliationPreflight::analyze(
            $this->manifest,
            $this->deltaSlugs,
            $occupations,
            $states,
        );
        $second = CareerPublicationIndexReconciliationPreflight::analyze(
            $this->manifest,
            array_reverse($this->deltaSlugs),
            array_reverse($occupations),
            array_reverse($states),
        );

        self::assertSame(1016, $first['database_latest_index_state']['matching_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::RECEIPT_SET_SHA256, $first['database_latest_index_state']['matching_set_sha256']);
        self::assertSame(0, $first['database_latest_index_state']['missing_or_mismatching_count']);
        self::assertSame(CareerPublicationIndexReconciliationPreflight::EMPTY_SET_SHA256, $first['database_latest_index_state']['missing_or_mismatching_set_sha256']);
        self::assertTrue($first['database_latest_index_state']['full_delta_match']);
        self::assertSame(
            $first['database_latest_index_state']['current_state_sha256'],
            $second['database_latest_index_state']['current_state_sha256'],
        );
    }

    public function test_missing_and_outside_target_receipts_are_independently_hashed(): void
    {
        $receipts = $this->deltaSlugs;
        $missing = array_shift($receipts);
        self::assertIsString($missing);
        $receipts[] = 'outside-frozen-target';

        $analysis = CareerPublicationIndexReconciliationPreflight::analyze(
            $this->manifest,
            $receipts,
            $this->occupations(),
            [],
        );

        self::assertFalse($analysis['receipt_authority']['exact_delta_receipt_authority']);
        self::assertSame(1, $analysis['receipt_authority']['missing_receipt_count']);
        self::assertSame(
            CareerPublicationIndexReconciliationPreflight::setHash([$missing]),
            $analysis['receipt_authority']['missing_receipt_set_sha256'],
        );
        self::assertSame(1, $analysis['receipt_authority']['outside_target_count']);
        self::assertSame(
            CareerPublicationIndexReconciliationPreflight::setHash(['outside-frozen-target']),
            $analysis['receipt_authority']['outside_target_set_sha256'],
        );
    }

    public function test_latest_state_timestamp_ties_are_explicitly_refused(): void
    {
        $occupation = $this->occupations()[0];
        $state = [
            'occupation_id' => $occupation['id'],
            'index_state' => 'indexed',
            'index_eligible' => true,
            'canonical_path' => '/en/career/jobs/'.$occupation['canonical_slug'],
            'canonical_target' => '',
            'reason_codes' => ['canonical_rollout_batch_promotion'],
            'changed_at' => '2026-08-12T00:00:00.000000+00:00',
            'created_at' => '2026-08-12T00:00:00.000000+00:00',
        ];
        $states = [
            [...$state, 'id' => 'state-a'],
            [...$state, 'id' => 'state-b'],
        ];

        $analysis = CareerPublicationIndexReconciliationPreflight::analyze(
            $this->manifest,
            $this->deltaSlugs,
            $this->occupations(),
            $states,
        );

        self::assertSame(1, $analysis['database_latest_index_state']['latest_state_tie_count']);
        self::assertSame(
            CareerPublicationIndexReconciliationPreflight::setHash([$occupation['canonical_slug']]),
            $analysis['database_latest_index_state']['latest_state_tie_set_sha256'],
        );
        self::assertFalse($analysis['database_latest_index_state']['full_delta_match']);
    }

    public function test_workflow_is_manual_protected_select_only_and_always_uploads_receipt(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = file_get_contents($root.'/.github/workflows/career-publication-index-reconciliation-preflight-production-ops.yml');
        $runner = file_get_contents($root.'/backend/scripts/operations/career_publication_index_reconciliation_preflight.php');
        self::assertIsString($workflow);
        self::assertIsString($runner);

        self::assertStringContainsString("on:\n  workflow_dispatch:", $workflow);
        self::assertStringNotContainsString("\n  push:", $workflow);
        self::assertStringNotContainsString("\n  pull_request:", $workflow);
        self::assertStringNotContainsString("\n  schedule:", $workflow);
        self::assertStringNotContainsString('workflow_run:', $workflow);
        self::assertStringNotContainsString('repository_dispatch:', $workflow);
        self::assertStringContainsString("permissions:\n  actions: read\n  contents: read", $workflow);
        self::assertStringContainsString('environment: production', $workflow);
        self::assertStringContainsString('Initialize sanitized failure receipt before checkout', $workflow);
        self::assertStringContainsString('if: always()', $workflow);
        self::assertStringContainsString('retention-days: 90', $workflow);
        self::assertStringContainsString('database_select_only == true', $workflow);
        self::assertStringContainsString('observed_database_query_count >= 1', $workflow);
        self::assertStringContainsString('automatic_retry_allowed == false', $workflow);
        self::assertStringContainsString("array_values(array_unique(\$observedSqlVerbs)) !== ['select']", $runner);

        foreach (['->save(', '->insert(', '->update(', '->delete(', 'DB::statement(', 'Artisan::call(', 'file_put_contents(', 'rename('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $runner);
        }
        foreach (['database_write_count' => 0, 'cms_write_count' => 0, 'cache_write_count' => 0, 'artifact_write_count' => 0, 'search_submission_count' => 0] as $field => $value) {
            self::assertStringContainsString("'{$field}' => {$value}", $runner);
        }
    }

    /** @return list<array{id:string,canonical_slug:string}> */
    private function occupations(): array
    {
        return array_map(
            static fn (string $slug, int $index): array => [
                'id' => sprintf('occupation-%04d', $index),
                'canonical_slug' => $slug,
            ],
            $this->deltaSlugs,
            array_keys($this->deltaSlugs),
        );
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
