<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class SeoOpsZhArticleQualityReadbackCommand extends Command
{
    private const INPUT_SCHEMA = 'seo-ops-zh-article-quality-controlled-writer.v1';

    private const OUTPUT_SCHEMA = 'seo-ops-zh-article-quality-readback.v1';

    protected $signature = 'seo-ops:zh-article-quality-readback
        {--writer-evidence= : Path to seo-ops-zh-article-quality-controlled-writer.v1 evidence}
        {--confirm-writer-evidence-sha256= : Expected SHA-256 of writer evidence}
        {--artifact-dir= : Directory for readback evidence output}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only readback QA for zh-CN article quality deterministic heading/link repairs.';

    public function handle(): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary(['artifact_dir_unwritable']));
        }

        $loaded = $this->loadEvidence();
        if (($loaded['ok'] ?? false) !== true) {
            $summary = $this->failureSummary((array) ($loaded['issues'] ?? ['writer_evidence_unreadable']), [
                'writer_evidence' => $loaded['writer_evidence'] ?? null,
            ]);
            $summary['evidence'] = $this->writeEvidence($artifactDir, $summary);

            return $this->finish($summary);
        }

        $payload = (array) $loaded['payload'];
        $plans = array_values((array) ($payload['article_repair_plans'] ?? []));
        $issues = $this->validateWriterEvidence($payload, $plans);
        $readbacks = array_map(fn (array $plan): array => $this->readbackPlan($plan), $plans);
        foreach ($readbacks as $readback) {
            foreach ((array) ($readback['issues'] ?? []) as $issue) {
                $issues[] = (string) $issue;
            }
        }
        $issues = array_values(array_unique($issues));

        $summary = [
            'schema_version' => self::OUTPUT_SCHEMA,
            'command' => 'php artisan seo-ops:zh-article-quality-readback',
            'ok' => $issues === [],
            'status' => $issues === [] ? 'success' : 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->utc()->toIso8601String(),
            'writer_evidence' => [
                'path' => (string) $loaded['path'],
                'sha256' => (string) $loaded['sha256'],
                'schema_version' => (string) ($payload['schema_version'] ?? ''),
            ],
            'readback_count' => [
                'targets' => count($readbacks),
                'passed' => count(array_filter($readbacks, static fn (array $readback): bool => ($readback['status'] ?? null) === 'success')),
                'blocked' => count(array_filter($readbacks, static fn (array $readback): bool => ($readback['status'] ?? null) !== 'success')),
            ],
            'article_readbacks' => $readbacks,
            'protected_diff_summary' => [
                'slug' => 'no_change',
                'canonical' => 'no_change',
                'locale' => 'no_change',
                'publication' => 'no_change',
                'schema' => 'hold_no_change',
                'hreflang' => 'hold_no_change',
                'sitemap' => 'no_change',
                'llms' => 'no_change',
                'search_submission' => 'hold_no_change',
            ],
            'side_effects' => [
                'database_write' => false,
                'cms_article_update' => false,
                'cms_publish' => false,
                'url_truth_write' => false,
                'schema_enable' => false,
                'hreflang_enable' => false,
                'sitemap_write' => false,
                'llms_write' => false,
                'search_channel_enqueue' => false,
                'indexnow_submit' => false,
                'baidu_submit' => false,
                'gsc_request_indexing' => false,
                'scheduler_start' => false,
                'queue_worker_start' => false,
                'external_api_call' => false,
                'revalidation' => false,
                'deploy' => false,
            ],
            'issues' => $issues,
        ];
        $summary['evidence'] = $this->writeEvidence($artifactDir, $summary);

        return $this->finish($summary);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadEvidence(): array
    {
        $path = $this->readablePath((string) $this->option('writer-evidence'));
        if ($path === null) {
            return ['ok' => false, 'issues' => ['writer_evidence_unreadable']];
        }
        $raw = (string) file_get_contents($path);
        $actualSha = hash('sha256', $raw);
        $expectedSha = trim((string) $this->option('confirm-writer-evidence-sha256'));
        if ($expectedSha === '' || ! hash_equals($expectedSha, $actualSha)) {
            return ['ok' => false, 'writer_evidence' => ['path' => $path, 'sha256' => $actualSha, 'expected_sha256' => $expectedSha], 'issues' => ['writer_evidence_sha_mismatch']];
        }
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'writer_evidence' => ['path' => $path, 'sha256' => $actualSha], 'issues' => ['writer_evidence_json_invalid']];
        }
        if (! is_array($payload)) {
            return ['ok' => false, 'writer_evidence' => ['path' => $path, 'sha256' => $actualSha], 'issues' => ['writer_evidence_json_not_object']];
        }

        return ['ok' => true, 'payload' => $payload, 'path' => $path, 'sha256' => $actualSha];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>  $plans
     * @return array<int, string>
     */
    private function validateWriterEvidence(array $payload, array $plans): array
    {
        $issues = [];
        if (($payload['schema_version'] ?? null) !== self::INPUT_SCHEMA) {
            $issues[] = 'writer_evidence_schema_invalid';
        }
        if (($payload['ok'] ?? null) !== true || ($payload['status'] ?? null) !== 'success' || (bool) ($payload['execute'] ?? false) !== true) {
            $issues[] = 'writer_evidence_not_success_execute';
        }
        if (count($plans) !== 9) {
            $issues[] = 'writer_plan_count_unexpected';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function readbackPlan(array $plan): array
    {
        $article = Article::query()->withoutGlobalScopes()->with(['publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(), 'seoMeta' => static fn ($query) => $query->withoutGlobalScopes()])->find((int) ($plan['article_id'] ?? 0));
        $issues = [];
        if (! $article instanceof Article) {
            return [
                'target' => (string) ($plan['target'] ?? ''),
                'status' => 'blocked',
                'issues' => ['article_not_found'],
            ];
        }

        $texts = [
            'article_content_md' => (string) $article->content_md,
            'article_content_html' => (string) $article->content_html,
            'published_revision_content_md' => (string) $article->publishedRevision?->content_md,
        ];
        $replacementReadback = [];
        foreach ((array) ($plan['replacements'] ?? []) as $replacement) {
            $find = (string) ($replacement['find'] ?? '');
            $replace = (string) ($replacement['replace_with'] ?? '');
            $fieldReadback = [];
            foreach ($texts as $field => $text) {
                $present = substr_count($text, $replace);
                $findCount = substr_count($text, $find);
                $remaining = str_contains($replace, $find)
                    ? max(0, $findCount - $present)
                    : $findCount;
                $fieldReadback[$field] = [
                    'find_remaining_count' => $remaining,
                    'replacement_present_count' => $present,
                ];
                if ($remaining > 0) {
                    $issues[] = 'replacement_find_still_present:'.$field.':'.$find;
                }
            }
            $replacementReadback[] = [
                'find' => $find,
                'replace_with' => $replace,
                'fields' => $fieldReadback,
            ];
        }

        $protectedBefore = (array) ($plan['protected_snapshot'] ?? []);
        $protectedAfter = $this->protectedSnapshot($article);
        if ($protectedBefore !== [] && $protectedBefore !== $protectedAfter) {
            $issues[] = 'protected_snapshot_changed';
        }

        return [
            'target' => (string) ($plan['target'] ?? ''),
            'article_id' => (int) $article->id,
            'status' => $issues === [] ? 'success' : 'blocked',
            'replacement_readback' => $replacementReadback,
            'protected_before' => $protectedBefore,
            'protected_after' => $protectedAfter,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function protectedSnapshot(Article $article): array
    {
        $article->loadMissing(['seoMeta' => static fn ($query) => $query->withoutGlobalScopes()]);

        return [
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'status' => (string) $article->status,
            'published_revision_id' => $article->published_revision_id,
            'working_revision_id' => $article->working_revision_id,
            'canonical_url' => (string) $article->seoMeta?->canonical_url,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
        ];
    }

    /**
     * @param  array<int, string>  $issues
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureSummary(array $issues, array $extra = []): array
    {
        return array_replace([
            'schema_version' => self::OUTPUT_SCHEMA,
            'command' => 'php artisan seo-ops:zh-article-quality-readback',
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->utc()->toIso8601String(),
            'issues' => array_values(array_unique($issues)),
        ], $extra);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function writeEvidence(string $artifactDir, array $summary): ?array
    {
        File::ensureDirectoryExists($artifactDir);
        $path = rtrim($artifactDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            .'seo-ops-zh-article-quality-readback-'.now()->utc()->format('Ymd\THis\Z').'.json';
        $artifact = $summary;
        unset($artifact['evidence']);
        File::put($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return ['path' => $path, 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path) ?: 0];
    }

    private function finish(array $summary): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif (($summary['ok'] ?? false) === true) {
            $this->info('ZH article quality readback success');
        } else {
            $this->error('ZH article quality readback blocked: '.implode(', ', (array) ($summary['issues'] ?? [])));
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function readablePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return $path;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            return null;
        }
        File::ensureDirectoryExists($dir);

        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }
}
