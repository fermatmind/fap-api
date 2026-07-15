<?php

declare(strict_types=1);

namespace App\Domain\Career\Bridge;

use JsonException;
use RuntimeException;

final class BigFiveCareerBridgeAuditor
{
    public const REPORT_VERSION = 'big_five_career_bridge.audit.v1';

    public const BIG_FIVE_PROJECTION_KIND = 'big_five_published_public_projection';

    public const BIG_FIVE_PROJECTION_VERSION = 'big_five.published_public_projection.v1';

    public const CANDIDATE_KIND = 'big_five_career_bridge_candidates';

    public const CANDIDATE_VERSION = 'big_five_career_bridge_candidates.v1';

    /** @var list<string> */
    private const FORBIDDEN_ARTIFACT_KEYS = [
        'working_revision_payload',
        'draft_snapshot',
        'review_snapshot',
        'snapshot_json',
        'generated_authority_package',
        'score_vector',
        'percentile',
        'answers',
        'selector_trace',
        'attempt_id',
        'report_url',
        'user_id',
        'order_id',
        'payment_id',
    ];

    public function __construct(
        private readonly BigFiveCareerBridgeContract $contract,
    ) {}

    /**
     * @param  array<string, mixed>  $bigFiveAuthority
     * @param  array<string, mixed>  $careerProjection
     * @param  array<string, mixed>  $candidateArtifact
     * @return array<string, mixed>
     */
    public function audit(array $bigFiveAuthority, array $careerProjection, array $candidateArtifact): array
    {
        $globalBlockers = $this->globalBlockers($bigFiveAuthority, $careerProjection, $candidateArtifact);
        $bigFiveItems = $this->objectList($bigFiveAuthority['items'] ?? null);
        $careerItems = $this->objectList($careerProjection['items'] ?? null);
        $candidateRows = is_array($candidateArtifact['rows'] ?? null) && array_is_list($candidateArtifact['rows'])
            ? $candidateArtifact['rows']
            : [];
        $careerProjectionHash = $this->fingerprint($careerProjection);
        $rows = [];

        foreach ($candidateRows as $index => $candidate) {
            $rows[] = $this->auditCandidate(
                candidate: is_array($candidate) && ! array_is_list($candidate) ? $candidate : [],
                index: $index,
                bigFiveItems: $bigFiveItems,
                careerItems: $careerItems,
                careerProjectionHash: $careerProjectionHash,
                globalBlockers: $globalBlockers,
            );
        }

        usort($rows, static fn (array $left, array $right): int => strcmp(
            (string) ($left['bridge_id'] ?? ''),
            (string) ($right['bridge_id'] ?? ''),
        ));

        $readyCount = count(array_filter($rows, static fn (array $row): bool => ($row['status'] ?? null) === BigFiveCareerBridgeContract::STATUS_PUBLISHED_PROJECTION_READY));
        $blockedCount = count($rows) - $readyCount;
        $blockerBreakdown = $this->blockerBreakdown($globalBlockers, $rows);

        return [
            'report_version' => self::REPORT_VERSION,
            'status' => $globalBlockers === [] && $blockedCount === 0 ? 'pass' : 'blocked',
            'read_only' => true,
            'candidate_count' => count($rows),
            'ready_count' => $readyCount,
            'blocked_count' => $blockedCount,
            'blocker_breakdown' => $blockerBreakdown,
            'source_revision_provenance' => $this->bigFiveProvenance($bigFiveItems),
            'career_projection_provenance' => $this->careerProvenance($careerProjection, $careerProjectionHash, $careerItems),
            'claim_boundary' => [
                'claim_mode' => BigFiveCareerBridgeContract::CLAIM_MODE,
                'primary_career_interest_signal' => BigFiveCareerBridgeContract::PRIMARY_CAREER_SIGNAL,
                'big_five_role' => BigFiveCareerBridgeContract::BIG_FIVE_ROLE,
                'recommendation_authority' => false,
                'ranking_allowed' => false,
                'hiring_use_allowed' => false,
                'outcome_prediction_allowed' => false,
                'pseo_allowed' => false,
            ],
            'private_data_boundary' => [
                'private_score_vector_read' => false,
                'percentile_read' => false,
                'item_answers_read' => false,
                'selector_trace_read' => false,
                'attempt_or_report_link_read' => false,
                'user_order_or_payment_data_read' => false,
            ],
            'write_boundary' => [
                'database' => false,
                'cms' => false,
                'cache' => false,
                'sitemap_or_llms' => false,
                'search_queue' => false,
                'public_route' => false,
            ],
            'rows' => $rows,
        ];
    }

