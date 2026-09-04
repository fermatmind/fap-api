<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Evaluation;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use Throwable;

final readonly class Platform12DailyUrlTruthEvaluator
{
    private const HASH_PATTERN = '/^[a-f0-9]{64}$/D';

    public function __construct(private SeoRegistryHasher $hasher) {}

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function evaluate(array $evidence): array
    {
        try {
            $evaluatedAt = $this->evaluatedAt($evidence);
            $authority = $this->authority($evidence['authority'] ?? null, $evidence['url_truth'] ?? null);
            $clustering = $this->clustering($evidence['clustering'] ?? null);
            $d1 = $this->d1($evidence['d1_observation'] ?? null);
            $observations = $this->observations($evidence['runtime_observation'] ?? null, $evidence['sitemap_observation'] ?? null);
            $state = $this->state($authority, $clustering, $d1, $observations);
        } catch (Throwable) {
            $evaluatedAt = '1970-01-01T00:00:00Z';
            $authority = $this->unavailableAuthority();
            $clustering = $this->unavailableClustering();
            $d1 = $this->unavailableD1();
            $observations = $this->unavailableObservations();
            $state = 'INPUT_HOLD';
        }

        $receipt = [
            'receipt_version' => 'seo.platform12_daily_url_truth.v1',
            'mission_id' => 'seo.platform12.daily_url_truth_reconciliation',
            'evaluated_at' => $evaluatedAt,
            'state' => $state,
            'authority_reconciliation' => $authority,
            'clustering_dedupe' => $clustering,
            'd1_observation' => $d1,
            'observation_boundaries' => $observations,
            'candidate_actions' => [],
            'read_only' => true,
            'execution_allowed' => false,
            'writes' => [
                'url_truth' => false,
                'canonical' => false,
                'robots' => false,
                'authority' => false,
            ],
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @return array<string,mixed> */
    private function authority(mixed $authority, mixed $urlTruth): array
    {
        if (! is_array($authority) || ! is_array($urlTruth)
            || ($authority['availability'] ?? null) !== 'AVAILABLE'
            || ($urlTruth['availability'] ?? null) !== 'AVAILABLE') {
            return $this->unavailableAuthority();
        }
        foreach ([$authority['revision_hash'] ?? null, $urlTruth['revision_hash'] ?? null] as $hash) {
            if (! is_string($hash) || preg_match(self::HASH_PATTERN, $hash) !== 1) {
                throw new \InvalidArgumentException('URL_TRUTH_REVISION_INVALID');
            }
        }
        $denominator = $this->count($authority, 'current_public_count');
        $truthCount = $this->count($urlTruth, 'current_url_truth_count');
        $wrongCanonical = $this->count($urlTruth, 'wrong_canonical_count');
        $falseNoindex = $this->count($urlTruth, 'false_noindex_count');
        if ($wrongCanonical + $falseNoindex > $denominator || $truthCount > $denominator) {
            throw new \InvalidArgumentException('URL_TRUTH_DENOMINATOR_INVALID');
        }

        return [
            'availability' => 'AVAILABLE',
            'authority_revision_hash' => $authority['revision_hash'],
            'url_truth_revision_hash' => $urlTruth['revision_hash'],
            'fixed_denominator' => $denominator,
            'url_truth_count' => $truthCount,
            'wrong_canonical' => ['priority' => 'P0', 'candidate_count' => $wrongCanonical],
            'false_noindex' => ['priority' => 'P1', 'candidate_count' => $falseNoindex],
            'mutation_allowed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function clustering(mixed $source): array
    {
        if (! is_array($source) || ($source['availability'] ?? null) !== 'AVAILABLE') {
            return $this->unavailableClustering();
        }
        $issues = $this->count($source, 'issue_count');
        $clustered = $this->count($source, 'clustered_issue_count');
        $dedupeCandidates = $this->count($source, 'dedupe_candidate_count');
        $dedupeUnique = $this->count($source, 'dedupe_unique_count');
        if ($clustered > $issues || $dedupeUnique > $dedupeCandidates) {
            throw new \InvalidArgumentException('CLUSTER_DEDUPE_DENOMINATOR_INVALID');
        }

        return [
            'availability' => 'AVAILABLE',
            'issue_denominator' => $issues,
            'clustered_issue_count' => $clustered,
            'dedupe_denominator' => $dedupeCandidates,
            'dedupe_unique_count' => $dedupeUnique,
        ];
    }

    /** @return array<string,mixed> */
    private function d1(mixed $source): array
    {
        if (! is_array($source) || ($source['availability'] ?? null) !== 'AVAILABLE') {
            return $this->unavailableD1();
        }
        $candidates = $this->count($source, 'candidate_count');
        $observed = $this->count($source, 'observed_count');
        if ($observed > $candidates) {
            throw new \InvalidArgumentException('D1_DENOMINATOR_INVALID');
        }

        return [
            'availability' => 'AVAILABLE',
            'checkpoint' => 'D1',
            'candidate_denominator' => $candidates,
            'observed_count' => $observed,
            'result_only' => true,
            'action_execution_allowed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function observations(mixed $runtime, mixed $sitemap): array
    {
        $runtimeAvailable = is_array($runtime) && ($runtime['availability'] ?? null) === 'AVAILABLE';
        $sitemapAvailable = is_array($sitemap) && ($sitemap['availability'] ?? null) === 'AVAILABLE';

        return [
            'runtime_state' => $runtimeAvailable ? 'AVAILABLE' : 'UNAVAILABLE',
            'runtime_observation_count' => $runtimeAvailable ? $this->count($runtime, 'observation_count') : null,
            'runtime_can_create_authority' => false,
            'sitemap_state' => $sitemapAvailable ? 'AVAILABLE' : 'UNAVAILABLE',
            'sitemap_observation_count' => $sitemapAvailable ? $this->count($sitemap, 'observation_count') : null,
            'sitemap_can_create_authority' => false,
        ];
    }

    /** @param array<string,mixed> $authority @param array<string,mixed> $clustering @param array<string,mixed> $d1 @param array<string,mixed> $observations */
    private function state(array $authority, array $clustering, array $d1, array $observations): string
    {
        return match (true) {
            $authority['availability'] !== 'AVAILABLE' => 'URL_TRUTH_UNAVAILABLE_HOLD',
            $clustering['availability'] !== 'AVAILABLE' => 'CLUSTER_DEDUPE_UNAVAILABLE_HOLD',
            $d1['availability'] !== 'AVAILABLE' => 'D1_OBSERVATION_HOLD',
            $observations['runtime_state'] !== 'AVAILABLE' || $observations['sitemap_state'] !== 'AVAILABLE' => 'OBSERVATION_UNAVAILABLE_HOLD',
            default => 'READY',
        };
    }

    private function count(array $source, string $field): int
    {
        $value = $source[$field] ?? null;
        if (! is_int($value) || $value < 0 || $value > 100000000) {
            throw new \InvalidArgumentException('COUNT_INVALID');
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

    /** @return array<string,mixed> */
    private function unavailableAuthority(): array
    {
        return ['availability' => 'UNAVAILABLE', 'authority_revision_hash' => null, 'url_truth_revision_hash' => null, 'fixed_denominator' => null, 'url_truth_count' => null, 'wrong_canonical' => ['priority' => 'P0', 'candidate_count' => null], 'false_noindex' => ['priority' => 'P1', 'candidate_count' => null], 'mutation_allowed' => false];
    }

    /** @return array<string,mixed> */
    private function unavailableClustering(): array
    {
        return ['availability' => 'UNAVAILABLE', 'issue_denominator' => null, 'clustered_issue_count' => null, 'dedupe_denominator' => null, 'dedupe_unique_count' => null];
    }

    /** @return array<string,mixed> */
    private function unavailableD1(): array
    {
        return ['availability' => 'UNAVAILABLE', 'checkpoint' => 'D1', 'candidate_denominator' => null, 'observed_count' => null, 'result_only' => true, 'action_execution_allowed' => false];
    }

    /** @return array<string,mixed> */
    private function unavailableObservations(): array
    {
        return ['runtime_state' => 'UNAVAILABLE', 'runtime_observation_count' => null, 'runtime_can_create_authority' => false, 'sitemap_state' => 'UNAVAILABLE', 'sitemap_observation_count' => null, 'sitemap_can_create_authority' => false];
    }
}
