<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use Carbon\CarbonImmutable;
use Throwable;

final class CompetitivePolicyObservationSet
{
    private const FIELDS = [
        'source_id',
        'evidence_kind',
        'environment',
        'release_ref',
        'policy_hash',
        'baseline_hash',
        'observed_hash',
        'review_state',
        'semantic_decision',
        'reason_code',
        'reviewed_at',
        'valid_until',
        'observation_hash',
    ];

    public function __construct(private readonly SeoEvidenceCanonicalHasher $hasher) {}

    /** @param array<string, mixed> $observation @return array<string, mixed> */
    public function seal(array $observation): array
    {
        unset($observation['observation_hash']);
        $observation['observation_hash'] = $this->hasher->hash($observation);

        return $observation;
    }

    /** @param list<array<string, mixed>> $observations @return list<array<string, mixed>> */
    public function ordered(array $observations): array
    {
        usort($observations, static fn (array $left, array $right): int => [
            (string) ($left['source_id'] ?? ''),
            (string) ($left['evidence_kind'] ?? ''),
        ] <=> [
            (string) ($right['source_id'] ?? ''),
            (string) ($right['evidence_kind'] ?? ''),
        ]);

        return $observations;
    }

    /** @param list<array<string, mixed>> $observations */
    public function hash(array $observations): string
    {
        return $this->hasher->hash($this->ordered($observations));
    }

    /** @param list<array<string, mixed>> $observations */
    public function verify(
        array $observations,
        string $setHash,
        string $environment,
        string $releaseRef,
        string $policySetHash,
        int $expectedSources,
        array $expectedSourceIds = [],
        array $expectedPolicies = [],
    ): bool {
        if (! array_is_list($observations)
            || count($observations) !== $expectedSources * 3
            || ! $this->isHash($setHash)
            || ! hash_equals($this->hash($observations), $setHash)) {
            return false;
        }
        $keys = [];
        $sourceIds = [];
        $policyHashes = [];
        foreach ($observations as $observation) {
            if (! is_array($observation)
                || ! $this->validObservation($observation, $environment, $releaseRef)
                || $observation['semantic_decision'] !== 'approved'
                || isset($expectedPolicies[$observation['source_id']])
                    && ! hash_equals((string) $expectedPolicies[$observation['source_id']], (string) $observation['policy_hash'])) {
                return false;
            }
            $key = $observation['source_id'].'|'.$observation['evidence_kind'];
            if (isset($keys[$key])) {
                return false;
            }
            $keys[$key] = true;
            $sourceIds[(string) $observation['source_id']] = true;
            $policyHashes[(string) $observation['policy_hash']] = true;
        }
        $hashes = array_keys($policyHashes);
        sort($hashes, SORT_STRING);

        $observedSourceIds = array_keys($sourceIds);
        sort($observedSourceIds, SORT_STRING);
        $expectedSourceIds = array_values(array_unique(array_map('strval', $expectedSourceIds)));
        sort($expectedSourceIds, SORT_STRING);

        return count($hashes) === $expectedSources
            && count($observedSourceIds) === $expectedSources
            && ($expectedSourceIds === [] || $observedSourceIds === $expectedSourceIds)
            && hash_equals($this->hasher->hash($hashes), $policySetHash);
    }

    /** @param array<string, mixed> $observation */
    public function isSealed(array $observation): bool
    {
        return $this->validObservation(
            $observation,
            (string) ($observation['environment'] ?? ''),
            (string) ($observation['release_ref'] ?? ''),
        );
    }

    /** @param array<string, mixed> $observation */
    private function validObservation(array $observation, string $environment, string $releaseRef): bool
    {
        $actual = array_keys($observation);
        $expected = self::FIELDS;
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', (string) $observation['source_id']) !== 1
            || ! in_array($observation['evidence_kind'], ['terms', 'license', 'robots'], true)
            || ! in_array($observation['environment'], ['staging', 'production'], true)
            || $observation['environment'] !== $environment
            || preg_match('/^release_[a-p]{64}$/D', (string) $observation['release_ref']) !== 1
            || $observation['release_ref'] !== $releaseRef
            || ! $this->isHash($observation['policy_hash'])
            || ! $this->isHash($observation['baseline_hash'])
            || ! $this->isHash($observation['observed_hash'])
            || ! in_array($observation['review_state'], ['baseline_valid', 'hash_drift', 'expired', 'expired_and_drift'], true)
            || ! in_array($observation['semantic_decision'], ['approved', 'hold'], true)
            || preg_match('/^[A-Z0-9_]{3,64}$/D', (string) $observation['reason_code']) !== 1
            || ! $this->isHash($observation['observation_hash'])
            || ! hash_equals($this->hasher->hashWithout($observation, 'observation_hash'), (string) $observation['observation_hash'])) {
            return false;
        }
        try {
            $reviewed = CarbonImmutable::parse((string) $observation['reviewed_at']);
            $validUntil = CarbonImmutable::parse((string) $observation['valid_until']);
        } catch (Throwable) {
            return false;
        }

        return $validUntil->greaterThan($reviewed)
            && $reviewed->diffInSeconds($validUntil) <= 2592000
            && ! $reviewed->greaterThan(now('UTC'))
            && (($observation['semantic_decision'] === 'approved') === ($observation['reason_code'] === 'NONE'));
    }

    private function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
