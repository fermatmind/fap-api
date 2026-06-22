<?php

declare(strict_types=1);

namespace App\Services\SeoAgent;

final class OpportunityAggregator
{
    public const SCHEMA_VERSION = 'seo-agent-opportunity-aggregate.v1';

    public const TASK = 'SEO-AGENT-OPPORTUNITY-AGGREGATOR-01';

    private const SOURCE_WEIGHTS = [
        'runtime_seo_qa' => 40,
        'cms_tdk_gap' => 30,
        'cms_faq_gap' => 20,
        'gsc_performance' => 10,
    ];

    private const SEVERITY_WEIGHTS = [
        'p0' => 400,
        'p1' => 300,
        'p2' => 200,
        'p3' => 100,
    ];

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @return array<string, mixed>
     */
    public function aggregate(array $artifacts, int $limit = 100): array
    {
        $limit = max(1, min($limit, 250));
        $merged = [];

        foreach ($artifacts as $artifact) {
            foreach ($this->extractCandidates($artifact) as $candidate) {
                $normalized = $this->normalizeCandidate($candidate);
                if ($normalized === null) {
                    continue;
                }

                $dedupeKey = $this->dedupeKey($normalized);
                if (! isset($merged[$dedupeKey])) {
                    $merged[$dedupeKey] = $normalized;

                    continue;
                }

                $merged[$dedupeKey] = $this->mergeCandidate($merged[$dedupeKey], $normalized);
            }
        }

        $candidates = array_values($merged);
        foreach ($candidates as &$candidate) {
            $candidate['score'] = $this->score($candidate);
        }
        unset($candidate);

        usort($candidates, fn (array $left, array $right): int => $this->sortCandidates($left, $right));
        $candidates = array_slice($candidates, 0, $limit);

        foreach ($candidates as $index => &$candidate) {
            $candidate['priority_rank'] = $index + 1;
        }
        unset($candidate);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'status' => 'success',
            'run_mode' => 'readonly_discovery',
            'input_artifact_count' => count($artifacts),
            'candidate_count' => count($candidates),
            'limit' => $limit,
            'candidates' => $candidates,
            'dedupe_policy' => 'subject_type + subject_ref + source_family + sorted_gap_types',
            'ranking_policy' => 'severity desc, source weight desc, evidence count desc, dedupe key asc',
            'source_weights' => self::SOURCE_WEIGHTS,
            'forbidden_output_fields_absent' => true,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<array<string, mixed>>
     */
    private function extractCandidates(array $artifact): array
    {
        $candidates = $artifact['candidates'] ?? data_get($artifact, 'data.recent_rows', []);
        if (! is_array($candidates)) {
            return [];
        }

        return array_values(array_filter($candidates, static fn ($candidate): bool => is_array($candidate)));
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function normalizeCandidate(array $candidate): ?array
    {
        $sourceFamily = (string) ($candidate['source_family'] ?? $candidate['source'] ?? '');
        $subjectType = (string) ($candidate['subject_type'] ?? '');
        $subjectRef = (string) ($candidate['subject_ref'] ?? '');
        $safePath = (string) ($candidate['safe_path'] ?? $candidate['canonical_path'] ?? '');
        $severity = (string) ($candidate['severity'] ?? 'p3');

        if ($sourceFamily === '' || $subjectType === '' || $subjectRef === '' || $safePath === '') {
            return null;
        }

        if (! in_array($severity, ['p0', 'p1', 'p2', 'p3'], true)) {
            $severity = 'p3';
        }

        $gapTypes = $this->strings((array) ($candidate['gap_types'] ?? $candidate['gap_codes'] ?? []));
        if ($gapTypes === []) {
            $gapTypes = $this->gapTypesFromEvidence((array) ($candidate['evidence_refs'] ?? []));
        }

        $normalized = [
            'source_family' => $sourceFamily,
            'source_id' => (string) ($candidate['source_id'] ?? hash('sha256', json_encode($candidate) ?: 'candidate')),
            'subject_type' => $subjectType,
            'subject_ref' => $subjectRef,
            'safe_path' => $safePath,
            'locale' => (string) ($candidate['locale'] ?? ''),
            'severity' => $severity,
            'gap_types' => $gapTypes,
            'dedupe_key' => '',
            'evidence_refs' => $this->sanitizeEvidenceRefs((array) ($candidate['evidence_refs'] ?? [])),
            'recommended_next_step' => (string) ($candidate['recommended_next_step'] ?? 'codex_review_required'),
            'allowed_action' => (string) ($candidate['allowed_action'] ?? 'readonly_review'),
            'blocked_actions' => $this->strings((array) ($candidate['blocked_actions'] ?? [])),
            'source_ids' => [(string) ($candidate['source_id'] ?? '')],
        ];

        $proposalPayload = $this->sanitizeProposalPayload((array) ($candidate['proposal_payload'] ?? []));
        if ($proposalPayload !== []) {
            $normalized['proposal_payload'] = $proposalPayload;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function dedupeKey(array &$candidate): string
    {
        $gapTypes = $this->strings((array) ($candidate['gap_types'] ?? []));
        sort($gapTypes);
        $candidate['gap_types'] = $gapTypes;

        $key = implode('|', [
            (string) ($candidate['subject_type'] ?? ''),
            (string) ($candidate['subject_ref'] ?? ''),
            (string) ($candidate['source_family'] ?? ''),
            implode(',', $gapTypes),
        ]);
        $candidate['dedupe_key'] = hash('sha256', $key);

        return (string) $candidate['dedupe_key'];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeCandidate(array $existing, array $incoming): array
    {
        $existing['evidence_refs'] = array_values(array_slice(array_merge(
            (array) ($existing['evidence_refs'] ?? []),
            (array) ($incoming['evidence_refs'] ?? [])
        ), 0, 20));

        $existing['source_ids'] = array_values(array_unique(array_filter(array_merge(
            (array) ($existing['source_ids'] ?? []),
            (array) ($incoming['source_ids'] ?? [])
        ))));

        if ($this->severityWeight((string) ($incoming['severity'] ?? 'p3')) > $this->severityWeight((string) ($existing['severity'] ?? 'p3'))) {
            $existing['severity'] = $incoming['severity'];
        }

        if (! isset($existing['proposal_payload']) && isset($incoming['proposal_payload'])) {
            $existing['proposal_payload'] = $incoming['proposal_payload'];
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function score(array $candidate): int
    {
        return $this->severityWeight((string) ($candidate['severity'] ?? 'p3'))
            + (self::SOURCE_WEIGHTS[(string) ($candidate['source_family'] ?? '')] ?? 0)
            + min(count((array) ($candidate['evidence_refs'] ?? [])), 20);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function sortCandidates(array $left, array $right): int
    {
        return ((int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0))
            ?: strcmp((string) ($left['dedupe_key'] ?? ''), (string) ($right['dedupe_key'] ?? ''));
    }

    private function severityWeight(string $severity): int
    {
        return self::SEVERITY_WEIGHTS[$severity] ?? self::SEVERITY_WEIGHTS['p3'];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', $values),
            static fn (string $value): bool => $value !== ''
        )));
    }

    /**
     * @param  array<int, mixed>  $evidenceRefs
     * @return list<string>
     */
    private function gapTypesFromEvidence(array $evidenceRefs): array
    {
        $codes = [];
        foreach ($evidenceRefs as $ref) {
            if (is_array($ref) && ($ref['code'] ?? '') !== '') {
                $codes[] = (string) $ref['code'];
            }
        }

        return $this->strings($codes);
    }

    /**
     * @param  array<int, mixed>  $evidenceRefs
     * @return list<array<string, mixed>>
     */
    private function sanitizeEvidenceRefs(array $evidenceRefs): array
    {
        $refs = [];
        foreach ($evidenceRefs as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $clean = [];
            foreach (['code', 'field_status', 'status_code', 'expected_safe_path_hash', 'observed_safe_path_hash'] as $field) {
                if (array_key_exists($field, $ref)) {
                    $clean[$field] = is_scalar($ref[$field]) ? $ref[$field] : (string) json_encode($ref[$field]);
                }
            }
            if ($clean !== []) {
                $refs[] = $clean;
            }
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeProposalPayload(array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        $runtime = (array) ($payload['runtime'] ?? []);
        $metrics = (array) ($payload['metrics'] ?? []);

        $safePath = (string) ($payload['safe_path'] ?? '');
        if (! str_starts_with($safePath, '/')) {
            $safePath = '';
        }

        return array_filter([
            'source' => $this->cleanText((string) ($payload['source'] ?? '')),
            'locale' => $this->cleanText((string) ($payload['locale'] ?? '')),
            'safe_path' => $safePath,
            'draft_angle' => $this->cleanText((string) ($payload['draft_angle'] ?? '')),
            'proposed_actions' => $this->cleanTextList((array) ($payload['proposed_actions'] ?? [])),
            'runtime' => array_filter([
                'title' => $this->cleanText((string) ($runtime['title'] ?? '')),
                'meta_description' => $this->cleanText((string) ($runtime['meta_description'] ?? '')),
                'title_length' => (int) ($runtime['title_length'] ?? 0),
                'meta_description_length' => (int) ($runtime['meta_description_length'] ?? 0),
                'jsonld_total' => (int) ($runtime['jsonld_total'] ?? 0),
                'internal_link_count' => (int) ($runtime['internal_link_count'] ?? 0),
                'sample_internal_paths' => array_values(array_slice(array_filter(
                    $this->cleanTextList((array) ($runtime['sample_internal_paths'] ?? [])),
                    static fn (string $path): bool => str_starts_with($path, '/')
                ), 0, 10)),
            ], static fn (mixed $value): bool => $value !== '' && $value !== []),
            'metrics' => array_filter([
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'clicks' => (int) ($metrics['clicks'] ?? 0),
                'ctr_ppm' => (int) ($metrics['ctr_ppm'] ?? 0),
                'average_position_milli' => (int) ($metrics['average_position_milli'] ?? 0),
            ], static fn (mixed $value): bool => $value !== ''),
        ], static fn (mixed $value): bool => $value !== '' && $value !== []);
    }

    private function cleanText(string $value): string
    {
        $value = preg_replace('#https?://\S+#i', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function cleanTextList(array $values): array
    {
        return array_values(array_slice(array_filter(
            array_map(fn (mixed $value): string => is_scalar($value) ? $this->cleanText((string) $value) : '', $values),
            static fn (string $value): bool => $value !== ''
        ), 0, 20));
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'cms_publish' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'google_search_console_api_call' => false,
            'google_indexing_api_call' => false,
            'external_model_api_call' => false,
            'pr_train_metadata_change' => false,
        ];
    }
}
