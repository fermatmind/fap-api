<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class Career80RolloutCandidateGate
{
    public const EXPECTED_RUNTIME_STATE = 'published_candidate';

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $auditRows
     * @return array{eligible: bool, reasons: list<string>, evidence: array<string, mixed>}
     */
    public function evaluate(array $auditRows): array
    {
        $reasons = [];
        $runtimeStates = $this->runtimeStates($auditRows);
        $truthStates = $this->truthStates($auditRows);
        $projectionStates = $this->projectionStates($auditRows, $runtimeStates);
        $canonicalPublicTypes = $this->canonicalPublicTypes($auditRows);
        $candidatePreRouteExpected = $this->candidatePreRouteExpected($auditRows);
        $candidateUnexpectedExposures = $this->candidateUnexpectedExposures($auditRows);
        $auditReasons = $this->auditReasons($auditRows);

        foreach ($this->reasonExclusions($auditReasons) as $reason) {
            $reasons[] = $reason;
        }

        if (in_array('published', $runtimeStates, true) || in_array('published', $truthStates, true) || in_array('published', $projectionStates, true)) {
            $reasons[] = 'already_published';
        }

        if (in_array('blocked', $runtimeStates, true) || in_array('blocked', $truthStates, true) || in_array('blocked', $projectionStates, true)) {
            $reasons[] = 'runtime_state_blocked';
        }

        if ($projectionStates === []) {
            $reasons[] = 'projection_row_missing';
        } elseif ($this->containsUnexpectedCandidateState($projectionStates)) {
            $reasons[] = 'projection_state_mismatch';
        }

        if ($runtimeStates === []) {
            $reasons[] = 'projection_row_missing';
        } elseif ($this->containsUnexpectedCandidateState($runtimeStates)) {
            $reasons[] = 'projection_state_mismatch';
        }

        if ($truthStates === []) {
            $reasons[] = 'truth_row_missing';
        } elseif ($this->containsUnexpectedCandidateState($truthStates)) {
            $reasons[] = 'truth_state_mismatch';
        }

        foreach ($canonicalPublicTypes as $type) {
            if ($type !== 'public_canonical_job') {
                $reasons[] = 'projection_state_mismatch';
                break;
            }
        }

        if (in_array(false, $candidatePreRouteExpected, true)) {
            $reasons[] = 'pre_route_not_expected';
        }

        if ($candidateUnexpectedExposures !== []) {
            if (in_array('api', $candidateUnexpectedExposures, true)) {
                $reasons[] = 'unexpected_api_exposure';
            }
            if (in_array('route', $candidateUnexpectedExposures, true)) {
                $reasons[] = 'unexpected_route_exposure';
            }
        }

        $reasons = $this->normalizeStrings($reasons);

        return [
            'eligible' => $reasons === [],
            'reasons' => $reasons,
            'evidence' => [
                'runtime_publish_states' => $runtimeStates,
                'truth_states' => $truthStates,
                'projection_states' => $projectionStates,
                'canonical_public_types' => $canonicalPublicTypes,
                'candidate_pre_route_expected' => $candidatePreRouteExpected,
                'candidate_unexpected_exposures' => $candidateUnexpectedExposures,
            ],
        ];
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $auditRows
     * @return list<string>
     */
    private function auditReasons(array $auditRows): array
    {
        $reasons = [];
        foreach ($auditRows as $row) {
            $reasons = [...$reasons, ...$row->reasons, ...$row->runtimeStatus->reasons, ...$row->surfaceStatus->reasons];
        }

        return $this->normalizeStrings($reasons);
    }

    /**
     * @param  list<string>  $auditReasons
     * @return list<string>
     */
    private function reasonExclusions(array $auditReasons): array
    {
        $map = [
            'candidate_unexpected_api_exposure' => 'unexpected_api_exposure',
            'candidate_unexpected_route_exposure' => 'unexpected_route_exposure',
            'candidate_truth_row_missing' => 'truth_row_missing',
            'truth_row_missing' => 'truth_row_missing',
            'candidate_projection_row_missing' => 'projection_row_missing',
            'projection_row_missing' => 'projection_row_missing',
            'candidate_projection_state_mismatch' => 'projection_state_mismatch',
            'candidate_projection_must_be_public_canonical_job' => 'projection_state_mismatch',
            'candidate_truth_state_mismatch' => 'truth_state_mismatch',
            'candidate_pre_route_not_expected' => 'pre_route_not_expected',
        ];

        $reasons = [];
        foreach ($auditReasons as $reason) {
            if (isset($map[$reason])) {
                $reasons[] = $map[$reason];
            }
        }

        return $this->normalizeStrings($reasons);
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $auditRows
     * @return list<string>
     */
    private function runtimeStates(array $auditRows): array
    {
        return $this->stringEvidenceValues($auditRows, 'runtime_publish_state');
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $auditRows
     * @return list<string>
     */
    private function truthStates(array $auditRows): array
    {
        return $this->stringEvidenceValues($auditRows, 'truth_state');
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $auditRows
     * @param  list<string>  $runtimeStates
     * @return list<string>
     */
    private function projectionStates(array $auditRows, array $runtimeStates): array
    {
        $states = $this->stringEvidenceValues($auditRows, 'projection_state');

        return $states === [] ? $runtimeStates : $states;
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $auditRows
     * @return list<string>
     */
    private function canonicalPublicTypes(array $auditRows): array
    {
        return $this->stringEvidenceValues($auditRows, 'canonical_public_type');
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $auditRows
     * @return list<bool>
     */
    private function candidatePreRouteExpected(array $auditRows): array
    {
        $values = [];
        foreach ($this->evidence($auditRows) as $item) {
            if (array_key_exists('candidate_pre_route_expected', $item) && is_bool($item['candidate_pre_route_expected'])) {
                $values[] = $item['candidate_pre_route_expected'];
            }
        }

        return array_values(array_unique($values, SORT_REGULAR));
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $auditRows
     * @return list<string>
     */
    private function candidateUnexpectedExposures(array $auditRows): array
    {
        $exposures = [];
        foreach ($this->evidence($auditRows) as $item) {
            $raw = $item['candidate_unexpected_exposures'] ?? null;
            if (! is_array($raw)) {
                continue;
            }

            foreach ($raw as $exposure) {
                if (is_string($exposure) && trim($exposure) !== '') {
                    $exposures[] = trim($exposure);
                }
            }
        }

        return $this->normalizeStrings($exposures);
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $auditRows
     * @return list<string>
     */
    private function stringEvidenceValues(array $auditRows, string $key): array
    {
        $values = [];
        foreach ($this->evidence($auditRows) as $item) {
            if (array_key_exists($key, $item) && is_string($item[$key]) && trim($item[$key]) !== '') {
                $values[] = trim($item[$key]);
            }
        }

        return $this->normalizeStrings($values);
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $auditRows
     * @return list<array<string, mixed>>
     */
    private function evidence(array $auditRows): array
    {
        $items = [];
        foreach ($auditRows as $row) {
            foreach ([
                $row->evidence,
                $row->runtimeStatus->evidence,
                $row->surfaceStatus->evidence,
                $row->seoGeoStatus->evidence,
            ] as $evidence) {
                foreach ($this->flattenEvidence($evidence) as $item) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function flattenEvidence(array $items): array
    {
        $flattened = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (! array_is_list($item)) {
                /** @var array<string, mixed> $item */
                $flattened[] = $item;
            }

            foreach ($item as $nested) {
                if (is_array($nested)) {
                    $flattened = [...$flattened, ...$this->flattenEvidence(array_is_list($nested) ? $nested : [$nested])];
                }
            }
        }

        return $flattened;
    }

    /**
     * @param  list<string>  $states
     */
    private function containsUnexpectedCandidateState(array $states): bool
    {
        foreach ($states as $state) {
            if (! in_array($state, [self::EXPECTED_RUNTIME_STATE, 'published'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalizeStrings(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        sort($normalized);

        return array_values(array_unique($normalized));
    }
}
