<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Routing;

use App\Services\SeoCouncil\Contracts\MissionRequestData;

final class DeterministicMissionRouter
{
    private const TECHNICAL = 'seo.expert.technical_search_authority';

    private const ANALYTICS = 'seo.expert.search_analytics_measurement';

    private const CONTENT = 'seo.expert.content_entity_quality';

    private const COMPETITOR = 'seo.expert.competitor_research';

    private const STABILITY = 'seo.expert.public_content_stability';

    private const CRO = 'seo.expert.commercial_funnel_cro';

    /** @return array{status:string,roles:list<string>,max_modes:int,all_team:bool} */
    public function route(MissionRequestData $request): array
    {
        $mission = (string) $request->payload['mission_type'];
        $types = array_values(array_unique(array_map(
            static fn (array $ref): string => (string) $ref['evidence_type'],
            (array) $request->payload['evidence_bundle_refs'],
        )));
        $roles = match ($mission) {
            'global_portfolio' => [self::TECHNICAL, self::ANALYTICS, self::CONTENT, self::COMPETITOR, self::STABILITY, self::CRO],
            'weekly_opportunity' => $this->weekly($types),
            'monthly_portfolio' => $this->monthly($types),
            'breakthrough_sprint' => $this->breakthrough($types),
            'bounded_review' => [$this->bounded((string) $request->payload['review_domain'])],
            'independent_registry_review' => ['seo.independent_reviewer'],
            'career_candidate_generation' => $request->payload['family'] === 'career'
                ? ['career.content_agent', self::CONTENT, 'seo.independent_reviewer']
                : [],
            default => [],
        };
        $max = match ($mission) {
            'global_portfolio' => 6,
            'weekly_opportunity' => 3,
            'monthly_portfolio' => 4,
            'breakthrough_sprint' => 5,
            'bounded_review' => 1,
            'independent_registry_review' => 1,
            'career_candidate_generation' => 3,
            default => 0,
        };
        if ($mission === 'career_candidate_generation' && $roles === []) {
            return ['status' => 'MISSION_SCOPE_HOLD', 'roles' => [], 'max_modes' => $max, 'all_team' => false];
        }
        if ($roles === [] || count($roles) > $max) {
            return ['status' => 'ROUTING_SCOPE_HOLD', 'roles' => [], 'max_modes' => $max, 'all_team' => false];
        }

        return [
            'status' => 'SOURCE_CAPABILITY_UNAVAILABLE',
            'roles' => $roles,
            'max_modes' => $max,
            'all_team' => $mission === 'global_portfolio',
        ];
    }

    /** @param list<string> $types @return list<string> */
    private function weekly(array $types): array
    {
        $roles = [self::ANALYTICS];
        $this->conditional($roles, $types, ['runtime_health', 'authority_parity', 'release_separation'], self::TECHNICAL);
        $this->conditional($roles, $types, ['content_claim', 'entity', 'duplicate', 'lifecycle'], self::CONTENT);
        $this->conditional($roles, $types, ['stability', 'cache_projection'], self::STABILITY);
        $this->conditional($roles, $types, ['funnel_aggregate'], self::CRO);
        $this->conditional($roles, $types, ['gateway_competitor_public'], self::COMPETITOR);

        return $roles;
    }

    /** @param list<string> $types @return list<string> */
    private function monthly(array $types): array
    {
        $roles = [self::ANALYTICS, self::CONTENT, self::CRO];
        $this->conditional($roles, $types, ['runtime_health', 'authority_parity', 'release_separation'], self::TECHNICAL);
        $this->conditional($roles, $types, ['stability', 'cache_projection'], self::STABILITY);
        $this->conditional($roles, $types, ['gateway_competitor_public'], self::COMPETITOR);

        return $roles;
    }

    /** @param list<string> $types @return list<string> */
    private function breakthrough(array $types): array
    {
        $roles = [self::CONTENT, self::ANALYTICS, self::CRO];
        $this->conditional($roles, $types, ['runtime_health', 'authority_parity', 'release_separation'], self::TECHNICAL);
        $this->conditional($roles, $types, ['stability', 'cache_projection'], self::STABILITY);
        $this->conditional($roles, $types, ['gateway_competitor_public'], self::COMPETITOR);

        return $roles;
    }

    private function bounded(string $domain): string
    {
        return match ($domain) {
            'technical' => self::TECHNICAL,
            'analytics' => self::ANALYTICS,
            'content' => self::CONTENT,
            'competitor' => self::COMPETITOR,
            'stability' => self::STABILITY,
            'cro' => self::CRO,
        };
    }

    /** @param list<string> $roles @param list<string> $types @param list<string> $signals */
    private function conditional(array &$roles, array $types, array $signals, string $role): void
    {
        if (array_intersect($types, $signals) !== [] && ! in_array($role, $roles, true)) {
            $roles[] = $role;
        }
    }
}