    /** @param array<string, mixed> $report */
    public function markdown(array $report): string
    {
        $lines = [
            '# Big Five → Career bridge audit',
            '',
            '- Status: `'.($report['status'] ?? 'blocked').'`',
            '- Candidate count: '.(int) ($report['candidate_count'] ?? 0),
            '- Ready count: '.(int) ($report['ready_count'] ?? 0),
            '- Blocked count: '.(int) ($report['blocked_count'] ?? 0),
            '- Mode: read-only',
            '',
            '## Blocker breakdown',
            '',
        ];

        $breakdown = is_array($report['blocker_breakdown'] ?? null) ? $report['blocker_breakdown'] : [];
        if ($breakdown === []) {
            $lines[] = '- None';
        } else {
            foreach ($breakdown as $blocker => $count) {
                $lines[] = '- `'.$this->markdownText((string) $blocker).'`: '.(int) $count;
            }
        }

        $lines[] = '';
        $lines[] = '## Candidate results';
        $lines[] = '';
        $lines[] = '| Bridge ID | Status | Blockers |';
        $lines[] = '| --- | --- | ---: |';
        foreach ($this->objectList($report['rows'] ?? null) as $row) {
            $lines[] = sprintf(
                '| %s | %s | %d |',
                $this->markdownText((string) ($row['bridge_id'] ?? 'unknown')),
                $this->markdownText((string) ($row['status'] ?? 'blocked')),
                count(is_array($row['blockers'] ?? null) ? $row['blockers'] : []),
            );
        }

        $lines[] = '';
        $lines[] = '## Boundaries';
        $lines[] = '';
        $lines[] = '- RIASEC remains the primary career-interest signal.';
        $lines[] = '- Big Five is supplementary work-style explanation only.';
        $lines[] = '- No ranking, hiring, outcome prediction, pSEO, private assessment data, or public mutation is allowed.';

        return implode("\n", $lines)."\n";
    }

