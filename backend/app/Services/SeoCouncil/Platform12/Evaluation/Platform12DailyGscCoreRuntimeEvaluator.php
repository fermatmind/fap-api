<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Evaluation;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use Carbon\CarbonImmutable;
use Throwable;

final readonly class Platform12DailyGscCoreRuntimeEvaluator
{
    private const SHA_PATTERN = '/^[a-f0-9]{40}$/D';

    public function __construct(private SeoRegistryHasher $hasher) {}

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    public function evaluate(array $evidence): array
    {
        try {
            $evaluatedAt = $this->evaluatedAt($evidence);
            $gsc = $this->gscProjection($evidence['gsc'] ?? null, $evaluatedAt);
            $runtime = $this->runtimeProjection($evidence['runtime'] ?? null);
            $state = $this->state($gsc, $runtime);
        } catch (Throwable) {
            $evaluatedAt = '1970-01-01T00:00:00Z';
            $gsc = $this->unavailableGsc();
            $runtime = $this->unavailableRuntime();
            $state = 'INPUT_HOLD';
        }

        $receipt = [
            'receipt_version' => 'seo.platform12_daily_gsc_core_runtime.v1',
            'mission_id' => 'seo.platform12.daily_gsc_core_runtime',
            'evaluated_at' => $evaluatedAt,
            'state' => $state,
            'gsc' => $gsc,
            'runtime' => $runtime,
            'read_only' => true,
            'execution_allowed' => false,
            'writes_allowed' => false,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @param mixed $source @return array<string, mixed> */
    private function gscProjection(mixed $source, string $evaluatedAt): array
    {
        if (! is_array($source)) {
            return $this->unavailableGsc();
        }
        $availability = $source['availability'] ?? null;
        $receiptStatus = $source['scheduled_receipt_status'] ?? null;
        $triggerMode = $source['trigger_mode'] ?? null;
        $mapping = $source['mapping_state'] ?? null;
        $quality = $source['data_quality_state'] ?? null;
        $window = $source['window_state'] ?? null;
        $rowCount = $source['row_count'] ?? null;
        if (! in_array($availability, ['AVAILABLE', 'UNAVAILABLE'], true)
            || ! in_array($mapping, ['READY', 'FAILED', 'UNAVAILABLE'], true)
            || ! in_array($quality, ['READY', 'HOLD', 'UNAVAILABLE'], true)
            || ! in_array($window, ['COMPLETE', 'INCOMPLETE', 'UNAVAILABLE'], true)
            || ! is_int($rowCount) || $rowCount < 0 || $rowCount > 100000000) {
            return $this->unavailableGsc();
        }
        if ($availability !== 'AVAILABLE' || $receiptStatus !== 'success'
            || ! in_array($triggerMode, ['scheduled', 'controlled_acceptance'], true)) {
            return $this->unavailableGsc();
        }

        $dataMaxDate = (string) ($source['data_max_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataMaxDate) !== 1) {
            return $this->unavailableGsc();
        }
        $maxDate = CarbonImmutable::createFromFormat('!Y-m-d', $dataMaxDate, 'UTC');
        $evaluation = CarbonImmutable::parse($evaluatedAt, 'UTC')->startOfDay();
        if ($maxDate === false || $maxDate->format('Y-m-d') !== $dataMaxDate || $maxDate->gt($evaluation)) {
            return $this->unavailableGsc();
        }
        $lagDays = intdiv($evaluation->getTimestamp() - $maxDate->getTimestamp(), 86400);
        $capability = match (true) {
            $mapping === 'FAILED' => 'MAPPING_FAILED',
            $mapping === 'UNAVAILABLE' || $quality === 'UNAVAILABLE' || $window === 'UNAVAILABLE' => 'UNAVAILABLE',
            $window === 'INCOMPLETE' => 'WINDOW_INCOMPLETE',
            $lagDays > 3 => 'DELAYED',
            $rowCount === 0 => 'VALID_ZERO',
            default => 'AVAILABLE',
        };

        return [
            'capability_state' => $capability,
            'scheduled_receipt_status' => 'success',
            'data_max_date' => $dataMaxDate,
            'lag_days' => $lagDays,
            'row_count' => $rowCount,
            'mapping_state' => $mapping,
            'data_quality_state' => $quality,
            'window_state' => $window,
            'source_read_only' => true,
        ];
    }

    /** @param mixed $source @return array<string, mixed> */
    private function runtimeProjection(mixed $source): array
    {
        if (! is_array($source)) {
            return $this->unavailableRuntime();
        }
        $core = $source['core_runtime_state'] ?? null;
        $publicApi = $source['public_api_state'] ?? null;
        $readback = $source['readback_state'] ?? null;
        $sha = $source['production_sha'] ?? null;
        $readbackSha = $source['readback_sha'] ?? null;
        if (! in_array($core, ['AVAILABLE', 'UNAVAILABLE', 'FAILED'], true)
            || ! in_array($publicApi, ['AVAILABLE', 'UNAVAILABLE', 'FAILED'], true)
            || ! in_array($readback, ['AVAILABLE', 'UNAVAILABLE', 'FAILED'], true)
            || ! is_string($sha) || preg_match(self::SHA_PATTERN, $sha) !== 1
            || ! is_string($readbackSha) || preg_match(self::SHA_PATTERN, $readbackSha) !== 1) {
            return $this->unavailableRuntime();
        }

        return [
            'core_runtime_state' => $core,
            'public_api_state' => $publicApi,
            'readback_state' => $readback,
            'production_sha' => $sha,
            'readback_sha' => $readbackSha,
            'sha_match' => hash_equals($sha, $readbackSha),
        ];
    }

    /** @param array<string,mixed> $gsc @param array<string,mixed> $runtime */
    private function state(array $gsc, array $runtime): string
    {
        return match (true) {
            $gsc['capability_state'] === 'UNAVAILABLE' => 'GSC_UNAVAILABLE_HOLD',
            $gsc['capability_state'] === 'MAPPING_FAILED' => 'MAPPING_FAILED_HOLD',
            $gsc['capability_state'] === 'WINDOW_INCOMPLETE' => 'WINDOW_INCOMPLETE_HOLD',
            $gsc['capability_state'] === 'DELAYED' || ($gsc['lag_days'] ?? 4) > 3 => 'DATA_FRESHNESS_HOLD',
            $gsc['data_quality_state'] !== 'READY' => 'DATA_QUALITY_HOLD',
            $runtime['core_runtime_state'] !== 'AVAILABLE'
                || $runtime['public_api_state'] !== 'AVAILABLE'
                || $runtime['readback_state'] !== 'AVAILABLE' => 'RUNTIME_UNAVAILABLE_HOLD',
            $runtime['sha_match'] !== true => 'RUNTIME_READBACK_HOLD',
            default => 'READY',
        };
    }

    private function evaluatedAt(array $evidence): string
    {
        $value = $evidence['evaluated_at'] ?? null;
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new \InvalidArgumentException('EVALUATION_TIME_INVALID');
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, 'UTC');
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new \InvalidArgumentException('EVALUATION_TIME_INVALID');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function unavailableGsc(): array
    {
        return [
            'capability_state' => 'UNAVAILABLE',
            'scheduled_receipt_status' => null,
            'data_max_date' => null,
            'lag_days' => null,
            'row_count' => null,
            'mapping_state' => 'UNAVAILABLE',
            'data_quality_state' => 'UNAVAILABLE',
            'window_state' => 'UNAVAILABLE',
            'source_read_only' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function unavailableRuntime(): array
    {
        return [
            'core_runtime_state' => 'UNAVAILABLE',
            'public_api_state' => 'UNAVAILABLE',
            'readback_state' => 'UNAVAILABLE',
            'production_sha' => null,
            'readback_sha' => null,
            'sha_match' => null,
        ];
    }
}
