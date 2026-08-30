<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

final class MeasurementEvidenceLoadResult
{
    public const NONE = 'NONE';

    public const OFFLINE_NOT_LOADED = 'OFFLINE_NOT_LOADED';

    public const COMMON_HOLDS = [
        'BUNDLE_PRIVACY_HOLD',
        'BUNDLE_VERIFICATION_HOLD',
        'CONTEXT_HOLD',
        'INTERNAL_SAFE_HOLD',
    ];

    public const SEARCH_HOLDS = [
        'GSC_SCHEMA_UNAVAILABLE',
        'GSC_NO_ELIGIBLE_ROWS',
        'GSC_QUALITY_HOLD',
        'GSC_WINDOW_INCOMPLETE',
        'GSC_STALE',
        'GSC_MAPPING_FAILED',
        'GSC_AUTHORITY_CONFLICT',
        'GSC_READMODEL_UNHEALTHY',
    ];

    public const CRO_HOLDS = [
        'CRO_SCHEMA_UNAVAILABLE',
        'CRO_READMODEL_UNHEALTHY',
        'CRO_WINDOW_INCOMPLETE',
        'CRO_STALE',
        'CRO_MAPPING_FAILED',
        'CRO_STAGE_COVERAGE_INCOMPLETE',
    ];

    /**
     * @param  list<array<string, mixed>>  $bundles
     */
    private function __construct(
        private readonly string $modeId,
        private readonly array $bundles,
        private readonly string $sourceState,
        private readonly string $freshnessState,
        private readonly string $holdReason,
        private readonly string $authorityRevision,
    ) {}

    /** @param list<array<string, mixed>> $bundles */
    public static function make(
        string $modeId,
        array $bundles,
        string $sourceState,
        string $freshnessState,
        string $holdReason,
        ?string $authorityRevision = null,
    ): self {
        $modeId = in_array($modeId, ['search_measurement', 'commercial_funnel_cro'], true)
            ? $modeId
            : 'search_measurement';
        $allowedReasons = [
            self::NONE,
            self::OFFLINE_NOT_LOADED,
            ...self::COMMON_HOLDS,
            ...($modeId === 'search_measurement' ? self::SEARCH_HOLDS : self::CRO_HOLDS),
        ];
        if (! in_array($holdReason, $allowedReasons, true)) {
            $holdReason = 'INTERNAL_SAFE_HOLD';
            $bundles = [];
            $sourceState = 'unavailable';
            $freshnessState = 'unknown';
        }
        if (! in_array($sourceState, ['available', 'held', 'unavailable', 'offline_not_loaded'], true)) {
            $sourceState = 'unavailable';
            $holdReason = 'INTERNAL_SAFE_HOLD';
        }
        if (! in_array($freshnessState, ['fresh', 'stale', 'unknown', 'not_applicable'], true)) {
            $freshnessState = 'unknown';
            $holdReason = 'INTERNAL_SAFE_HOLD';
        }
        if ($holdReason === self::NONE && ($sourceState !== 'available' || $freshnessState !== 'fresh' || count($bundles) !== 1)) {
            $bundles = [];
            $sourceState = 'unavailable';
            $freshnessState = 'unknown';
            $holdReason = 'INTERNAL_SAFE_HOLD';
        }
        $authorityRevision = is_string($authorityRevision)
            && preg_match('/^[a-f0-9]{64}$/D', $authorityRevision) === 1
                ? $authorityRevision
                : hash('sha256', $modeId.'|'.$holdReason);

        return new self($modeId, $bundles, $sourceState, $freshnessState, $holdReason, $authorityRevision);
    }

    /** @return list<array<string, mixed>> */
    public function bundles(): array
    {
        return $this->bundles;
    }

    public function ready(): bool
    {
        return $this->holdReason === self::NONE;
    }

    /** @return array{mode_id:string,source_state:string,freshness_state:string,hold_reason:string,authority_revision:string,bundle_present:bool} */
    public function diagnostic(): array
    {
        return [
            'mode_id' => $this->modeId,
            'source_state' => $this->sourceState,
            'freshness_state' => $this->freshnessState,
            'hold_reason' => $this->holdReason,
            'authority_revision' => $this->authorityRevision,
            'bundle_present' => count($this->bundles) === 1,
        ];
    }
}
