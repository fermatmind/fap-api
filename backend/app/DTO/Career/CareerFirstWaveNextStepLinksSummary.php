<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveNextStepLinksSummary
{
    /**
     * @param  array<string, mixed>  $subjectIdentity
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $nextStepLinks
     */
    public function __construct(
        public readonly string $summaryVersion,
        public readonly string $scope,
        public readonly array $subjectIdentity,
        public readonly array $counts,
        public readonly array $nextStepLinks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary_kind' => 'career_first_wave_next_step_links',
            'summary_version' => $this->summaryVersion,
            'scope' => $this->scope,
            'subject_kind' => 'occupation',
            'subject_identity' => $this->subjectIdentity,
            'counts' => $this->counts,
            'next_step_links' => $this->nextStepLinks,
        ];
    }
}
