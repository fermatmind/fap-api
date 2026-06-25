<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class SeoOpsGaokaoV5PropagationGateReadinessCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-ops-gaokao-v5-propagation-gate-readiness.v1';

    private const TARGET_LOCALE = 'zh-CN';

    private const TARGET_SLUG = 'gaokao-major-choice-parent-conflict-riasec-course-checklist';

    private const TARGET_PATH = '/zh/articles/'.self::TARGET_SLUG;

    private const FORBIDDEN_STRINGS = [
        'raw_url',
        'raw_query',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'Bearer ',
        'Cookie:',
        'Set-Cookie:',
        'content_md',
        'content_html',
        'cms_draft_body',
    ];

    protected $signature = 'seo-ops:gaokao-v5-propagation-gate-readiness
        {--draft-gate-evidence= : Optional seo-ops-gaokao-v5-cms-draft-gate.v1 dry-run artifact}
        {--draft-write-evidence= : Optional future draft write evidence artifact}
        {--readback-qa= : Optional future draft readback QA artifact}
        {--preview-qa= : Optional future preview QA artifact}
        {--publish-evidence= : Optional future publish canary evidence artifact}
        {--artifact-dir= : Directory for sanitized gate readiness evidence}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only Gaokao v5 preview, publish, URL Truth, and search propagation gate readiness evidence.';

    public function handle(): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $loaded = $this->loadOptionalArtifacts([
            'draft_gate_evidence' => (string) $this->option('draft-gate-evidence'),
            'draft_write_evidence' => (string) $this->option('draft-write-evidence'),
            'readback_qa' => (string) $this->option('readback-qa'),
            'preview_qa' => (string) $this->option('preview-qa'),
            'publish_evidence' => (string) $this->option('publish-evidence'),
        ]);
        if (($loaded['issue'] ?? null) !== null) {
            $summary = $this->failureSummary((string) $loaded['issue'], (array) ($loaded['extra'] ?? []));
            $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

            return $this->finish($summary);
        }

        $evidence = $this->evidence((array) ($loaded['artifacts'] ?? []));
        $evidence['artifact'] = $this->writeArtifact($artifactDir, $evidence);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => (string) ($evidence['status'] ?? 'review_required'),
            'target' => $evidence['target'],
            'gate_statuses' => $evidence['gate_statuses'],
            'artifact' => $evidence['artifact'],
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $artifacts
     * @return array<string, mixed>
     */
    private function evidence(array $artifacts): array
    {
        $draftGate = $artifacts['draft_gate_evidence']['payload'] ?? null;
        $draftGatePlanned = is_array($draftGate)
            && ($draftGate['schema_version'] ?? null) === 'seo-ops-gaokao-v5-cms-draft-gate.v1'
            && ($draftGate['status'] ?? null) === 'planned';
        $draftWritten = isset($artifacts['draft_write_evidence']);
        $readbackPassing = $this->artifactPassing($artifacts['readback_qa']['payload'] ?? null);
        $previewPassing = $this->artifactPassing($artifacts['preview_qa']['payload'] ?? null);
        $publishCompleted = $this->artifactPassing($artifacts['publish_evidence']['payload'] ?? null);

        $publishDryRunReady = $draftWritten && $readbackPassing && $previewPassing;
        $postPublishReady = $publishCompleted;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => $postPublishReady ? 'post_publish_gate_ready' : ($publishDryRunReady ? 'publish_dry_run_ready' : 'review_required'),
            'dry_run' => true,
            'execute' => false,
            'generated_at' => Carbon::now('UTC')->toIso8601String(),
            'target' => [
                'model' => 'article',
                'locale' => self::TARGET_LOCALE,
                'slug' => self::TARGET_SLUG,
                'canonical_path' => self::TARGET_PATH,
                'canonical_url' => 'https://fermatmind.com'.self::TARGET_PATH,
            ],
            'artifact_inputs' => $this->artifactSummary($artifacts),
            'gate_statuses' => [
                'cms_draft_gate_planned' => $draftGatePlanned,
                'draft_write_evidence_present' => $draftWritten,
                'readback_qa_passing' => $readbackPassing,
                'preview_qa_passing' => $previewPassing,
                'publish_dry_run_ready' => $publishDryRunReady,
                'publish_evidence_present_and_passing' => $publishCompleted,
                'url_truth_ready_after_publish' => $postPublishReady,
                'search_bridge_ready_after_url_truth' => $postPublishReady,
                'indexnow_gate_ready_after_enqueue' => $postPublishReady,
            ],
            'required_evidence_checklist' => [
                'preview_qa' => [
                    'required_before_publish_execute' => true,
                    'must_confirm' => [
                        'draft preview resolves the Gaokao article draft',
                        'public runtime does not expose draft before publish',
                        'canonical remains '.self::TARGET_PATH,
                    ],
                ],
                'publish_dry_run' => [
                    'required_before_publish_execute' => true,
                    'approval_phrase_template' => 'I explicitly approve production CMS publish canary for article:<id>:zh-CN revision <revision_id> using write evidence sha256 <write_evidence_sha256>; no URL Truth, no sitemap, no IndexNow, no search, no indexing, no scheduler.',
                ],
                'post_publish_url_truth' => [
                    'required_after_publish' => true,
                    'approval_phrase_template' => 'I explicitly approve bounded URL Truth handoff import write for article page_entity_type using artifact sha256 <url_truth_artifact_sha256> generated at <url_truth_artifact_path>; no search submission, no CMS content changes, no publish, no schema/hreflang writes.',
                ],
                'search_bridge' => [
                    'required_after_url_truth' => true,
                    'enqueue_approval_phrase_template' => 'I explicitly approve SEO Agent post-publish Search Channel enqueue for Gaokao article publish evidence sha256 <publish_evidence_sha256> channels indexnow limit=1; no live submit, no Google Indexing API, no sitemap submit, no scheduler.',
                    'indexnow_live_submit_approval_template' => 'I explicitly approve SEARCH-CHANNEL-BOUNDED-LIVE-EXECUTOR live submission for queue items <queue_item_id> channels indexnow.',
                ],
            ],
            'future_command_templates' => [
                'preview_qa' => [
                    'php artisan <future-gaokao-preview-qa-command>',
                    '--target=article:<id>:zh-CN',
                    '--revision-id=<draft_revision_id>',
                    '--json',
                ],
                'publish_dry_run' => [
                    'php artisan seo-agent:article-cms-publish-canary',
                    '--target=article:<id>:zh-CN',
                    '--revision-id=<draft_revision_id>',
                    '--json',
                    '# no --execute until exact approval',
                ],
                'post_publish_propagation_dry_run' => [
                    'php artisan seo-agent:article-post-publish-propagation-dry-run',
                    '--publish-evidence=<publish_evidence>',
                    '--target=article:<id>:zh-CN',
                    '--revision-id=<source_revision_id>',
                    '--limit=1',
                    '--json',
                ],
                'url_truth_export_import_dry_run' => [
                    'php artisan seo-intel:url-truth-handoff --export=<artifact> --dry-run --limit=1 --page-type=article --canonical-path='.self::TARGET_PATH.' --json',
                    'php artisan seo-intel:url-truth-handoff --import=<artifact> --dry-run --limit=1 --page-type=article --json',
                ],
                'search_bridge_dry_run' => [
                    'php artisan seo-agent:post-publish-search-submit',
                    '--publish-evidence=<publish_evidence>',
                    '--channels=indexnow',
                    '--limit=1',
                    '--base-url=https://fermatmind.com',
                    '--json',
                    '# no --execute until exact approval',
                ],
            ],
            'approval_boundaries' => [
                'draft_write_is_separate_from_publish' => true,
                'publish_is_separate_from_url_truth' => true,
                'url_truth_is_separate_from_search_enqueue' => true,
                'search_enqueue_is_separate_from_indexnow_live_submit' => true,
                'no_approval_phrase_is_execution' => true,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    private function artifactPassing(mixed $payload): bool
    {
        return is_array($payload)
            && ($payload['ok'] ?? false) === true
            && in_array((string) ($payload['status'] ?? ''), ['success', 'planned', 'publish_ready', 'post_publish_gate_ready'], true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $artifacts
     * @return array<string, array<string, mixed>>
     */
    private function artifactSummary(array $artifacts): array
    {
        $summary = [];
        foreach ($artifacts as $key => $artifact) {
            $payload = $artifact['payload'] ?? [];
            $summary[$key] = [
                'path' => $artifact['path'] ?? null,
                'sha256' => $artifact['sha256'] ?? null,
                'schema_version' => is_array($payload) ? ($payload['schema_version'] ?? ($payload['version'] ?? null)) : null,
                'status' => is_array($payload) ? ($payload['status'] ?? null) : null,
                'ok' => is_array($payload) ? ($payload['ok'] ?? null) : null,
            ];
        }

        return $summary;
    }

    /**
     * @param  array<string, string>  $pathsByKind
     * @return array<string, mixed>
     */
    private function loadOptionalArtifacts(array $pathsByKind): array
    {
        $artifacts = [];
        foreach ($pathsByKind as $kind => $rawPath) {
            $rawPath = trim($rawPath);
            if ($rawPath === '') {
                continue;
            }
            $path = $this->readablePath($rawPath);
            if ($path === null) {
                return ['issue' => $kind.'_unreadable'];
            }
            $raw = (string) file_get_contents($path);
            $forbidden = $this->forbiddenStringsPresent($raw);
            if ($forbidden !== []) {
                return ['issue' => 'forbidden_input_field_present', 'extra' => ['kind' => $kind, 'forbidden_matches' => $forbidden]];
            }
            $payload = json_decode($raw, true);
            if (! is_array($payload)) {
                return ['issue' => $kind.'_json_invalid'];
            }
            $artifacts[$kind] = [
                'path' => $path,
                'sha256' => hash_file('sha256', $path) ?: '',
                'payload' => $payload,
            ];
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
            $dir = storage_path('app/seo-ops/gaokao-v5-propagation-gate-readiness');
        }
        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        File::ensureDirectoryExists($dir);

        return is_dir($dir) && is_writable($dir) ? $dir : null;
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
    private function writeArtifact(string $dir, array $payload): array
    {
        $path = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'seo-ops-gaokao-v5-propagation-gate-readiness-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Failed to encode Gaokao v5 propagation gate readiness artifact.');
        }
        file_put_contents($path, $encoded."\n");

        return [
            'path' => $path,
            'size_bytes' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    /**
     * @return array<string, false>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'cms_import' => false,
            'cms_publish' => false,
            'url_truth_write' => false,
            'schema_hreflang_write' => false,
            'sitemap_llms_mutation' => false,
            'search_channel_enqueue' => false,
            'indexnow_submit' => false,
            'baidu_submit' => false,
            'gsc_request_indexing' => false,
            'scheduler_activation' => false,
            'queue_worker_start' => false,
            'deploy' => false,
            'revalidation' => false,
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
            'dry_run' => true,
            'execute' => false,
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
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        } else {
            $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
            $this->line('status='.(string) ($summary['status'] ?? ''));
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
