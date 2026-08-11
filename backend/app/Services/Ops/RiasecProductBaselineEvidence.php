<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Carbon\CarbonImmutable;
use JsonException;
use RuntimeException;
use Throwable;

/** @review-surface riasec_content_release_review */
final class RiasecProductBaselineEvidence
{
    private const RECEIPT_SCHEMA = 'fermatmind.production-riasec-product-baseline.v1';

    private const RECEIPT_STATUS = 'PASS_PRODUCTION_RIASEC_PRODUCT_BASELINE';

    private const LANDING_SCHEMA = 'fermatmind.riasec-landing-product-funnel.v1';

    private const FUNNEL_SCHEMA = 'fermatmind.measurement-funnel.v2';

    private const FAILURE_SCHEMA = 'fermatmind.measurement-failure-cohorts.v2';

    private const FROM = '2026-07-13';

    private const TO = '2026-08-09';

    private const MAX_AGE_SECONDS = 7200;

    /** @var list<string> */
    private const FORMS = ['riasec_60', 'riasec_140'];

    /** @var list<string> */
    private const FUNNEL_METRICS = [
        'attempt_started_count',
        'test_completed_count',
        'result_ready_count',
        'result_ready_event_count',
        'result_ready_duplicate_event_count',
    ];

    /** @var list<string> */
    private const NEGATIVE_GUARANTEES = [
        'deploy',
        'migration',
        'database_write',
        'cms_write',
        'cache_write',
        'publication',
        'discoverability_change',
        'queue_action',
        'process_restart',
        'remote_file_write',
        'raw_log_read',
        'search_submit',
    ];

