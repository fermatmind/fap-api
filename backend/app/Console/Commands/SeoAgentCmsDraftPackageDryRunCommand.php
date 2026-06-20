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

        return [
            'source_id' => (string) ($candidate['source_id'] ?? ''),
            'source_family' => (string) ($candidate['source_family'] ?? ''),
            'subject_type' => (string) ($candidate['subject_type'] ?? ''),
            'subject_ref' => (string) ($candidate['subject_ref'] ?? ''),
            'safe_path' => (string) ($candidate['safe_path'] ?? ''),
            'severity' => (string) ($candidate['severity'] ?? ''),
            'gap_codes' => $gapCodes,
            'target_fields' => $this->targetFields($gapCodes),
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
                'missing_meta_description' => 'seo_description',
                'missing_canonical' => 'canonical_url_or_path',
                'missing_indexability_metadata' => 'is_indexable_or_robots',
                'missing_faq_items', 'missing_visible_faq' => 'faq_items',
                'faq_schema_enabled_without_visible_faq' => 'faq_schema_eligible',
                default => 'manual_review_required',
            };
        }

        return array_values(array_unique($fields));
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
