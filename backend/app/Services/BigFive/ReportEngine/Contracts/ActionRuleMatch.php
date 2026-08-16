<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Contracts;

final class ActionRuleMatch
{
    /**
     * @param  list<string>  $scenarioTags
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $scenario,
        public readonly string $traitCode,
        public readonly string $bucket,
        public readonly array $scenarioTags,
        public readonly string $difficultyLevel,
        public readonly string $timeHorizon,
        public readonly string $title,
        public readonly string $body,
        public readonly int $priorityWeight = 0,
        public readonly string $whyRecommended = '',
        public readonly string $completionSignal = '',
        public readonly array $evidence = [],
        public readonly array $relatedInsightRuleIds = [],
        public readonly array $relatedFacetRuleIds = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'scenario' => $this->scenario,
            'trait_code' => $this->traitCode,
            'bucket' => $this->bucket,
            'scenario_tags' => $this->scenarioTags,
            'difficulty_level' => $this->difficultyLevel,
            'time_horizon' => $this->timeHorizon,
            'title' => $this->title,
            'body' => $this->body,
            'priority_weight' => $this->priorityWeight,
            'why_recommended' => $this->whyRecommended,
            'completion_signal' => $this->completionSignal,
            'evidence' => $this->evidence,
            'related_insight_rule_ids' => $this->relatedInsightRuleIds,
            'related_facet_rule_ids' => $this->relatedFacetRuleIds,
        ];
    }
}