    /** @param array<string, mixed> $payload */
    public function fingerprint(array $payload): string
    {
        try {
            return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to fingerprint bridge audit artifact.', previous: $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<array<string, mixed>>  $bigFiveItems
     * @param  list<array<string, mixed>>  $careerItems
     * @param  list<string>  $globalBlockers
     * @return array<string, mixed>
     */
    private function auditCandidate(array $candidate, int $index, array $bigFiveItems, array $careerItems, string $careerProjectionHash, array $globalBlockers): array
    {
        $input = is_array($candidate['input'] ?? null) && ! array_is_list($candidate['input']) ? $candidate['input'] : [];
        $output = is_array($candidate['output'] ?? null) && ! array_is_list($candidate['output']) ? $candidate['output'] : [];
        $bridgeId = trim((string) ($input['bridge_id'] ?? $output['bridge_id'] ?? 'candidate-'.($index + 1)));
        $blockers = $globalBlockers;

        if ($input === []) {
            $blockers[] = 'candidate.input_missing';
        }
        if ($output === []) {
            $blockers[] = 'candidate.output_missing';
        }

        $bigFiveProjection = is_array($input['big_five_projection'] ?? null) ? $input['big_five_projection'] : [];
        $matchingBigFive = array_values(array_filter($bigFiveItems, static fn (array $item): bool => ($item['asset_id'] ?? null) === ($bigFiveProjection['asset_id'] ?? null)
            && ($item['locale'] ?? null) === ($bigFiveProjection['locale'] ?? null)
        ));
        if (count($matchingBigFive) !== 1) {
            $blockers[] = count($matchingBigFive) === 0
                ? 'authority.big_five_published_projection_missing'
                : 'authority.big_five_published_projection_ambiguous';
        } elseif (! $this->publishedProjectionMatches($bigFiveProjection, $matchingBigFive[0])) {
            $blockers[] = 'authority.big_five_published_projection_mismatch';
        }

        $careerProjection = is_array($input['career_projection'] ?? null) ? $input['career_projection'] : [];
        $matchingCareer = array_values(array_filter($careerItems, static fn (array $item): bool => ($item['slug'] ?? null) === ($careerProjection['canonical_slug'] ?? null)
            && ($item['locale'] ?? null) === ($careerProjection['locale'] ?? null)
        ));
        if (($input['career_runtime_projection_hash'] ?? null) !== $careerProjectionHash
            || ($careerProjection['projection_hash'] ?? null) !== $careerProjectionHash) {
            $blockers[] = 'authority.career_runtime_projection_hash_mismatch';
        }
        if (count($matchingCareer) !== 1) {
            $blockers[] = count($matchingCareer) === 0
                ? 'authority.career_runtime_projection_item_missing'
                : 'authority.career_runtime_projection_item_ambiguous';
        } elseif (! $this->careerProjectionMatches($careerProjection, $matchingCareer[0])) {
            $blockers[] = 'authority.career_runtime_projection_item_mismatch';
        }

        $assessment = $this->contract->assess($input, $output);
        $blockers = $this->uniqueSorted([...$blockers, ...$assessment['blockers']]);
        $ready = $blockers === [];

        return [
            'bridge_id' => $bridgeId === '' ? 'candidate-'.($index + 1) : $bridgeId,
            'status' => $ready ? BigFiveCareerBridgeContract::STATUS_PUBLISHED_PROJECTION_READY : BigFiveCareerBridgeContract::STATUS_BLOCKED,
            'public_reader_allowed' => $ready,
            'big_five_asset_id' => is_string($bigFiveProjection['asset_id'] ?? null) ? $bigFiveProjection['asset_id'] : null,
            'big_five_published_revision_id' => is_int($bigFiveProjection['published_revision_id'] ?? null) ? $bigFiveProjection['published_revision_id'] : null,
            'career_canonical_slug' => is_string($careerProjection['canonical_slug'] ?? null) ? $careerProjection['canonical_slug'] : null,
            'career_locale' => is_string($careerProjection['locale'] ?? null) ? $careerProjection['locale'] : null,
            'blockers' => $blockers,
        ];
    }

    /** @return list<string> */
    private function globalBlockers(array $bigFiveAuthority, array $careerProjection, array $candidateArtifact): array
    {
        $blockers = [];
        foreach ([
            'big_five' => $bigFiveAuthority,
            'career' => $careerProjection,
            'candidate' => $candidateArtifact,
        ] as $artifact => $payload) {
            foreach ($this->forbiddenArtifactKeys($payload) as $key) {
                $blockers[] = 'artifact.'.$artifact.'.forbidden_private_or_draft_key:'.$key;
            }
        }
        if (($bigFiveAuthority['projection_kind'] ?? null) !== self::BIG_FIVE_PROJECTION_KIND) {
            $blockers[] = 'authority.big_five_projection_kind_invalid';
        }
        if (($bigFiveAuthority['projection_version'] ?? null) !== self::BIG_FIVE_PROJECTION_VERSION) {
            $blockers[] = 'authority.big_five_projection_version_invalid';
        }
        if ($this->objectList($bigFiveAuthority['items'] ?? null) === []) {
            $blockers[] = 'authority.big_five_published_projection_items_missing';
        }
        if ($this->hasInvalidObjectListItem($bigFiveAuthority['items'] ?? null)) {
            $blockers[] = 'authority.big_five_published_projection_items_invalid';
        }
        if (($careerProjection['projection_kind'] ?? null) !== BigFiveCareerBridgeContract::CAREER_PROJECTION_KIND) {
            $blockers[] = 'authority.career_projection_kind_invalid';
        }
        if (($careerProjection['projection_version'] ?? null) !== BigFiveCareerBridgeContract::CAREER_PROJECTION_VERSION) {
            $blockers[] = 'authority.career_projection_version_invalid';
        }
        if ($this->objectList($careerProjection['items'] ?? null) === []) {
            $blockers[] = 'authority.career_projection_items_missing';
        }
        if ($this->hasInvalidObjectListItem($careerProjection['items'] ?? null)) {
            $blockers[] = 'authority.career_projection_items_invalid';
        }
        if (($candidateArtifact['candidate_kind'] ?? null) !== self::CANDIDATE_KIND) {
            $blockers[] = 'candidate.kind_invalid';
        }
        if (($candidateArtifact['candidate_version'] ?? null) !== self::CANDIDATE_VERSION) {
            $blockers[] = 'candidate.version_invalid';
        }
        if (! is_array($candidateArtifact['rows'] ?? null) || ! array_is_list($candidateArtifact['rows'])) {
            $blockers[] = 'candidate.rows_invalid';
        } elseif ($candidateArtifact['rows'] === []) {
            $blockers[] = 'candidate.rows_empty';
        }

        return $this->uniqueSorted($blockers);
    }

    /** @param array<string, mixed> $value @return list<string> */
    private function forbiddenArtifactKeys(array $value): array
    {
        $found = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_ARTIFACT_KEYS, true)) {
                $found[] = strtolower($key);
            }
            if (is_array($item)) {
                $found = [...$found, ...$this->forbiddenArtifactKeys($item)];
            }
        }

