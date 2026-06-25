<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class SeoOpsZhArticleQualityControlledWriterCommand extends Command
{
    private const INPUT_SCHEMA = 'seo-ops-zh-article-quality-repair-dry-run.v1';

    private const OUTPUT_SCHEMA = 'seo-ops-zh-article-quality-controlled-writer.v1';

    private const EXPECTED_COUNT = 9;

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
        'cms_draft_body',
    ];

    protected $signature = 'seo-ops:zh-article-quality-controlled-writer
        {--dry-run-evidence= : Path to seo-ops-zh-article-quality-repair-dry-run.v1 evidence}
        {--confirm-dry-run-evidence-sha256= : Expected SHA-256 of the dry-run evidence}
        {--artifact-dir= : Directory for writer evidence output}
        {--execute : Apply deterministic heading/link repairs}
        {--confirm-write= : Exact controlled writer approval phrase}
        {--json : Emit JSON summary}';

    protected $description = 'Controlled writer for zh-CN article quality heading/link repairs from verified dry-run evidence; defaults to dry-run.';

    public function handle(): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary(['artifact_dir_unwritable']));
        }

        $loaded = $this->loadEvidence();
        if (($loaded['ok'] ?? false) !== true) {
            $summary = $this->failureSummary((array) ($loaded['issues'] ?? ['dry_run_evidence_unreadable']), [
                'dry_run_evidence' => $loaded['dry_run_evidence'] ?? null,
            ]);
            $summary['evidence'] = $this->writeEvidence($artifactDir, $summary);

            return $this->finish($summary);
        }

        $payload = (array) $loaded['payload'];
        $sha = (string) $loaded['sha256'];
        $plans = array_values((array) ($payload['article_operation_plans'] ?? []));
        $issues = $this->validateEvidence($payload, $plans);
        $writePlans = array_map(fn (array $plan): array => $this->buildWritePlan($plan), $plans);

        foreach ($writePlans as $plan) {
            foreach ((array) ($plan['issues'] ?? []) as $issue) {
                $issues[] = (string) $issue;
            }
        }

        $issues = array_values(array_unique($issues));
        $execute = (bool) $this->option('execute');
        $requiredPhrase = $this->requiredConfirmationPhrase($sha);
        if ($execute && ! hash_equals($requiredPhrase, trim((string) $this->option('confirm-write')))) {
            $issues[] = 'confirm_write_phrase_mismatch';
        }

        $ok = $issues === [];
        $writeResults = [];
        if ($ok && $execute) {
            $writeResults = $this->executePlans($writePlans, $sha);
        }

        $readyCount = count(array_filter($writePlans, static fn (array $plan): bool => ($plan['ready'] ?? false) === true));
        $summary = [
            'schema_version' => self::OUTPUT_SCHEMA,
            'command' => 'php artisan seo-ops:zh-article-quality-controlled-writer',
            'ok' => $ok,
            'status' => $ok ? ($execute ? 'success' : 'planned') : 'blocked',
            'dry_run' => ! $execute,
            'execute' => $execute,
            'generated_at' => now()->utc()->toIso8601String(),
            'dry_run_evidence' => [
                'path' => (string) $loaded['path'],
                'sha256' => $sha,
                'schema_version' => (string) ($payload['schema_version'] ?? ''),
            ],
            'planned_write_count' => [
                'article_quality_repairs' => $readyCount,
                'cms_publish' => 0,
                'search_submission' => 0,
                'total' => $readyCount,
            ],
            'target_lock' => [
                'expected_count' => self::EXPECTED_COUNT,
                'targets' => array_values(array_map(static fn (array $plan): string => (string) ($plan['target'] ?? ''), $writePlans)),
            ],
            'required_confirmation_phrase' => $requiredPhrase,
            'article_repair_plans' => $writePlans,
            'write_results' => $writeResults,
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
                'database_write' => $execute && $ok,
                'cms_article_update' => $execute && $ok,
                'cms_draft_create' => false,
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
        $path = $this->readablePath((string) $this->option('dry-run-evidence'));
        if ($path === null) {
            return ['ok' => false, 'issues' => ['dry_run_evidence_unreadable']];
        }

        $raw = (string) file_get_contents($path);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return [
                'ok' => false,
                'dry_run_evidence' => ['path' => $path],
                'issues' => ['forbidden_input_field_present'],
                'forbidden_matches' => $forbidden,
            ];
        }

        $actualSha = hash('sha256', $raw);
        $expectedSha = trim((string) $this->option('confirm-dry-run-evidence-sha256'));
        if ($expectedSha === '' || ! hash_equals($expectedSha, $actualSha)) {
            return [
                'ok' => false,
                'dry_run_evidence' => [
                    'path' => $path,
                    'sha256' => $actualSha,
                    'expected_sha256' => $expectedSha,
                ],
                'issues' => ['dry_run_evidence_sha_mismatch'],
            ];
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'dry_run_evidence' => ['path' => $path, 'sha256' => $actualSha], 'issues' => ['dry_run_evidence_json_invalid']];
        }

        if (! is_array($payload)) {
            return ['ok' => false, 'dry_run_evidence' => ['path' => $path, 'sha256' => $actualSha], 'issues' => ['dry_run_evidence_json_not_object']];
        }

        return ['ok' => true, 'payload' => $payload, 'path' => $path, 'sha256' => $actualSha];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>  $plans
     * @return array<int, string>
     */
    private function validateEvidence(array $payload, array $plans): array
    {
        $issues = [];
        if (($payload['schema_version'] ?? null) !== self::INPUT_SCHEMA) {
            $issues[] = 'dry_run_evidence_schema_invalid';
        }
        if (($payload['ok'] ?? null) !== true || ($payload['status'] ?? null) !== 'planned') {
            $issues[] = 'dry_run_evidence_not_planned';
        }
        if ((bool) ($payload['dry_run'] ?? false) !== true || (bool) ($payload['execute'] ?? true) !== false) {
            $issues[] = 'dry_run_evidence_mode_invalid';
        }
        if (count($plans) !== self::EXPECTED_COUNT) {
            $issues[] = 'article_plan_count_unexpected';
        }
        if ((int) data_get($payload, 'candidate_counts.resolved_article_operations') !== self::EXPECTED_COUNT) {
            $issues[] = 'resolved_article_count_unexpected';
        }
        foreach (['database_write', 'cms_write', 'cms_publish', 'url_truth_write', 'schema_enable', 'hreflang_enable', 'sitemap_write', 'llms_write', 'search_channel_enqueue', 'indexnow_submit', 'baidu_submit', 'gsc_request_indexing', 'revalidation', 'deploy'] as $field) {
            if ((bool) data_get($payload, 'negative_guarantees.'.$field) !== false) {
                $issues[] = 'dry_run_negative_guarantee_invalid:'.$field;
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function buildWritePlan(array $plan): array
    {
        $articleId = (int) data_get($plan, 'current.article_id', 0);
        $article = $articleId > 0
            ? Article::query()->withoutGlobalScopes()->with(['publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(), 'seoMeta' => static fn ($query) => $query->withoutGlobalScopes()])->find($articleId)
            : null;
        $issues = [];
        if (! $article instanceof Article) {
            $issues[] = 'article_not_found:'.$articleId;
        }
        if ((string) data_get($plan, 'locale') !== 'zh-CN') {
            $issues[] = 'locale_not_zh_cn';
        }
        if ((bool) data_get($plan, 'resolved') !== true) {
            $issues[] = 'dry_run_plan_not_resolved';
        }

        $replacements = $this->validatedReplacements($plan);
        if ($replacements === []) {
            $issues[] = 'replacement_plan_empty';
        }

        $texts = $article instanceof Article ? $this->textsFor($article) : [];
        $counts = $this->replacementCounts($texts, $replacements);

        return [
            'target' => (string) ($plan['target'] ?? ''),
            'article_id' => $articleId,
            'path' => (string) ($plan['path'] ?? ''),
            'slug' => (string) ($plan['slug'] ?? ''),
            'locale' => (string) ($plan['locale'] ?? ''),
            'ready' => $issues === [],
            'replacements' => $replacements,
            'replacement_counts_before' => $counts,
            'protected_snapshot' => $article instanceof Article ? $this->protectedSnapshot($article) : null,
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<int, array{find:string,replace_with:string,scope:string}>
     */
    private function validatedReplacements(array $plan): array
    {
        $allowed = [
            'Dynamic next steps' => '下一步怎么做',
            'Frequently asked questions' => '常见问题',
            'Frequently Asked Questions' => '常见问题',
            'Related reading' => '相关阅读',
            'Related reading / internal links' => '相关阅读',
            'Trust links' => '可信度与边界',
            '/tests/mbti-personality-test-16-personality-types' => '/zh/tests/mbti-personality-test-16-personality-types',
            '/tests/holland-career-interest-test-riasec' => '/zh/tests/holland-career-interest-test-riasec',
            '/tests/big-five-personality-test-ocean-model' => '/zh/tests/big-five-personality-test-ocean-model',
            '/science' => '/zh/science',
            '/method-boundaries' => '/zh/method-boundaries',
            '/reliability-validity' => '/zh/reliability-validity',
        ];
        $raw = array_merge(
            array_values((array) data_get($plan, 'planned_repairs.heading_replacements', [])),
            array_values((array) data_get($plan, 'planned_repairs.link_replacements', [])),
        );

        $out = [];
        foreach ($raw as $replacement) {
            $find = (string) (data_get($replacement, 'find') ?? data_get($replacement, 'find_href', ''));
            $replace = (string) (data_get($replacement, 'replace_with') ?? data_get($replacement, 'replace_with_href', ''));
            if (($allowed[$find] ?? null) !== $replace) {
                continue;
            }
            $out[] = [
                'find' => $find,
                'replace_with' => $replace,
                'scope' => (string) data_get($replacement, 'scope', ''),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     * @return array<int, array<string, mixed>>
     */
    private function executePlans(array $plans, string $dryRunSha): array
    {
        return DB::transaction(function () use ($plans, $dryRunSha): array {
            $results = [];
            foreach ($plans as $plan) {
                if (($plan['ready'] ?? false) !== true) {
                    continue;
                }
                /** @var Article $article */
                $article = Article::query()->withoutGlobalScopes()->with(['publishedRevision' => static fn ($query) => $query->withoutGlobalScopes()])->findOrFail((int) $plan['article_id']);
                $revision = $article->publishedRevision;
                $before = $this->protectedSnapshot($article);
                $articleMd = (string) $article->content_md;
                $articleHtml = (string) $article->content_html;
                $revisionMd = $revision instanceof ArticleTranslationRevision ? (string) $revision->content_md : null;
                $applied = ['article_content_md' => 0, 'article_content_html' => 0, 'published_revision_content_md' => 0];

                foreach ((array) $plan['replacements'] as $replacement) {
                    [$articleMd, $count] = $this->replaceText($articleMd, (string) $replacement['find'], (string) $replacement['replace_with']);
                    $applied['article_content_md'] += $count;
                    [$articleHtml, $count] = $this->replaceText($articleHtml, (string) $replacement['find'], (string) $replacement['replace_with']);
                    $applied['article_content_html'] += $count;
                    if ($revisionMd !== null) {
                        [$revisionMd, $count] = $this->replaceText($revisionMd, (string) $replacement['find'], (string) $replacement['replace_with']);
                        $applied['published_revision_content_md'] += $count;
                    }
                }

                $article->forceFill([
                    'content_md' => $articleMd,
                    'content_html' => $articleHtml,
                ])->save();
                if ($revision instanceof ArticleTranslationRevision && $revisionMd !== null) {
                    $revision->forceFill(['content_md' => $revisionMd])->save();
                }

                $article->refresh();
                $results[] = [
                    'target' => (string) $plan['target'],
                    'article_id' => (int) $article->id,
                    'applied_replacement_count' => array_sum($applied),
                    'applied_replacement_counts' => $applied,
                    'protected_before' => $before,
                    'protected_after' => $this->protectedSnapshot($article),
                    'source_dry_run_evidence_sha256' => $dryRunSha,
                ];
            }

            return $results;
        });
    }

    /**
     * @return array<string, string>
     */
    private function textsFor(Article $article): array
    {
        return [
            'article_content_md' => (string) $article->content_md,
            'article_content_html' => (string) $article->content_html,
            'published_revision_content_md' => (string) $article->publishedRevision?->content_md,
        ];
    }

    /**
     * @param  array<string, string>  $texts
     * @param  array<int, array<string, mixed>>  $replacements
     * @return array<string, int>
     */
    private function replacementCounts(array $texts, array $replacements): array
    {
        $counts = [];
        foreach ($texts as $field => $text) {
            $counts[$field] = 0;
            foreach ($replacements as $replacement) {
                $counts[$field] += substr_count($text, (string) $replacement['find']);
            }
        }

        return $counts;
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
     * @return array{0:string,1:int}
     */
    private function replaceText(string $text, string $find, string $replace): array
    {
        $count = 0;
        $updated = str_replace($find, $replace, $text, $count);

        return [$updated, $count];
    }

    private function requiredConfirmationPhrase(string $sha): string
    {
        return 'I explicitly approve SEO-OPS-ZH-ARTICLE-QUALITY-CONTROLLED-WRITER-01 to write 9 zh-CN article quality deterministic heading/link repairs from dry-run evidence sha256 '.$sha.'; no publish, no URL Truth, no sitemap/llms, no schema/hreflang, no Search Channel, no IndexNow/Baidu/GSC, no deploy/revalidation.';
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
            'command' => 'php artisan seo-ops:zh-article-quality-controlled-writer',
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => ! (bool) $this->option('execute'),
            'execute' => (bool) $this->option('execute'),
            'generated_at' => now()->utc()->toIso8601String(),
            'issues' => array_values(array_unique($issues)),
            'side_effects' => [
                'database_write' => false,
                'cms_article_update' => false,
                'cms_publish' => false,
                'search_channel_enqueue' => false,
                'indexnow_submit' => false,
            ],
        ], $extra);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function writeEvidence(string $artifactDir, array $summary): ?array
    {
        File::ensureDirectoryExists($artifactDir);
        $path = rtrim($artifactDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            .'seo-ops-zh-article-quality-controlled-writer-'.now()->utc()->format('Ymd\THis\Z').'.json';
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
            $this->info('ZH article quality controlled writer '.($summary['status'] ?? 'planned'));
        } else {
            $this->error('ZH article quality controlled writer blocked: '.implode(', ', (array) ($summary['issues'] ?? [])));
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

    /**
     * @return array<int, string>
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
}
