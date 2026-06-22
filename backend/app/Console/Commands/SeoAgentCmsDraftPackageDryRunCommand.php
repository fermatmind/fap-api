<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentCmsDraftPackageDryRunCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

    private const VERDICT_SCHEMA_VERSION = 'seo-agent-codex-review-verdict.v1';

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

    protected $signature = 'seo-agent:cms-draft-package-dry-run
        {--verdict= : Path to a seo-agent-codex-review-verdict.v1 JSON artifact}
        {--artifact-dir= : Directory for sanitized JSON artifacts}
        {--json : Emit JSON summary}';

    protected $description = 'Build a CMS draft package dry-run artifact from Codex review verdicts without writing CMS or DB rows.';

    public function handle(): int
    {
        $verdictPath = $this->verdictPath();
        if ($verdictPath === null) {
            return $this->finish($this->failureSummary('verdict_unreadable'));
        }

        $raw = (string) file_get_contents($verdictPath);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $verdict = json_decode($raw, true);
        if (! is_array($verdict)) {
            return $this->finish($this->failureSummary('verdict_json_invalid'));
        }

        if (($verdict['schema_version'] ?? null) !== self::VERDICT_SCHEMA_VERSION) {
            return $this->finish($this->failureSummary('verdict_schema_invalid'));
        }

        if ((bool) ($verdict['execution_permission'] ?? true)) {
            return $this->finish($this->failureSummary('verdict_execution_boundary_invalid'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $package = $this->package($verdict, $verdictPath);
        $artifactRef = $this->writeArtifact($artifactDir, 'seo-agent-cms-draft-package-dry-run-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json', $package);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => 'success',
            'draft_brief_count' => count($package['draft_briefs']),
            'artifact' => $artifactRef,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    private function verdictPath(): ?string
    {
        $path = trim((string) $this->option('verdict'));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
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
     * @param  array<string, mixed>  $verdict
     * @return array<string, mixed>
     */
    private function package(array $verdict, string $verdictPath): array
    {
        $candidateVerdicts = array_values(array_filter(
            (array) ($verdict['candidate_verdicts'] ?? []),
            static fn ($candidate): bool => is_array($candidate)
                && ($candidate['recommended_action'] ?? null) === 'cms_draft_package_dry_run'
                && ($candidate['worth_optimizing'] ?? false) === true
                && ($candidate['execution_permission'] ?? false) === false
        ));

        $draftBriefs = array_map(
            fn (array $candidate): array => $this->draftBrief($candidate),
            $candidateVerdicts
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'run_mode' => 'cms_draft_package_dry_run',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'input_verdict' => [
                'path_hash' => hash('sha256', $verdictPath),
                'sha256' => hash_file('sha256', $verdictPath) ?: '',
                'schema_version' => (string) ($verdict['schema_version'] ?? ''),
            ],
            'draft_brief_count' => count($draftBriefs),
            'draft_briefs' => $draftBriefs,
            'proposal_count' => count($draftBriefs),
            'proposal_items' => $draftBriefs,
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'forbidden_actions' => [
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
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function draftBrief(array $candidate): array
    {
        $gapCodes = $this->gapCodes($candidate);
        $targetFields = $this->targetFields($gapCodes);
        $safePath = (string) ($candidate['safe_path'] ?? '');
        $subjectType = (string) ($candidate['subject_type'] ?? '');
        $targetModel = $subjectType === 'content_page' ? 'content_page' : 'article';
        $label = $this->labelFromSafePath($safePath);
        $proposalPayload = $this->proposalPayload($candidate);

        return [
            'source_id' => (string) ($candidate['source_id'] ?? ''),
            'source_family' => (string) ($candidate['source_family'] ?? ''),
            'subject_type' => $subjectType,
            'subject_ref' => (string) ($candidate['subject_ref'] ?? ''),
            'safe_path' => $safePath,
            'severity' => (string) ($candidate['severity'] ?? ''),
            'gap_codes' => $gapCodes,
            'target_model' => $targetModel,
            'target_fields' => $targetFields,
            'proposed_seo_title' => in_array('seo_title', $targetFields, true)
                ? $this->proposedSeoTitle($label, $proposalPayload)
                : null,
            'proposed_seo_description' => in_array('seo_description', $targetFields, true)
                ? $this->proposedSeoDescription($label, $proposalPayload)
                : null,
            'proposed_faq_items' => in_array('faq_items', $targetFields, true)
                ? $this->proposedFaqItems($label, $proposalPayload)
                : [],
            'proposed_internal_link_actions' => $this->proposedInternalLinkActions($proposalPayload),
            'proposed_canonical_path' => in_array('canonical_url_or_path', $targetFields, true)
                ? $safePath
                : null,
            'proposed_indexability' => in_array('is_indexable_or_robots', $targetFields, true)
                ? 'indexable_after_manual_review'
                : null,
            'proposal_quality' => $this->proposalQuality($proposalPayload),
            'draft_instructions' => [
                'prepare_field_level_proposal_only',
                'do_not_generate_final_body_copy',
                'preserve_existing_slug_and_canonical_unless_separately_approved',
                'run_claim_gate_before_any_cms_write',
            ],
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'execution_permission' => false,
            'blocked_actions' => [
                'cms_write',
                'cms_publish',
                'search_channel_enqueue',
                'search_channel_submit',
                'indexing_request',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function gapCodes(array $candidate): array
    {
        $codes = array_map('strval', (array) ($candidate['gap_types'] ?? []));
        foreach ((array) ($candidate['evidence_refs'] ?? []) as $ref) {
            if (is_array($ref) && ($ref['code'] ?? '') !== '') {
                $codes[] = (string) $ref['code'];
            }
        }

        return array_values(array_unique(array_filter($codes, static fn (string $code): bool => $code !== '')));
    }

    /**
     * @param  list<string>  $gapCodes
     * @return list<string>
     */
    private function targetFields(array $gapCodes): array
    {
        $fields = [];
        foreach ($gapCodes as $code) {
            $fields[] = match ($code) {
                'missing_title' => 'seo_title',
                'gsc_low_ctr_title_opportunity', 'gsc_low_ctr_rank_8_20' => 'seo_title',
                'missing_meta_description' => 'seo_description',
                'gsc_low_ctr_description_opportunity' => 'seo_description',
                'missing_canonical' => 'canonical_url_or_path',
                'missing_indexability_metadata' => 'is_indexable_or_robots',
                'missing_faq_items', 'missing_visible_faq' => 'faq_items',
                'faq_schema_enabled_without_visible_faq' => 'faq_schema_eligible',
                default => 'manual_review_required',
            };
        }

        return array_values(array_unique($fields));
    }

    private function labelFromSafePath(string $safePath): string
    {
        $lastSegment = basename(trim($safePath, '/'));
        $label = trim(str_replace(['-', '_'], ' ', $lastSegment));

        return $label !== '' ? ucwords($label) : 'FermatMind page';
    }

    /**
     * @param  array<string, mixed>  $proposalPayload
     */
    private function proposedSeoTitle(string $label, array $proposalPayload = []): string
    {
        $runtimeTitle = trim((string) data_get($proposalPayload, 'runtime.title', ''));
        if ($runtimeTitle !== '') {
            return mb_substr($this->normalizeFermatMindTitle($runtimeTitle), 0, 70);
        }

        return mb_substr($label.' | FermatMind', 0, 70);
    }

    /**
     * @param  array<string, mixed>  $proposalPayload
     */
    private function proposedSeoDescription(string $label, array $proposalPayload = []): string
    {
        $runtimeDescription = trim((string) data_get($proposalPayload, 'runtime.meta_description', ''));
        if ($runtimeDescription !== '') {
            return mb_substr($runtimeDescription, 0, 155);
        }

        return mb_substr('Review '.$label.' with FermatMind guidance, evidence, and next steps after claim-gate approval.', 0, 155);
    }

    /**
     * @param  array<string, mixed>  $proposalPayload
     * @return list<array<string, string>>
     */
    private function proposedFaqItems(string $label, array $proposalPayload = []): array
    {
        if ($this->isChineseLocale((string) ($proposalPayload['locale'] ?? ''))) {
            return [
                [
                    'question' => '这篇文章需要补充哪些常见问题？',
                    'answer' => '仅作为字段级草稿建议；答案需基于现有正文、claim gate 和人工审核后再写入。',
                ],
                [
                    'question' => '读者下一步应该如何使用这篇文章？',
                    'answer' => '仅作为字段级草稿建议；需人工确认内部链接和行动路径后再写入。',
                ],
            ];
        }

        return [
            [
                'question' => 'What should readers know about '.$label.'?',
                'answer' => 'Draft answer pending claim gate and human approval.',
            ],
            [
                'question' => 'What is the next step for '.$label.'?',
                'answer' => 'Draft answer pending claim gate and human approval.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function proposalPayload(array $candidate): array
    {
        $payload = (array) ($candidate['proposal_payload'] ?? []);
        if ($payload === [] || ($payload['source'] ?? '') !== 'gsc_cohort_artifact') {
            return [];
        }

        $runtime = (array) ($payload['runtime'] ?? []);
        $metrics = (array) ($payload['metrics'] ?? []);

        return [
            'source' => 'gsc_cohort_artifact',
            'locale' => $this->cleanText((string) ($payload['locale'] ?? '')),
            'safe_path' => (string) ($payload['safe_path'] ?? ''),
            'draft_angle' => $this->cleanText((string) ($payload['draft_angle'] ?? '')),
            'proposed_actions' => $this->cleanTextList((array) ($payload['proposed_actions'] ?? [])),
            'runtime' => [
                'title' => $this->cleanText((string) ($runtime['title'] ?? '')),
                'meta_description' => $this->cleanText((string) ($runtime['meta_description'] ?? '')),
                'title_length' => (int) ($runtime['title_length'] ?? 0),
                'meta_description_length' => (int) ($runtime['meta_description_length'] ?? 0),
                'jsonld_total' => (int) ($runtime['jsonld_total'] ?? 0),
                'internal_link_count' => (int) ($runtime['internal_link_count'] ?? 0),
                'sample_internal_paths' => array_values(array_filter(
                    $this->strings((array) ($runtime['sample_internal_paths'] ?? [])),
                    static fn (string $path): bool => str_starts_with($path, '/')
                )),
            ],
            'metrics' => [
                'clicks' => (int) ($metrics['clicks'] ?? 0),
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'ctr_ppm' => (int) ($metrics['ctr_ppm'] ?? 0),
                'average_position_milli' => is_numeric($metrics['average_position_milli'] ?? null)
                    ? (int) $metrics['average_position_milli']
                    : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $proposalPayload
     * @return list<string>
     */
    private function proposedInternalLinkActions(array $proposalPayload): array
    {
        if ($proposalPayload === []) {
            return [];
        }

        return array_values(array_filter(
            (array) ($proposalPayload['proposed_actions'] ?? []),
            static fn (mixed $action): bool => is_string($action) && str_contains(strtolower($action), 'internal link')
        ));
    }

    /**
     * @param  array<string, mixed>  $proposalPayload
     * @return array<string, mixed>
     */
    private function proposalQuality(array $proposalPayload): array
    {
        if ($proposalPayload === []) {
            return [
                'source' => 'fallback_slug_label',
                'locale_preserved' => false,
                'slug_generated_copy' => true,
                'needs_human_approval' => true,
            ];
        }

        return [
            'source' => 'gsc_cohort_artifact',
            'locale_preserved' => $this->isChineseLocale((string) ($proposalPayload['locale'] ?? ''))
                ? $this->containsCjk((string) data_get($proposalPayload, 'runtime.title', '').' '.(string) data_get($proposalPayload, 'runtime.meta_description', ''))
                : true,
            'slug_generated_copy' => false,
            'uses_runtime_title' => (string) data_get($proposalPayload, 'runtime.title', '') !== '',
            'uses_runtime_meta_description' => (string) data_get($proposalPayload, 'runtime.meta_description', '') !== '',
            'proposed_actions_preserved' => count((array) ($proposalPayload['proposed_actions'] ?? [])),
            'sample_internal_path_count' => count((array) data_get($proposalPayload, 'runtime.sample_internal_paths', [])),
            'needs_human_approval' => true,
        ];
    }

    private function normalizeFermatMindTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
        $title = preg_replace('/(?:\s*\|\s*FermatMind)+$/u', '', $title) ?: $title;

        return trim($title).' | FermatMind';
    }

    private function isChineseLocale(string $locale): bool
    {
        return str_starts_with($locale, 'zh');
    }

    private function containsCjk(string $value): bool
    {
        return preg_match('/\p{Han}/u', $value) === 1;
    }

    private function cleanText(string $value): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('~https?://[^\s<>"\']+~iu', '', $value) ?: '';

        return preg_replace('/\s+/u', ' ', $value) ?: '';
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function cleanTextList(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (mixed $value): string => is_scalar($value) ? $this->cleanText((string) $value) : '', $values),
            static fn (string $value): bool => $value !== ''
        )));
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
                'draft_brief_count' => (int) ($payload['draft_brief_count'] ?? 0),
                'proposal_count' => (int) ($payload['proposal_count'] ?? 0),
                'cms_write_allowed' => false,
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
        ];
    }
}
