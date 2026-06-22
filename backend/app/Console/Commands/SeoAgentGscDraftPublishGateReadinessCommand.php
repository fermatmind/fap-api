<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentGscDraftPublishGateReadinessCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-gsc-draft-publish-gate-readiness.v1';

    private const WRITE_SCHEMA_VERSION = 'seo-agent-controlled-cms-draft-write.v1';

    private const READBACK_SCHEMA_VERSION = 'seo-agent-cms-draft-readback-qa.v1';

    private const CLAIM_RISK_SCHEMA_VERSION = 'seo-agent-article-draft-claim-risk-qa.v1';

    private const PREVIEW_RUNTIME_SCHEMA_VERSION = 'seo-agent-article-draft-preview-runtime-qa.v1';

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

    protected $signature = 'seo-agent:gsc-draft-publish-gate-readiness
        {--write-evidence= : Path to seo-agent-controlled-cms-draft-write.v1 JSON artifact}
        {--readback-qa=* : Path to one or more seo-agent-cms-draft-readback-qa.v1 artifacts}
        {--claim-risk-qa=* : Path to one or more seo-agent-article-draft-claim-risk-qa.v1 artifacts}
        {--preview-runtime-qa=* : Path to one or more seo-agent-article-draft-preview-runtime-qa.v1 artifacts}
        {--artifact-dir= : Directory for sanitized publish gate readiness evidence}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only publish gate readiness evidence for SEO Agent GSC CMS drafts; does not publish.';

    public function handle(): int
    {
        $writePath = $this->readablePath((string) $this->option('write-evidence'));
        if ($writePath === null) {
            return $this->finish($this->failureSummary('write_evidence_unreadable'));
        }
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $loaded = $this->loadArtifacts([
            'write_evidence' => [$writePath],
            'readback_qa' => (array) $this->option('readback-qa'),
            'claim_risk_qa' => (array) $this->option('claim-risk-qa'),
            'preview_runtime_qa' => (array) $this->option('preview-runtime-qa'),
        ]);
        if (($loaded['issue'] ?? null) !== null) {
            return $this->finish($this->failureSummary((string) $loaded['issue'], (array) ($loaded['extra'] ?? [])));
        }

        $writeEvidence = $loaded['artifacts']['write_evidence'][0]['payload'] ?? null;
        if (! is_array($writeEvidence) || ($writeEvidence['schema_version'] ?? null) !== self::WRITE_SCHEMA_VERSION) {
            return $this->finish($this->failureSummary('write_evidence_schema_invalid'));
        }
        if (($writeEvidence['status'] ?? null) !== 'success' || (bool) ($writeEvidence['execute'] ?? false) !== true) {
            return $this->finish($this->failureSummary('write_evidence_not_success_execute'));
        }

        $evidence = $this->evidence($loaded['artifacts'], $writeEvidence, hash_file('sha256', $writePath) ?: '');
        $artifactRef = $this->writeArtifact(
            $artifactDir,
            'seo-agent-gsc-draft-publish-gate-readiness-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json',
            $evidence
        );

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => (string) ($evidence['status'] ?? 'unknown'),
            'draft_count' => (int) ($evidence['draft_count'] ?? 0),
            'publish_ready_count' => (int) ($evidence['publish_ready_count'] ?? 0),
            'artifact' => $artifactRef,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $artifacts
     * @param  array<string, mixed>  $writeEvidence
     * @return array<string, mixed>
     */
    private function evidence(array $artifacts, array $writeEvidence, string $writeEvidenceSha): array
    {
        $readback = $this->indexByTarget($artifacts['readback_qa'] ?? []);
        $claimRisk = $this->indexByTarget($artifacts['claim_risk_qa'] ?? []);
        $preview = $this->indexByTarget($artifacts['preview_runtime_qa'] ?? []);
        $drafts = [];
        foreach ((array) ($writeEvidence['affected_refs'] ?? []) as $ref) {
            if (! is_array($ref) || (string) ($ref['target_model'] ?? '') !== 'article') {
                continue;
            }
            $drafts[] = $this->draftVerdict($ref, $readback, $claimRisk, $preview, $writeEvidenceSha);
        }
        $readyCount = count(array_filter($drafts, static fn (array $draft): bool => ($draft['gate_status'] ?? '') === 'publish_ready'));
        $blockedCount = count(array_filter($drafts, static fn (array $draft): bool => ($draft['gate_status'] ?? '') === 'blocked'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => $blockedCount > 0 ? 'blocked' : ($readyCount === count($drafts) && $drafts !== [] ? 'publish_ready' : 'review_required'),
            'draft_count' => count($drafts),
            'publish_ready_count' => $readyCount,
            'draft_verdicts' => $drafts,
            'approval_boundary' => [
                'draft_write_is_separate_from_publish' => true,
                'publish_is_separate_from_url_truth_sitemap_indexnow_search' => true,
                'no_publish_phrase_emitted_when_qa_missing_or_failing' => true,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $ref
     * @param  array<string, array<string, mixed>>  $readback
     * @param  array<string, array<string, mixed>>  $claimRisk
     * @param  array<string, array<string, mixed>>  $preview
     * @return array<string, mixed>
     */
    private function draftVerdict(array $ref, array $readback, array $claimRisk, array $preview, string $writeEvidenceSha): array
    {
        $target = (string) ($ref['subject_ref'] ?? '');
        $revisionId = (int) ($ref['revision_id'] ?? 0);
        $issues = [];
        foreach (['readback_qa' => $readback, 'claim_risk_qa' => $claimRisk, 'preview_runtime_qa' => $preview] as $label => $index) {
            if (! isset($index[$target])) {
                $issues[] = $label.'_missing';
            }
        }
        if (isset($readback[$target]) && (($readback[$target]['status'] ?? null) !== 'success' || (int) ($readback[$target]['mismatch_count'] ?? count((array) ($readback[$target]['mismatches'] ?? []))) !== 0)) {
            $issues[] = 'readback_qa_not_passing';
        }
        if (isset($claimRisk[$target]) && (($claimRisk[$target]['status'] ?? null) !== 'success' || (int) ($claimRisk[$target]['critical_finding_count'] ?? 0) !== 0)) {
            $issues[] = 'claim_risk_qa_not_passing';
        }
        if (isset($preview[$target]) && (($preview[$target]['status'] ?? null) !== 'success' || (bool) ($preview[$target]['ok'] ?? false) !== true)) {
            $issues[] = 'preview_runtime_qa_not_passing';
        }

        $status = $issues === [] ? 'publish_ready' : 'review_required';

        return [
            'subject_ref' => $target,
            'revision_id' => $revisionId,
            'gate_status' => $status,
            'issues' => $issues,
            'readback_qa_status' => (string) ($readback[$target]['status'] ?? 'missing'),
            'claim_risk_qa_status' => (string) ($claimRisk[$target]['status'] ?? 'missing'),
            'preview_runtime_qa_status' => (string) ($preview[$target]['status'] ?? 'missing'),
            'publish_approval_phrase' => $status === 'publish_ready'
                ? 'I explicitly approve production CMS publish canary for '.$target.' revision '.$revisionId.' using write evidence sha256 '.$writeEvidenceSha.'; no URL Truth, no sitemap, no IndexNow, no search, no indexing, no scheduler.'
                : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @return array<string, array<string, mixed>>
     */
    private function indexByTarget(array $artifacts): array
    {
        $out = [];
        foreach ($artifacts as $artifact) {
            $payload = $artifact['payload'] ?? [];
            if (is_array($payload) && (string) ($payload['target'] ?? '') !== '') {
                $out[(string) $payload['target']] = $payload;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $pathsByKind
     * @return array<string, mixed>
     */
    private function loadArtifacts(array $pathsByKind): array
    {
        $expectedSchemas = [
            'write_evidence' => self::WRITE_SCHEMA_VERSION,
            'readback_qa' => self::READBACK_SCHEMA_VERSION,
            'claim_risk_qa' => self::CLAIM_RISK_SCHEMA_VERSION,
            'preview_runtime_qa' => self::PREVIEW_RUNTIME_SCHEMA_VERSION,
        ];
        $artifacts = [];
        foreach ($pathsByKind as $kind => $paths) {
            $artifacts[$kind] = [];
            foreach ($paths as $rawPath) {
                $path = $this->readablePath((string) $rawPath);
                if ($path === null) {
                    return ['issue' => $kind.'_artifact_unreadable'];
                }
                $raw = (string) file_get_contents($path);
                $forbidden = $this->forbiddenStringsPresent($raw);
                if ($forbidden !== []) {
                    return ['issue' => 'forbidden_input_field_present', 'extra' => ['forbidden_matches' => $forbidden]];
                }
                $payload = json_decode($raw, true);
                if (! is_array($payload)) {
                    return ['issue' => $kind.'_json_invalid'];
                }
                if (($payload['schema_version'] ?? null) !== $expectedSchemas[$kind]) {
                    return ['issue' => $kind.'_schema_invalid'];
                }
                $artifacts[$kind][] = [
                    'path' => $path,
                    'payload' => $payload,
                    'sha256' => hash_file('sha256', $path) ?: '',
                ];
            }
        }

        return ['artifacts' => $artifacts];
    }

    private function readablePath(string $rawPath): ?string
    {
        $path = trim($rawPath);
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
            $dir = storage_path('app/seo-agent/gsc-draft-publish-gate-readiness');
        }
        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $payload): array
    {
        $matches = [];
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            if (str_contains($payload, $needle)) {
                $matches[] = $needle;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function writeArtifact(string $dir, string $filename, array $payload): array
    {
        $path = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Failed to encode publish gate readiness artifact.');
        }
        file_put_contents($path, $encoded."\n");

        return ['path' => $path, 'size_bytes' => filesize($path) ?: 0, 'sha256' => hash_file('sha256', $path) ?: ''];
    }

    /**
     * @return array<string, false>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'cms_publish' => false,
            'url_truth_write' => false,
            'sitemap_submission' => false,
            'indexnow_submit' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'scheduler_activation' => false,
            'queue_worker_start' => false,
            'external_model_api_call' => false,
            'live_gsc_api_call' => false,
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
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line(sprintf('%s %s', $summary['schema_version'] ?? self::SCHEMA_VERSION, $summary['status'] ?? 'unknown'));
        }

        return (bool) ($summary['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