        return $this->uniqueSorted($found);
    }

    /** @param array<string, mixed> $selected @param array<string, mixed> $authority */
    private function publishedProjectionMatches(array $selected, array $authority): bool
    {
        foreach ([
            'authority_surface', 'source_kind', 'framework', 'asset_id', 'locale', 'primary_status',
            'is_public', 'published_revision_id', 'selected_revision_id', 'selected_revision_source',
            'public_projection_hash', 'public_projection_ready', 'visible_evidence',
            'working_or_draft_revision_used', 'generated_authority_package_used',
        ] as $key) {
            if (($selected[$key] ?? null) !== ($authority[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $selected @param array<string, mixed> $authority */
    private function careerProjectionMatches(array $selected, array $authority): bool
    {
        $authorityBlockers = is_array($authority['blockers'] ?? null) && array_is_list($authority['blockers']) ? $authority['blockers'] : [];
        $published = ($authority['public_resolution_type'] ?? null) === 'public_canonical_job'
            && ($authority['runtime_publish_state'] ?? null) === 'published'
            && ($authority['detail_route_enabled'] ?? null) === true
            && ($authority['dataset_visible'] ?? null) === true
            && ($authority['release_gate_pass'] ?? null) === true
            && $authorityBlockers === [];

        $expected = [
            'projection_kind' => BigFiveCareerBridgeContract::CAREER_PROJECTION_KIND,
            'projection_version' => BigFiveCareerBridgeContract::CAREER_PROJECTION_VERSION,
            'canonical_slug' => $authority['slug'] ?? null,
            'locale' => $authority['locale'] ?? null,
            'public_resolution_type' => $authority['public_resolution_type'] ?? null,
            'runtime_publish_state' => $authority['runtime_publish_state'] ?? null,
            'detail_route_enabled' => $authority['detail_route_enabled'] ?? null,
            'dataset_visible' => $authority['dataset_visible'] ?? null,
            'release_gate_pass' => $authority['release_gate_pass'] ?? null,
            'publish_eligibility' => $published,
            'public_projection_ready' => $published,
            'blockers' => $authorityBlockers,
        ];
        foreach ($expected as $key => $value) {
            if (($selected[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $globalBlockers @param list<array<string, mixed>> $rows @return array<string, int> */
    private function blockerBreakdown(array $globalBlockers, array $rows): array
    {
        $counts = [];
        foreach ($globalBlockers as $blocker) {
            $counts[$blocker] = ($counts[$blocker] ?? 0) + 1;
        }
        foreach ($rows as $row) {
            foreach (is_array($row['blockers'] ?? null) ? $row['blockers'] : [] as $blocker) {
                if (is_string($blocker) && ! in_array($blocker, $globalBlockers, true)) {
                    $counts[$blocker] = ($counts[$blocker] ?? 0) + 1;
                }
            }
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function bigFiveProvenance(array $items): array
    {
        $provenance = array_map(static fn (array $item): array => [
            'asset_id' => $item['asset_id'] ?? null,
            'locale' => $item['locale'] ?? null,
            'primary_status' => $item['primary_status'] ?? null,
            'published_revision_id' => $item['published_revision_id'] ?? null,
            'selected_revision_id' => $item['selected_revision_id'] ?? null,
            'selected_revision_source' => $item['selected_revision_source'] ?? null,
            'public_projection_hash' => $item['public_projection_hash'] ?? null,
            'source_kind' => $item['source_kind'] ?? null,
        ], $items);
        usort($provenance, static fn (array $left, array $right): int => strcmp(
            (string) ($left['asset_id'] ?? '').'|'.(string) ($left['locale'] ?? ''),
            (string) ($right['asset_id'] ?? '').'|'.(string) ($right['locale'] ?? ''),
        ));

        return $provenance;
    }

    /** @param array<string, mixed> $projection @param list<array<string, mixed>> $items @return array<string, mixed> */
    private function careerProvenance(array $projection, string $hash, array $items): array
    {
        $states = [];
        foreach ($items as $item) {
            $state = (string) ($item['runtime_publish_state'] ?? 'unknown');
            $states[$state] = ($states[$state] ?? 0) + 1;
        }
        ksort($states, SORT_STRING);

        return [
            'projection_kind' => $projection['projection_kind'] ?? null,
            'projection_version' => $projection['projection_version'] ?? null,
            'projection_hash' => $hash,
            'source_authority' => $projection['source_authority'] ?? null,
            'item_count' => count($items),
            'runtime_state_counts' => $states,
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function objectList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item) && ! array_is_list($item)));
    }

    private function hasInvalidObjectListItem(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        return count($this->objectList($value)) !== count($value);
    }

    /** @param list<string> $values @return list<string> */
    private function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '')));
        sort($values, SORT_STRING);

        return $values;
    }

    private function markdownText(string $value): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $value);
    }
}
