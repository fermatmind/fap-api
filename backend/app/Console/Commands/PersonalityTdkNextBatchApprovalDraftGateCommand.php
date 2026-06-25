<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class PersonalityTdkNextBatchApprovalDraftGateCommand extends Command
{
    private const SCHEMA_VERSION = 'personality-tdk-next-batch-approval-draft-gate.v1';

    private const EXPECTED_TARGETS = [
        '/zh/personality/intp-a',
        '/zh/personality/esfp-a',
        '/en/personality/enfj-a',
    ];

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

    protected $signature = 'personality:tdk-next-batch-approval-draft-gate
        {--recommendations= : Path to personality next-batch recommendations JSON artifact}
        {--qa= : Path to personality next-batch QA JSON artifact}
        {--artifact-dir= : Directory for sanitized gate evidence}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only gate evidence for the 3 personality TDK next-batch approval queue and CMS draft dry-run sequence.';

    public function handle(): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $loaded = $this->loadInputs();
        if (($loaded['issue'] ?? null) !== null) {
            $summary = $this->failureSummary((string) $loaded['issue'], (array) ($loaded['extra'] ?? []));
            $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

            return $this->finish($summary);
        }

        $summary = $this->evidence(
            (array) $loaded['recommendations'],
            (array) $loaded['qa'],
            (string) $loaded['recommendations_path'],
            (string) $loaded['qa_path'],
            (string) $loaded['recommendations_sha256'],
            (string) $loaded['qa_sha256']
        );
        $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => (bool) $summary['ok'],
            'status' => (string) $summary['status'],
            'candidate_count' => (int) data_get($summary, 'counts.candidate_count', 0),
            'approval_queue_dry_run_ready' => (bool) data_get($summary, 'gate_statuses.approval_queue_dry_run_ready', false),
            'cms_projection_draft_dry_run_ready' => (bool) data_get($summary, 'gate_statuses.cms_projection_draft_dry_run_ready', false),
            'artifact' => $summary['artifact'],
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $recommendations
     * @param  array<string, mixed>  $qa
     * @return array<string, mixed>
     */
    private function evidence(array $recommendations, array $qa, string $recommendationsPath, string $qaPath, string $recommendationsSha, string $qaSha): array
    {
        $recommendationRows = array_values(array_filter((array) ($recommendations['recommendations'] ?? []), 'is_array'));
        $qaRows = array_values(array_filter((array) ($qa['page_results'] ?? []), 'is_array'));
        $qaByPath = [];
        foreach ($qaRows as $row) {
            $qaByPath[(string) ($row['path'] ?? '')] = $row;
        }

        $issues = [];
        if (($recommendations['artifact'] ?? null) !== 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-RECOMMENDATIONS-01') {
            $issues[] = 'recommendations_artifact_invalid';
        }
        if (($qa['artifact'] ?? null) !== 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-QA-01') {
            $issues[] = 'qa_artifact_invalid';
        }
        if (($qa['final_decision'] ?? null) !== 'PASS_READY_FOR_APPROVAL_REVIEW') {
            $issues[] = 'qa_final_decision_not_ready_for_approval_review';
        }

        $candidates = [];
        foreach ($recommendationRows as $row) {
            $path = (string) ($row['path'] ?? '');
            if (! in_array($path, self::EXPECTED_TARGETS, true)) {
                continue;
            }
            $qaRow = $qaByPath[$path] ?? [];
            $candidateIssues = [];
            if (($row['framework'] ?? null) !== 'mbti64') {
                $candidateIssues[] = 'framework_not_mbti64';
            }
            if (($row['page_type'] ?? null) !== 'variant') {
                $candidateIssues[] = 'page_type_not_variant';
            }
            if (($qaRow['decision'] ?? null) !== 'PASS_READY_FOR_APPROVAL_REVIEW') {
                $candidateIssues[] = 'qa_decision_not_ready';
            }
            foreach ((array) ($qaRow['gates'] ?? []) as $gate => $status) {
                if ($status !== 'pass') {
                    $candidateIssues[] = 'qa_gate_not_passing:'.$gate;
                }
            }
            $candidates[] = [
                'path' => $path,
                'target_url_hash' => hash('sha256', (string) ($row['target_url'] ?? '')),
                'locale' => (string) ($row['locale'] ?? ''),
                'framework' => (string) ($row['framework'] ?? ''),
                'page_type' => (string) ($row['page_type'] ?? ''),
                'mbti_type' => (string) ($row['mbti_type'] ?? ''),
                'recommendation_id' => (string) ($row['recommendation_id'] ?? ''),
                'qa_decision' => (string) ($qaRow['decision'] ?? 'missing'),
                'recommended_title_length' => mb_strlen((string) data_get($row, 'recommendations.title.recommended')),
                'recommended_description_length' => mb_strlen((string) data_get($row, 'recommendations.description.recommended')),
                'candidate_issues' => $candidateIssues,
            ];
        }

        $actualTargets = array_map(static fn (array $candidate): string => (string) $candidate['path'], $candidates);
        sort($actualTargets);
        $expectedTargets = self::EXPECTED_TARGETS;
        sort($expectedTargets);
        if ($actualTargets !== $expectedTargets) {
            $issues[] = 'expected_target_set_mismatch';
        }
        foreach ($candidates as $candidate) {
            foreach ((array) ($candidate['candidate_issues'] ?? []) as $issue) {
                $issues[] = (string) $issue;
            }
        }

        $ok = $issues === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $ok ? 'planned' : 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => Carbon::now('UTC')->toIso8601String(),
            'source_artifacts' => [
                'recommendations' => ['path' => $recommendationsPath, 'sha256' => $recommendationsSha],
                'qa' => ['path' => $qaPath, 'sha256' => $qaSha],
            ],
            'counts' => [
                'candidate_count' => count($candidates),
                'expected_candidate_count' => 3,
                'blocked_candidate_count' => count(array_filter($candidates, static fn (array $candidate): bool => ($candidate['candidate_issues'] ?? []) !== [])),
            ],
            'targets' => $candidates,
            'gate_statuses' => [
                'approval_queue_dry_run_ready' => $ok,
                'cms_projection_draft_dry_run_ready' => $ok,
                'production_approval_queue_write_ready' => false,
                'production_cms_draft_write_ready' => false,
            ],
            'future_command_templates' => [
                'approval_queue_dry_run' => [
                    'php artisan personality:agent-approval-queue',
                    '--package='.escapeshellarg($recommendationsPath),
                    '--qa='.escapeshellarg($qaPath),
                    '--dry-run',
                    '--json',
                ],
                'cms_projection_draft_dry_run' => [
                    'php artisan personality:mbti64-cms-projection-draft',
                    '--package='.escapeshellarg($recommendationsPath),
                    '--qa='.escapeshellarg($qaPath),
                    '--dry-run',
                    '--agent-batch-size=5',
                    '--agent-batch-offset=0',
                    '--json',
                ],
            ],
            'separate_approval_templates' => [
                'approval_queue_write' => 'I explicitly approve PERSONALITY-AGENT-HUMAN-APPROVAL-QUEUE-01 for the 3 personality TDK next-batch targets using recommendations sha256 '.$recommendationsSha.' and QA sha256 '.$qaSha.'; no CMS draft write, no promotion, no publish, no index/search, no sitemap/llms.',
                'cms_projection_draft_write' => 'I explicitly approve MBTI64-AGENT-CMS-DRAFT-BATCH-SAFE-WRITER-01 for the 3 personality TDK next-batch targets after approval queue evidence; draft-only, no publish, no index, no sitemap, no llms, no search release.',
            ],
            'issues' => array_values(array_unique($issues)),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadInputs(): array
    {
        $recommendations = $this->loadJsonInput((string) $this->option('recommendations'), 'recommendations');
        if (($recommendations['issue'] ?? null) !== null) {
            return $recommendations;
        }
        $qa = $this->loadJsonInput((string) $this->option('qa'), 'qa');
        if (($qa['issue'] ?? null) !== null) {
            return $qa;
        }

        return [
            'recommendations_path' => $recommendations['path'],
            'recommendations_sha256' => $recommendations['sha256'],
            'recommendations' => $recommendations['payload'],
            'qa_path' => $qa['path'],
            'qa_sha256' => $qa['sha256'],
            'qa' => $qa['payload'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJsonInput(string $rawPath, string $kind): array
    {
        $path = $this->readablePath($rawPath);
        if ($path === null) {
            return ['issue' => $kind.'_unreadable'];
        }
        $raw = (string) File::get($path);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return ['issue' => 'forbidden_input_field_present', 'extra' => ['kind' => $kind, 'forbidden_matches' => $forbidden]];
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return ['issue' => $kind.'_json_invalid'];
        }

        return [
            'path' => $path,
            'sha256' => hash('sha256', $raw),
            'payload' => $payload,
        ];
    }

    private function readablePath(string $rawPath): ?string
    {
        $path = trim($rawPath);
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return File::isFile($path) && is_readable($path) ? $path : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/personality/tdk-next-batch-approval-draft-gate');
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
        $path = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'personality-tdk-next-batch-approval-draft-gate-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Failed to encode personality TDK next-batch gate artifact.');
        }
        File::put($path, $encoded.PHP_EOL);

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
            'approval_queue_write' => false,
            'cms_draft_write' => false,
            'cms_promotion' => false,
            'cms_publish' => false,
            'indexability_change' => false,
            'sitemap_llms_mutation' => false,
            'search_channel_enqueue' => false,
            'live_submit' => false,
            'frontend_metadata_edit' => false,
            'deploy' => false,
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
