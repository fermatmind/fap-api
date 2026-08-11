<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class RiasecLandingCmsExperiment01Test extends TestCase
{
    private const ROOT = 'docs/seo/riasec-landing-cms-experiment-01';

    public function test_exact_package_preserves_surface_and_discoverability_boundaries(): void
    {
        $before = $this->readJson('current_public_readback.json');
        $target = $this->readJson('target_internal_update.json');

        $this->assertTrue($before['ok'] ?? false);
        $surface = $before['surface'] ?? [];
        $this->assertSame('test_detail_holland_career_interest_test_riasec', $surface['surface_key'] ?? null);
        $this->assertSame('en', $surface['locale'] ?? null);
        $this->assertSame('en', $target['locale'] ?? null);
        $this->assertSame(0, $target['org_id'] ?? null);

        foreach (['schema_version', 'status', 'is_public', 'is_indexable', 'published_at', 'scheduled_at', 'page_blocks'] as $field) {
            $this->assertSame($surface[$field] ?? null, $target[$field] ?? null, $field.' must remain unchanged');
        }

        $this->assertSame(
            data_get($surface, 'payload_json.approved_internal_link_targets'),
            data_get($target, 'payload_json.approved_internal_link_targets'),
        );
        $this->assertSame('published', $target['status'] ?? null);
        $this->assertTrue($target['is_public'] ?? false);
        $this->assertTrue($target['is_indexable'] ?? false);
        $this->assertSame([], $target['page_blocks'] ?? null);
    }

    public function test_variant_matches_e02_copy_and_claim_boundaries(): void
    {
        $target = $this->readJson('target_internal_update.json');
        $payload = $target['payload_json'] ?? [];

        $this->assertSame('Free Holland Career Interest Test (RIASEC) | FermatMind', $target['title'] ?? null);
        $this->assertSame($target['title'] ?? null, $payload['seo_title'] ?? null);
        $this->assertSame($target['description'] ?? null, $payload['seo_description'] ?? null);
        $this->assertSame('Free Holland Career Interest Test (RIASEC)', $payload['h1_or_hero_title'] ?? null);
        $this->assertSame('Start 60-question form (about 8 min)', $payload['primary_cta_label'] ?? null);

        $visibleCopy = implode(' ', [
            (string) ($target['title'] ?? ''),
            (string) ($target['description'] ?? ''),
            (string) ($payload['hero_copy'] ?? ''),
            (string) ($payload['methodology_boundary_note'] ?? ''),
            (string) ($payload['aeo_answer_block'] ?? ''),
        ]);
        foreach (['60 questions', '140 questions', '8 minutes', '18 minutes', 'not more accurate', 'not aptitude', 'not recommendations'] as $required) {
            $this->assertStringContainsStringIgnoringCase($required, $visibleCopy);
        }
        foreach (['guaranteed career fit', 'hiring suitability', 'raw scores are not directly comparable'] as $requiredBoundary) {
            $this->assertStringContainsStringIgnoringCase($requiredBoundary, $visibleCopy);
        }

        foreach (['full report', 'most accurate', 'validated career fit', 'job guarantee', 'official holland instrument'] as $blocked) {
            $this->assertStringNotContainsStringIgnoringCase($blocked, $visibleCopy);
        }
    }

    public function test_measurement_plan_and_pending_receipt_fail_closed(): void
    {
        $measurement = $this->readJson('measurement_plan.json');
        $receipt = $this->readJson('t0_receipt.json');
        $productBaseline = $this->readJson('product_funnel_baseline.json');

        $this->assertSame('FERMATMIND-EN-RIASEC-CMS-EXPERIMENT-01', $measurement['experiment_id'] ?? null);
        $this->assertSame('9b7c470aa39aff0e6062c41fe5d71e2e8164159747953d42bd032046cc10f691', data_get($measurement, 'baseline.m01_joint_source_sha256'));
        $this->assertSame(['T+0', 'T+3', 'T+7', 'T+14', 'T+28'], array_column($measurement['checkpoints'] ?? [], 'label'));
        $this->assertContains('questions_load_failure', $measurement['product_measures'] ?? []);
        $this->assertContains('submit_failure', $measurement['product_measures'] ?? []);
        $this->assertContains('test_complete_by_form_code', $measurement['product_measures'] ?? []);
        $this->assertNotContains('test_submit_by_form_code', $measurement['product_measures'] ?? []);
        $this->assertNotContains('result_load_failure', $measurement['product_measures'] ?? []);
        $this->assertFalse($measurement['discoverability_change_authorized'] ?? true);
        $this->assertFalse($measurement['application_deploy_authorized'] ?? true);
        $this->assertSame(['from' => '2026-07-13', 'to' => '2026-08-09'], $productBaseline['date_window'] ?? null);
        $this->assertSame([
            'org_id' => 0,
            'surface_key' => 'test_detail_holland_career_interest_test_riasec',
            'canonical_path' => '/en/tests/holland-career-interest-test-riasec',
            'scale_code' => 'RIASEC',
            'form_codes' => ['riasec_60', 'riasec_140'],
            'locale' => 'en',
            'environment' => 'production',
        ], $productBaseline['filters'] ?? null);
        $this->assertTrue($productBaseline['read_only'] ?? false);
        $this->assertSame(0, $productBaseline['database_write_count'] ?? null);
        $this->assertSame(0, $productBaseline['cms_write_count'] ?? null);
        $this->assertSame(
            ['landing_and_product_funnel', 'attempt_result_funnel', 'failure_cohorts'],
            array_keys($productBaseline['required_sources'] ?? []),
        );
        $this->assertSame(
            [
                'source_table' => 'events',
                'source_builder' => 'App\\Services\\Analytics\\SeoConversionDailyBuilder::build',
                'materialized_table_used' => false,
                'date_column' => 'occurred_at',
                'date_filter' => '2026-07-13..2026-08-09 inclusive',
                'fixed_filters' => [
                    'org_id' => 0,
                    'canonical_path' => '/en/tests/holland-career-interest-test-riasec',
                    'take_path' => '/en/tests/holland-career-interest-test-riasec/take',
                    'url_identity_policy' => 'root_relative_or_exact_https_fermatmind_origin_then_normalized_path',
                    'approved_absolute_origins' => ['https://fermatmind.com'],
                    'accepted_url_examples' => [
                        '/en/tests/holland-career-interest-test-riasec',
                        'https://fermatmind.com/en/tests/holland-career-interest-test-riasec',
                        '/en/tests/holland-career-interest-test-riasec/take',
                        'https://fermatmind.com/en/tests/holland-career-interest-test-riasec/take',
                    ],
                    'lang' => 'en',
                    'scale_id' => 'RIASEC',
                ],
                'form_filter' => ['riasec_60', 'riasec_140'],
                'group_by' => 'builder safe dimensions followed by fixed event-path attribution and form_id aggregation',
                'event_path_attribution' => [
                    'landing_pv' => 'url canonical_path',
                    'start_test' => 'url take_path or source_url canonical_path',
                    'complete_test' => 'url take_path or source_url canonical_path',
                    'view_result' => 'url canonical_path, url take_path, or source_url canonical_path',
                ],
                'event_metric_map' => [
                    'landing_pv' => 'landing_view',
                    'start_test' => 'test_start',
                    'complete_test' => 'test_complete',
                    'view_result' => 'riasec_result_view',
                ],
                'skipped_source_rows_required' => 0,
            ],
            data_get($productBaseline, 'required_sources.landing_and_product_funnel.query_contract'),
        );
        $this->assertSame(
            ['ok' => true, 'allowed_statuses' => ['pass'], 'issues' => []],
            data_get($productBaseline, 'required_sources.landing_and_product_funnel.health_contract'),
        );
        $this->assertSame(
            ['ok' => true, 'allowed_statuses' => ['pass'], 'issues' => []],
            data_get($productBaseline, 'required_sources.attempt_result_funnel.health_contract'),
        );
        $this->assertSame(
            ['ok' => true, 'allowed_statuses' => ['pass', 'empty'], 'issues' => []],
            data_get($productBaseline, 'required_sources.failure_cohorts.health_contract'),
        );
        $this->assertSame(
            'MeasurementFunnelReadModel::report for 2026-07-13..2026-08-09/org=0/scale=RIASEC/locale=en followed by an exact riasec_60+riasec_140 form_code projection with recomputed totals and coverage health',
            data_get($productBaseline, 'required_sources.attempt_result_funnel.authority'),
        );
        $this->assertSame(
            'analytics:measurement-failure-cohorts-report --from=2026-07-13 --to=2026-08-09 --org=0 --scale=RIASEC --form=riasec_60 --form=riasec_140 --locale=en --json',
            data_get($productBaseline, 'required_sources.failure_cohorts.authority'),
        );
        $this->assertSame(
            [
                'landing_view' => [
                    'source' => 'landing_and_product_funnel',
                    'json_path' => '$.totals.landing_view',
                    'aggregation' => 'sum both fixed form rows',
                ],
                'test_start_by_form_code' => [
                    'source' => 'landing_and_product_funnel',
                    'json_path' => '$.by_form_code.{form_code}.test_start',
                    'aggregation' => 'exact fixed form row',
                ],
                'test_complete_by_form_code' => [
                    'source' => 'landing_and_product_funnel',
                    'json_path' => '$.by_form_code.{form_code}.test_complete',
                    'aggregation' => 'exact fixed form row',
                ],
                'riasec_result_view_by_form_code' => [
                    'source' => 'landing_and_product_funnel',
                    'json_path' => '$.by_form_code.{form_code}.riasec_result_view',
                    'aggregation' => 'exact fixed form row',
                ],
                'questions_load_failure' => [
                    'source' => 'failure_cohorts',
                    'json_path' => '$.cohorts.questions_load_failure.failed_attempt_count',
                    'aggregation' => 'privacy-safe distinct failed attempts',
                ],
                'submit_failure' => [
                    'source' => 'failure_cohorts',
                    'json_path' => '$.cohorts.submit_failure.failed_attempt_count',
                    'aggregation' => 'privacy-safe distinct failed attempts',
                ],
            ],
            $productBaseline['total_source_mappings'] ?? null,
        );

        $receiptStatus = $receipt['status'] ?? null;
        $this->assertContains($receiptStatus, ['pending_apply', 'live_readback_pass', 'rolled_back']);
        if ($receiptStatus === 'pending_apply') {
            $this->assertNull($receipt['applied_at'] ?? null);
            $this->assertNull($receipt['readback_passed_at'] ?? null);
            $this->assertNull($receipt['rolled_back_at'] ?? null);
            $this->assertNull($receipt['public_api_readback_sha256'] ?? null);
            $this->assertNull($receipt['bridge_apply_audit'] ?? null);
            $this->assertNull($receipt['bridge_rollback_audit'] ?? null);
            $this->assertSame('not_needed', $receipt['rollback_status'] ?? null);
            foreach ($measurement['checkpoints'] ?? [] as $checkpoint) {
                $this->assertNull($checkpoint['due_at'] ?? null);
            }
        }

        $baselineStatus = $productBaseline['capture_status'] ?? null;
        $this->assertContains($baselineStatus, ['pending_preapply_production_read', 'frozen_preapply_production_read']);
        if ($baselineStatus === 'pending_preapply_production_read') {
            $this->assertSame('pending_apply', $receiptStatus);
            $this->assertSame('pending_preapply_production_read', $productBaseline['capture_status'] ?? null);
            $this->assertNull(data_get($measurement, 'baseline.product_funnel_source_sha256'));
            foreach ($productBaseline['required_sources'] ?? [] as $source) {
                $this->assertSame('pending', $source['status'] ?? null);
                $this->assertNull($source['report_sha256'] ?? null);
                $this->assertNull($source['report_health'] ?? null);
            }

            return;
        }

        $this->assertSame('frozen_preapply_production_read', $productBaseline['capture_status'] ?? null);
        $capturedAt = $this->absoluteTimestamp($productBaseline['captured_at'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) ($productBaseline['active_backend_revision'] ?? ''));
        $this->assertSame([], $productBaseline['issues'] ?? null);
        $this->assertSame(
            hash_file('sha256', base_path(self::ROOT.'/product_funnel_baseline.json')),
            data_get($measurement, 'baseline.product_funnel_source_sha256'),
        );
        foreach ($productBaseline['required_sources'] ?? [] as $source) {
            $this->assertSame('captured', $source['status'] ?? null);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($source['report_sha256'] ?? ''));
            $health = $source['report_health'] ?? null;
            $this->assertIsArray($health);
            $this->assertTrue($health['ok'] ?? false);
            $this->assertContains($health['status'] ?? null, data_get($source, 'health_contract.allowed_statuses', []));
            $this->assertSame([], $health['issues'] ?? null);
            $this->assertSame(data_get($source, 'health_contract.ok'), $health['ok'] ?? null);
            $this->assertSame(data_get($source, 'health_contract.issues'), $health['issues'] ?? null);
        }
        $this->assertIsInt(data_get($productBaseline, 'totals.landing_view'));
        foreach (['test_start_by_form_code', 'test_complete_by_form_code', 'riasec_result_view_by_form_code'] as $metric) {
            $this->assertSame(['riasec_60', 'riasec_140'], array_keys((array) data_get($productBaseline, 'totals.'.$metric)));
            foreach ((array) data_get($productBaseline, 'totals.'.$metric) as $value) {
                $this->assertIsInt($value);
            }
        }
        $this->assertIsInt(data_get($productBaseline, 'totals.questions_load_failure'));
        $this->assertIsInt(data_get($productBaseline, 'totals.submit_failure'));

        if ($receiptStatus === 'pending_apply') {
            return;
        }

        $this->assertSame('064b9e15eb8eae102623306487c4b63635b7500a32925706f14688158734e3f1', $receipt['target_package_sha256'] ?? null);
        $appliedAt = $this->absoluteTimestamp($receipt['applied_at'] ?? null);
        $this->assertGreaterThanOrEqual($capturedAt->getTimestamp(), $appliedAt->getTimestamp());
        $this->assertBridgeAudit($receipt['bridge_apply_audit'] ?? null, 'riasec_global_cms_apply', 'applied', $appliedAt);
        $this->assertSame(
            $productBaseline['active_backend_revision'] ?? null,
            data_get($receipt, 'bridge_apply_audit.deployed_sha'),
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($receipt['public_api_readback_sha256'] ?? ''));
        $this->assertSame('published', data_get($receipt, 'authority_guardrails.status'));
        $this->assertTrue(data_get($receipt, 'authority_guardrails.is_public'));
        $this->assertTrue(data_get($receipt, 'authority_guardrails.is_indexable'));
        $this->assertTrue(data_get($receipt, 'authority_guardrails.page_blocks_unchanged'));
        $this->assertTrue(data_get($receipt, 'authority_guardrails.canonical_unchanged'));
        foreach (['sitemap_change_triggered', 'llms_change_triggered', 'search_submission_triggered', 'application_deploy_triggered'] as $guardrail) {
            $this->assertFalse(data_get($receipt, 'authority_guardrails.'.$guardrail));
        }
        $this->assertIsArray($receipt['issues'] ?? null);
        $this->assertSame('https://fermatmind.com/en/tests/holland-career-interest-test-riasec', data_get($receipt, 'rendered_readback.canonical'));
        $this->assertRiasecCtaHref((string) data_get($receipt, 'rendered_readback.default_cta_href'), 'riasec_60');
        $this->assertRiasecCtaHref((string) data_get($receipt, 'rendered_readback.enhanced_cta_href'), 'riasec_140');

        if ($receiptStatus === 'live_readback_pass') {
            $this->assertSame([], $receipt['issues'] ?? null);
            $readbackPassedAt = $this->absoluteTimestamp($receipt['readback_passed_at'] ?? null);
            $this->assertGreaterThanOrEqual(
                $appliedAt->getTimestamp(),
                $readbackPassedAt->getTimestamp(),
            );
            $this->assertCheckpointDueAts($measurement['checkpoints'] ?? [], $readbackPassedAt);
            $this->assertNull($receipt['rolled_back_at'] ?? null);
            $this->assertNull($receipt['bridge_rollback_audit'] ?? null);
            $this->assertSame('not_needed', $receipt['rollback_status'] ?? null);
            $this->assertSame('Free Holland Career Interest Test (RIASEC) | FermatMind', data_get($receipt, 'rendered_readback.title'));
            $this->assertSame('Explore six RIASEC career-interest dimensions with a free Holland Code test. Choose 60 questions (about 8 minutes) or 140 questions (about 18 minutes). Interests are not aptitude or guaranteed career fit.', data_get($receipt, 'rendered_readback.meta_description'));
            $this->assertSame('Free Holland Career Interest Test (RIASEC)', data_get($receipt, 'rendered_readback.h1'));

            return;
        }

        $this->assertSame('rolled_back', $receipt['status'] ?? null);
        $rolledBackAt = $this->absoluteTimestamp($receipt['rolled_back_at'] ?? null);
        $this->assertBridgeAudit($receipt['bridge_rollback_audit'] ?? null, 'riasec_global_cms_rollback', 'rolled_back', $rolledBackAt);
        $this->assertGreaterThanOrEqual($appliedAt->getTimestamp(), $rolledBackAt->getTimestamp());
        if (($receipt['readback_passed_at'] ?? null) === null) {
            foreach ($measurement['checkpoints'] ?? [] as $checkpoint) {
                $this->assertNull($checkpoint['due_at'] ?? null);
            }
        } else {
            $readbackPassedAt = $this->absoluteTimestamp($receipt['readback_passed_at']);
            $this->assertGreaterThanOrEqual($appliedAt->getTimestamp(), $readbackPassedAt->getTimestamp());
            $this->assertGreaterThanOrEqual($readbackPassedAt->getTimestamp(), $rolledBackAt->getTimestamp());
            $this->assertCheckpointDueAts($measurement['checkpoints'] ?? [], $readbackPassedAt);
        }
        $this->assertSame('completed', $receipt['rollback_status'] ?? null);
        $this->assertSame('Free Holland Career Interest Test | RIASEC Full Report', data_get($receipt, 'rendered_readback.title'));
        $this->assertSame('Take the Holland career interest test free and get your RIASEC interest pattern, career exploration cues, and full report.', data_get($receipt, 'rendered_readback.meta_description'));
        $this->assertSame('Free Holland Career Interest Test with Full Report', data_get($receipt, 'rendered_readback.h1'));
    }

    public function test_manifest_binds_every_exact_artifact(): void
    {
        $manifest = $this->readJson('manifest.json');
        $this->assertSame('064b9e15eb8eae102623306487c4b63635b7500a32925706f14688158734e3f1', $manifest['target_package_sha256'] ?? null);
        $this->assertFalse($manifest['production_cms_write_authorized_by_user'] ?? true);
        $this->assertSame('pending_fresh_production_preflight', $manifest['production_cms_write_authorization_status'] ?? null);
        $this->assertFalse($manifest['application_deploy_authorized'] ?? true);
        $this->assertFalse($manifest['discoverability_change_authorized'] ?? true);
        $this->assertSame([
            'current_public_readback.json',
            'target_internal_update.json',
            'product_funnel_baseline.json',
            'measurement_plan.json',
            't0_receipt.json',
        ], array_keys($manifest['files'] ?? []));

        foreach ($manifest['files'] ?? [] as $name => $expectedSha) {
            $path = base_path(self::ROOT.'/'.$name);
            $this->assertFileExists($path);
            $this->assertSame($expectedSha, hash_file('sha256', $path), $name.' SHA mismatch');
        }
    }

    public function test_apply_contract_requires_the_atomic_bridge_and_never_generic_put(): void
    {
        $readme = (string) file_get_contents(base_path(self::ROOT.'/README.md'));

        $this->assertStringContainsString('RiasecGlobalCmsApplyBridge', $readme);
        $this->assertStringContainsString('/ops/riasec-global-cms-apply', $readme);
        $this->assertStringContainsString('pending_fresh_production_preflight', $this->readFile('manifest.json'));
        $this->assertStringContainsString('exact active backend `REVISION` and managed release-directory identity', $readme);
        $this->assertStringContainsString('session-bound `preflight_fingerprint`', $readme);
        $this->assertStringContainsString('15-minute lifetime', $readme);
        $this->assertStringContainsString('product_funnel_baseline.json', $readme);
        $this->assertStringContainsString('pending_preapply_production_read', $readme);
        $this->assertStringContainsString('immutable sanitized audit-record SHA-256', $readme);
        $this->assertStringContainsString('exact generated phrase', $readme);
        $this->assertStringContainsString('consumes the apply authorization', $readme);
        $this->assertStringContainsString('Apply authorization never authorizes rollback', $readme);
        $this->assertStringContainsString('`already_applied` still requires its own fresh apply preflight', $readme);
        $this->assertStringContainsString('`already_rolled_back` retry also requires a fresh rollback preflight', $readme);
        $this->assertStringContainsString('lockForUpdate', $readme);
        $this->assertStringContainsString('never passes `page_blocks`', $readme);
        $this->assertStringContainsString('Do not call the generic internal landing-surface PUT', $readme);
    }

    /** @return array<string, mixed> */
    private function readJson(string $name): array
    {
        $decoded = json_decode($this->readFile($name), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function readFile(string $name): string
    {
        $path = base_path(self::ROOT.'/'.$name);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function assertRiasecCtaHref(string $href, string $expectedForm): void
    {
        $parts = parse_url($href);
        $this->assertIsArray($parts);

        if (array_key_exists('host', $parts)) {
            $this->assertSame('https', $parts['scheme'] ?? null);
            $this->assertSame('fermatmind.com', strtolower((string) ($parts['host'] ?? '')));
            foreach (['port', 'user', 'pass'] as $forbiddenAuthorityPart) {
                $this->assertArrayNotHasKey($forbiddenAuthorityPart, $parts);
            }
        } else {
            $this->assertStringStartsWith('/', $href);
            foreach (['scheme', 'host', 'port', 'user', 'pass'] as $forbiddenRelativePart) {
                $this->assertArrayNotHasKey($forbiddenRelativePart, $parts);
            }
        }

        $this->assertArrayNotHasKey('fragment', $parts);
        $this->assertSame('/en/tests/holland-career-interest-test-riasec/take', $parts['path'] ?? null);
        $this->assertSame('form='.rawurlencode($expectedForm), $parts['query'] ?? null);
    }

    /** @param list<array<string, mixed>> $checkpoints */
    private function assertCheckpointDueAts(array $checkpoints, \DateTimeImmutable $anchor): void
    {
        $expectedOffsets = ['T+0' => 0, 'T+3' => 3, 'T+7' => 7, 'T+14' => 14, 'T+28' => 28];
        $this->assertSame(array_keys($expectedOffsets), array_column($checkpoints, 'label'));

        foreach ($checkpoints as $checkpoint) {
            $label = (string) ($checkpoint['label'] ?? '');
            $dueAt = $this->absoluteTimestamp($checkpoint['due_at'] ?? null);
            $this->assertSame(
                $anchor->modify('+'.$expectedOffsets[$label].' days')->getTimestamp(),
                $dueAt->getTimestamp(),
                $label.' deadline must use the readback clock',
            );
        }
    }

    private function absoluteTimestamp(mixed $value): \DateTimeImmutable
    {
        $this->assertIsString($value);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
            $value,
        );

        return new \DateTimeImmutable($value);
    }

    private function assertBridgeAudit(mixed $audit, string $expectedAction, string $expectedResult, \DateTimeImmutable $eventAt): void
    {
        $this->assertIsArray($audit);
        $this->assertSame($expectedAction, $audit['action'] ?? null);
        $this->assertSame($expectedResult, $audit['result'] ?? null);
        $this->assertSame('landing_surface', $audit['target_type'] ?? null);
        $this->assertSame('test_detail_holland_career_interest_test_riasec', $audit['target_id'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) ($audit['deployed_sha'] ?? ''));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', (string) ($audit['release_id'] ?? ''));
        foreach (['preflight_fingerprint', 'operator_approval_phrase_sha256', 'audit_record_sha256'] as $hashField) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($audit[$hashField] ?? ''));
        }
        $this->assertSame(
            $eventAt->getTimestamp(),
            $this->absoluteTimestamp($audit['audit_created_at'] ?? null)->getTimestamp(),
        );
    }
}
