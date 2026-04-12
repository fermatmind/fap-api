<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveRecommendationCompanionLinksSummary
{
    /**
     * @param  array<string, mixed>  $subjectIdentity
     * @param  array<string, int>  $counts
     * @param  list<array{
     *     route_kind:'career_job_detail'|'career_family_hub'|'test_landing'|'topic_detail',
     *     canonical_path:string,
     *     canonical_slug:string,
     *     link_reason_code:string,
     *     occupation_uuid?:string,
     *     canonical_title_en?:string,
     *     family_uuid?:string,
     *     title_en?:string,
     *     scale_code?:string,
     *     topic_code?:string
     * }>  $companionLinks
     */
    public function __construct(
        public readonly string $summaryVersion,
        public readonly string $scope,
        public readonly array $subjectIdentity,
        public readonly array $counts,
        public readonly array $companionLinks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary_kind' => 'career_first_wave_recommendation_companion_links',
            'summary_version' => $this->summaryVersion,
            'scope' => $this->scope,
            'subject_kind' => 'recommendation_subject',
            'subject_identity' => $this->subjectIdentity,
            'counts' => $this->counts,
            'companion_links' => $this->companionLinks,
        ];
    }
}
