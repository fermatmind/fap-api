<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\LandingSurface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class SeoOpsP0CtrRepairDryRunCommand extends Command
{
    private const INPUT_SCHEMA = 'fermatmind-seo-ops-ctr-repair-p0-dry-run-preview.v1';

    private const OUTPUT_SCHEMA = 'seo-ops-p0-ctr-repair-dry-run.v1';

    private const EXPECTED_LANDING_SURFACE_COUNT = 2;

    private const EXPECTED_ARTICLE_COUNT = 3;

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
        'content_html',
        'content_md',
        'cms_draft_body',
    ];

    protected $signature = 'seo-ops:p0-ctr-repair-dry-run
        {--artifact= : Path to fermatmind-seo-ops-ctr-repair-p0-dry-run-preview.v1 JSON artifact}
        {--confirm-artifact-sha256= : Expected SHA-256 of the source artifact}
        {--artifact-dir= : Directory for read-only evidence output}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only backend/CMS authority dry-run for the P0 CTR repair artifact; writes evidence only.';

    public function handle(): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary(['artifact_dir_unwritable']));
        }

        $loaded = $this->loadSourceArtifact();
        if (($loaded['ok'] ?? false) !== true) {
            $summary = $this->failureSummary((array) ($loaded['issues'] ?? ['artifact_unreadable']), [
                'source_artifact' => $loaded['source_artifact'] ?? null,
            ]);
            $summary['evidence'] = $this->writeEvidence($artifactDir, $summary);

            return $this->finish($summary);
        }

        $artifact = (array) $loaded['artifact'];
        $landingCandidates = array_values((array) data_get($artifact, 'authority_groups.test_landing_surfaces', []));
        $articleCandidates = array_values((array) data_get($artifact, 'authority_groups.article_cms_updates', []));
        $issues = $this->validateArtifactShape($artifact, $landingCandidates, $articleCandidates);

        $landingPlans = array_map(fn (array $candidate): array => $this->landingSurfacePlan($candidate), $landingCandidates);
        $articlePlans = array_map(fn (array $candidate): array => $this->articlePlan($candidate), $articleCandidates);

        foreach ($landingPlans as $plan) {
            foreach ((array) ($plan['issues'] ?? []) as $issue) {
                $issues[] = (string) $issue;
            }
        }
        foreach ($articlePlans as $plan) {
            foreach ((array) ($plan['issues'] ?? []) as $issue) {
                $issues[] = (string) $issue;
            }
        }

        $issues = array_values(array_unique($issues));
        $ok = $issues === [];

        $plannedLandingCount = count(array_filter($landingPlans, static fn (array $plan): bool => (bool) ($plan['resolved'] ?? false)));
        $plannedArticleCount = count(array_filter($articlePlans, static fn (array $plan): bool => (bool) ($plan['resolved'] ?? false)));

        $summary = [
            'schema_version' => self::OUTPUT_SCHEMA,
            'command' => 'php artisan seo-ops:p0-ctr-repair-dry-run',
            'ok' => $ok,
            'status' => $ok ? 'planned' : 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->utc()->toIso8601String(),
            'source_artifact' => [
                'path' => (string) $loaded['source_artifact_path'],
                'sha256' => (string) $loaded['source_artifact_sha256'],
                'schema' => (string) ($artifact['schema'] ?? ''),
            ],
            'authority_sources' => [
                'landing_surfaces' => 'backend.landing_surfaces',
                'article_cms_updates' => 'backend.articles + article_seo_meta + article_translation_revisions',
            ],
            'candidate_counts' => [
                'landing_surface_candidates' => count($landingCandidates),
                'article_cms_update_candidates' => count($articleCandidates),
                'resolved_landing_surfaces' => $plannedLandingCount,
                'resolved_article_cms_updates' => $plannedArticleCount,
                'unresolved_landing_surfaces' => count($landingCandidates) - $plannedLandingCount,
                'unresolved_article_cms_updates' => count($articleCandidates) - $plannedArticleCount,
            ],
            'planned_write_count' => [
                'landing_surfaces' => $plannedLandingCount,
                'article_cms_updates' => $plannedArticleCount,
                'total' => $plannedLandingCount + $plannedArticleCount,
            ],
            'protected_diff_summary' => [
                'slug' => 'no_change',
                'canonical' => 'no_change',
                'locale' => 'no_change',
                'article_id' => $plannedArticleCount === count($articleCandidates) ? 'resolved_no_change' : 'unresolved',
                'publication' => 'no_change',
                'schema' => 'hold_no_change',
                'hreflang' => 'hold_no_change',
                'sitemap' => 'no_change',
                'llms' => 'no_change',
            ],
            'landing_surface_plans' => $landingPlans,
            'article_plans' => $articlePlans,
            'issues' => $issues,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
        $summary['evidence'] = $this->writeEvidence($artifactDir, $summary);

        return $this->finish($summary);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSourceArtifact(): array
    {
        $path = $this->readablePath((string) $this->option('artifact'));
        if ($path === null) {
            return ['ok' => false, 'issues' => ['artifact_unreadable']];
        }

        $raw = (string) file_get_contents($path);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return [
                'ok' => false,
                'source_artifact' => ['path' => $path],
                'issues' => ['forbidden_input_field_present'],
                'forbidden_matches' => $forbidden,
            ];
        }

        $actualSha = hash('sha256', $raw);
        $expectedSha = trim((string) $this->option('confirm-artifact-sha256'));
        if ($expectedSha === '' || ! hash_equals($expectedSha, $actualSha)) {
            return [
                'ok' => false,
                'source_artifact' => [
                    'path' => $path,
                    'sha256' => $actualSha,
                    'expected_sha256' => $expectedSha,
                ],
                'issues' => ['artifact_sha_mismatch'],
            ];
        }

        try {
            $artifact = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'ok' => false,
                'source_artifact' => ['path' => $path, 'sha256' => $actualSha],
                'issues' => ['artifact_json_invalid'],
            ];
        }

        if (! is_array($artifact)) {
            return [
                'ok' => false,
                'source_artifact' => ['path' => $path, 'sha256' => $actualSha],
                'issues' => ['artifact_json_not_object'],
            ];
        }

        if (($artifact['schema'] ?? null) !== self::INPUT_SCHEMA) {
            return [
                'ok' => false,
                'source_artifact' => ['path' => $path, 'sha256' => $actualSha],
                'issues' => ['artifact_schema_invalid'],
            ];
        }

        return [
            'ok' => true,
            'artifact' => $artifact,
            'source_artifact_path' => $path,
            'source_artifact_sha256' => $actualSha,
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<int, mixed>  $landingCandidates
     * @param  array<int, mixed>  $articleCandidates
     * @return array<int, string>
     */
    private function validateArtifactShape(array $artifact, array $landingCandidates, array $articleCandidates): array
    {
        $issues = [];
        if (($artifact['schema'] ?? null) !== self::INPUT_SCHEMA) {
            $issues[] = 'artifact_schema_invalid';
        }
        if (count($landingCandidates) !== self::EXPECTED_LANDING_SURFACE_COUNT) {
            $issues[] = 'landing_surface_candidate_count_unexpected';
        }
        if (count($articleCandidates) !== self::EXPECTED_ARTICLE_COUNT) {
            $issues[] = 'article_candidate_count_unexpected';
        }
        if ((bool) data_get($artifact, 'scope.cms_write_allowed') !== false) {
            $issues[] = 'artifact_scope_cms_write_not_false';
        }
        if ((bool) data_get($artifact, 'scope.publish_allowed') !== false) {
            $issues[] = 'artifact_scope_publish_not_false';
        }
        if ((bool) data_get($artifact, 'scope.search_submit_allowed') !== false) {
            $issues[] = 'artifact_scope_search_submit_not_false';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function landingSurfacePlan(array $candidate): array
    {
        $surfaceKey = (string) ($candidate['surface_key'] ?? '');
        $inputLocale = (string) ($candidate['locale'] ?? '');
        $locale = $this->normalizeLocale($inputLocale);
        $safePath = (string) ($candidate['safe_path'] ?? '');
        $issues = [];

        $surface = $surfaceKey !== ''
            ? LandingSurface::query()
                ->withoutGlobalScopes()
                ->where('surface_key', $surfaceKey)
                ->whereIn('locale', $this->localeCandidates($locale, $inputLocale))
                ->first()
            : null;

        if (! $surface instanceof LandingSurface) {
            $issues[] = 'landing_surface_not_found:'.$surfaceKey.':'.$locale;
        }

        $resolvedLocale = $surface instanceof LandingSurface ? (string) $surface->locale : $locale;

        return [
            'payload_key' => (string) ($candidate['payload_key'] ?? ''),
            'target' => $surfaceKey.':'.$resolvedLocale,
            'surface_key' => $surfaceKey,
            'input_locale' => $inputLocale,
            'resolved_locale' => $resolvedLocale,
            'safe_path' => $safePath,
            'resolved' => $surface instanceof LandingSurface,
            'authority_source' => 'backend.landing_surfaces',
            'current' => $surface instanceof LandingSurface ? [
                'id' => (int) $surface->id,
                'title' => (string) $surface->title,
                'description' => (string) $surface->description,
                'schema_version' => (string) $surface->schema_version,
                'status' => (string) $surface->status,
                'is_public' => (bool) $surface->is_public,
                'is_indexable' => (bool) $surface->is_indexable,
                'published_at' => optional($surface->published_at)->toIso8601String(),
                'payload_snapshot' => [
                    'seo_title' => data_get($surface->payload_json, 'seo_title'),
                    'seo_description' => data_get($surface->payload_json, 'seo_description'),
                    'h1_or_hero_title' => data_get($surface->payload_json, 'h1_or_hero_title'),
                    'primary_cta_label' => data_get($surface->payload_json, 'primary_cta_label'),
                ],
            ] : null,
            'planned_fields' => (array) ($candidate['proposed_payload_json_updates'] ?? []),
            'protected_diff' => [
                'surface_key' => $this->diffItem($surfaceKey, $surfaceKey, 'no_change'),
                'slug' => $this->diffItem((string) ($candidate['slug'] ?? ''), (string) ($candidate['slug'] ?? ''), 'no_change'),
                'canonical' => $this->diffItem($safePath, $safePath, 'no_change'),
                'locale' => $this->diffItem($resolvedLocale, $resolvedLocale, 'no_change'),
                'publication' => $this->diffItem($surface instanceof LandingSurface ? (string) $surface->status : null, 'unchanged', 'no_change'),
                'schema' => $this->diffItem('current_gate_preserved', 'hold', 'hold_no_change'),
                'hreflang' => $this->diffItem('current_gate_preserved', 'hold', 'hold_no_change'),
                'sitemap' => $this->diffItem('not_managed_by_landing_surface_dry_run', 'unchanged', 'no_change'),
                'llms' => $this->diffItem('not_managed_by_landing_surface_dry_run', 'unchanged', 'no_change'),
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function articlePlan(array $candidate): array
    {
        $slug = (string) ($candidate['slug'] ?? '');
        $inputLocale = (string) ($candidate['locale'] ?? '');
        $locale = $this->normalizeLocale($inputLocale);
        $safePath = (string) ($candidate['safe_path'] ?? '');
        $issues = [];

        $article = $slug !== ''
            ? Article::query()
                ->withoutGlobalScopes()
                ->with([
                    'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
                    'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                    'workingRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                ])
                ->where('slug', $slug)
                ->whereIn('locale', $this->localeCandidates($locale, $inputLocale))
                ->first()
            : null;

        if (! $article instanceof Article) {
            $issues[] = 'article_not_found:'.$slug.':'.$locale;
        }

        $seoMeta = $article?->seoMeta;
        $resolvedLocale = $article instanceof Article ? (string) $article->locale : $locale;
        $currentCanonical = $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->canonical_url : null;
        $currentCanonicalPath = $this->canonicalPath($currentCanonical);

        return [
            'payload_key' => (string) ($candidate['payload_key'] ?? ''),
            'target' => $article instanceof Article ? 'article:'.(int) $article->id.':'.$resolvedLocale : 'article:unresolved:'.$resolvedLocale,
            'slug' => $slug,
            'input_locale' => $inputLocale,
            'resolved_locale' => $resolvedLocale,
            'safe_path' => $safePath,
            'resolved' => $article instanceof Article,
            'authority_source' => 'backend.articles + article_seo_meta + article_translation_revisions',
            'current' => $article instanceof Article ? [
                'article_id' => (int) $article->id,
                'translation_group_id' => (string) $article->translation_group_id,
                'title' => (string) $article->title,
                'excerpt' => (string) $article->excerpt,
                'seo_title' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->seo_title : null,
                'seo_description' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->seo_description : null,
                'canonical_url' => $currentCanonical,
                'canonical_path' => $currentCanonicalPath,
                'robots' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->robots : null,
                'jsonld_total' => $seoMeta instanceof ArticleSeoMeta && is_array($seoMeta->schema_json) ? count($seoMeta->schema_json) : 0,
                'status' => (string) $article->status,
                'lifecycle_state' => $article->lifecycle_state,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'working_revision_id' => $article->working_revision_id,
                'published_revision_id' => $article->published_revision_id,
                'published_revision_status' => $article->publishedRevision?->revision_status,
                'published_at' => optional($article->published_at)->toIso8601String(),
            ] : null,
            'planned_fields' => (array) ($candidate['proposed_cms_field_updates'] ?? []),
            'protected_diff' => [
                'slug' => $this->diffItem($article instanceof Article ? (string) $article->slug : $slug, $slug, 'no_change'),
                'canonical' => $this->diffItem($currentCanonicalPath ?: $safePath, $currentCanonicalPath ?: $safePath, 'no_change'),
                'locale' => $this->diffItem($resolvedLocale, $resolvedLocale, 'no_change'),
                'article_id' => $article instanceof Article
                    ? $this->diffItem((int) $article->id, (int) $article->id, 'resolved_no_change')
                    : $this->diffItem(null, 'unresolved', 'unresolved'),
                'translation_group_id' => $article instanceof Article
                    ? $this->diffItem((string) $article->translation_group_id, (string) $article->translation_group_id, 'no_change')
                    : $this->diffItem(null, 'unresolved', 'unresolved'),
                'publication' => $this->diffItem($article instanceof Article ? (string) $article->status : null, 'unchanged', 'no_change'),
                'schema' => $this->diffItem('current_gate_preserved', 'hold', 'hold_no_change'),
                'hreflang' => $this->diffItem('current_gate_preserved', 'hold', 'hold_no_change'),
                'sitemap' => $this->diffItem($article instanceof Article ? (bool) $article->sitemap_eligible : null, 'unchanged', 'no_change'),
                'llms' => $this->diffItem($article instanceof Article ? (bool) $article->llms_eligible : null, 'unchanged', 'no_change'),
            ],
            'issues' => $issues,
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
            'command' => 'php artisan seo-ops:p0-ctr-repair-dry-run',
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->utc()->toIso8601String(),
            'issues' => array_values(array_unique($issues)),
            'negative_guarantees' => $this->negativeGuarantees(),
        ], $extra);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function writeEvidence(string $artifactDir, array $summary): ?array
    {
        File::ensureDirectoryExists($artifactDir);
        $path = rtrim($artifactDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            .'seo-ops-p0-ctr-repair-dry-run-'.now()->utc()->format('Ymd\THis\Z').'.json';

        $artifact = $summary;
        unset($artifact['evidence']);
        File::put($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [
            'path' => $path,
            'sha256' => hash_file('sha256', $path),
            'bytes' => filesize($path) ?: 0,
        ];
    }

    private function finish(array $summary): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif (($summary['ok'] ?? false) === true) {
            $this->info('P0 CTR repair dry-run planned: '.json_encode($summary['planned_write_count'] ?? []));
        } else {
            $this->error('P0 CTR repair dry-run blocked: '.implode(', ', (array) ($summary['issues'] ?? [])));
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
        if (! is_dir($dir)) {
            File::ensureDirectoryExists($dir);
        }

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

    private function normalizeLocale(string $locale): string
    {
        return match ($locale) {
            'zh' => 'zh-CN',
            default => $locale,
        };
    }

    /**
     * @return array<int, string>
     */
    private function localeCandidates(string $normalizedLocale, string $inputLocale): array
    {
        return array_values(array_unique(array_filter([$normalizedLocale, $inputLocale])));
    }

    private function canonicalPath(?string $canonical): ?string
    {
        if ($canonical === null || trim($canonical) === '') {
            return null;
        }
        $path = parse_url($canonical, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            return $path;
        }

        return str_starts_with($canonical, '/') ? $canonical : null;
    }

    /**
     * @param  mixed  $current
     * @param  mixed  $proposed
     * @return array<string, mixed>
     */
    private function diffItem($current, $proposed, string $change): array
    {
        return [
            'current' => $current,
            'proposed' => $proposed,
            'change' => $change,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
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
        ];
    }
}
