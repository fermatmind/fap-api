<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Evaluation;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use Throwable;

final readonly class Platform12WeeklyOpportunityEvaluator
{
    private const KINDS = [
        'opportunity', 'decay', 'review_due', 'query_owner_conflict',
        'cannibalization', 'internal_link', 'orphan',
    ];

    private const FAMILIES = ['tests', 'articles_topics', 'career', 'personality', 'trust_method_help', 'other_public'];

    public function __construct(private SeoRegistryHasher $hasher) {}

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function evaluate(array $evidence, string $locale): array
    {
        try {
            if (! in_array($locale, ['zh-CN', 'en'], true)) {
                throw new \InvalidArgumentException('LOCALE_INVALID');
            }
            $evaluatedAt = $this->evaluatedAt($evidence);
            $source = $evidence['candidates'] ?? null;
            if (! is_array($source) || ! array_is_list($source) || count($source) > 100) {
                throw new \InvalidArgumentException('CANDIDATES_INVALID');
            }
            $candidates = array_map(fn (mixed $candidate): array => $this->candidate($candidate, $locale), $source);
            $unresolved = count(array_filter(
                $candidates,
                static fn (array $candidate): bool => $candidate['kind'] === 'query_owner_conflict'
                    && $candidate['owner_conflict_resolved'] === false,
            ));
            $state = $unresolved > 0 ? 'HOLD' : ($candidates === [] ? 'VALID_ZERO' : 'READY');
            $reasonCodes = $unresolved > 0 ? ['QUERY_OWNER_CONFLICT_UNRESOLVED'] : [];
        } catch (Throwable) {
            $evaluatedAt = '1970-01-01T00:00:00Z';
            $candidates = [];
            $unresolved = 0;
            $state = 'HOLD';
            $reasonCodes = ['INPUT_UNAVAILABLE'];
        }

        $artifact = [
            'artifact_version' => 'seo.platform12_weekly_opportunity_candidates.v1',
            'mission_id' => 'seo.platform12.weekly_opportunity_'.$this->localeCode($locale),
            'evaluated_at' => $evaluatedAt,
            'locale' => $locale,
            'state' => $state,
            'reason_codes' => $reasonCodes,
            'candidate_count' => count($candidates),
            'unresolved_owner_conflict_count' => $unresolved,
            'candidates' => $candidates,
            'artifact_only' => true,
            'read_only' => true,
            'execution_allowed' => false,
            'writes' => ['query_owner' => false, 'url_truth' => false, 'cms' => false],
        ];
        $artifact['artifact_hash'] = $this->hasher->hash($artifact);

        return $artifact;
    }

    /** @return array<string,mixed> */
    private function candidate(mixed $candidate, string $locale): array
    {
        if (! is_array($candidate)
            || ! is_string($candidate['candidate_ref'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $candidate['candidate_ref']) !== 1
            || ! in_array($candidate['kind'] ?? null, self::KINDS, true)
            || ! in_array($candidate['family'] ?? null, self::FAMILIES, true)
            || ($candidate['locale'] ?? null) !== $locale
            || ! is_int($candidate['confidence_ppm'] ?? null)
            || $candidate['confidence_ppm'] < 0
            || $candidate['confidence_ppm'] > 1000000
            || ! is_bool($candidate['owner_conflict_resolved'] ?? null)) {
            throw new \InvalidArgumentException('CANDIDATE_INVALID');
        }

        return [
            'candidate_ref' => $candidate['candidate_ref'],
            'kind' => $candidate['kind'],
            'family' => $candidate['family'],
            'locale' => $locale,
            'evidence_refs' => $this->stringList($candidate['evidence_refs'] ?? null, 16, '/^[a-f0-9]{64}$/D'),
            'confidence_ppm' => $candidate['confidence_ppm'],
            'unknowns' => $this->stringList($candidate['unknowns'] ?? null, 16, '/^[a-z][a-z0-9._-]{0,63}$/D', true),
            'owner_conflict_resolved' => $candidate['owner_conflict_resolved'],
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $maximum, string $pattern, bool $allowEmpty = false): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximum
            || (! $allowEmpty && $value === []) || count(array_unique($value)) !== count($value)) {
            throw new \InvalidArgumentException('CANDIDATE_LIST_INVALID');
        }
        foreach ($value as $item) {
            if (! is_string($item) || preg_match($pattern, $item) !== 1) {
                throw new \InvalidArgumentException('CANDIDATE_LIST_INVALID');
            }
        }

        return $value;
    }

    private function evaluatedAt(array $evidence): string
    {
        $value = $evidence['evaluated_at'] ?? null;
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new \InvalidArgumentException('EVALUATION_TIME_INVALID');
        }

        return $value;
    }

    private function localeCode(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh_cn' : 'en';
    }
}
