<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveLaunchTierSummary;
use App\Models\Occupation;

final class CareerFirstWaveLaunchTierSummaryService
{
    public const SUMMARY_VERSION = 'career.launch_tier.first_wave.v1';

    public const SCOPE = 'career_first_wave_10';

    /**
     * @var array<string, true>
     */
    private const HOLD_CROSSWALK_MODES = [
        'local_heavy_interpretation' => true,
        'family_proxy' => true,
        'unmapped' => true,
    ];

    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
        private readonly FirstWavePublishReadyValidator $validator,
        private readonly CareerFirstWaveLifecycleSummaryService $lifecycleSummaryService,
        private readonly FirstWavePublishGate $publishGate,
    ) {}

    public function build(): CareerFirstWaveLaunchTierSummary
    {
        $manifest = $this->manifestReader->read();
        $report = $this->validator->validate();
        $lifecycleSummary = $this->lifecycleSummaryService->build()->toArray();

        $readinessBySlug = [];
        foreach ((array) ($report['occupations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = (string) ($row['canonical_slug'] ?? '');
            if ($slug !== '') {
                $readinessBySlug[$slug] = $row;
            }
        }

        $lifecycleBySlug = [];
        foreach ((array) ($lifecycleSummary['occupations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = (string) ($row['canonical_slug'] ?? '');
            if ($slug !== '') {
                $lifecycleBySlug[$slug] = $row;
            }
        }

        $counts = [
            'total' => 0,
            WaveClassification::STABLE => 0,
            WaveClassification::CANDIDATE => 0,
            WaveClassification::HOLD => 0,
        ];
        $occupations = [];

        foreach ((array) ($manifest['occupations'] ?? []) as $occupation) {
            if (! is_array($occupation)) {
                continue;
            }

            $slug = (string) ($occupation['canonical_slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $readiness = $readinessBySlug[$slug] ?? [];
            $lifecycle = $lifecycleBySlug[$slug] ?? [];
            $seed = $this->loadSeedRow($slug);
            $gate = $this->evaluateGate($readiness, $seed);
            $launchTier = $this->resolveLaunchTier(
                gateClassification: (string) ($gate['classification'] ?? WaveClassification::HOLD),
                readinessStatus: $this->normalizeReadinessStatus((string) ($readiness['status'] ?? '')),
                blockedGovernanceStatus: $this->normalizeNullableString($readiness['blocked_governance_status'] ?? null),
            );

            $counts['total']++;
            $counts[$launchTier]++;

            $occupations[] = [
                'occupation_uuid' => (string) ($occupation['occupation_uuid'] ?? ''),
                'canonical_slug' => $slug,
                'canonical_title_en' => (string) ($occupation['canonical_title_en'] ?? ''),
                'launch_tier' => $launchTier,
                'readiness_status' => $this->normalizeReadinessStatus((string) ($readiness['status'] ?? '')),
                'lifecycle_state' => (string) ($lifecycle['lifecycle_state'] ?? CareerIndexLifecycleState::NOINDEX),
                'public_index_state' => (string) ($lifecycle['public_index_state'] ?? 'noindex'),
                'index_eligible' => (bool) ($readiness['index_eligible'] ?? false),
                'reviewer_status' => $this->normalizeNullableString($readiness['reviewer_status'] ?? null),
                'crosswalk_mode' => $this->normalizeNullableString($readiness['crosswalk_mode'] ?? $seed['crosswalk_mode'] ?? null),
                'allow_strong_claim' => (bool) ($seed['allow_strong_claim'] ?? false),
                'confidence_score' => $seed['confidence_score'],
                'blocked_governance_status' => $this->normalizeNullableString($readiness['blocked_governance_status'] ?? null),
                'reason_codes' => $this->curateReasonCodes(
                    launchTier: $launchTier,
                    gateReasons: (array) ($gate['reasons'] ?? []),
                    blockedGovernanceStatus: $this->normalizeNullableString($readiness['blocked_governance_status'] ?? null),
                    reviewerStatus: $this->normalizeNullableString($readiness['reviewer_status'] ?? null),
                    crosswalkMode: $this->normalizeNullableString($readiness['crosswalk_mode'] ?? $seed['crosswalk_mode'] ?? null),
                    indexEligible: (bool) ($readiness['index_eligible'] ?? false),
                    allowStrongClaim: (bool) ($seed['allow_strong_claim'] ?? false),
                    confidenceScore: $seed['confidence_score'],
                ),
            ];
        }

        return new CareerFirstWaveLaunchTierSummary(
            summaryVersion: self::SUMMARY_VERSION,
            scope: self::SCOPE,
            counts: $counts,
            occupations: $occupations,
        );
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  array{crosswalk_mode:?string,confidence_score:?int,allow_strong_claim:bool,reviewer_status:?string,index_state:?string,index_eligible:bool}  $seed
     * @return array{classification:string,reasons:list<string>,publishable:bool}
     */
    private function evaluateGate(array $readiness, array $seed): array
    {
        return $this->publishGate->evaluate([
            'crosswalk_mode' => $readiness['crosswalk_mode'] ?? $seed['crosswalk_mode'],
            'confidence_score' => $seed['confidence_score'] ?? 0,
            'reviewer_status' => $readiness['reviewer_status'] ?? $seed['reviewer_status'],
            'index_state' => $readiness['index_state'] ?? $seed['index_state'],
            'index_eligible' => $readiness['index_eligible'] ?? $seed['index_eligible'],
            'allow_strong_claim' => $seed['allow_strong_claim'],
        ]);
    }

    private function resolveLaunchTier(
        string $gateClassification,
        string $readinessStatus,
        ?string $blockedGovernanceStatus,
    ): string {
        if ($blockedGovernanceStatus !== null) {
            return WaveClassification::HOLD;
        }

        if ($gateClassification === WaveClassification::STABLE && $readinessStatus === 'publish_ready') {
            return WaveClassification::STABLE;
        }

        if ($gateClassification === WaveClassification::CANDIDATE) {
            return WaveClassification::CANDIDATE;
        }

        return WaveClassification::HOLD;
    }

    /**
     * @param  list<mixed>  $gateReasons
     * @return list<string>
     */
    private function curateReasonCodes(
        string $launchTier,
        array $gateReasons,
        ?string $blockedGovernanceStatus,
        ?string $reviewerStatus,
        ?string $crosswalkMode,
        bool $indexEligible,
        bool $allowStrongClaim,
        ?int $confidenceScore,
    ): array {
        $reasonCodes = match ($launchTier) {
            WaveClassification::STABLE => ['stable_launch_ready'],
            WaveClassification::CANDIDATE => ['candidate_review_required'],
            default => [],
        };

        $gateReasons = array_values(array_filter(array_map(
            static fn (mixed $code): string => is_string($code) ? trim($code) : '',
            $gateReasons,
        )));

        if ($launchTier === WaveClassification::HOLD) {
            if ($blockedGovernanceStatus !== null) {
                $reasonCodes[] = 'hold_blocked_governance';
            }

            if ($reviewerStatus !== null && ! in_array($reviewerStatus, ['approved', 'reviewed'], true)) {
                $reasonCodes[] = 'hold_review_required';
            }

            if (! $indexEligible) {
                $reasonCodes[] = 'hold_not_index_eligible';
            }

            if ($crosswalkMode !== null && isset(self::HOLD_CROSSWALK_MODES[strtolower($crosswalkMode)])) {
                $reasonCodes[] = 'hold_crosswalk_restricted';
            }

            if (! $allowStrongClaim) {
                $reasonCodes[] = 'hold_strong_claim_disallowed';
            }

            if (in_array(PublishReasonCode::CONFIDENCE_TOO_LOW, $gateReasons, true)
                || ($confidenceScore !== null && $confidenceScore < 60)) {
                $reasonCodes[] = 'hold_confidence_too_low';
            }

            if ($reasonCodes === []) {
                $reasonCodes[] = 'hold_scope_restricted';
            }
        }

        return array_values(array_unique($reasonCodes));
    }

    /**
     * @return array{crosswalk_mode:?string,confidence_score:?int,allow_strong_claim:bool,reviewer_status:?string,index_state:?string,index_eligible:bool}
     */
    private function loadSeedRow(string $slug): array
    {
        $occupation = Occupation::query()->where('canonical_slug', $slug)->first();
        if (! $occupation instanceof Occupation) {
            return [
                'crosswalk_mode' => null,
                'confidence_score' => null,
                'allow_strong_claim' => false,
                'reviewer_status' => null,
                'index_state' => null,
                'index_eligible' => false,
            ];
        }

        $trustManifest = $occupation->trustManifests()
            ->orderByDesc('reviewed_at')
            ->orderByDesc('created_at')
            ->first();
        $indexState = $occupation->indexStates()
            ->orderByDesc('changed_at')
            ->orderByDesc('updated_at')
            ->first();
        $snapshot = $occupation->recommendationSnapshots()
            ->with(['contextSnapshot', 'profileProjection'])
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->first();

        $confidenceScore = data_get($trustManifest?->quality, 'confidence_score', data_get($trustManifest?->quality, 'confidence'));
        $normalizedConfidence = is_numeric($confidenceScore) ? (int) round((float) $confidenceScore) : null;

        return [
            'crosswalk_mode' => $this->normalizeNullableString($occupation->crosswalk_mode),
            'confidence_score' => $normalizedConfidence,
            'allow_strong_claim' => (bool) data_get($snapshot?->snapshot_payload, 'claim_permissions.allow_strong_claim', false),
            'reviewer_status' => $this->normalizeNullableString($trustManifest?->reviewer_status),
            'index_state' => $this->normalizeNullableString($indexState?->index_state),
            'index_eligible' => (bool) ($indexState?->index_eligible ?? false),
        ];
    }

    private function normalizeReadinessStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return match ($normalized) {
            'publish_ready', 'blocked_override_eligible', 'blocked_not_safely_remediable', 'partial', 'partial_raw' => $normalized === 'partial' ? 'partial_raw' : $normalized,
            default => 'blocked_not_safely_remediable',
        };
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
