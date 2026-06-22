<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\SeoAgent\OpportunityAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

final class SeoAgentGscCohortHandoffCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-gsc-cohort-handoff.v1';

    private const CLASSIFIED_SCHEMA_VERSION = 'fermatmind-gsc-seo-agent-cohort.v1';

    private const PROPOSALS_SCHEMA_VERSION = 'fermatmind-seo-agent-gsc-draft-proposals.v1';

    private const SOURCE_FAMILY = 'gsc_cohort_artifact';

    private const FORBIDDEN_STRINGS = [
        'raw_url',
        'raw_query',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'Bearer ',
        'token',
        'cookie',
        'session',
        'content_md',
        'content_html',
        'cms_draft_body',
    ];

    protected $signature = 'seo-agent:gsc-cohort-handoff
        {--classified= : Path to a fermatmind-gsc-seo-agent-cohort.v1 JSON artifact}
        {--proposals= : Path to a fermatmind-seo-agent-gsc-draft-proposals.v1 JSON artifact}
        {--limit=10 : Candidate limit, bounded 1..100}
        {--artifact-dir= : Directory for sanitized JSON artifacts}
        {--json : Emit JSON summary}';

    protected $description = 'Convert exported GSC cohort artifacts into standard SEO Agent review and CMS draft dry-run artifacts without writes.';

    public function handle(): int
    {
        $classified = $this->readArtifact((string) $this->option('classified'), self::CLASSIFIED_SCHEMA_VERSION, 'classified');
        if (($classified['ok'] ?? false) !== true) {
            return $this->finish($this->failureSummary((string) ($classified['issue'] ?? 'classified_unreadable')));
        }

        $proposals = $this->readArtifact((string) $this->option('proposals'), self::PROPOSALS_SCHEMA_VERSION, 'proposals');
        if (($proposals['ok'] ?? false) !== true) {
            return $this->finish($this->failureSummary((string) ($proposals['issue'] ?? 'proposals_unreadable')));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $limit = $this->limit();
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $prepared = $this->prepareCandidates((array) data_get($proposals, 'payload.proposals', []), $limit);
        $sourceArtifact = $this->sourceArtifact(
            (array) $classified['payload'],
            (array) $proposals['payload'],
            (array) $classified['ref'],
            (array) $proposals['ref'],
            $prepared,
            $limit
        );
        $sourceRef = $this->writeArtifact($artifactDir, 'seo-agent-gsc-cohort-source-'.$timestamp.'.json', $sourceArtifact);

        $aggregate = (new OpportunityAggregator)->aggregate([$sourceArtifact], $limit);
        $aggregate['input_artifacts'] = [$sourceRef];
        $aggregateRef = $this->writeArtifact($artifactDir, 'seo-agent-gsc-cohort-aggregate-'.$timestamp.'.json', $aggregate);

        $handoff = $this->codexReviewHandoff($sourceRef, $aggregateRef, $aggregate);
        $handoffRef = $this->writeArtifact($artifactDir, 'seo-agent-gsc-cohort-codex-handoff-'.$timestamp.'.json', $handoff);

        $verdictRef = $this->runCodexReview($handoffRef, $artifactDir);
        if ($verdictRef === null) {
            return $this->finish($this->failureSummary('codex_review_runner_failed', [
                'source_artifact' => $sourceRef,
                'aggregate_artifact' => $aggregateRef,
                'handoff_artifact' => $handoffRef,
            ]));
        }

        $draftRef = $this->runDraftPackageDryRun($verdictRef, $artifactDir);
        if ($draftRef === null) {
            return $this->finish($this->failureSummary('cms_draft_package_dry_run_failed', [
                'source_artifact' => $sourceRef,
                'aggregate_artifact' => $aggregateRef,
                'handoff_artifact' => $handoffRef,
                'verdict_artifact' => $verdictRef,
            ]));
        }

        $evidence = $this->runEvidence($prepared, $sourceRef, $aggregateRef, $handoffRef, $verdictRef, $draftRef);
        $evidenceRef = $this->writeArtifact($artifactDir, 'seo-agent-gsc-cohort-handoff-evidence-'.$timestamp.'.json', $evidence);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => 'success',
            'candidate_count' => count((array) ($prepared['candidates'] ?? [])),
            'deferred_non_draft_count' => count((array) ($prepared['deferred_non_draft_groups'] ?? [])),
            'unresolved_article_count' => count((array) ($prepared['unresolved_article_targets'] ?? [])),
            'draft_brief_count' => (int) data_get($draftRef, 'sanitized_summary.draft_brief_count', 0),
            'artifact' => $evidenceRef,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    private function limit(): int
    {
        $raw = trim((string) $this->option('limit'));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return 10;
        }

        return max(1, min((int) $raw, 100));
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @return array{ok:bool, issue?:string, payload?:array<string,mixed>, ref?:array<string,mixed>}
     */
    private function readArtifact(string $path, string $schemaVersion, string $label): array
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            return ['ok' => false, 'issue' => $label.'_path_missing'];
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);
        if (! is_file($path) || ! is_readable($path)) {
            return ['ok' => false, 'issue' => $label.'_unreadable'];
        }

        $raw = (string) file_get_contents($path);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return ['ok' => false, 'issue' => 'forbidden_input_field_present'];
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return ['ok' => false, 'issue' => $label.'_json_invalid'];
        }

        if (($payload['schema_version'] ?? null) !== $schemaVersion) {
            return ['ok' => false, 'issue' => $label.'_schema_invalid'];
        }

        return [
            'ok' => true,
            'payload' => $payload,
            'ref' => [
                'path_hash' => hash('sha256', $path),
                'sha256' => hash_file('sha256', $path) ?: '',
                'schema_version' => $schemaVersion,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     * @return array{candidates:list<array<string,mixed>>, deferred_non_draft_groups:list<array<string,mixed>>, unresolved_article_targets:list<array<string,mixed>>}
     */
    private function prepareCandidates(array $proposals, int $limit): array
    {
        $candidates = [];
        $deferred = [];
        $unresolved = [];

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            if (($proposal['proposal_type'] ?? null) !== 'article_title_meta_faq_internal_link_draft') {
                $deferred[] = $this->deferredProposal($proposal, 'non_article_or_non_draft_proposal');

                continue;
            }

            $safePath = $this->safePath((string) ($proposal['target_url'] ?? ''));
            $articleTarget = $safePath === null ? null : $this->articleTarget($safePath);
            if ($articleTarget === null) {
                $unresolved[] = [
                    'safe_path_hash' => $safePath === null ? '' : hash('sha256', $safePath),
                    'reason' => $safePath === null ? 'target_path_invalid' : 'article_target_missing',
                ];

                continue;
            }

            $candidates[] = $this->candidate($proposal, $safePath, $articleTarget);
            if (count($candidates) >= $limit) {
                break;
            }
        }

        return [
            'candidates' => $candidates,
            'deferred_non_draft_groups' => $deferred,
            'unresolved_article_targets' => $unresolved,
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array{id:int, locale:string}  $articleTarget
     * @return array<string, mixed>
     */
    private function candidate(array $proposal, string $safePath, array $articleTarget): array
    {
        $runtime = (array) ($proposal['runtime_seo_check'] ?? []);
        $evidence = (array) ($proposal['evidence_7d_or_28d'] ?? []);
        $impressions = (int) ($evidence['impressions'] ?? 0);
        $clicks = (int) ($evidence['clicks'] ?? 0);
        $ctrPpm = $this->ctrPpm($evidence['ctr'] ?? null, $clicks, $impressions);
        $positionMilli = $this->positionMilli($evidence['position'] ?? null);
        $gapTypes = $this->gapTypes($runtime, $ctrPpm);
        $sourceId = hash('sha256', implode('|', [
            self::SOURCE_FAMILY,
            $safePath,
            (string) $articleTarget['id'],
            implode(',', $gapTypes),
            (string) $impressions,
            (string) $positionMilli,
        ]));

        return [
            'source_family' => self::SOURCE_FAMILY,
            'source_id' => $sourceId,
            'subject_type' => 'article',
            'subject_ref' => 'article:'.$articleTarget['id'].':'.$articleTarget['locale'],
            'safe_path' => $safePath,
            'locale' => $articleTarget['locale'],
            'severity' => $impressions >= 500 && $ctrPpm <= 5000 ? 'p1' : 'p2',
            'gap_types' => $gapTypes,
            'evidence_refs' => $this->evidenceRefs($runtime, $ctrPpm),
            'metrics' => [
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr_ppm' => $ctrPpm,
                'average_position_milli' => $positionMilli,
                'title_length' => (int) ($runtime['title_length'] ?? 0),
                'meta_description_length' => (int) ($runtime['meta_description_length'] ?? 0),
                'jsonld_total' => (int) ($runtime['jsonld_total'] ?? 0),
                'internal_link_count' => (int) data_get($runtime, 'internal_link_summary.total_internal_links', 0),
            ],
            'proposal_payload' => $this->proposalPayload($proposal, $safePath, $articleTarget['locale'], [
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr_ppm' => $ctrPpm,
                'average_position_milli' => $positionMilli,
            ]),
            'safe_path_hash' => hash('sha256', $safePath),
            'recommended_next_step' => 'codex_review_then_cms_draft_package_dry_run',
            'allowed_action' => 'cms_draft_package_dry_run',
            'blocked_actions' => [
                'database_write',
                'cms_write',
                'cms_publish',
                'search_channel_enqueue',
                'search_channel_submit',
                'indexing_request',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<string, int|null>  $metrics
     * @return array<string, mixed>
     */
    private function proposalPayload(array $proposal, string $safePath, string $locale, array $metrics): array
    {
        $runtime = (array) ($proposal['runtime_seo_check'] ?? []);

        return [
            'source' => self::SOURCE_FAMILY,
            'locale' => $locale,
            'safe_path' => $safePath,
            'draft_angle' => $this->safeText((string) ($proposal['draft_angle'] ?? ''), 160),
            'proposed_actions' => $this->safeTextList((array) ($proposal['proposed_actions'] ?? []), 8, 240),
            'runtime' => [
                'title' => $this->safeText((string) ($runtime['title'] ?? ''), 180),
                'meta_description' => $this->safeText((string) ($runtime['meta_description'] ?? ''), 260),
                'title_length' => (int) ($runtime['title_length'] ?? 0),
                'meta_description_length' => (int) ($runtime['meta_description_length'] ?? 0),
                'jsonld_total' => (int) ($runtime['jsonld_total'] ?? 0),
                'internal_link_count' => (int) data_get($runtime, 'internal_link_summary.total_internal_links', 0),
                'sample_internal_paths' => $this->safeInternalPaths((array) data_get($runtime, 'internal_link_summary.sample_internal_paths', [])),
            ],
            'metrics' => [
                'clicks' => (int) ($metrics['clicks'] ?? 0),
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'ctr_ppm' => (int) ($metrics['ctr_ppm'] ?? 0),
                'average_position_milli' => is_int($metrics['average_position_milli'] ?? null)
                    ? $metrics['average_position_milli']
                    : null,
            ],
        ];
    }

    private function safeText(string $value, int $maxLength): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('~https?://[^\s<>"\']+~iu', '', $value) ?: '';
        $value = preg_replace('/\s+/u', ' ', $value) ?: '';

        return mb_substr($value, 0, $maxLength);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function safeTextList(array $values, int $limit, int $maxLength): array
    {
        $items = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $text = $this->safeText((string) $value, $maxLength);
            if ($text === '') {
                continue;
            }

            $items[] = $text;
            if (count($items) >= $limit) {
                break;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param  array<int, mixed>  $paths
     * @return list<string>
     */
    private function safeInternalPaths(array $paths): array
    {
        $safe = [];
        foreach ($paths as $path) {
            if (! is_scalar($path)) {
                continue;
            }

            $parsed = parse_url((string) $path, PHP_URL_PATH);
            if (! is_string($parsed) || ! str_starts_with($parsed, '/')) {
                continue;
            }

            $safe[] = $parsed;
            if (count($safe) >= 10) {
                break;
            }
        }

        return array_values(array_unique($safe));
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return list<string>
     */
    private function gapTypes(array $runtime, int $ctrPpm): array
    {
        $gapTypes = [
            'gsc_low_ctr_title_opportunity',
            'missing_visible_faq',
        ];

        if ((int) ($runtime['meta_description_length'] ?? 0) < 80 || $ctrPpm <= 10000) {
            $gapTypes[] = 'gsc_low_ctr_description_opportunity';
        }

        if ((int) ($runtime['jsonld_total'] ?? 0) === 0) {
            $gapTypes[] = 'missing_structured_data';
        }

        return array_values(array_unique($gapTypes));
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return list<array<string, string>>
     */
    private function evidenceRefs(array $runtime, int $ctrPpm): array
    {
        $refs = [
            [
                'code' => 'gsc_cohort_article_proposal',
                'field_status' => 'artifact_only_candidate',
            ],
            [
                'code' => 'gsc_low_ctr_rank_8_20',
                'field_status' => $ctrPpm <= 10000 ? 'eligible_low_ctr' : 'review_required',
            ],
        ];

        if ((int) ($runtime['meta_description_length'] ?? 0) < 80) {
            $refs[] = [
                'code' => 'gsc_low_ctr_description_opportunity',
                'field_status' => 'short_meta_description',
            ];
        }

        if ((int) ($runtime['jsonld_total'] ?? 0) === 0) {
            $refs[] = [
                'code' => 'missing_structured_data',
                'field_status' => 'jsonld_total_zero',
            ];
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function deferredProposal(array $proposal, string $reason): array
    {
        $safePath = $this->safePath((string) ($proposal['target_url'] ?? ''));

        return [
            'group' => (string) ($proposal['group'] ?? 'unknown'),
            'proposal_type' => (string) ($proposal['proposal_type'] ?? 'unknown'),
            'safe_path_hash' => $safePath === null ? '' : hash('sha256', $safePath),
            'reason' => $reason,
        ];
    }

    /**
     * @return array{id:int, locale:string}|null
     */
    private function articleTarget(string $safePath): ?array
    {
        if (preg_match('~^/(en|zh)/articles/([^/?#]+)$~', $safePath, $matches) !== 1) {
            return null;
        }

        $localeCandidates = $matches[1] === 'zh' ? ['zh-CN', 'zh'] : ['en'];
        $article = Article::query()
            ->withoutGlobalScopes()
            ->where('slug', $matches[2])
            ->whereIn('locale', $localeCandidates)
            ->orderByRaw('case when locale = ? then 0 else 1 end', [$localeCandidates[0]])
            ->first(['id', 'locale']);

        if (! $article instanceof Article) {
            return null;
        }

        return [
            'id' => (int) $article->id,
            'locale' => (string) $article->locale,
        ];
    }

    private function safePath(string $url): ?string
    {
        if (trim($url) === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        return $path;
    }

    private function ctrPpm(mixed $ctr, int $clicks, int $impressions): int
    {
        if (is_string($ctr) && preg_match('/^([0-9]+(?:\.[0-9]+)?)%$/', trim($ctr), $matches) === 1) {
            return (int) round(((float) $matches[1] / 100) * 1_000_000);
        }

        return $impressions > 0 ? (int) floor(($clicks / $impressions) * 1_000_000) : 0;
    }

    private function positionMilli(mixed $position): ?int
    {
        if (! is_numeric($position)) {
            return null;
        }

        return (int) round(((float) $position) * 1000);
    }

    /**
     * @param  array<string, mixed>  $classified
     * @param  array<string, mixed>  $proposals
     * @param  array<string, mixed>  $classifiedRef
     * @param  array<string, mixed>  $proposalsRef
     * @param  array<string, mixed>  $prepared
     * @return array<string, mixed>
     */
    private function sourceArtifact(
        array $classified,
        array $proposals,
        array $classifiedRef,
        array $proposalsRef,
        array $prepared,
        int $limit
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-GSC-COHORT-HANDOFF-01',
            'run_mode' => 'readonly_gsc_cohort_artifact_to_draft_dry_run',
            'source_family' => self::SOURCE_FAMILY,
            'input_artifacts' => [
                'classified' => $classifiedRef,
                'proposals' => $proposalsRef,
            ],
            'input_summary' => [
                'classified_row_counts' => (array) ($classified['row_counts'] ?? []),
                'proposal_count' => (int) ($proposals['proposal_count'] ?? count((array) ($proposals['proposals'] ?? []))),
            ],
            'candidate_contract' => [
                'draft_eligible_proposal_type' => 'article_title_meta_faq_internal_link_draft',
                'allowed_subject_types' => ['article'],
                'source_family' => self::SOURCE_FAMILY,
                'safe_path_only' => true,
                'target_url_output_allowed' => false,
            ],
            'candidate_count' => count((array) ($prepared['candidates'] ?? [])),
            'limit' => $limit,
            'candidates' => (array) ($prepared['candidates'] ?? []),
            'deferred_non_draft_groups' => (array) ($prepared['deferred_non_draft_groups'] ?? []),
            'unresolved_article_targets' => (array) ($prepared['unresolved_article_targets'] ?? []),
            'forbidden_output_fields_absent' => true,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceRef
     * @param  array<string, mixed>  $aggregateRef
     * @param  array<string, mixed>  $aggregate
     * @return array<string, mixed>
     */
    private function codexReviewHandoff(array $sourceRef, array $aggregateRef, array $aggregate): array
    {
        return [
            'schema_version' => 'seo-agent-codex-review-handoff.v1',
            'task' => 'SEO-AGENT-GSC-COHORT-HANDOFF-01',
            'reviewer' => 'codex',
            'role' => 'review_only',
            'execution_permission' => false,
            'input_candidates' => $aggregateRef,
            'input_artifacts' => [$sourceRef],
            'candidate_count' => (int) ($aggregate['candidate_count'] ?? 0),
            'review_output_contract' => [
                'worth_optimizing' => 'boolean',
                'recommended_action' => 'cms_draft_package_dry_run|defer',
                'risk_flags' => 'list<string>',
                'needs_human_approval' => 'boolean',
            ],
            'candidate_preview' => array_slice((array) ($aggregate['candidates'] ?? []), 0, 100),
            'forbidden_actions' => [
                'database_write',
                'cms_write',
                'cms_publish',
                'search_channel_enqueue',
                'search_channel_submit',
                'indexing_request',
                'scheduler_activation',
                'queue_worker_activation',
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $handoffRef
     * @return array<string, mixed>|null
     */
    private function runCodexReview(array $handoffRef, string $artifactDir): ?array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('seo-agent:codex-review-runner', [
            '--handoff' => (string) ($handoffRef['path'] ?? ''),
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ], $output);
        $summary = json_decode(trim($output->fetch()), true);

        return $exit === self::SUCCESS && is_array($summary) && is_array($summary['artifact'] ?? null)
            ? $summary['artifact']
            : null;
    }

    /**
     * @param  array<string, mixed>  $verdictRef
     * @return array<string, mixed>|null
     */
    private function runDraftPackageDryRun(array $verdictRef, string $artifactDir): ?array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('seo-agent:cms-draft-package-dry-run', [
            '--verdict' => (string) ($verdictRef['path'] ?? ''),
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ], $output);
        $summary = json_decode(trim($output->fetch()), true);

        return $exit === self::SUCCESS && is_array($summary) && is_array($summary['artifact'] ?? null)
            ? $summary['artifact']
            : null;
    }

    /**
     * @param  array<string, mixed>  $prepared
     * @param  array<string, mixed>  $sourceRef
     * @param  array<string, mixed>  $aggregateRef
     * @param  array<string, mixed>  $handoffRef
     * @param  array<string, mixed>  $verdictRef
     * @param  array<string, mixed>  $draftRef
     * @return array<string, mixed>
     */
    private function runEvidence(
        array $prepared,
        array $sourceRef,
        array $aggregateRef,
        array $handoffRef,
        array $verdictRef,
        array $draftRef
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-GSC-COHORT-HANDOFF-01',
            'status' => 'success',
            'candidate_count' => count((array) ($prepared['candidates'] ?? [])),
            'deferred_non_draft_groups' => (array) ($prepared['deferred_non_draft_groups'] ?? []),
            'unresolved_article_targets' => (array) ($prepared['unresolved_article_targets'] ?? []),
            'artifacts' => [
                'gsc_cohort_source' => $sourceRef,
                'opportunity_aggregate' => $aggregateRef,
                'codex_review_handoff' => $handoffRef,
                'codex_review_verdict' => $verdictRef,
                'cms_draft_package_dry_run' => $draftRef,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function writeArtifact(string $artifactDir, string $filename, array $payload): array
    {
        $path = rtrim($artifactDir, '/').'/'.$filename;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded) || file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException('artifact_write_failed');
        }

        return [
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'schema_version' => (string) ($payload['schema_version'] ?? 'unknown'),
            'sanitized_summary' => [
                'candidate_count' => (int) ($payload['candidate_count'] ?? 0),
                'draft_brief_count' => (int) ($payload['draft_brief_count'] ?? 0),
                'forbidden_output_fields_absent' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue, array $extra = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'issues' => [$issue],
            ...$extra,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
            foreach ((array) ($summary['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
            if (is_array($summary['artifact'] ?? null)) {
                $this->line('artifact_path='.(string) ($summary['artifact']['path'] ?? ''));
                $this->line('artifact_size='.(string) ($summary['artifact']['size'] ?? 0));
                $this->line('artifact_sha256='.(string) ($summary['artifact']['sha256'] ?? ''));
            }
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $raw): array
    {
        $matches = [];
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            if (str_contains($raw, $needle)) {
                $matches[] = $needle;
            }
        }

        return $matches;
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
        ];
    }
}
