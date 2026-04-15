<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerTransitionPreviewBundle
{
    private const BUNDLE_KIND = 'career_transition_preview';

    private const BUNDLE_VERSION = 'career.protocol.transition_preview.v1';

    /**
     * @var list<string>
     */
    private const PUBLIC_TOP_LEVEL_KEYS = [
        'bundle_kind',
        'bundle_version',
        'path_type',
        'steps',
        'delta',
        'target_job',
        'score_summary',
        'trust_summary',
        'why_this_path',
        'what_is_lost',
        'bridge_steps_90d',
        'rationale_codes',
        'tradeoff_codes',
        'seo_contract',
        'provenance_meta',
    ];

    /**
     * @param  array<string, mixed>  $targetJob
     * @param  array<string, mixed>  $scoreSummary
     * @param  array<string, mixed>  $trustSummary
     * @param  array<string, mixed>  $seoContract
     * @param  array<string, mixed>  $provenanceMeta
     * @param  list<array{step_key:string,title:string,description:string,time_horizon:string}>|null  $bridgeSteps90d
     * @param  list<string>|null  $rationaleCodes
     * @param  list<string>|null  $tradeoffCodes
     * @param  list<string>|null  $steps
     * @param  array<string, array{source_value:string,target_value:string,direction:string}>|null  $delta
     */
    public function __construct(
        public readonly string $pathType,
        public readonly ?array $steps,
        public readonly ?array $delta,
        public readonly array $targetJob,
        public readonly array $scoreSummary,
        public readonly array $trustSummary,
        public readonly ?string $whyThisPath,
        public readonly ?string $whatIsLost,
        public readonly ?array $bridgeSteps90d,
        public readonly ?array $rationaleCodes,
        public readonly ?array $tradeoffCodes,
        public readonly array $seoContract,
        public readonly array $provenanceMeta,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'bundle_kind' => self::BUNDLE_KIND,
            'bundle_version' => self::BUNDLE_VERSION,
            'path_type' => $this->pathType,
            'steps' => $this->steps,
            'delta' => $this->delta,
            'target_job' => $this->targetJob,
            'score_summary' => $this->scoreSummary,
            'trust_summary' => $this->trustSummary,
            'why_this_path' => $this->whyThisPath,
            'what_is_lost' => $this->whatIsLost,
            'bridge_steps_90d' => $this->bridgeSteps90d,
            'rationale_codes' => $this->rationaleCodes,
            'tradeoff_codes' => $this->tradeoffCodes,
            'seo_contract' => $this->seoContract,
            'provenance_meta' => $this->provenanceMeta,
        ];

        if ($payload['steps'] === null) {
            unset($payload['steps']);
        }

        if ($payload['delta'] === null) {
            unset($payload['delta']);
        }

        if ($payload['why_this_path'] === null || $payload['why_this_path'] === '') {
            unset($payload['why_this_path']);
        }

        if ($payload['what_is_lost'] === null || $payload['what_is_lost'] === '') {
            unset($payload['what_is_lost']);
        }

        if ($payload['bridge_steps_90d'] === null || $payload['bridge_steps_90d'] === []) {
            unset($payload['bridge_steps_90d']);
        }

        if ($payload['rationale_codes'] === null || $payload['rationale_codes'] === []) {
            unset($payload['rationale_codes']);
        }

        if ($payload['tradeoff_codes'] === null || $payload['tradeoff_codes'] === []) {
            unset($payload['tradeoff_codes']);
        }

        /** @var array<string, mixed> $publicPayload */
        $publicPayload = array_intersect_key($payload, array_flip(self::PUBLIC_TOP_LEVEL_KEYS));

        return $publicPayload;
    }

    /**
     * @return list<string>
     */
    public static function publicTopLevelKeys(): array
    {
        return self::PUBLIC_TOP_LEVEL_KEYS;
    }
}
