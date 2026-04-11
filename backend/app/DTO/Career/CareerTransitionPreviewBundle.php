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
        'target_job',
        'score_summary',
        'trust_summary',
        'seo_contract',
        'provenance_meta',
    ];

    /**
     * @param  array<string, mixed>  $targetJob
     * @param  array<string, mixed>  $scoreSummary
     * @param  array<string, mixed>  $trustSummary
     * @param  array<string, mixed>  $seoContract
     * @param  array<string, mixed>  $provenanceMeta
     */
    public function __construct(
        public readonly string $pathType,
        public readonly array $targetJob,
        public readonly array $scoreSummary,
        public readonly array $trustSummary,
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
            'target_job' => $this->targetJob,
            'score_summary' => $this->scoreSummary,
            'trust_summary' => $this->trustSummary,
            'seo_contract' => $this->seoContract,
            'provenance_meta' => $this->provenanceMeta,
        ];

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