    /**
     * @return array<string,mixed>
     */
    public function validate(
        string $receiptJson,
        string $landingJson,
        string $funnelJson,
        string $failureJson,
        string $expectedDeployedSha,
        string $expectedReleaseId,
    ): array {
        $receipt = $this->decodeObject($receiptJson, 'production baseline receipt');
        $landing = $this->decodeObject($landingJson, 'landing and product funnel report');
        $funnel = $this->decodeObject($funnelJson, 'attempt and result funnel report');
        $failure = $this->decodeObject($failureJson, 'failure cohorts report');

        $sourceHashes = [
            'landing_and_product_funnel' => hash('sha256', $landingJson),
            'attempt_result_funnel' => hash('sha256', $funnelJson),
            'failure_cohorts' => hash('sha256', $failureJson),
        ];

        $this->assertReceipt($receipt, $sourceHashes, $expectedDeployedSha, $expectedReleaseId);
        $landingTotals = $this->assertLanding($landing);
        $this->assertFunnel($funnel);
        $failureTotals = $this->assertFailure($failure);

        $expectedTotals = $landingTotals + $failureTotals;
        if (! $this->same($receipt['totals'] ?? null, $expectedTotals)) {
            throw new RuntimeException('Production baseline receipt totals do not match the source reports.');
        }

        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'receipt_sha256' => hash('sha256', $receiptJson),
            'control_plane_sha' => $receipt['control_plane_sha'],
            'active_revision' => $receipt['active_revision'],
            'release_id' => $receipt['release_id'],
            'checked_at' => $receipt['checked_at'],
            'source_report_sha256' => $sourceHashes,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,string>  $sourceHashes
     */
    private function assertReceipt(
        array $receipt,
        array $sourceHashes,
        string $expectedDeployedSha,
        string $expectedReleaseId,
    ): void {
        $checkedAt = (string) ($receipt['checked_at'] ?? '');
        $checkedAtValue = null;
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $checkedAt) === 1) {
            try {
                $candidate = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $checkedAt, 'UTC');
                if ($candidate->format('Y-m-d\TH:i:s\Z') === $checkedAt) {
                    $checkedAtValue = $candidate;
                }
            } catch (Throwable) {
                $checkedAtValue = null;
            }
        }
        $age = $checkedAtValue instanceof CarbonImmutable ? now()->getTimestamp() - $checkedAtValue->getTimestamp() : null;

        if (
            ($receipt['schema_version'] ?? null) !== self::RECEIPT_SCHEMA
            || ($receipt['status'] ?? null) !== self::RECEIPT_STATUS
            || ! array_key_exists('failed_check', $receipt)
            || $receipt['failed_check'] !== null
            || preg_match('/\A[0-9a-f]{40}\z/', (string) ($receipt['control_plane_sha'] ?? '')) !== 1
            || ($receipt['active_revision'] ?? null) !== $expectedDeployedSha
            || ($receipt['release_id'] ?? null) !== $expectedReleaseId
            || ! $checkedAtValue instanceof CarbonImmutable
            || ! is_int($age)
            || $age < 0
            || $age > self::MAX_AGE_SECONDS
            || ($receipt['writes_committed'] ?? null) !== false
            || ! $this->allNegative($receipt['negative_guarantees'] ?? null)
            || ! $this->same($receipt['source_report_sha256'] ?? null, $sourceHashes)
        ) {
            throw new RuntimeException('Production baseline receipt contract mismatch.');
        }

        $health = $receipt['source_health'] ?? null;
        if (
            ! is_array($health)
            || ! $this->same($health['landing_and_product_funnel'] ?? null, ['ok' => true, 'status' => 'pass', 'issues' => []])
            || ! $this->same($health['attempt_result_funnel'] ?? null, ['ok' => true, 'status' => 'pass', 'issues' => []])
            || ! in_array(($health['failure_cohorts']['status'] ?? null), ['pass', 'empty'], true)
            || ($health['failure_cohorts']['ok'] ?? null) !== true
            || ($health['failure_cohorts']['issues'] ?? null) !== []
        ) {
            throw new RuntimeException('Production baseline receipt source health mismatch.');
        }
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function assertLanding(array $report): array
    {
        $filters = [
            'canonical_path' => '/en/tests/holland-career-interest-test-riasec',
            'take_path' => '/en/tests/holland-career-interest-test-riasec/take',
            'url_identity_policy' => 'root_relative_or_exact_https_fermatmind_origin_then_normalized_path',
            'approved_absolute_origins' => ['https://fermatmind.com'],
            'event_path_attribution' => [
                'landing_pv' => 'url canonical_path; form_id is not required',
                'start_test' => 'url take_path or source_url canonical_path',
                'complete_test' => 'url take_path or source_url canonical_path',
                'view_result' => 'url canonical_path, url take_path, or source_url canonical_path',
            ],
            'lang' => 'en',
            'scale_id' => 'RIASEC',
            'form_ids' => self::FORMS,
        ];
        $authority = $report['authority'] ?? null;
        $landingView = $this->nonNegativeInt(data_get($report, 'totals.landing_view'));
        $sourceCount = $this->nonNegativeInt(data_get($authority, 'scoped_source_event_count'));
        $projectedCount = $this->nonNegativeInt(data_get($authority, 'scoped_projected_event_count'));

        if (
            ! $this->healthyReport($report, self::LANDING_SCHEMA, ['pass'])
            || ! $this->same($report['filters'] ?? null, $filters)
            || ! is_array($authority)
            || ($authority['source_table'] ?? null) !== 'events'
            || ($authority['source_builder'] ?? null) !== 'App\\Services\\Analytics\\SeoConversionDailyBuilder'
            || ($authority['materialized_table_used'] ?? null) !== false
            || ($authority['scoped_source_reconciliation'] ?? null) !== 'exact'
            || $this->nonNegativeInt($authority['unscoped_builder_skipped_rows'] ?? null) === null
            || $this->nonNegativeInt($authority['matched_source_rows'] ?? null) === null
            || $landingView === null
            || $sourceCount === null
            || $projectedCount === null
            || $sourceCount !== $projectedCount
        ) {
            throw new RuntimeException('Landing and product funnel baseline contract mismatch.');
        }

        $byForm = [];
        foreach (self::FORMS as $form) {
            $row = $report['by_form_code'][$form] ?? null;
            if (! is_array($row)) {
                throw new RuntimeException('Landing and product funnel form totals are missing.');
            }
            foreach (['test_start', 'test_complete', 'riasec_result_view'] as $metric) {
                $value = $this->nonNegativeInt($row[$metric] ?? null);
                if ($value === null) {
                    throw new RuntimeException('Landing and product funnel form totals are invalid.');
                }
                $byForm[$metric][$form] = $value;
            }
        }

        $computedProjection = $landingView;
        foreach ($byForm as $values) {
            $computedProjection += array_sum($values);
        }
        if ($computedProjection !== $projectedCount) {
            throw new RuntimeException('Landing and product funnel source reconciliation mismatch.');
        }

        return [
            'landing_view' => $landingView,
            'test_start_by_form_code' => $byForm['test_start'],
            'test_complete_by_form_code' => $byForm['test_complete'],
            'riasec_result_view_by_form_code' => $byForm['riasec_result_view'],
        ];
    }

    /** @param array<string,mixed> $report */
    private function assertFunnel(array $report): void
    {
        if (
            ! $this->healthyReport($report, self::FUNNEL_SCHEMA, ['pass'])
            || ! $this->same($report['filters'] ?? null, [
                'scale_codes' => ['RIASEC'],
                'locales' => ['en'],
                'form_codes' => self::FORMS,
            ])
            || ! is_array($report['rows'] ?? null)
            || ($report['row_count'] ?? null) !== count($report['rows'])
        ) {
            throw new RuntimeException('Attempt and result funnel baseline contract mismatch.');
        }

        $totals = array_fill_keys(self::FUNNEL_METRICS, 0);
        foreach ($report['rows'] as $row) {
            if (
                ! is_array($row)
                || data_get($row, 'dimensions.scale_code') !== 'RIASEC'
                || data_get($row, 'dimensions.locale') !== 'en'
                || ! in_array(data_get($row, 'dimensions.form_code'), self::FORMS, true)
            ) {
                throw new RuntimeException('Attempt and result funnel row boundary mismatch.');
            }
            foreach (self::FUNNEL_METRICS as $metric) {
                $value = $this->nonNegativeInt(data_get($row, 'metrics.'.$metric));
                if ($value === null) {
                    throw new RuntimeException('Attempt and result funnel metric mismatch.');
                }
                $totals[$metric] += $value;
            }
        }

        foreach ($totals as $metric => $value) {
            if (($report['totals'][$metric] ?? null) !== $value) {
                throw new RuntimeException('Attempt and result funnel totals mismatch.');
            }
        }
        if (($report['totals']['result_ready_event_coverage_status'] ?? null) !== 'complete') {
            throw new RuntimeException('Attempt and result funnel coverage is not complete.');
        }
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,int>
     */
    private function assertFailure(array $report): array
    {
        $expectedFilters = [
            'scale_code' => ['RIASEC'],
            'form_code' => self::FORMS,
            'locale' => ['en'],
            'device_class' => [],
            'browser_class' => [],
            'endpoint_class' => [],
            'status_group' => [],
            'error_class' => [],
        ];
        if (
            ! $this->healthyReport($report, self::FAILURE_SCHEMA, ['pass', 'empty'])
            || ! $this->same($report['filters'] ?? null, $expectedFilters)
        ) {
            throw new RuntimeException('Failure cohort baseline contract mismatch.');
        }

        $totals = [];
        foreach (['questions_load_failure', 'submit_failure'] as $event) {
            $value = $this->nonNegativeInt(data_get($report, 'cohorts.'.$event.'.failed_attempt_count'));
            if ($value === null) {
                throw new RuntimeException('Failure cohort totals are invalid.');
            }
            $totals[$event] = $value;
        }

        return $totals;
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  list<string>  $allowedStatuses
     */
    private function healthyReport(array $report, string $schema, array $allowedStatuses): bool
    {
        return ($report['schema_version'] ?? null) === $schema
            && ($report['ok'] ?? null) === true
            && in_array(($report['status'] ?? null), $allowedStatuses, true)
            && ($report['issues'] ?? null) === []
            && ($report['from'] ?? null) === self::FROM
            && ($report['to'] ?? null) === self::TO
            && ($report['org_id'] ?? null) === 0
            && ($report['read_only'] ?? null) === true;
    }

    private function allNegative(mixed $guarantees): bool
    {
        if (! is_array($guarantees) || array_is_list($guarantees)) {
            return false;
        }
        foreach (self::NEGATIVE_GUARANTEES as $key) {
            if (($guarantees[$key] ?? null) !== false) {
                return false;
            }
        }

        $actualKeys = array_keys($guarantees);
        $expectedKeys = self::NEGATIVE_GUARANTEES;
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("Invalid {$label} JSON.");
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("Invalid {$label} JSON object.");
        }

        return $decoded;
    }

    private function same(mixed $left, mixed $right): bool
    {
        return $this->canonicalize($left) === $this->canonicalize($right);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
